<?php

declare(strict_types=1);

namespace Inovio\Gateway;

use Inovio\Gateway\Errors\ConfigurationException;
use Inovio\Gateway\Errors\ValidationException;
use Inovio\Gateway\Model\Card;
use Inovio\Gateway\Model\SavedCard;
use Inovio\Gateway\Model\ThreeDSChallengeResult;
use Inovio\Gateway\Request\TransactionRequest;
use Inovio\Gateway\Result\TransactionResult;
use Inovio\Gateway\Transport\HttpClient;
use Inovio\Gateway\Transport\Transport;

/**
 * What 3dsrequest.cfm answers on prepare() — everything the browser needs to
 * run device-data collection.
 */
final class DdcReference
{
    /** @param array<string,string> $raw */
    public function __construct(
        public string $reqId,
        /** POST this (field name JWT) to $ddcUrl in a hidden iframe. */
        public string $jwt,
        /** The 3DS provider's device-data collection endpoint. */
        public string $ddcUrl,
        /** Carry into ThreeDS on the enrollment leg. */
        public string $ddcReferenceId,
        public array $raw,
        /** First 6 of the card — returned so token/saved-card flows have the BIN. */
        public ?string $pmtBin = null,
        /** The merchant account distribution resolved to. */
        public ?string $merchAcctId = null
    ) {
    }
}

/**
 * Input to prepare(). The 3DS request service identifies the card three ways;
 * use the constructor matching what you hold. Currency and country drive
 * merchant-account distribution when merchAcctId is not given.
 */
final class ThreeDSPrepare
{
    private function __construct(
        public string $currency,
        public string $country,
        public ?Card $card = null,
        public ?SavedCard $savedCard = null,
        public ?string $bin = null,
        public ?string $merchAcctId = null
    ) {
    }

    public static function card(Card $card, string $currency, string $country, ?string $merchAcctId = null): self
    {
        return new self($currency, $country, card: $card, merchAcctId: $merchAcctId);
    }

    /**
     * Vaulted-card flow (spec §15.1.5.1): the gateway looks the BIN up from the
     * saved card; carry the returned pmtBin into later steps.
     */
    public static function savedCard(SavedCard $saved, string $currency, string $country, ?string $merchAcctId = null): self
    {
        if ($saved->pmtId() === null || $saved->custId() === null) {
            throw new ValidationException(
                '3DS prepare with a saved card requires both pmtId and custId'
            );
        }

        return new self($currency, $country, savedCard: $saved, merchAcctId: $merchAcctId);
    }

    /** Token flow: the browser tokenized the PAN but its BIN is known client-side. */
    public static function bin(string $bin, string $currency, string $country, ?string $merchAcctId = null): self
    {
        if (strlen($bin) < 6) {
            throw new ValidationException('3DS prepare requires at least the first 6 digits', null, 'PMT_BIN');
        }

        return new self($currency, $country, bin: $bin, merchAcctId: $merchAcctId);
    }
}

/**
 * The 3DS server legs (object model §6). The client-side pieces — the hidden
 * DDC iframe and the visible ACS challenge iframe — are the integration's job;
 * this class owns every gateway call around them:
 *
 *   1. prepare()                 -> DdcReference          (3dsrequest.cfm)
 *      (browser POSTs jwt to ddcUrl in a hidden iframe)
 *   2. sale/authorize with ThreeDS{ddcReferenceId, returnUrl} + BrowserData
 *      -> APPROVED/DECLINED (frictionless), or PENDING with
 *         nextAction=threeDSChallenge {redirectUrl, jwt, procTransId}
 *      (browser POSTs that jwt to redirectUrl in a visible iframe; the ACS
 *       POSTs TRANSACTIONID/RESPONSE/MD back to returnUrl)
 *   3. completeSale()/completeAuthorize() with ThreeDSChallengeResult
 *      -> final TransactionResult
 */
final class ThreeDSecureClient
{
    public function __construct(
        private Credentials $credentials,
        private string $endpoint,
        private HttpClient $http,
        private int $timeoutMs,
        private string $apiVersion,
        private InovioClient $client
    ) {
    }

    /**
     * Start a 3DS session against 3dsrequest.cfm.
     *
     * No REQUEST_ACTION is sent — the service derives 3DSINVOKEDDC from the
     * username/password auth path (verified against the deployed CFM source).
     */
    public function prepare(ThreeDSPrepare $req): DdcReference
    {
        $p = [
            'REQ_USERNAME' => $this->credentials->reqUsername,
            'REQ_PASSWORD' => $this->credentials->reqPassword,
            'SITE_ID' => $this->credentials->siteId,
            'REQUEST_API_VERSION' => $this->apiVersion,
            'REQUEST_RESPONSE_FORMAT' => 'JSON',
            'REQUEST_CURRENCY' => $req->currency,
            'BILL_ADDR_COUNTRY' => $req->country,
        ];
        $merchAcctId = $req->merchAcctId ?? $this->credentials->merchAcctId;
        if ($merchAcctId !== null) {
            $p['MERCH_ACCT_ID'] = $merchAcctId;
        }
        if ($req->card !== null) {
            $p['PMT_NUMB'] = $req->card->number();
        } elseif ($req->savedCard !== null) {
            $p['PMT_ID'] = (string) $req->savedCard->pmtId();
            $p['CUST_ID'] = (string) $req->savedCard->custId();
        } else {
            $p['PMT_BIN'] = (string) $req->bin;
        }

        $raw = Transport::send($this->endpoint, $this->http, $this->timeoutMs, $p);
        InovioClient::raiseIfApiError($raw);

        $jwt = $raw['JWT'] ?? null;
        $ddcUrl = $raw['DDC_URL'] ?? null;
        $refId = $raw['DDC_REFERENCEID'] ?? null;
        if ($jwt === null || $jwt === '' || $ddcUrl === null || $refId === null) {
            throw new ConfigurationException(
                $raw['API_ADVICE']
                    ?? '3DS request service did not return JWT/DDC_URL/DDC_REFERENCEID — '
                    . 'check that the merchant account is 3DS-configured',
                null,
                $raw
            );
        }

        return new DdcReference(
            reqId: $raw['REQ_ID'] ?? '',
            jwt: $jwt,
            ddcUrl: $ddcUrl,
            ddcReferenceId: $refId,
            raw: $raw,
            pmtBin: self::blankToNull($raw['PMT_BIN'] ?? null),
            merchAcctId: self::blankToNull($raw['MERCH_ACCT_ID'] ?? null),
        );
    }

    /**
     * Final leg after the ACS challenge — CCAUTHCAP carrying the challenge
     * outcome. Reuse the SAME TransactionRequest that ran the enrollment leg;
     * this swaps its ThreeDS block for the challenge result.
     */
    public function completeSale(TransactionRequest $req, ThreeDSChallengeResult $challenge): TransactionResult
    {
        return $this->client->sale(self::toCompletion($req, $challenge));
    }

    /** Final leg as CCAUTHORIZE — see completeSale(). */
    public function completeAuthorize(TransactionRequest $req, ThreeDSChallengeResult $challenge): TransactionResult
    {
        return $this->client->authorize(self::toCompletion($req, $challenge));
    }

    private static function toCompletion(TransactionRequest $req, ThreeDSChallengeResult $challenge): TransactionRequest
    {
        $req->threeDS = null;
        $req->threeDSChallenge = $challenge;

        return $req;
    }

    private static function blankToNull(?string $v): ?string
    {
        return ($v === null || trim($v) === '') ? null : $v;
    }
}
