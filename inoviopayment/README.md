# Inovio Payment Gateway — PrestaShop 9

Tokenized direct-post card checkout for PrestaShop 9, on the Inovio gateway.
**The card number never reaches the shop server.**

Design: [`docs/prestashop-integration-design.md`](../../../docs/prestashop-integration-design.md)

---

## Requirements

| | |
|---|---|
| PrestaShop | **9.0+** (9.x only — PrestaShop 8.x caps at PHP 8.1 and 1.7 is EOL) |
| PHP | **8.1+** |
| PHP extensions | **`bcmath`** (required), `curl`, `json` |
| From Inovio | API username, API password, Site ID, **Site Key**, Gateway Product ID |

`bcmath` is required, not optional. The SDK's `Money` type does every decimal
calculation through it so amounts never touch a binary float. Installation
fails without it.

---

## Install

1. Copy the `inoviopayment/` directory into your shop's `modules/` directory,
   or upload the ZIP through **Modules → Module Manager → Upload a module**.
2. Install it:

   ```bash
   php bin/console prestashop:module install inoviopayment
   ```

3. Configure it under **Payment → Payment Methods → Inovio Payment Gateway →
   Configure**.

The module creates its own saved-card table and the two order states it needs
(awaiting capture, awaiting 3-D Secure) at install time.

---

## Configuration

All settings are on the module's Configure screen.

### Gateway credentials

| Setting | Required | Meaning |
|---|---|---|
| **API Username** | Yes | Gateway `REQ_USERNAME`. |
| **API Password** | Yes | Gateway `REQ_PASSWORD`. Leave blank when saving to keep the stored password. |
| **Site ID** | Yes | Gateway `SITE_ID`. |
| **Merchant Account ID** | No | `MERCH_ACCT_ID`. Leave empty to let the gateway distribute by currency/country. |
| **Site Key** | Yes | See below. Not the API password. |
| **Gateway Product ID** | Yes | See below. Not a PrestaShop SKU. |
| **Gateway Endpoint** | No | The `pmt_service.cfm` transaction URL. The tokenization endpoint (`token_service.cfm`) and the 3-D Secure endpoint are **derived from it** by suffix rewrite, exactly as the SDK derives them, so the three cannot drift apart. Leave it at the default for production. |

**Site Key** is a **separate per-site HMAC secret issued by Inovio support**.
It is **not** the API password, and it is not something you can generate. It is
used only to sign the browser's tokenization request. Without it the browser
cannot tokenize and checkout fails with **error 121**.

**Gateway Product ID** (`LI_PROD_ID`) is a product registered on the *gateway*,
not a product or SKU from your catalogue. The whole order bills as one line
item under it.

Until all five required fields are filled in, the module hides itself at
checkout rather than presenting a card form it cannot process.

### Payment behaviour

| Setting | Required | Meaning |
|---|---|---|
| **Payment Action** | Yes | *Sale* charges at checkout. *Authorize only* reserves the funds and leaves the order awaiting a capture you trigger from the order page. |
| **Enable 3D Secure** | No | Runs 3-D Secure authentication. Requires a 3DS-configured merchant account. |
| **Enable Saved Cards** | No | Lets logged-in customers save a card for reuse. |
| **Statement Descriptor** | No | `PMT_DESCRIPTOR` — what appears on the cardholder's statement. **See the warning below.** |
| **Descriptor Phone** | No | `PMT_DESCRIPTOR_PHONE` — support number shown alongside the descriptor. |
| **Debug Logging** | No | Logs gateway activity. Card numbers are never logged — they never reach this server. |

> ### ⚠️ The statement descriptor must not contain a space, underscore or slash
>
> The gateway rejects the **entire transaction** with `Invalid Data` if
> `PMT_DESCRIPTOR` contains a **space**, an **underscore** or a **forward
> slash**. Every sale fails, not just the descriptor.
>
> | | |
> |---|---|
> | `ACME STORE` | ❌ rejected |
> | `ACME_STORE` | ❌ rejected |
> | `ACME/STORE` | ❌ rejected |
> | `ACME-STORE` | ✅ |
> | `ACMESTORE` | ✅ |
> | `ACME.STORE` | ✅ |
>
> The full allowed set is `A-Z`, `a-z`, `0-9`, and `.` `-` `*` `+` `&` `@`.
> This was mapped empirically by sending each candidate character in an
> otherwise-identical approved request. A multi-word descriptor is the natural
> thing for a merchant to type, and it kills every sale — so if you are unsure,
> leave the field empty.

---

## What you get

| Capability | Support |
|---|---|
| Sale | Authorize and capture in one step at checkout. |
| Authorize only | Reserve funds, capture later from the order page. |
| Capture | Full **and partial**, from the order page. |
| Void | Reverse an uncaptured authorization. |
| Refund | Full **and partial**, settlement-aware (see below). |
| Saved cards | The module's own storage plus a customer-account UI — PrestaShop has no vault API. |
| 3-D Secure | Device-data collection and challenge, with a module-created pending order state. |
| Timeout recovery | Reconciles via `status()` before failing an order. |

