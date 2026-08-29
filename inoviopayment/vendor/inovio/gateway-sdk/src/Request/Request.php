<?php

declare(strict_types=1);

namespace Inovio\Gateway\Request;

use Inovio\Gateway\Errors\ValidationException;
use Inovio\Gateway\Model\Address;
use Inovio\Gateway\Model\Affiliate;
use Inovio\Gateway\Model\BrowserData;
use Inovio\Gateway\Model\Card;
use Inovio\Gateway\Model\Customer;
use Inovio\Gateway\Model\Descriptor;
use Inovio\Gateway\Model\Fees;
use Inovio\Gateway\Model\Idempotency;
use Inovio\Gateway\Model\Metadata;
use Inovio\Gateway\Model\Money;
use Inovio\Gateway\Model\PartialAuth;
use Inovio\Gateway\Model\PaymentMethod;
use Inovio\Gateway\Model\PaymentMethods;
use Inovio\Gateway\Model\Recurring;
use Inovio\Gateway\Model\RiskOptions;
use Inovio\Gateway\Model\SavedCard;
use Inovio\Gateway\Model\ThreeDS;
use Inovio\Gateway\Model\ThreeDSChallengeResult;
use Inovio\Gateway\Model\ThreeDSResult;
use Inovio\Gateway\Model\Token;

/** A card transaction request (object model §3.3). */
final class TransactionRequest
{
    public ?Money $amount = null;
    public ?Customer $customer = null;
    public ?Address $billingAddress = null;
    public ?Address $shippingAddress = null;
    public ?Descriptor $descriptor = null;
    public ?RiskOptions $risk = null;
    public ?PartialAuth $partialAuth = null;
    public ?Idempotency $idempotency = null;
    public ?Recurring $recurring = null;
    public ?Fees $fees = null;
    public ?Affiliate $affiliate = null;
    public ?Metadata $metadata = null;
    public ?string $merchAcctId = null;
    public ?BrowserData $browser = null;
    /** 3DS enrollment leg (requires $browser). Mutually exclusive with the other 3DS blocks. */
    public ?ThreeDS $threeDS = null;
    /** 3DS challenge completion leg — normally set via ThreeDSecureClient::completeSale(). */
    public ?ThreeDSChallengeResult $threeDSChallenge = null;
    /** Externally-obtained 3DS authentication attached to a one-leg transaction. */
    public ?ThreeDSResult $threeDSResult = null;
    /** CCCREDIT + FORCE_CREDIT — a credit with no referenced original. */
    public bool $forceCredit = false;

    /** @param \Inovio\Gateway\Model\LineItem[] $lineItems */
    public function __construct(public PaymentMethod $paymentMethod, public array $lineItems = [])
    {
    }

    public function withIdempotency(string $xtlOrderId): self
    {
        $this->idempotency = new Idempotency($xtlOrderId);

        return $this;
    }
}

/** CCTRANSUPDATE payload — receipts attached post-hoc (Appendix G compliance). */
final class OrderUpdate
{
    public ?Metadata $metadata = null;

    public function __construct(public ?string $receipt = null)
    {
    }
}

/**
 * Model -> wire projection.
 *
 * This is where the SDK earns its keep: flat, uppercase, 1-indexed wire params
 * (LI_VALUE_1, BILL_ADDR_ZIP, REQUEST_INITATOR ...) are produced from cohesive
 * objects so the partner never types a wire field name.
 */
final class RequestBuilder
{
    private const IDEMPOTENCY_WIRE = [
        'OFF' => '0',
        'DECLINE_DUP' => '1',
        'RETURN_ORIGINAL' => '2',
    ];
    private const AVS_WIRE = ['on' => '1', 'off' => '0', 'ignore' => '2', 'conditional' => '3'];
    private const REBILL_WIRE = ['NONE' => '0', 'REBILL' => '1', 'START_SUBSCRIPTION' => '2'];
    private const REBILL_TYPE_WIRE = ['NONE' => '0', 'TRIAL' => '1', 'INITIAL' => '2', 'REBILL' => '3'];

    private function __construct()
    {
    }

