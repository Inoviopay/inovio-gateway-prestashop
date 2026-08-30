<?php
/**
 * HMAC signing for browser tokenization.
 *
 * The browser POSTs the PAN directly to Inovio, but that call must be signed
 * with the per-site key — which never leaves this server. This endpoint mints
 * the signature only; it never sees a card number.
 *
 * It must stay tightly guarded: an open signing endpoint would let anyone mint
 * tokens against the merchant's site.
 */

use Inovio\Gateway\Tokenize;

class InoviopaymentSignatureModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    /** @var bool JSON endpoint — never render the theme. */
    public $ajax = true;

    public function postProcess(): void
    {
        header('Content-Type: application/json');

        // (1) Same-origin: a valid per-visitor nonce must accompany the call.
        if (!$this->module->verifyCsrfToken((string) Tools::getValue('inovio_token'))) {
            $this->fail('invalid_token');
        }

        // (2) There must be a real cart in session with something in it.
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart) || (int) $cart->nbProducts() < 1) {
            $this->fail('no_cart');
        }

        // (3) Server-side rate limit — a signing endpoint is a minting oracle,
        // and a client-resettable cookie counter is not a real limit.
        if (!InovioGateway::withinRateLimit('signature', (int) $cart->id, (string) Tools::getRemoteAddr())) {
            $this->fail('rate_limited');
        }

        $uniqueId = (string) Tools::getValue('uniqueId');
        if ($uniqueId === '' || !preg_match('/^[a-f0-9]{8,64}$/i', $uniqueId)) {
            $this->fail('bad_unique_id');
        }

        $siteId = (string) Configuration::get('INOVIOPAYMENT_SITE_ID');
        $siteKey = (string) Configuration::get('INOVIOPAYMENT_SITE_KEY');
        if ($siteId === '' || $siteKey === '') {
            $this->fail('not_configured');
        }

        $timestamp = Tokenize::timestamp();
        // hex(hmac_sha256(siteKey, timestamp . uniqueId . siteId)) — the PAN is
        // NOT part of the signed message (the v4.14 PDF is wrong on this).
        $signature = Tokenize::signRequest($siteKey, $timestamp, $uniqueId, $siteId);

        $this->ajaxRender((string) json_encode([
            'siteId' => $siteId,
            'timestamp' => $timestamp,
            'signature' => $signature,
            'tokenUrl' => $this->module->getTokenEndpoint(),
        ]));
        exit;
    }

    private function fail(string $reason): void
    {
        InovioGateway::log('signature refused: ' . $reason, true);
        http_response_code(403);
        $this->ajaxRender((string) json_encode(['error' => $reason]));
        exit;
    }
}
