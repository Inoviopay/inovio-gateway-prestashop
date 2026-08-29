<?php

declare(strict_types=1);

namespace Inovio\Gateway\Transport;

use RuntimeException;

/** Response from the gateway. */
final class HttpResponse
{
    public function __construct(public int $status, public string $body)
    {
    }
}

/** Implementations throw this to signal a timeout rather than a generic failure. */
final class TimeoutSignal extends RuntimeException
{
}

/**
 * Injectable so hosts can supply their own client (and tests can mock).
 *
 * WooCommerce/Magento hosts typically inject a PSR-18 adapter here rather than
 * letting the SDK open its own sockets.
 */
interface HttpClient
{
    /** @param array<string,string> $headers */
    public function post(string $url, string $body, array $headers, int $timeoutMs): HttpResponse;
}

/** Default client — cURL, which ships with virtually every PHP install. */
final class CurlHttpClient implements HttpClient
{
    public function post(string $url, string $body, array $headers, int $timeoutMs): HttpResponse
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('failed to initialise cURL');
        }
        $hdrs = [];
        foreach ($headers as $k => $v) {
            $hdrs[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
        ]);
        $out = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno === CURLE_OPERATION_TIMEOUTED) {
            throw new TimeoutSignal('request timed out');
        }
        if ($out === false) {
            throw new RuntimeException('cURL error ' . $errno);
        }

        return new HttpResponse($status, (string) $out);
    }
}
