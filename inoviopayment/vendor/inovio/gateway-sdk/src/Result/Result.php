<?php

declare(strict_types=1);

namespace Inovio\Gateway\Result;

use Inovio\Gateway\Model\Money;
use Inovio\Gateway\Refs\BatchId;
use Inovio\Gateway\Refs\CustomerRef;
use Inovio\Gateway\Refs\LineItemRef;
use Inovio\Gateway\Refs\MembershipRef;
use Inovio\Gateway\Refs\OrderRef;
use Inovio\Gateway\Refs\ReqId;
use Inovio\Gateway\Refs\SavedCardRef;
use Inovio\Gateway\Refs\TransactionId;
use Inovio\Gateway\Refs\XtlOrderId;

/** One of the four layered response tiers (§1.3). */
final class Tier
{
    public function __construct(
        public ?int $code = null,
        public ?string $advice = null,
        /** API tier only — names the offending field on validation failures. */
        public ?string $refField = null
    ) {
    }
}

/** The four independent tiers, outermost -> innermost. */
final class Outcome
{
    public function __construct(
        public Tier $api,
        /** The decline taxonomy lives here. */
        public Tier $service,
        public Tier $processor,
        public Tier $industry
    ) {
    }

    public static function empty(): self
    {
        return new self(new Tier(), new Tier(), new Tier(), new Tier());
    }
}

final class ServiceClassification
{
    public function __construct(
        public bool $retryable,
        public bool $stopRecurring,
        public bool $terminal,
        public bool $approval
    ) {
    }
}

final class Conversion
{
    public function __construct(public Money $amount, public string $exchangeRate)
    {
    }
}

final class AvsResult
{
    public function __construct(
        public string $code,
        public string $description,
        public string $cardNetwork,
        /** DERIVED: positive | partial | negative | neutral. */
        public string $classification,
        public string $raw
    ) {
    }
}

final class CvvResult
{
    public function __construct(
        public string $code,
        public string $description,
        /** DERIVED: match | no_match | neutral. */
        public string $classification,
        public string $raw
    ) {
    }
}

final class AccountUpdater
{
    public function __construct(
        public ?string $description = null,
        public ?string $date = null,
        public ?string $newExpiry = null,
        public ?string $newLast4 = null
    ) {
    }
}

final class CardInfo
{
    public ?string $brand = null;
    public ?string $detail = null;
    public ?string $type = null;
    public ?string $cardClass = null;
    public ?string $country = null;
    public ?string $bank = null;
    public ?bool $prepaid = null;
    public ?string $balance = null;
    public ?string $last4 = null;
    public ?int $networkTokenUsed = null;
    public ?AccountUpdater $accountUpdater = null;
}

/** What must happen next when the status is PENDING (§4.1). */
final class NextAction
{
    public ?string $url = null;
    public ?string $barcode = null;
    public ?string $token = null;
    public ?string $redirectUrl = null;
    public ?string $jwt = null;
    public ?string $procTransId = null;
    public ?string $pareq = null;

    public function __construct(public string $kind)
    {
    }
}

/**
 * 3DS authentication attached to a completed transaction. eci 05/06 (Visa) is
 * full authentication — the liability-shift signal partners branch on.
 */
final class ThreeDSAuth
{
    public function __construct(
        public ?string $eci = null,
        public ?string $cavv = null,
        public ?string $transId = null,
        /** e.g. "Frictionless Authentication" */
        public ?string $response = null,
        public ?string $vendor = null,
        public ?string $version = null
    ) {
    }
}

/**
 * The result of a single gateway call (object model §3.5).
 *
 * Two deliberate shapes, both load-bearing:
 *  1. NO derived approved/declined flags. $status is the only way to ask about
 *     outcome — booleans invite `if ($approved) ... else ...`, which silently
 *     treats PENDING as failure.
 *  2. Reference keys are FLAT, not nested in a refs bag; they are the most
 *     touched fields on the result.
 */
final class TransactionResult
{
    /** @param array<string,string> $raw @param LineItemRef[] $lineItemRefs */
    public function __construct(
        /** APPROVED | DECLINED | PENDING | RUNNING | FAILED */
        public string $status,
        /** PENDING or RUNNING — a genuine grouping, not an alias for status. */
        public bool $settling,
        public string $action,
        public Outcome $outcome,
        /**
         * The FACT of settlement. Written 0 at auth and flipped later by batch,
         * so this is usually false at response time and is NOT a failure signal.
         */
        public bool $settled,
        public array $raw,
        public array $lineItemRefs = [],
        public ?OrderRef $orderRef = null,
        public ?XtlOrderId $xtlOrderRef = null,
        public ?TransactionId $transactionId = null,
        public ?ReqId $requestId = null,
        public ?BatchId $batchId = null,
        public ?CustomerRef $customerRef = null,
        public ?SavedCardRef $savedCardRef = null,
        public ?MembershipRef $membershipRef = null,
        public ?Money $amount = null,
        /**
         * Present ONLY when real currency conversion occurred. On a domestic
         * transaction the wire's "settled" amount is the auth amount echoed
         * back, so an always-present block would mean nothing.
         */
        public ?Conversion $conversion = null,
        public ?ServiceClassification $serviceClassification = null,
        public ?AvsResult $avs = null,
        public ?CvvResult $cvv = null,
        public ?CardInfo $card = null,
        public ?NextAction $nextAction = null,
        public ?ThreeDSAuth $threeDS = null
    ) {
    }
}

/**
 * OrderStatus — the order is the aggregation root (§3.6).
 *
 * Partial capture, refund and void are SEPARATE transaction rows sharing a
 * PO_ID, not modifications of the original — so net position is an order-level
 * question. These figures mirror BATCH_PKG's own sibling-sum keyed on PO_ID.
 */
final class OrderStatus
{
    /** @param TransactionResult[] $transactions @param array<string,string> $raw */
    public function __construct(
        public OrderRef $ref,
        public array $transactions,
        public bool $settled,
        public array $raw,
        public ?XtlOrderId $xtlRef = null,
        public ?Money $authorized = null,
        public ?Money $captured = null,
        public ?Money $refunded = null,
        /** captured - refunded */
        public ?Money $net = null,
        /** authorized - captured (uncaptured balance) */
        public ?Money $outstanding = null
    ) {
    }
}

final class HealthResult
{
    /** @param array<string,string> $raw */
    public function __construct(
        public bool $ok,
        public string $action,
        public Outcome $outcome,
        public array $raw
    ) {
    }
}
