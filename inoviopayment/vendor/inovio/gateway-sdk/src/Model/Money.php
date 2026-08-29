<?php

declare(strict_types=1);

namespace Inovio\Gateway\Model;

use InvalidArgumentException;

/**
 * Money — decimal amount + ISO-4217 currency (object model §3.3 / Q7).
 *
 * The amount is held as a STRING and arithmetic goes through bcmath. PHP floats
 * are binary and cannot represent decimal amounts exactly (0.1 + 0.2 !== 0.3),
 * and the wire format is a decimal string like "1.25" — so the constructor
 * rejects float input outright rather than silently corrupting an amount.
 */
final class Money
{
    private const SCALE = 8;

    private string $amount;
    private string $currency;

    private function __construct(string $amount, string $currency)
    {
        $this->amount = $amount;
        $this->currency = $currency;
    }

    /**
     * @param string|int $amount decimal string, e.g. "1.25"
     * @param string $currency ISO-4217 alpha-3, e.g. "USD"
     */
    public static function of($amount, string $currency): self
    {
        if (is_float($amount)) {
            throw new InvalidArgumentException(
                'Money::of: amount must be a decimal string, not a float — '
                . 'binary floats cannot represent decimal amounts exactly. '
                . 'Pass "1.25", not 1.25.'
            );
        }
        if (is_int($amount)) {
            $amount = (string) $amount;
        }
        if (!is_string($amount) || preg_match('/^-?\d+(\.\d+)?$/', trim($amount)) !== 1) {
            throw new InvalidArgumentException(
                'Money::of: amount must be a decimal string like "1.25", got '
                . var_export($amount, true)
            );
        }
        if (preg_match('/^[A-Za-z]{3}$/', trim($currency)) !== 1) {
            throw new InvalidArgumentException(
                'Money::of: currency must be an ISO-4217 alpha-3 code like "USD", got '
                . var_export($currency, true)
            );
        }

        return new self(trim($amount), strtoupper(trim($currency)));
    }

    public function amount(): string
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /** Wire representation of the amount (what goes into LI_VALUE_n). */
    public function toWire(): string
    {
        return $this->amount;
    }

    public function plus(self $other): self
    {
        $this->requireSameCurrency($other);

        return new self(self::trimZeros(bcadd($this->amount, $other->amount, self::SCALE)), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->requireSameCurrency($other);

        return new self(self::trimZeros(bcsub($this->amount, $other->amount, self::SCALE)), $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && bccomp($this->amount, $other->amount, self::SCALE) === 0;
    }

    public function __toString(): string
    {
        return $this->amount . ' ' . $this->currency;
    }

    private function requireSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                'cannot combine ' . $this->currency . ' with ' . $other->currency
            );
        }
    }

    /** bcmath pads to SCALE; the wire wants the natural form. */
    private static function trimZeros(string $v): string
    {
        if (strpos($v, '.') === false) {
            return $v;
        }
        $v = rtrim($v, '0');

        return rtrim($v, '.') ?: '0';
    }
}
