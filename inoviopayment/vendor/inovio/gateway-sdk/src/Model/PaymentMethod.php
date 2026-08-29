<?php

declare(strict_types=1);

namespace Inovio\Gateway\Model;

/**
 * PaymentMethod — the central polymorphic type (object model §3.2).
 *
 * Absorbs the PMT_NUMB wire overload: that one field means PAN (card), bank
 * account number (ACH) or IBAN (SEPA/iDEAL/EPS) depending on the rail. The SDK
 * keys the wire semantics off the concrete variant so a partner never sees it.
 *
 * Sealed by convention: every implementation is `final` and constructed only
 * through {@see PaymentMethods}.
 */
interface PaymentMethod
{
    /** Discriminator matching the other SDKs: card, token, savedCard, ... */
    public function kind(): string;
}
