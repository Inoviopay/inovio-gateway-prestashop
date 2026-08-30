<?php

declare(strict_types=1);

namespace Inovio\Gateway;

use Inovio\Gateway\Enums\Generated;
use Inovio\Gateway\Errors\AuthenticationException;
use Inovio\Gateway\Errors\ConfigurationException;
use Inovio\Gateway\Errors\RateLimitException;
use Inovio\Gateway\Errors\ValidationException;
use Inovio\Gateway\Model\Card;
use Inovio\Gateway\Model\Money;
use Inovio\Gateway\Model\PaymentMethods;
use Inovio\Gateway\Model\Token;
use Inovio\Gateway\Refs\LineItemRef;
use Inovio\Gateway\Refs\OrderRef;
use Inovio\Gateway\Refs\XtlOrderId;
use Inovio\Gateway\Request\OrderUpdate;
use Inovio\Gateway\Request\RequestBuilder;
use Inovio\Gateway\Request\TransactionRequest;
use Inovio\Gateway\Result\HealthResult;
use Inovio\Gateway\Result\OrderStatus;
use Inovio\Gateway\Result\ResultMapper;
use Inovio\Gateway\Result\TransactionResult;
use Inovio\Gateway\Transport\CurlHttpClient;
use Inovio\Gateway\Transport\HttpClient;
use Inovio\Gateway\Transport\Transport;

/** Gateway credentials. */
final class Credentials
{
    public function __construct(
        public string $reqUsername,
        public string $reqPassword,
        public string $siteId,
        public ?string $merchAcctId = null
    ) {
        if ($reqUsername === '' || $reqPassword === '' || $siteId === '') {
            throw new ValidationException(
                'credentials require reqUsername, reqPassword and siteId'
            );
        }
    }
}

/**
 * The v1 card-core surface (object model §3.1).
 *
 * Partners call $client->sale(), never REQUEST_ACTION=CCAUTHCAP.
 */
final class InovioClient
{
    private const DEFAULT_TIMEOUT_MS = 120000;

    private string $endpoint;
    private string $tokenEndpoint;
    private string $threeDsEndpoint;
    private string $apiVersion;
    private int $timeoutMs;
    private HttpClient $http;
    private ?ThreeDSecureClient $threeDSecure = null;

    public function __construct(
        private Credentials $credentials,
        string $environment = 'SANDBOX',
        ?string $endpoint = null,
        ?HttpClient $httpClient = null,
        ?string $apiVersion = null,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        ?string $tokenEndpoint = null,
        /** Per-site HMAC secret for the token service (§4.8); tokenize() only. */
        private ?string $siteKey = null,
        ?string $threeDsEndpoint = null
    ) {
        $this->endpoint = $endpoint
            ?? ($environment === 'PRODUCTION'
                ? Transport::PRODUCTION_ENDPOINT
                : Transport::SANDBOX_ENDPOINT);
        $this->tokenEndpoint = $tokenEndpoint
            ?? (string) preg_replace('/pmt_service\.cfm$/', 'token_service.cfm', $this->endpoint);
        $this->threeDsEndpoint = $threeDsEndpoint
            ?? (string) preg_replace('/pmt_service\.cfm$/', '3dsrequest.cfm', $this->endpoint);
        $this->apiVersion = $apiVersion ?? Generated::SPEC_API_VERSION;
        $this->timeoutMs = $timeoutMs;
        $this->http = $httpClient ?? new CurlHttpClient();
    }

    /** CCAUTHCAP — authorize and capture in one step. */
    public function sale(TransactionRequest $req): TransactionResult
    {
        return $this->transact('CCAUTHCAP', $req);
    }

    /** CCAUTHORIZE — authorization only; capture later. */
    public function authorize(TransactionRequest $req): TransactionResult
    {
        return $this->transact('CCAUTHORIZE', $req);
    }

    /** CCCAPTURE — capture a previous authorization. Partial-capable. */
    public function capture(OrderRef $order, ?Money $amount = null): TransactionResult
    {
        return ResultMapper::toTransactionResult(
            $this->call('CCCAPTURE', $this->refParams('REQUEST_REF_PO_ID', $order->poId(), $amount))
        );
    }

    /**
     * CCCAPTURE against a single line item.
     *
     * Per spec §5.5.6 the gateway requires the PARENT ORDER and an amount
     * alongside the line-item id — sending REQUEST_REF_PO_LI_ID alone is
     * rejected with API 113 "Invalid Data". LineItemRef does not carry its
     * order, so both must be passed. Verified against the live T1 gateway.
     */
    public function captureLineItem(
        OrderRef $order,
        LineItemRef $item,
        Money $amount
    ): TransactionResult {
        return ResultMapper::toTransactionResult($this->call('CCCAPTURE', [
            'REQUEST_REF_PO_ID' => $order->poId(),
            'REQUEST_REF_PO_LI_ID' => $item->poLiId(),
            'LI_VALUE_1' => $amount->toWire(),
            'LI_COUNT_1' => '1',
            'REQUEST_CURRENCY' => $amount->currency(),
        ]));
    }