    /** @return array<string,string> */
    public static function build(TransactionRequest $req): array
    {
        $p = [];
        PaymentMethods::assertV1($req->paymentMethod);

        if ($req->lineItems === []) {
            throw new ValidationException('at least one line item is required', null, 'LI_VALUE_1');
        }

        // --- payment method: absorbs the PMT_NUMB overload ---
        $pm = $req->paymentMethod;
        if ($pm instanceof Card) {
            self::put($p, 'PMT_NUMB', $pm->number());
            self::put($p, 'PMT_EXPIRY', $pm->expiry());
            self::put($p, 'PMT_KEY', $pm->cvv());
        } elseif ($pm instanceof Token) {
            // The token stands in for the PAN only — the transaction service
            // still requires the expiry (and CVV where the processor asks).
            self::put($p, 'TOKEN_GUID', $pm->guid());
            self::put($p, 'PMT_EXPIRY', $pm->expiry());
            self::put($p, 'PMT_KEY', $pm->cvv());
        } elseif ($pm instanceof SavedCard) {
            self::put($p, 'PMT_ID', $pm->pmtId());
            self::put($p, 'PMT_ID_XTL', $pm->pmtIdXtl());
            self::put($p, 'CUST_ID', $pm->custId());
        }

        // --- line items: the SDK owns the 1-based wire indexing ---
        $currency = $req->amount?->currency();
        foreach ($req->lineItems as $i => $li) {
            $n = $i + 1;
            if ($li->count > 10) {
                throw new ValidationException(
                    "line item {$n}: count must be <= 10 (spec §4.4)",
                    null,
                    "LI_COUNT_{$n}"
                );
            }
            self::put($p, "LI_PROD_ID_{$n}", $li->productId);
            self::put($p, "LI_PROD_ID_XTL_{$n}", $li->xtlProductId);
            self::put($p, "LI_COUNT_{$n}", (string) $li->count);
            self::put($p, "LI_VALUE_{$n}", $li->value->toWire());
            self::put($p, "LI_TYPE_{$n}", $li->type);
            if ($currency === null) {
                $currency = $li->value->currency();
            } elseif ($li->value->currency() !== $currency) {
                throw new ValidationException(
                    "line item {$n} currency {$li->value->currency()} does not match {$currency} — "
                    . 'a single transaction cannot mix currencies'
                );
            }
        }
        self::put($p, 'REQUEST_CURRENCY', $currency);

        $c = $req->customer;
        if ($c !== null) {
            self::put($p, 'CUST_FNAME', $c->firstName);
            self::put($p, 'CUST_LNAME', $c->lastName);
            self::put($p, 'CUST_EMAIL', $c->email);
            self::put($p, 'CUST_PHONE', $c->phone);
            self::put($p, 'CUST_LOGIN', $c->login);
            self::put($p, 'CUST_PASSWORD', $c->password);
            self::put($p, 'CUST_BIRTHDAY', $c->birthday);
            self::put($p, 'CUST_DLN', $c->dln);
            self::put($p, 'CUST_DLN_STATE', $c->dlnState);
            self::put($p, 'CUST_SSN_L4', $c->ssnLast4);
            self::put($p, 'CUST_BRCPFCNPJ', $c->brCpfCnpj);
            self::put($p, 'XTL_IP', $c->ip);
            self::put($p, 'USER_AGENT_XTL', $c->userAgent);
        }
        self::address($p, 'BILL', $req->billingAddress);
        self::address($p, 'SHIP', $req->shippingAddress);

        if ($req->descriptor !== null) {
            self::put($p, 'PMT_DESCRIPTOR', $req->descriptor->name);
            self::put($p, 'PMT_DESCRIPTOR_PHONE', $req->descriptor->phone);
            self::put($p, 'PMT_DESCRIPTOR_CITY', $req->descriptor->city);
        }

        if ($req->risk !== null) {
            if ($req->risk->avs !== null) {
                self::put($p, 'CHKAVS', self::AVS_WIRE[$req->risk->avs] ?? null);
            }
            self::put($p, 'AVS_MATCH_SET', $req->risk->avsMatchSet);
            if ($req->risk->cvv !== null) {
                self::put($p, 'CHKCVV', self::AVS_WIRE[$req->risk->cvv] ?? null);
            }
            self::put($p, 'CVV_MATCH_SET', $req->risk->cvvMatchSet);
            if ($req->risk->timeoutVoid !== null) {
                $s = $req->risk->timeoutVoid->seconds;
                if ($s < 30 || $s > 600) {
                    throw new ValidationException(
                        "risk.timeoutVoid.seconds must be between 30 and 600, got {$s}",
                        null,
                        'REQUEST_MAX_WAIT'
                    );
                }
                self::put($p, 'REQUEST_MAX_WAIT', (string) $s);
            }
        }

        if ($req->partialAuth !== null && $req->partialAuth->enabled) {
            self::put($p, 'PARTIAL_AUTH', '1');
            if ($req->partialAuth->minimumAmount !== null) {
                self::put($p, 'PARTIAL_AUTH_MIN', $req->partialAuth->minimumAmount->toWire());
            }
        }

        if ($req->idempotency !== null) {
            self::put($p, 'XTL_ORDER_ID', $req->idempotency->xtlOrderId);
            $mode = $req->idempotency->mode ?? 'RETURN_ORIGINAL';
            self::put($p, 'UNIQUE_XTL_ORDER_ID', self::IDEMPOTENCY_WIRE[$mode] ?? null);
        }

        if ($req->recurring !== null) {
            $r = $req->recurring;
            // NOTE: the wire field is misspelled "INITATOR". Normalized here so
            // the partner never sees it.
            self::put($p, 'REQUEST_INITATOR', $r->initiator);
            if ($r->rebill !== null) {
                self::put($p, 'REQUEST_REBILL', self::REBILL_WIRE[$r->rebill] ?? null);
            }
            if ($r->rebillType !== null) {
                self::put($p, 'TRANS_REBILL_TYPE', self::REBILL_TYPE_WIRE[$r->rebillType] ?? null);
            }
            self::flag($p, 'INSTALLMENT', $r->installment);
            self::flag($p, 'CARD_ON_FILE', $r->cardOnFile);
            self::put($p, 'MBSHP_ID_XTL', $r->membershipXtlId);
            self::flag($p, 'TRIAL_CONSENT', $r->trialConsent);
            self::put($p, 'RECEIPT', $r->receipt);
        }

        if ($req->fees !== null) {
            if ($req->fees->tax !== null) {
                self::put($p, 'TAX_AMT', $req->fees->tax->amount->toWire());
                self::flag($p, 'TAX_EXEMPT', $req->fees->tax->exempt);
            }
            if ($req->fees->convenienceFee !== null) {
                self::put($p, 'CONVENIENCE_FEE', $req->fees->convenienceFee->toWire());
            }
        }

        if ($req->affiliate !== null) {
            self::put($p, 'REQUEST_AFF_ID', $req->affiliate->affId);
            self::put($p, 'REQUEST_AFF_ID_SUB', $req->affiliate->subAffId);
        }

        if ($req->metadata !== null) {
            self::put($p, 'TPPE_ID', $req->metadata->tppeId);
            self::put($p, 'PROC_UDF01', $req->metadata->procUdf1);
            self::put($p, 'PROC_UDF02', $req->metadata->procUdf2);
            foreach ($req->metadata->udf as $k => $v) {
                self::put($p, 'XTL_UDF' . str_pad((string) $k, 2, '0', STR_PAD_LEFT), $v);
            }
        }

        if ($req->browser !== null) {
            $b = $req->browser;
            self::put($p, 'P3DS_BROWSER_LANGUAGE', $b->language);
            self::put($p, 'USER_AGENT_XTL', $b->userAgent);
            self::put($p, 'P3DS_BROWSER_HEADER', $b->header);
            if ($b->javaEnabled !== null) {
                $p['P3DS_JAVA_ENABLED'] = $b->javaEnabled ? 'TRUE' : 'FALSE';
            }
            if ($b->javascriptEnabled !== null) {
                $p['P3DS_JAVASCRIPT_ENABLED'] = $b->javascriptEnabled ? 'TRUE' : 'FALSE';
            }
            if ($b->colorDepth !== null) {
                self::put($p, 'P3DS_BROWSER_COLOR_DEPTH', (string) $b->colorDepth);
            }
            if ($b->screenHeight !== null) {
                self::put($p, 'P3DS_SCREEN_HEIGHT', (string) $b->screenHeight);
            }
            if ($b->screenWidth !== null) {
                self::put($p, 'P3DS_SCREEN_WIDTH', (string) $b->screenWidth);
            }
            if ($b->timeZoneOffsetMinutes !== null) {
                self::put($p, 'P3DS_BROWSER_TIME_ZONE', (string) abs($b->timeZoneOffsetMinutes));
            }
            self::put($p, 'P3DS_CHALLENGE_WINDOW', $b->challengeWindow);
            self::put($p, 'P3DS_IP_ADDRESS', $b->ipAddress);
        }

        $threeDsBlocks = ($req->threeDS !== null ? 1 : 0)
            + ($req->threeDSChallenge !== null ? 1 : 0)
            + ($req->threeDSResult !== null ? 1 : 0);
        if ($threeDsBlocks > 1) {
            throw new ValidationException(
                'threeDS, threeDSChallenge and threeDSResult are distinct legs — set at most one'
            );
        }
        if ($req->threeDS !== null) {
            if ($req->browser === null) {
                throw new ValidationException(
                    '3DS enrollment requires browser data — the gateway silently skips 3DS '
                    . 'without P3DS_BROWSER_LANGUAGE / USER_AGENT_XTL / P3DS_BROWSER_HEADER',
                    null,
                    'P3DS_BROWSER_LANGUAGE'
                );
            }
            self::put($p, 'REQUEST_ENROLLMENT', '1');
            self::put($p, 'DDC_REFERENCEID', $req->threeDS->ddcReferenceId);
            self::put($p, 'P3DS_RETURN_URL', $req->threeDS->returnUrl);
            self::put($p, 'P3DS_VERSION', $req->threeDS->version);
        }
        if ($req->threeDSChallenge !== null) {
            self::put($p, 'P3DS_PROCTRANSID', $req->threeDSChallenge->procTransId);
            // The ACS may return an empty RESPONSE; the spec requires sending
            // REQUEST_PARES as the empty value, so bypass put()'s blank filter.
            $p['REQUEST_PARES'] = $req->threeDSChallenge->pares;
            // The gateway needs the protocol version on the completion leg to
            // recognize it as a 3DS-2 finalization (verified against the working
            // 3ds-tool finalize payload; without it the completion declines).
            self::put($p, 'P3DS_VERSION', $req->threeDSChallenge->version);
        }
        if ($req->threeDSResult !== null) {
            self::put($p, 'P3DS_CAVV', $req->threeDSResult->cavv);
            self::put($p, 'P3DS_ECI', $req->threeDSResult->eci);
            self::put($p, 'P3DS_TRANSID', $req->threeDSResult->transId);
            self::put($p, 'P3DS_VERSION', $req->threeDSResult->version);
            self::put($p, 'P3DS_XID', $req->threeDSResult->xid);
        }

        self::put($p, 'MERCH_ACCT_ID', $req->merchAcctId);
        if ($req->forceCredit) {
            self::put($p, 'FORCE_CREDIT', '1');
        }

        return $p;
    }

    /** @param array<string,string> $p */
    private static function address(array &$p, string $prefix, ?Address $a): void
    {
        if ($a === null) {
            return;
        }
        self::put($p, $prefix . '_ADDR', $a->line1);
        self::put($p, $prefix . '_ADDR2', $a->line2);
        self::put($p, $prefix . '_ADDR_CITY', $a->city);
        self::put($p, $prefix . '_ADDR_STATE', $a->state);
        self::put($p, $prefix . '_ADDR_ZIP', $a->zip);
        self::put($p, $prefix . '_ADDR_COUNTRY', $a->country);
        self::put($p, $prefix . '_ADDR_DISTRICT', $a->district);
    }

    /** @param array<string,string> $p */
    private static function put(array &$p, string $k, ?string $v): void
    {
        if ($v !== null && $v !== '') {
            $p[$k] = $v;
        }
    }

    /** @param array<string,string> $p */
    private static function flag(array &$p, string $k, ?bool $v): void
    {
        if ($v !== null) {
            $p[$k] = $v ? '1' : '0';
        }
    }
}
