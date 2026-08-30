<?php
/**
 * 3DS prepare leg: opens a 3DS session and returns the device-data-collection
 * JWT/URL the browser posts into a hidden iframe.
 */

use Inovio\Gateway\ThreeDSPrepare;

class InoviopaymentThreedsModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $ajax = true;

    public function postProcess(): void
    {
        header('Content-Type: application/json');

        if (!$this->module->verifyCsrfToken((string) Tools::getValue('inovio_token'))) {
            // Log it: a silent refusal here means the 3DS block never gets
            // attached and the transaction quietly proceeds WITHOUT 3DS.
            InovioGateway::log('3DS prepare refused: invalid_token', true);
            http_response_code(403);
            $this->ajaxRender((string) json_encode(['error' => 'invalid_token']));
            exit;
        }

        // prepare() needs the transaction currency and the billing country,
        // and the rate limiter below needs the cart id — load it first.
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart)) {
            $this->ajaxRender((string) json_encode([]));
            exit;
        }

        // Every hit here is a paid gateway call — same server-side limit as
        // the signing endpoint, keyed separately so the two budgets don't share.
        if (!InovioGateway::withinRateLimit('threeds', (int) $cart->id, (string) Tools::getRemoteAddr())) {
            InovioGateway::log('3DS prepare rate limited', true);
            http_response_code(429);
            $this->ajaxRender((string) json_encode(['error' => 'rate_limited']));
            exit;
        }

        $bin = preg_replace('/\D/', '', (string) Tools::getValue('bin'));
        if (strlen((string) $bin) < 6) {
            $this->ajaxRender((string) json_encode([]));
            exit;
        }

        $currency = new Currency((int) $cart->id_currency);
        $billing = new Address((int) $cart->id_address_invoice);
        $country = Validate::isLoadedObject($billing)
            ? (string) Country::getIsoById((int) $billing->id_country)
            : (string) Country::getIsoById((int) Configuration::get('PS_COUNTRY_DEFAULT'));

        $merchAcct = trim((string) Configuration::get('INOVIOPAYMENT_MERCH_ACCT_ID'));

        try {
            $ddc = InovioGateway::client()->threeDSecure()->prepare(ThreeDSPrepare::bin(
                substr((string) $bin, 0, 6),
                (string) $currency->iso_code,
                $country,
                $merchAcct !== '' ? $merchAcct : null
            ));
            $this->ajaxRender((string) json_encode([
                'jwt' => $ddc->jwt,
                'ddcUrl' => $ddc->ddcUrl,
                'ddcReferenceId' => $ddc->ddcReferenceId,
            ]));
        } catch (\Throwable $e) {
            // A prepare failure must not block checkout — the transaction
            // simply proceeds without 3DS data and the gateway decides.
            InovioGateway::log('3DS prepare failed: ' . $e->getMessage(), true);
            $this->ajaxRender((string) json_encode([]));
        }
        exit;
    }
}
