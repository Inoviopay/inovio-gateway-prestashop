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

        // (1) Same-origin: a valid PrestaShop token must accompany the call.
        if (!hash_equals(Tools::getToken(false), (string) Tools::getValue('inovio_token'))) {
            $this->fail('invalid_token');
        }

        // (2) There must be a real cart in session with something in it.
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart) || (int) $cart->nbProducts() < 1) {
            $this->fail('no_cart');
        }

        // (3) Rate limit per session — a signing endpoint is a minting oracle.
        if (!$this->withinRateLimit()) {
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

    private function withinRateLimit(): bool
    {
        $now = time();
        $window = 60;
        $max = 12;

        $hits = array_values(array_filter(
            (array) ($this->context->cookie->inovio_sig_hits ? json_decode($this->context->cookie->inovio_sig_hits, true) : []),
            static fn($t) => is_numeric($t) && ($now - (int) $t) < $window
        ));
        if (count($hits) >= $max) {
            return false;
        }
        $hits[] = $now;
        $this->context->cookie->inovio_sig_hits = json_encode($hits);

        return true;
    }

    private function fail(string $reason): void
    {
        InovioGateway::log('signature refused: ' . $reason, true);
        http_response_code(403);
        $this->ajaxRender((string) json_encode(['error' => $reason]));
        exit;
    }
}