    /**
     * CCREVERSE — void the original authorization.
     *
     * With $creditOnFail the gateway sends CREDIT_ON_FAIL=1 (spec §5.4.2): if
     * the transaction is already settled and cannot be reversed, the gateway
     * itself re-routes the request to CCCREDIT. The response then carries
     * REQUEST_ACTION=CCCREDIT instead of CCREVERSE.
     */
    public function reverse(OrderRef $order, bool $creditOnFail = false): TransactionResult
    {
        return ResultMapper::toTransactionResult(
            $this->call('CCREVERSE', self::reversalParams($order, $creditOnFail))
        );
    }

    /**
     * CCREVERSECAP — void a CCCAPTURE (not the original auth).
     *
     * $creditOnFail behaves exactly as on reverse(): the gateway's reversal
     * handling is shared between CCREVERSE and CCREVERSECAP, so a settled
     * capture is re-routed to CCCREDIT gateway-side and the response comes
     * back as CCCREDIT.
     */
    public function reverseCapture(OrderRef $order, bool $creditOnFail = false): TransactionResult
    {
        return ResultMapper::toTransactionResult(
            $this->call('CCREVERSECAP', self::reversalParams($order, $creditOnFail))
        );
    }

    /** @return array<string,string> */
    private static function reversalParams(OrderRef $order, bool $creditOnFail): array
    {
        $p = ['REQUEST_REF_PO_ID' => $order->poId()];
        if ($creditOnFail) {
            $p['CREDIT_ON_FAIL'] = '1';
        }

        return $p;
    }

    /** CCCREDIT — refund against an existing order. Partial-capable. */
    public function refund(OrderRef $order, ?Money $amount = null): TransactionResult
    {
        return ResultMapper::toTransactionResult(
            $this->call('CCCREDIT', $this->refParams('REQUEST_REF_PO_ID', $order->poId(), $amount))
        );
    }

    /** CCCREDIT + FORCE_CREDIT — a credit with no referenced original. */
    public function forceCredit(TransactionRequest $req): TransactionResult
    {
        $req->forceCredit = true;

        return $this->transact('CCCREDIT', $req);
    }

    /**
     * CCSTATUS — the reconciliation primitive AND the unknown-state recovery path.
     *
     * Returns order-level net position derived from every leg sharing the PO_ID.
     * For any order with more than one leg this is the only correct source of
     * net figures.
     */
    public function status(OrderRef|XtlOrderId $ref): OrderStatus
    {
        $p = $ref instanceof OrderRef
            ? ['REQUEST_REF_PO_ID' => $ref->poId()]
            : ['REQUEST_REF_PO_ID_XTL' => $ref->value()];
        $raw = $this->call('CCSTATUS', $p);
        $legMaps = self::extractLegs($raw);
        $legs = [];
        foreach ($legMaps as $leg) {
            $legs[] = ResultMapper::toTransactionResult($leg);
        }
        if ($legs === []) {
            $legs[] = ResultMapper::toTransactionResult($raw);
        }

        return ResultMapper::toOrderStatus($raw, $legs);
    }

    /** CCTRANSUPDATE — attach receipts to an existing order (Appendix G/J). */
    public function updateOrder(OrderRef $order, OrderUpdate $update): TransactionResult
    {
        $p = ['REQUEST_REF_PO_ID' => $order->poId()];
        if ($update->receipt !== null) {
            $p['RECEIPT'] = $update->receipt;
        }
        foreach ($update->metadata->udf ?? [] as $k => $v) {
            $p['XTL_UDF' . str_pad((string) $k, 2, '0', STR_PAD_LEFT)] = $v;
        }

        return ResultMapper::toTransactionResult($this->call('CCTRANSUPDATE', $p));
    }

    /**
     * Ephemeral tokenization (spec §4.8) — exchange a PAN for a single-use
     * TOKEN_GUID usable in place of PMT_NUMB.
     *
     * Requires $siteKey on the constructor — the per-site HMAC secret issued by
     * Inovio support. It is NOT the gateway password; without it the token
     * service answers error 121.
     *
     * NOTE: this is a server-side call — the PAN passes through your
     * infrastructure, so you remain in your server's data flow. The low-scope path is the
     * browser Hosted Fields client.
     */
    public function tokenize(Card $card, ?string $uniqueId = null): TokenizeResult
    {
        if ($this->siteKey === null || $this->siteKey === '') {
            throw new ValidationException(
                'tokenize requires $siteKey on the client — the per-site HMAC secret '
                . 'from Inovio support (not your gateway password).'
            );
        }

        return Tokenize::tokenize(
            $card,
            $this->tokenEndpoint,
            $this->http,
            $this->timeoutMs,
            $this->credentials->siteId,
            $this->siteKey,
            $this->apiVersion,
            $uniqueId
        );
    }

    /**
     * The 3DS server legs (object model §6): prepare() -> DDC ->
     * sale/authorize with a ThreeDS block -> completeSale()/completeAuthorize().
     */
    public function threeDSecure(): ThreeDSecureClient
    {
        return $this->threeDSecure ??= new ThreeDSecureClient(
            $this->credentials,
            $this->threeDsEndpoint,
            $this->http,
            $this->timeoutMs,
            $this->apiVersion,
            $this
        );
    }