### Refunds are settlement-aware

The gateway rejects a refund on an order that has not settled yet with
`SERVICE 536 "Order not settled: Please reverse"`. Before settlement the correct
undo is a reversal, not a credit. `InovioGateway::refundOrder()` checks
settlement and picks the right verb, falling back to a reversal if a refund
still returns 536. You get one Refund control and the module chooses — you have
no way to know whether a batch has settled, so you are not asked to guess.

---

## Security and PCI posture

**The card number never reaches your server.**

Card fields live in the checkout page's DOM. The shipped JavaScript reads them,
exchanges the PAN for a single-use `TOKEN_GUID` by POSTing **directly to
Inovio**, and only that token is submitted to PrestaShop. Nothing in the payment
path on your server ever sees a card number.

Posture ≈ **SAQ A-EP**, and **no Inovio-hosted infrastructure is required** —
there is no hosted payment page and no iframe you have to redirect to.

Saved cards store the gateway's own references (`CUST_ID`, `PMT_ID`) plus the
card brand, last four digits and expiry — display-only fields explicitly
permitted under PCI DSS. There is no column that could hold a PAN or CVV.

**This was verified, not assumed:** a full `mysqldump` of the shop database
taken after a live test run contains **zero** occurrences of the test card
number in any representation. `ps_order_payment.card_number` holds the last four
digits only.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| No payment method appears at checkout | One of the five required credential fields is empty. The module hides itself rather than showing a card form it cannot process. | Fill in API Username, API Password, Site ID, **Site Key** and Gateway Product ID. |
| **Error 121** at checkout, or the card form never tokenizes | Site Key missing or wrong. | Enter the per-site HMAC Site Key from Inovio support. It is not the API password. |
| **`Invalid Data`** returned on every transaction | The statement descriptor contains a space, underscore or forward slash. | Remove them — see the descriptor warning above, or clear the field. |
| Refund fails with `SERVICE 536` | The order has not settled; a credit is the wrong verb. | Handled automatically — the module retries as a reversal. If you see 536 surface to the admin, capture the log and report it. |
| A test transaction declines with **Insufficient Funds** | The Inovio test gateway decides from the order total, not the card. | See Testing below — change the order total. |
| 3-D Secure challenge never completes | The ACS return URL must be reachable over HTTPS. | Test 3DS on an HTTPS-reachable host. |

---

## Testing

These apply to the **Inovio test gateway** only.

| Card | Behaviour |
|---|---|
| `4111111111111111` | Approves (no 3-D Secure challenge). |
| `4000000000002503` | Triggers a 3-D Secure challenge. Sandbox OTP: **`1234`**. |

Any future expiry date and any CVV are accepted.

### Forcing a decline

The sandbox decides declines from the **whole order total**, matched exactly.
The PAN, expiry and CVV are not consulted at all.

| Order total | Result |
|---|---|
| `6.35` | Declined — Insufficient Funds |
| `5.06` | Declined — Fraud |

The total must land on the trigger amount exactly, including tax and shipping —
not the product price. This is also why a test order can decline unexpectedly:
check the total before assuming the card or the credentials are at fault.

---

## Layout

```
inoviopayment.php              main class (PaymentModule)
classes/
  InovioGateway.php            SDK requests + transaction verbs
  InovioStoredCard.php         vault ObjectModel
  InovioVault.php              vault write path
controllers/front/
  signature.php                HMAC for browser tokenization (CSRF + cart-bound + rate-limited)
  validation.php               order placement
  threeds.php                  3DS prepare (AJAX)
  threedsreturn.php            ACS return (CSRF-exempt, TransactionId-authenticated)
  storedcards.php              saved-cards management
views/js/inovio-checkout.js    direct-post + 3DS checkout JS
vendor/inovio/gateway-sdk/     vendored SDK (Addons requires a self-contained ZIP)
tests/                         e2e scripts (see below)
```

## Developer tests

The e2e scripts need a booted Symfony kernel, so they run through a small
runner rather than bare CLI:

```php
// /tmp/runner.php
require_once "/var/www/html/vendor/autoload.php";
require_once "/var/www/html/config/config.inc.php";
$kernel = new FrontKernel("dev", true);
$kernel->boot();
global $kernel;
require "/var/www/html/modules/inoviopayment/tests/e2e_module.php";
```

- `tests/e2e_module.php` — sale → order → vault → refund through module code
- `tests/e2e_module_verbs.php` — authorize/capture/void/partial-capture/saved-card
- `tests/sdk_verbs.php` — raw SDK verbs; deliberately exercises the 536 path

A Playwright suite lives in `e2e/`. A local stack lives at
`stack/prestashop/docker-compose.yml`.

## Known gaps

- The checkout JS has not been exercised in a real browser yet (PrestaShop has
  no order-submission event, so it intercepts the form's own submit — that path
  needs a browser pass, plus a theme-compatibility matrix).
- 3DS challenge completion needs a browser and an HTTPS-reachable return URL.
- Whether deleting a saved card should also revoke the gateway-side payment
  method is open (design doc §11).
