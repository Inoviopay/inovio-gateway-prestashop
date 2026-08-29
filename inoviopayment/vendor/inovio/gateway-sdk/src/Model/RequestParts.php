<?php

declare(strict_types=1);

namespace Inovio\Gateway\Model;

use Inovio\Gateway\Errors\ValidationException;

/** CUST_* + XTL_IP */
final class Customer
{
    public ?string $firstName = null;
    public ?string $lastName = null;
    public ?string $email = null;
    public ?string $phone = null;
    public ?string $login = null;
    public ?string $password = null;
    /** MM-DD-YYYY per spec §4.2 */
    public ?string $birthday = null;
    public ?string $dln = null;
    public ?string $dlnState = null;
    public ?string $ssnLast4 = null;
    /** Brazil CPF/CNPJ — presence activates Credilink scrubbing. */
    public ?string $brCpfCnpj = null;
    public ?string $ip = null;
    public ?string $userAgent = null;
}

/** BILL_ADDR_* / SHIP_ADDR_* */
final class Address
{
    public ?string $line1 = null;
    public ?string $line2 = null;
    public ?string $city = null;
    public ?string $state = null;
    public ?string $zip = null;
    /** ISO-2 */
    public ?string $country = null;
    public ?string $district = null;
}

/** LI_*_n — the SDK owns the 1-based wire indexing. */
final class LineItem
{
    public ?string $xtlProductId = null;
    public ?string $type = null;

    public function __construct(
        public string $productId,
        public int $count,
        public Money $value
    ) {
    }
}

/** PMT_DESCRIPTOR* */
final class Descriptor
{
    /**
     * Characters the gateway accepts in PMT_DESCRIPTOR.
     *
     * Empirical, not from the spec: mapped 2026-08-29 by sending each
     * candidate in an otherwise-identical approved request. SPACE, UNDERSCORE
     * and FORWARD SLASH are rejected and fail the ENTIRE transaction with
     * "Invalid Data"; hyphen, dot, asterisk, plus, ampersand, at, digits and
     * mixed case all pass.
     *
     * Space matters most: a multi-word descriptor ("ACME STORE") is the
     * natural thing for a merchant to configure, and it kills every sale.
     */
    public const ALLOWED_NAME = '/^[A-Za-z0-9.\-*+&@]+$/';

    public ?string $phone = null;
    public ?string $city = null;

    public function __construct(public string $name)
    {
        if ($name !== '' && !preg_match(self::ALLOWED_NAME, $name)) {
            throw new ValidationException(
                'descriptor may not contain spaces, underscores or slashes: ' . $name,
                null,
                'PMT_DESCRIPTOR'
            );
        }
    }
}

/** Spec §14.3 — opt-in, NOT defaulted on (Q6). Range 30..600 seconds. */
final class TimeoutVoid
{
    public function __construct(public int $seconds)
    {
    }
}

/** CHKAVS / CHKCVV / REQUEST_MAX_WAIT */
final class RiskOptions
{
    /** on | off | ignore | conditional */
    public ?string $avs = null;
    public ?string $avsMatchSet = null;
    public ?string $cvv = null;
    public ?string $cvvMatchSet = null;
    public ?TimeoutVoid $timeoutVoid = null;
}

/** PARTIAL_AUTH / PARTIAL_AUTH_MIN */
final class PartialAuth
{
    public ?Money $minimumAmount = null;

    public function __construct(public bool $enabled = false)
    {
    }
}

/**
 * Idempotency (Q6). mode maps to UNIQUE_XTL_ORDER_ID and defaults to
 * RETURN_ORIGINAL — a retry returns the original result instead of charging twice.
 */
final class Idempotency
{
    /** OFF | DECLINE_DUP | RETURN_ORIGINAL */
    public ?string $mode = null;

    public function __construct(public string $xtlOrderId)
    {
    }
}

/** Card-on-file / recurring compliance flags (Appendices G/J/K). */
final class Recurring
{
    /** CIT | MIT */
    public ?string $initiator = null;
    /** NONE | REBILL | START_SUBSCRIPTION */
    public ?string $rebill = null;
    /** NONE | TRIAL | INITIAL | REBILL */
    public ?string $rebillType = null;
    public ?bool $installment = null;
    public ?bool $cardOnFile = null;
    public ?bool $trialConsent = null;
    public ?string $membershipXtlId = null;
    public ?string $receipt = null;
}

final class Tax
{
    public ?bool $exempt = null;

    public function __construct(public Money $amount)
    {
    }
}

final class Fees
{
    public ?Tax $tax = null;
    public ?Money $convenienceFee = null;
}

final class Affiliate
{
    public ?string $affId = null;
    public ?string $subAffId = null;
}

/** XTL_UDF01..20, TPPE_ID, PROC_UDF01/02 */
final class Metadata
{
    /** @var array<string,string> */
    public array $udf = [];
    public ?string $tppeId = null;
    public ?string $procUdf1 = null;
    public ?string $procUdf2 = null;
}

/**
 * 3DS browser/device data. The gateway silently disables 3DS if language,
 * userAgent or header is missing — hence they are required here. The rest are
 * the optional EMVCo device fields (spec §15.1, IOTP-1685).
 */
final class BrowserData
{
    public function __construct(
        public string $language,
        public string $userAgent,
        public string $header,
        /** Browser can execute Java — wire TRUE/FALSE. */
        public ?bool $javaEnabled = null,
        /** Browser can execute JavaScript — wire TRUE/FALSE. */
        public ?bool $javascriptEnabled = null,
        /** Bits per pixel: 1, 4, 8, 15, 16, 24, 32 or 48. */
        public ?int $colorDepth = null,
        public ?int $screenHeight = null,
        public ?int $screenWidth = null,
        /** Minutes between UTC and browser local time; positive regardless of direction. */
        public ?int $timeZoneOffsetMinutes = null,
        /** ACS challenge window size override code. */
        public ?string $challengeWindow = null,
        public ?string $ipAddress = null
    ) {
    }
}

/**
 * 3DS enrollment leg (CMPI lookup) — attach to sale/authorize AFTER the DDC
 * iframe has run against DdcReference->ddcUrl.
 *
 * Requires TransactionRequest->browser to be set; the builder rejects the
 * request otherwise, because the gateway silently skips 3DS without it.
 */
final class ThreeDS
{
    public function __construct(
        /** From ThreeDSecureClient::prepare(). */
        public string $ddcReferenceId,
        /** Where the ACS POSTs TRANSACTIONID/RESPONSE/MD after the challenge. */
        public string $returnUrl,
        public string $version = '2'
    ) {
    }
}

/**
 * 3DS challenge completion — what the ACS POSTed back to the return URL.
 *
 * RESPONSE may legitimately be empty; the spec requires passing it through as
 * the empty value, so $pares is a plain string, not nullable.
 */
final class ThreeDSChallengeResult
{
    public function __construct(
        /** TRANSACTIONID from the ACS return POST (P3DS_PROCTRANSID). */
        public string $procTransId,
        /** RESPONSE from the ACS return POST (REQUEST_PARES) — may be ''. */
        public string $pares = '',
        public string $version = '2'
    ) {
    }
}

/**
 * Externally-obtained 3DS authentication — for partners running their own 3DS
 * provider. Attaches to a normal sale/authorize; one leg, no redirect.
 */
final class ThreeDSResult
{
    public function __construct(
        public string $cavv,
        public string $eci,
        public string $transId,
        public string $version = '2',
        public ?string $xid = null
    ) {
    }
}
