<?php

declare(strict_types=1);

namespace Inovio\Gateway;

use Inovio\Gateway\Errors\ConfigurationException;
use Inovio\Gateway\Errors\ValidationException;
use Inovio\Gateway\Model\Card;
use Inovio\Gateway\Model\PaymentMethods;
use Inovio\Gateway\Model\Token;
use Inovio\Gateway\Transport\HttpClient;
use Inovio\Gateway\Transport\Transport;

/** BIN metadata the token service returns alongside the token. */
final class TokenizedCardInfo
{
    public function __construct(
        public ?string $brand = null,
        public ?string $type = null,
        public ?string $bank = null,
        public ?string $country = null,
        public ?string $accountFundSource = null,
        public ?string $cardClass = null
    ) {
    }
}

final class TokenizeResult
{
    /** @param array<string,string> $raw */
    public function __construct(
        public Token $token,
        public TokenizedCardInfo $card,
        public array $raw,
        /** Gateway-side IP recorded for the token request. */
        public ?string $tokenIp = null,
        /** Token service request id — quote this to support. */
        public ?string $tokenReqId = null
    ) {
    }
}

/**
 * Ephemeral tokenization (spec §4.8).
 *
 * This is NOT the transaction service: a different endpoint
 * (token_service.cfm), a different request shape, and HMAC header auth instead
 * of username/password. Exchanging a PAN here yields a single-use TOKEN_GUID
 * that replaces PMT_NUMB on a later sale/authorize.
 *
 * Signature construction:
 *
 *   X-SIGNATURE = hex(hmac_sha256(siteKey, timestamp . uniqueId . siteId))
 *   X-TIMESTAMP = YYYYMMDDHHMMSS, UTC, valid for 300 seconds
 *
 * ⚠️ The v4.14 PDF is self-contradictory here. Its §4.8.1.2 note claims the
 * message also includes card_pan, and the document's worked example agrees. The
 * gateway does NOT do this — CRPT.TOKEN_PKG validates
 * hmac_sha256(utc || unique_id || site_id, site_key). Signing with the PAN
 * included yields error 121 "Get CCtoken GUID signature match fail". Verified
 * against the live T1 token service; this follows the gateway, not the document.
 *
 * The site key is provisioned per merchant site and is NOT the gateway
 * password — obtain it from Inovio support.
 */
final class Tokenize
{
    private function __construct()
    {
    }

    /** UTC timestamp in the token service's YYYYMMDDHHMMSS format. */
    public static function timestamp(): string
    {
        return gmdate('YmdHis');
    }

    /**
     * Build the request signature.
     *
     * Exposed so a caller can verify their site key without a live call.
     */
    public static function signRequest(
        string $siteKey,
        string $timestamp,
        string $uniqueId,
        string $siteId
    ): string {
        return hash_hmac('sha256', $timestamp . $uniqueId . $siteId, $siteKey);
    }

    /**
     * Verify the response signature the token service returns.
     *
     * Per CRPT.TOKEN_PKG the gateway signs
     * timestamp . tokenReqId . rawResponseBody with the same site key.
     */
    public static function verifyResponse(
        string $siteKey,
        string $timestamp,
        string $tokenReqId,
        string $rawBody,
        string $signature
    ): bool {
        $expected = hash_hmac('sha256', $timestamp . $tokenReqId . trim($rawBody), $siteKey);

        return hash_equals(strtolower($expected), strtolower($signature));
    }

    /** Blank means "BIN not in the lookup table", not "empty string". */
    private static function blankToNull(?string $v): ?string
    {
        return ($v === null || trim($v) === '') ? null : $v;
    }

    public static function tokenize(
        Card $card,
        string $endpoint,
        HttpClient $http,
        int $timeoutMs,
        string $siteId,
        string $siteKey,
        string $apiVersion,
        ?string $uniqueId = null
    ): TokenizeResult {
        if ($siteKey === '') {
            throw new ValidationException(
                'tokenize requires a siteKey — the per-site HMAC secret from Inovio '
                . 'support. It is NOT your gateway password.'
            );
        }
        $uid = $uniqueId ?? bin2hex(random_bytes(16));
        if (strlen($uid) > 32) {
            throw new ValidationException('tokenize: uniqueId must be at most 32 characters');
        }
        $ts = self::timestamp();

        $raw = Transport::send($endpoint, $http, $timeoutMs, [
            // The token service takes CARD_PAN — not PMT_NUMB, no expiry/CVV.
            'CARD_PAN' => $card->number(),
            'SITE_ID' => $siteId,
            'UNIQUE_ID' => $uid,
            'REQUEST_API_VERSION' => $apiVersion,
            'REQUEST_RESPONSE_FORMAT' => 'JSON',
        ], null, [
            'X-SIGNATURE' => self::signRequest($siteKey, $ts, $uid, $siteId),
            'X-TIMESTAMP' => $ts,
        ]);

        $guid = $raw['TOKEN_GUID'] ?? null;
        if ($guid === null || $guid === '') {
            $message = $raw['ERROR_MESSAGE'] ?? 'token service did not return a TOKEN_GUID';
            if (($raw['ERROR_CODE'] ?? null) === '121') {
                $message .= ' (signature mismatch — check the site key, and that the '
                    . 'signed message is timestamp+uniqueId+siteId with NO card_pan)';
            }
            throw new ConfigurationException($message, null, $raw);
        }

        return new TokenizeResult(
            // Carry expiry/cvv forward: the token replaces the PAN, but the
            // transaction service still needs them (§4.8.2).
            token: PaymentMethods::token($guid, $card->expiry(), $card->cvv()),
            // BIN metadata is best-effort: the service returns these keys EMPTY
            // when the BIN is not in its lookup table (observed on live T1).
            card: new TokenizedCardInfo(
                brand: self::blankToNull($raw['CARD_BRAND_NAME'] ?? null),
                type: self::blankToNull($raw['CARD_TYPE'] ?? null),
                bank: self::blankToNull($raw['CARD_BANK'] ?? null),
                country: self::blankToNull($raw['CARD_COUNTRY'] ?? null),
                accountFundSource: self::blankToNull($raw['CARD_ACCOUNT_FUND_SOURCE'] ?? null),
                cardClass: self::blankToNull($raw['CARD_CLASS'] ?? null),
            ),
            raw: $raw,
            tokenIp: self::blankToNull($raw['TOKEN_IP'] ?? null),
            tokenReqId: self::blankToNull($raw['TOKEN_REQID'] ?? null),
        );
    }
}
