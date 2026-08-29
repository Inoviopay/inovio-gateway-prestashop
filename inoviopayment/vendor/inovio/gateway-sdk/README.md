# Inovio Gateway SDK — PHP

The Inovio payment gateway for PHP 8+. Card transactions — authorize, capture,
refund, tokenize. Designed to drop into a WooCommerce, Magento, or custom cart:
bring your own HTTP client, no Composer dependencies.

> **Status: alpha.** Not yet published to Packagist.

## Requirements

PHP **8.0+**, with `ext-json`, `ext-bcmath` and `ext-curl`. No Composer
dependencies.

`bcmath` is required, not optional: `Money` does decimal arithmetic through it
so amounts never touch a binary float.

## Test

```bash
php tests/conformance.php     # 67 assertions, no Composer needed
python3 scripts/generate_enums.py   # regenerate enums from spec/spec-enums.json
```

The suite is plain PHP rather than PHPUnit so it runs on a bare interpreter.

## Quick start

```php
use Inovio\Gateway\{Credentials, InovioClient};
use Inovio\Gateway\Model\{LineItem, Money, PaymentMethods};
use Inovio\Gateway\Request\TransactionRequest;

$client = new InovioClient(new Credentials($user, $password, '123'), 'SANDBOX');

$req = (new TransactionRequest(
    PaymentMethods::card('4111111111111111', '122030', '123'),
    [new LineItem('SKU-1', 1, Money::of('10.00', 'USD'))]
))->withIdempotency('ORDER-555');   // retry-safe by default

$result = $client->sale($req);

match ($result->status) {
    'APPROVED' => /* fulfil */,
    'DECLINED' => /* $result->outcome->service, $result->serviceClassification */,
    'PENDING'  => /* $result->nextAction — 3DS challenge, redirect, voucher */,
    default    => /* RUNNING | FAILED */,
};
```

## Injecting your own HTTP client

Host platforms usually want their own transport (WordPress `wp_remote_post`,
Magento's PSR-18 client, an instrumented client). Implement `HttpClient` and
pass it in — the SDK never assumes it owns the socket:

```php
$client = new InovioClient($creds, 'PRODUCTION', null, new MyPsr18Adapter());
```

Throw `Inovio\Gateway\Transport\TimeoutSignal` from your adapter on timeout so
the SDK can convert it into `GatewayTimeoutException` with the idempotency key
attached.

## Five things that will surprise you

The behaviours below are worth internalizing before you integrate:

1. **A decline is not an exception.** `sale()` returns `status === 'DECLINED'`.
   Exceptions mean you never got a payment answer.
2. **No `approved`/`declined` flags.** Only `$result->status` — so `PENDING`
   cannot be silently treated as failure.
3. **`settled` is almost always `false` at response time** (batch flips it
   later); `conversion` is non-null only on real FX.
4. **`status()` is the reconciliation primitive**, not just timeout recovery.
5. **`Money::of(1.25, 'USD')` throws.** Pass `'1.25'` — floats cannot represent
   decimal amounts exactly.

## Classifier fields are our interpretation, not the spec

Some fields the SDK gives you are **derived by us from the response codes, not
returned by the gateway** — and you will branch real logic on them, so it is
worth knowing which:

- **`serviceClassification->retryable` / `terminal` / `stopRecurring`** — your
  dunning logic decides whether to re-try a declined charge based on these. We
  set them from the service response code; the gateway does not send them.
- **`avs->classification`** — `positive` / `partial` / `negative` / `neutral`.
  `partial` means some elements matched and some did not (e.g. street matches
  but postal code does not). **Whether a partial AVS result is acceptable is
  your risk decision** — the SDK reports the classification and deliberately
  does not accept or reject for you.

If you need the raw gateway value instead of our label, every result carries a
`raw` array with the verbatim response fields.

## Tokenization (spec §4.8)

`tokenize()` exchanges a PAN for a single-use `TOKEN_GUID` that replaces
`PMT_NUMB` on a later sale or authorize. It hits a **different endpoint**
(`token_service.cfm`) with **different auth** — HMAC headers, not
username/password.

You need a **site key**: a per-site HMAC secret issued by Inovio support. It is
*not* your gateway password. Without it the service answers error 121.

Two things the SDK handles that the spec will mislead you on:

**1. The signed message excludes the PAN.** The v4.14 PDF's §4.8.1.2 note says
the HMAC covers `card_pan`, and its worked example agrees — but the gateway
does not. The gateway actually validates:

```
hmac_sha256(timestamp || unique_id || site_id, site_key)
```

Signing with the card number included fails with error 121. This SDK signs
the way the gateway expects.

**2. A token replaces the PAN only.** The transaction still needs the expiry
(and CVV where the processor asks), so `tokenize()` carries them forward onto
the returned token. Sending a bare `TOKEN_GUID` yields API 110 `Required field`
on `REF_FIELD=pmt_expiry`.

BIN metadata (`brand`, `bank`, `country`, ...) is best-effort: the service
returns those keys **empty** when the BIN is not in its lookup table, and the
SDK normalizes blanks to null/undefined so you can test for presence.

⚠️ `tokenize()` runs on your server, so the card number passes through it. To
keep the number in the cardholder's browser instead, use the browser Hosted
Fields client (not yet available).

## 3D Secure (spec §15)

The SDK owns every server leg; your page owns two iframes (a hidden one for
device-data collection, a visible one for the challenge):

```php
use Inovio\Gateway\ThreeDSPrepare;
use Inovio\Gateway\Model\{BrowserData, ThreeDS, ThreeDSChallengeResult};

// 1. Start the session — returns what the DDC iframe needs.
$ddc = $client->threeDSecure()->prepare(
    ThreeDSPrepare::card($card, 'USD', 'US')
);
// Browser: POST $ddc->jwt (field name JWT) to $ddc->ddcUrl in a hidden iframe.

// 2. Enrollment leg — a normal sale/authorize carrying the ThreeDS block.
//    BrowserData is REQUIRED: without it the gateway silently skips 3DS.
$req->browser = new BrowserData($lang, $userAgent, $acceptHeader,
    javaEnabled: false, colorDepth: 24, screenHeight: 1080, screenWidth: 1920,
    timeZoneOffsetMinutes: 480);
$req->threeDS = new ThreeDS($ddc->ddcReferenceId, 'https://you.example/3ds-return');
$r = $client->sale($req);

match ($r->status) {
    'APPROVED', 'DECLINED' => /* frictionless — done, check $r->threeDS->eci */,
    'PENDING' => /* challenge: POST $r->nextAction->jwt to
                    $r->nextAction->redirectUrl in a visible iframe */,
};

// 3. The ACS POSTs TRANSACTIONID / RESPONSE / MD to your return URL.
//    RESPONSE may be EMPTY — that is not an error; pass it through as ''.
$final = $client->threeDSecure()->completeSale(
    $req,
    new ThreeDSChallengeResult($_POST['TransactionId'], $_POST['Response'] ?? '')
);
// $final->threeDS?->eci — 05/06 means fully authenticated (liability shift).
```

Vaulted cards use `ThreeDSPrepare::savedCard(...)` (the gateway looks up the
BIN); browser-tokenized cards use `ThreeDSPrepare::bin(...)` with the BIN
captured client-side. Partners running their own 3DS provider skip all of the
above and attach `$req->threeDSResult = new ThreeDSResult($cavv, $eci, $transId)`
to a normal one-leg `sale()`.
