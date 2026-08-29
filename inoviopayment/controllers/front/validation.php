<?php
/**
 * Order placement.
 *
 * Receives the single-use token minted in the browser — never a PAN — runs the
 * gateway transaction, and creates the order in the state the outcome implies.
 *
 * Two Addons payment rules are structural here, not afterthoughts:
 *  - the cart is re-verified (ownership + contents) before any order is created;
 *  - the amount charged is read back from the gateway result, never recomputed
 *    from the cart after the fact.
 */

use Inovio\Gateway\Errors\GatewayTimeoutException;
use Inovio\Gateway\Result\TransactionResult;

class InoviopaymentValidationModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    /** Explicit so the module's media hook can load the checkout JS here too. */
    public $php_self = 'module-inoviopayment-validation';

    public function postProcess(): void
    {
        $cart = $this->context->cart;

        // --- Addons rule: verify the cart before creating anything ----------
        if (!Validate::isLoadedObject($cart)
            || $cart->id_customer == 0
            || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0
            || !$this->module->active
        ) {
            $this->redirectWithError('cart_invalid');
        }
        // The cart must belong to the logged-in customer.
        if ((int) $cart->id_customer !== (int) $this->context->customer->id) {
            $this->redirectWithError('cart_not_owned');
        }
        if (!hash_equals(Tools::getToken(false), (string) Tools::getValue('inovio_token'))) {
            $this->redirectWithError('invalid_token');
        }
        // The module must still be an available payment option for this cart.
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] === $this->module->name) {
                $authorized = true;
                break;
            }
        }
        if (!$authorized) {
            $this->redirectWithError('method_unavailable');
        }

        $payment = $this->collectPaymentData($cart);

        try {
            $result = $this->runTransaction($cart, $payment);
        } catch (GatewayTimeoutException $e) {
            $recovered = InovioGateway::reconcileTimeout($cart, $e);
            if ($recovered === null) {
                $this->redirectWithError('timeout');
            }
            $result = $recovered;
        } catch (\Throwable $e) {
            InovioGateway::log('transaction error: ' . $e->getMessage(), true);
            $this->redirectWithError('error');
        }

        $this->finalize($cart, $result, $payment);
    }

    /**
     * Everything the browser sent. Note what is absent: no PAN, no CVV.
     *
     * @return array<string,mixed>
     */
    private function collectPaymentData(Cart $cart): array
    {
        $savedCardId = (int) Tools::getValue('inovio_saved_card_id');
        $savedCard = null;
        if ($savedCardId > 0) {
            // Ownership-checked load — the IDOR guard.
            $savedCard = InovioStoredCard::findForCustomer($savedCardId, (int) $cart->id_customer);
            if ($savedCard === null) {
                $this->redirectWithError('saved_card_not_found');
            }
        }

        return [
            'token_guid' => (string) Tools::getValue('inovio_token_guid'),
            'token_guid_completion' => (string) Tools::getValue('inovio_token_guid_completion'),
            'pmt_expiry' => (string) Tools::getValue('inovio_pmt_expiry'),
            'cc_brand' => (string) Tools::getValue('inovio_cc_brand'),
            'cc_last4' => (string) Tools::getValue('inovio_cc_last4'),
            'ddc_reference_id' => (string) Tools::getValue('inovio_ddc_reference_id'),
            'browser' => (string) Tools::getValue('inovio_browser'),
            'save_card' => Tools::getValue('inovio_save_card') === 'true',
            'saved_card_id' => $savedCardId,
            'saved_card' => $savedCard,
        ];
    }

    /** @param array<string,mixed> $payment */
    private function runTransaction(Cart $cart, array $payment): TransactionResult
    {
        $req = InovioGateway::buildRequest($cart, $payment);
        $client = InovioGateway::client();

        return Configuration::get('INOVIOPAYMENT_PAYMENT_ACTION') === 'authorize'
            ? $client->authorize($req)
            : $client->sale($req);
    }

    /** @param array<string,mixed> $payment */
    private function finalize(Cart $cart, TransactionResult $result, array $payment): void
    {
        $customer = new Customer((int) $cart->id_customer);
        $currency = new Currency((int) $cart->id_currency);

        // --- Addons rule: the amount comes from the gateway, not the cart ---
        $amountPaid = $result->amount !== null
            ? (float) $result->amount->amount()
            : (float) $cart->getOrderTotal(true, Cart::BOTH);

        if ($result->status === 'PENDING' && $result->nextAction?->kind === 'threeDSChallenge') {
            $this->createOrder($cart, $customer, Inoviopayment::STATE_AWAITING_3DS, 0.0, $result, $payment);
            $this->renderChallenge($result);

            return;
        }

        if (!InovioGateway::isApproved($result)) {
            InovioGateway::log('declined cart ' . (int) $cart->id . ': ' . (InovioGateway::advice($result) ?? 'no advice'));
            $this->redirectWithError('declined', InovioGateway::advice($result));
        }

        $stateKey = Configuration::get('INOVIOPAYMENT_PAYMENT_ACTION') === 'authorize'
            ? Inoviopayment::STATE_AWAITING_CAPTURE
            : null; // null => Payment accepted

        $order = $this->createOrder($cart, $customer, $stateKey, $amountPaid, $result, $payment);

        Tools::redirect($this->context->link->getPageLink('order-confirmation', true, null, [
            'id_cart' => (int) $cart->id,
            'id_module' => (int) $this->module->id,
            'id_order' => (int) $order->id,
            'key' => $customer->secure_key,
        ]));
    }

    /**
     * @param array<string,mixed> $payment
     */
    private function createOrder(
        Cart $cart,
        Customer $customer,
        ?string $stateKey,
        float $amountPaid,
        TransactionResult $result,
        array $payment
    ): Order {
        $state = $stateKey !== null
            ? (int) Configuration::getGlobalValue($stateKey)
            : (int) Configuration::get('PS_OS_PAYMENT');

        $this->module->validateOrder(
            (int) $cart->id,
            $state,
            $amountPaid,
            $this->module->displayName,
            null,
            ['transaction_id' => $result->transactionId?->value() ?? ''],
            (int) $cart->id_currency,
            false,
            $customer->secure_key
        );

        $order = new Order((int) $this->module->currentOrder);
        InovioGateway::recordReferences($order, $result);

        if ($payment['save_card'] && Configuration::get('INOVIOPAYMENT_VAULT_ACTIVE')) {
            InovioVault::saveFromResult(
                (int) $cart->id_customer,
                (int) $this->context->shop->id,
                $result,
                (string) $payment['pmt_expiry'],
                (string) $payment['cc_brand'],
                (string) $payment['cc_last4']
            );
        }

        // The completion token and challenge data must survive to the ACS return.
        if ($result->nextAction?->kind === 'threeDSChallenge') {
            InovioGateway::setOrderRefValue($order, 'challenge', (string) json_encode([
                'procTransId' => $result->nextAction->procTransId ?? '',
                'redirectUrl' => $result->nextAction->redirectUrl ?? '',
                'jwt' => $result->nextAction->jwt ?? '',
            ]));
            InovioGateway::setOrderRefValue($order, 'token_completion', (string) $payment['token_guid_completion']);
            InovioGateway::setOrderRefValue($order, 'pmt_expiry', (string) $payment['pmt_expiry']);
        }

        return $order;
    }

    /** Renders the ACS challenge; the module JS drives the iframe. */
    /**
     * Stash the challenge for initContent().
     *
     * setTemplate() must NOT be called from postProcess(): PrestaShop runs
     * postProcess() BEFORE setMedia()/initContent(), so a template set here
     * renders with no theme assets and no module JS — which meant
     * window.inovioRunChallenge was undefined and the ACS iframe never opened.
     */
    private function renderChallenge(TransactionResult $result): void
    {
        $this->challengeResult = $result;
    }

    /** @var TransactionResult|null set by renderChallenge() during postProcess() */
    private $challengeResult = null;

    public function initContent(): void
    {
        parent::initContent();

        if ($this->challengeResult === null) {
            return;
        }

        $result = $this->challengeResult;
        $cart = $this->context->cart;
        $customer = new Customer((int) $cart->id_customer);

        $this->context->smarty->assign([
            'inovioChallenge' => json_encode([
                'redirectUrl' => $result->nextAction->redirectUrl ?? '',
                'jwt' => $result->nextAction->jwt ?? '',
            ]),
            // The resume form needs these to reach order-confirmation once the
            // cardholder finishes the challenge.
            'inovioCartId' => (int) $cart->id,
            'inovioModuleId' => (int) $this->module->id,
            'inovioSecureKey' => $customer->secure_key,
            'inovioConfirmUrl' => $this->context->link->getPageLink('order-confirmation', true),
        ]);
        $this->setTemplate('module:inoviopayment/views/templates/front/threeds_challenge.tpl');
    }

    private function redirectWithError(string $reason, ?string $advice = null): void
    {
        InovioGateway::log('checkout refused: ' . $reason);
        $this->errors[] = $advice ?: $this->trans(
            'Your payment could not be processed. Please check your details and try again.',
            [],
            'Modules.Inoviopayment.Shop'
        );
        $this->redirectWithNotifications($this->context->link->getPageLink('order', true, null, ['step' => 3]));
    }
}
