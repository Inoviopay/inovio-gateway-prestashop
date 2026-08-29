<?php

declare(strict_types=1);

namespace Inovio\Gateway\Refs;

use InvalidArgumentException;

/**
 * Typed identity wrappers (object model §3.4).
 *
 * There is no single transaction handle in the gateway — different follow-ups
 * consume different keys (§1.4). Distinct classes make it impossible to hand
 * capture() a customer id by mistake.
 */
final class Refs
{
    private function __construct()
    {
    }

    public static function order(string $poId): OrderRef
    {
        return new OrderRef($poId);
    }

    public static function xtlOrder(string $value): XtlOrderId
    {
        return new XtlOrderId($value);
    }

    public static function lineItem(string $poLiId): LineItemRef
    {
        return new LineItemRef($poLiId);
    }

    public static function transaction(string $v): TransactionId
    {
        return new TransactionId($v);
    }

    public static function req(string $v): ReqId
    {
        return new ReqId($v);
    }

    public static function batch(string $v): BatchId
    {
        return new BatchId($v);
    }

    public static function customer(?string $custId, ?string $xtlCustId): CustomerRef
    {
        return new CustomerRef($custId, $xtlCustId);
    }

    public static function savedCard(?string $pmtId, ?string $pmtIdXtl): SavedCardRef
    {
        return new SavedCardRef($pmtId, $pmtIdXtl);
    }

    public static function membership(?string $mbshpId, ?string $mbshpIdXtl): MembershipRef
    {
        return new MembershipRef($mbshpId, $mbshpIdXtl);
    }
}

/** @internal shared base for single-value references */
abstract class StringRef
{
    protected string $value;

    public function __construct(string $value, string $name)
    {
        if ($value === '') {
            throw new InvalidArgumentException($name . ' is required');
        }
        $this->value = $value;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return static::class . '{' . $this->value . '}';
    }
}

/** Gateway order id (PO_ID) -> REQUEST_REF_PO_ID */
final class OrderRef extends StringRef
{
    public function __construct(string $poId)
    {
        parent::__construct($poId, 'poId');
    }

    public function poId(): string
    {
        return $this->value;
    }
}

/** Merchant order id (XTL_ORDER_ID) -> REQUEST_REF_PO_ID_XTL; idempotency key */
final class XtlOrderId extends StringRef
{
    public function __construct(string $value)
    {
        parent::__construct($value, 'xtlOrderId');
    }
}

/** Gateway line-item id (PO_LI_ID_n) -> REQUEST_REF_PO_LI_ID */
final class LineItemRef extends StringRef
{
    public function __construct(string $poLiId)
    {
        parent::__construct($poLiId, 'poLiId');
    }

    public function poLiId(): string
    {
        return $this->value;
    }
}

final class TransactionId extends StringRef
{
    public function __construct(string $v)
    {
        parent::__construct($v, 'transactionId');
    }
}

final class ReqId extends StringRef
{
    public function __construct(string $v)
    {
        parent::__construct($v, 'reqId');
    }
}

final class BatchId extends StringRef
{
    public function __construct(string $v)
    {
        parent::__construct($v, 'batchId');
    }
}

final class CustomerRef
{
    public function __construct(private ?string $custId = null, private ?string $xtlCustId = null)
    {
    }

    public function custId(): ?string
    {
        return $this->custId;
    }

    public function xtlCustId(): ?string
    {
        return $this->xtlCustId;
    }
}

final class SavedCardRef
{
    public function __construct(private ?string $pmtId = null, private ?string $pmtIdXtl = null)
    {
    }

    public function pmtId(): ?string
    {
        return $this->pmtId;
    }

    public function pmtIdXtl(): ?string
    {
        return $this->pmtIdXtl;
    }
}

final class MembershipRef
{
    public function __construct(private ?string $mbshpId = null, private ?string $mbshpIdXtl = null)
    {
    }

    public function mbshpId(): ?string
    {
        return $this->mbshpId;
    }

    public function mbshpIdXtl(): ?string
    {
        return $this->mbshpIdXtl;
    }
}