    /** TESTAUTH — verify credentials. */
    public function testAuth(): HealthResult
    {
        return $this->toHealth($this->call('TESTAUTH', []));
    }

    /** TESTGW — verify gateway availability. */
    public function testAvailability(): HealthResult
    {
        return $this->toHealth($this->call('TESTGW', []));
    }

    // ------------------------------------------------------------------

    /** @return array<string,string> */
    private function authParams(string $action): array
    {
        $p = [
            'REQ_USERNAME' => $this->credentials->reqUsername,
            'REQ_PASSWORD' => $this->credentials->reqPassword,
            'SITE_ID' => $this->credentials->siteId,
            'REQUEST_ACTION' => $action,
            'REQUEST_API_VERSION' => $this->apiVersion,
            'REQUEST_RESPONSE_FORMAT' => 'JSON',
        ];
        if ($this->credentials->merchAcctId !== null) {
            $p['MERCH_ACCT_ID'] = $this->credentials->merchAcctId;
        }

        return $p;
    }

    /** @return array<string,string> */
    private function refParams(string $key, string $value, ?Money $amount): array
    {
        $p = [$key => $value];
        if ($amount !== null) {
            $p['LI_VALUE_1'] = $amount->toWire();
            $p['LI_COUNT_1'] = '1';
            $p['REQUEST_CURRENCY'] = $amount->currency();
        }

        return $p;
    }

    /**
     * Raise for API-tier failures only.
     *
     * A DECLINE IS NOT AN ERROR — it returns normally as a TransactionResult
     * with status DECLINED (Q1).
     *
     * @param array<string,string> $r
     */
    public static function raiseIfApiError(array $r): void
    {
        $code = $r['API_RESPONSE'] ?? null;
        if ($code === null || $code === '' || !is_numeric($code)) {
            return;
        }
        $c = (int) $code;
        if ($c === 0) {
            return;
        }
        $info = Generated::API_RESPONSE_CODES[$c] ?? null;
        if ($info === null) {
            return;
        }
        $msg = $info['description']
            . ($info['recommendation'] !== '' ? ' — ' . $info['recommendation'] : '');
        switch ($info['mapsToException']) {
            case 'RateLimitException':
                throw new RateLimitException($msg, $r);
            case 'AuthenticationException':
                throw new AuthenticationException($msg, $c, $r);
            case 'ValidationException':
                throw new ValidationException($msg, $c, $r['REF_FIELD'] ?? null, $r);
            case 'ConfigurationException':
                throw new ConfigurationException($msg, $c, $r);
        }
    }

    /**
     * @param array<string,string> $params
     * @return array<string,string>
     */
    private function call(string $action, array $params, ?string $idempotencyKey = null): array
    {
        $merged = array_merge($this->authParams($action), $params);
        $raw = Transport::send($this->endpoint, $this->http, $this->timeoutMs, $merged, $idempotencyKey);
        self::raiseIfApiError($raw);

        return $raw;
    }

    private function transact(string $action, TransactionRequest $req): TransactionResult
    {
        $params = RequestBuilder::build($req);

        return ResultMapper::toTransactionResult(
            $this->call($action, $params, $req->idempotency?->xtlOrderId)
        );
    }

    /** @param array<string,string> $raw */
    private function toHealth(array $raw): HealthResult
    {
        $res = ResultMapper::toTransactionResult($raw);
        $svc = $raw['SERVICE_RESPONSE'] ?? null;

        return new HealthResult(
            $res->status === 'APPROVED' || $svc === '100' || $svc === '101',
            $raw['REQUEST_ACTION'] ?? '',
            $res->outcome,
            $res->raw
        );
    }

    /**
     * CCSTATUS does not answer with flat fields like every other action — it
     * returns a tabular payload:
     *
     *   {"COLUMNS": ["REQUEST_ACTION","TRANS_STATUS_NAME",...],
     *    "DATA":    [["CCAUTHORIZE","APPROVED",...], ["CCCAPTURE",...]]}
     *
     * One DATA row per leg against the order. Verified against the live T1
     * gateway; this shape is not described in the v4.14 response-fields section.
     *
     * @param array<string,string> $raw
     * @return array<int,array<string,string>>
     */
    private static function extractLegs(array $raw): array
    {
        $tabular = $raw['__TABULAR__'] ?? null;
        if ($tabular === null) {
            return [];
        }
        $parsed = json_decode($tabular, true);
        if (!is_array($parsed) || !isset($parsed['COLUMNS'], $parsed['DATA'])) {
            return [];
        }
        $columns = $parsed['COLUMNS'];
        $legs = [];
        foreach ($parsed['DATA'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $leg = [];
            foreach ($columns as $i => $col) {
                $v = $row[$i] ?? null;
                if ($v === null || $v === '') {
                    continue;
                }
                $name = strtoupper((string) $col);
                // Duplicate column names appear (TRANS_ID twice); first wins.
                if (!array_key_exists($name, $leg)) {
                    $leg[$name] = (string) $v;
                }
            }
            $legs[] = $leg;
        }

        return $legs;
    }
}
