<?php

declare(strict_types=1);

namespace Inovio\Gateway\Model;

use InvalidArgumentException;

/** Validating constructors for the v1 payment methods. */
final class PaymentMethods
{
    private function __construct()
    {
    }

    public static function card(string $number, string $expiry, ?string $cvv = null): Card
    {
        return new Card($number, $expiry, $cvv);
    }

    /** @param string|null $expiry MMYYYY — required when the token is used to transact. */
    public static function token(string $guid, ?string $expiry = null, ?string $cvv = null): Token
    {
        return new Token($guid, $expiry, $cvv);
    }

    public static function savedCard(
        ?string $pmtId = null,
        ?string $pmtIdXtl = null,
        ?string $custId = null
    ): SavedCard {
        return new SavedCard($pmtId, $pmtIdXtl, $custId);
    }

    /** v1 implements card, token and savedCard; other rails fill later phases. */
    public static function assertV1(PaymentMethod $pm): void
    {
        if (!$pm instanceof Card && !$pm instanceof Token && !$pm instanceof SavedCard) {
            throw new InvalidArgumentException(
                'payment method "' . $pm->kind() . '" is declared in the model but not '
                . 'implemented in v1 (v1 supports card, token, savedCard)'
            );
        }
    }
}
