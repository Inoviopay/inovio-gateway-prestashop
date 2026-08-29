<?php

declare(strict_types=1);

namespace Inovio\Gateway\Model;

use InvalidArgumentException;

/** Raw card-number entry — the number passes through your server. Prefer {@see Token}. */
final class Card implements PaymentMethod
{
    private string $number;
    private string $expiry;
    private ?string $cvv;

    /** @internal use PaymentMethods::card() */
    public function __construct(string $number, string $expiry, ?string $cvv = null)
    {
        $digits = preg_replace('/[\s-]/', '', $number) ?? '';
        if (preg_match('/^\d{12,19}$/', $digits) !== 1) {
            throw new InvalidArgumentException('card number must be 12-19 digits');
        }
        if (preg_match('/^\d{6}$/', $expiry) !== 1) {
            throw new InvalidArgumentException(
                'card expiry must be MMYYYY (6 digits), got ' . $expiry
            );
        }
        $month = (int) substr($expiry, 0, 2);
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException('card expiry month out of range in ' . $expiry);
        }
        if ($cvv !== null && preg_match('/^\d{3,4}$/', $cvv) !== 1) {
            throw new InvalidArgumentException('card cvv must be 3-4 digits');
        }

        $this->number = $digits;
        $this->expiry = $expiry;
        $this->cvv = $cvv;
    }

    public function kind(): string
    {
        return 'card';
    }

    /** PAN -> PMT_NUMB */
    public function number(): string
    {
        return $this->number;
    }

    /** MMYYYY -> PMT_EXPIRY */
    public function expiry(): string
    {
        return $this->expiry;
    }

    /** CVV2/CVC2 -> PMT_KEY */
    public function cvv(): ?string
    {
        return $this->cvv;
    }

    /** Deliberately does not expose the PAN. */
    public function __toString(): string
    {
        return 'Card{last4=' . substr($this->number, -4) . ', expiry=' . $this->expiry . '}';
    }
}
