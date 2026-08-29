<?php

declare(strict_types=1);

namespace Inovio\Gateway\Model;

use InvalidArgumentException;

/** A previously vaulted card -> PMT_ID / PMT_ID_XTL (+ CUST_ID). */
final class SavedCard implements PaymentMethod
{
    private ?string $pmtId;
    private ?string $pmtIdXtl;
    private ?string $custId;

    /** @internal use PaymentMethods::savedCard() */
    public function __construct(?string $pmtId = null, ?string $pmtIdXtl = null, ?string $custId = null)
    {
        if (($pmtId === null || $pmtId === '') && ($pmtIdXtl === null || $pmtIdXtl === '')) {
            throw new InvalidArgumentException('savedCard requires one of pmtId or pmtIdXtl');
        }
        $this->pmtId = $pmtId;
        $this->pmtIdXtl = $pmtIdXtl;
        $this->custId = $custId;
    }

    public function kind(): string
    {
        return 'savedCard';
    }

    public function pmtId(): ?string
    {
        return $this->pmtId;
    }

    public function pmtIdXtl(): ?string
    {
        return $this->pmtIdXtl;
    }

    public function custId(): ?string
    {
        return $this->custId;
    }
}
