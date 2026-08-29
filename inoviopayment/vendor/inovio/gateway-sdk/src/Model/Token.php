<?php

declare(strict_types=1);

namespace Inovio\Gateway\Model;

use InvalidArgumentException;

/**
 * Single-use ephemeral token from the token service -> TOKEN_GUID.
 *
 * The token replaces ONLY the PAN. Per spec §4.8.2 a token-based transaction
 * still carries PMT_EXPIRY and PMT_KEY, so those travel with the token —
 * omitting the expiry yields API 110 "Required field" on REF_FIELD=pmt_expiry.
 * Verified against the live T1 gateway.
 */
final class Token implements PaymentMethod
{
    private string $guid;
    private ?string $expiry;
    private ?string $cvv;

    /** @internal use PaymentMethods::token() */
    public function __construct(string $guid, ?string $expiry = null, ?string $cvv = null)
    {
        if ($guid === '') {
            throw new InvalidArgumentException('token guid is required');
        }
        if ($expiry !== null && preg_match('/^\d{6}$/', $expiry) !== 1) {
            throw new InvalidArgumentException(
                'token expiry must be MMYYYY (6 digits), got ' . $expiry
            );
        }
        $this->guid = $guid;
        $this->expiry = $expiry;
        $this->cvv = $cvv;
    }

    /** MMYYYY -> PMT_EXPIRY. Required by the transaction service. */
    public function expiry(): ?string
    {
        return $this->expiry;
    }

    /** CVV2/CVC2 -> PMT_KEY. */
    public function cvv(): ?string
    {
        return $this->cvv;
    }

    public function kind(): string
    {
        return 'token';
    }

    public function guid(): string
    {
        return $this->guid;
    }
}
