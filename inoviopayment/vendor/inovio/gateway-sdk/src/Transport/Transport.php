<?php

declare(strict_types=1);

namespace Inovio\Gateway\Transport;

use Inovio\Gateway\Errors\GatewayTimeoutException;
use Inovio\Gateway\Errors\TransportException;

/**
 * Transport — form-encoded POST to pmt_service.cfm, plus response normalization.
 *
 * Wire quirks are normalized ONCE, here, and never leak to the partner
 * (object model §2 principle 8):
 *   - responses are case-inconsistent   -> keys upper-cased
 *   - XTL_ORDER_ID / XTL_PO_ID name the same thing
 *   - PMT_L4 / PMT_LAST4 name the same thing
 */
final class Transport
{
    /** Spec §2.1. Sandbox host is configurable — confirm before non-local use. */
    public const PRODUCTION_ENDPOINT = 'https://api.inoviopay.com/payment/pmt_service.cfm';
    public const SANDBOX_ENDPOINT = 'https://api-uap.inoviopay.com/payment/pmt_service.cfm';

    private const ALIASES = [
        'XTL_PO_ID' => 'XTL_ORDER_ID',
        'PMT_LAST4' => 'PMT_L4',
    ];

    private function __construct()
    {
    }

    /**
     * Fields that must be transmitted even when empty. The ACS may hand back an
     * empty RESPONSE after a 3DS challenge, and the spec (§15.1.4) requires it
     * be passed through as the empty value for REQUEST_PARES.
     */
    private const SEND_WHEN_EMPTY = ['REQUEST_PARES' => true];

    /**
     * Spec §2.2: URL-encoded form body.
     *
     * @param array<string,string> $params
     */
    public static function formEncode(array $params): string
    {
        $filtered = [];
        foreach ($params as $k => $v) {
            if ($v !== null && ($v !== '' || isset(self::SEND_WHEN_EMPTY[$k]))) {
                $filtered[$k] = $v;
            }
        }

        return http_build_query($filtered, '', '&', PHP_QUERY_RFC1738);
    }

    /**
     * Normalize a raw gateway response into an upper-cased field map. Accepts
     * JSON (what we request) and falls back to form-encoded text.
     *
     * @return array<string,string>
     */
    public static function normalizeResponse(string $body): array
    {
        $out = [];
        $trimmed = trim($body);

        $put = static function (string $key, $value) use (&$out): void {
            if ($value === null) {
                return;
            }
            $k = strtoupper(trim($key));
            $out[$k] = is_string($value) ? $value : (string) $value;
            $alias = self::ALIASES[$k] ?? null;
            if ($alias !== null && !array_key_exists($alias, $out)) {
                $out[$alias] = $out[$k];
            }
        };

        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $parsed = json_decode($trimmed, true);
            if (!is_array($parsed)) {
                throw new TransportException('gateway returned malformed JSON');
            }
            // CCSTATUS answers with a COLUMNS/DATA table rather than flat
            // fields. Flattening would destroy the row structure, so pass it
            // through untouched for the client to expand.
            if (isset($parsed['COLUMNS'], $parsed['DATA'])
                && is_array($parsed['COLUMNS']) && is_array($parsed['DATA'])) {
                return ['__TABULAR__' => $trimmed];
            }

            $flatten = static function ($obj, string $prefix = '') use (&$flatten, $put): void {
                foreach ($obj as $k => $v) {
                    if (is_array($v)) {
                        $flatten($v, $prefix . $k . '_');
                    } else {
                        $put($prefix . $k, $v);
                    }
                }
            };
            $flatten($parsed);

            return $out;
        }

        parse_str($trimmed, $pairs);
        foreach ($pairs as $k => $v) {
            if (is_string($v)) {
                $put((string) $k, $v);
            }
        }

        return $out;
    }

    /**
     * @param array<string,string> $params
     * @return array<string,string>
     */
    public static function send(
        string $endpoint,
        HttpClient $client,
        int $timeoutMs,
        array $params,
        ?string $idempotencyKey = null,
        array $extraHeaders = []
    ): array {
        // $extraHeaders carries X-SIGNATURE/X-TIMESTAMP for the token service.
        $body = self::formEncode($params);
        try {
            $res = $client->post($endpoint, $body, array_merge([
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ], $extraHeaders), $timeoutMs);
        } catch (TimeoutSignal $e) {
            throw new GatewayTimeoutException(
                'gateway did not respond within ' . $timeoutMs
                . 'ms — transaction state is UNKNOWN',
                $timeoutMs,
                $idempotencyKey
            );
        }

        if ($res->status < 200 || $res->status >= 300) {
            throw new TransportException('gateway returned HTTP ' . $res->status);
        }

        return self::normalizeResponse($res->body);
    }
}
