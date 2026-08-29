<?php
/**
 * Inovio Payment Gateway for PrestaShop 9.
 *
 * Tokenized direct-post checkout: the card number never reaches this server.
 * See docs/prestashop-integration-design.md for the design this implements.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/inovio/autoload.php';
require_once __DIR__ . '/classes/InovioStoredCard.php';
require_once __DIR__ . '/classes/InovioVault.php';
require_once __DIR__ . '/classes/InovioGateway.php';

class Inoviopayment extends PaymentModule
{
    /** Order states this module creates at install (PrestaShop has none for these). */
    public const STATE_AWAITING_3DS = 'INOVIOPAYMENT_OS_AWAITING_3DS';
    public const STATE_AWAITING_CAPTURE = 'INOVIOPAYMENT_OS_AWAITING_CAPTURE';

    public const PRODUCTION_ENDPOINT = 'https://api.inoviopay.com/payment/pmt_service.cfm';

    /** @var string[] */
    public $configKeys = [
        'INOVIOPAYMENT_REQ_USERNAME',
        'INOVIOPAYMENT_REQ_PASSWORD',
        'INOVIOPAYMENT_SITE_ID',
        'INOVIOPAYMENT_MERCH_ACCT_ID',
        'INOVIOPAYMENT_SITE_KEY',
        'INOVIOPAYMENT_PRODUCT_ID',
        'INOVIOPAYMENT_PAYMENT_ACTION',
        'INOVIOPAYMENT_THREEDS_ACTIVE',
        'INOVIOPAYMENT_VAULT_ACTIVE',
        'INOVIOPAYMENT_DESCRIPTOR',
        'INOVIOPAYMENT_DESCRIPTOR_PHONE',
        'INOVIOPAYMENT_ENDPOINT',
        'INOVIOPAYMENT_DEBUG',
    ];

    public function __construct()
    {
        $this->name = 'inoviopayment';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'Inovio Payments';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
        $this->controllers = ['signature', 'validation', 'threeds', 'threedsreturn', 'storedcards'];
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Inovio Payment Gateway', [], 'Modules.Inoviopayment.Admin');
        $this->description = $this->trans(
            'Accept credit cards through the Inovio gateway. The card number never touches your server.',
            [],
            'Modules.Inoviopayment.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Uninstall Inovio? Saved card references will be deleted.',
            [],
            'Modules.Inoviopayment.Admin'
        );
    }

    public function install(): bool
    {
        if (!extension_loaded('bcmath')) {
            $this->_errors[] = $this->trans(
                'The bcmath PHP extension is required (payment amounts are computed without binary floats).',
                [],
                'Modules.Inoviopayment.Admin'
            );

            return false;
        }

        return parent::install()
            && $this->installSql()
            && $this->installOrderStates()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('actionProductCancel')
            && $this->registerHook('displayCustomerAccount')
            && $this->registerHook('displayAdminOrderMainBottom')
            && $this->setDefaults();
    }

    public function uninstall(): bool
    {
        foreach ($this->configKeys as $key) {
            Configuration::deleteByName($key);
        }

        return $this->uninstallSql() && parent::uninstall();
    }

    private function installSql(): bool
    {
        return (bool) require __DIR__ . '/sql/install.php';
    }

    private function uninstallSql(): bool
    {
        return (bool) require __DIR__ . '/sql/uninstall.php';
    }

    private function setDefaults(): bool
    {
        Configuration::updateValue('INOVIOPAYMENT_PAYMENT_ACTION', 'sale');
        Configuration::updateValue('INOVIOPAYMENT_THREEDS_ACTIVE', '0');
        Configuration::updateValue('INOVIOPAYMENT_VAULT_ACTIVE', '1');
        Configuration::updateValue('INOVIOPAYMENT_ENDPOINT', self::PRODUCTION_ENDPOINT);
        Configuration::updateValue('INOVIOPAYMENT_DEBUG', '0');

        return true;
    }

    /**
     * PrestaShop has no pending-authentication or awaiting-capture state, so
     * the module creates its own. Both are unpaid and non-loggable: an order
     * sitting in them must not count as revenue.
     */
    private function installOrderStates(): bool
    {
        $states = [
            self::STATE_AWAITING_3DS => [
                'name' => 'Awaiting 3DS authentication (Inovio)',
                'color' => '#4169E1',
            ],
            self::STATE_AWAITING_CAPTURE => [
                'name' => 'Awaiting capture (Inovio)',
                'color' => '#FF8C00',
            ],
        ];

        foreach ($states as $configKey => $meta) {
            $existing = (int) Configuration::getGlobalValue($configKey);
            if ($existing > 0 && Validate::isLoadedObject(new OrderState($existing))) {
                continue;
            }

            $state = new OrderState();
            $state->name = [];
            foreach (Language::getLanguages(false) as $language) {
                $state->name[(int) $language['id_lang']] = $meta['name'];
            }
            $state->color = $meta['color'];
            $state->send_email = false;
            $state->hidden = false;
            $state->delivery = false;
            $state->logable = false;   // not a completed sale
            $state->invoice = false;
            $state->paid = false;      // no money captured yet
            $state->module_name = $this->name;

            if (!$state->add()) {
                return false;
            }
            Configuration::updateGlobalValue($configKey, (int) $state->id);
        }

        return true;
    }

    // ---------------------------------------------------------------- config

    public function isConfigured(): bool
    {
        foreach (['REQ_USERNAME', 'REQ_PASSWORD', 'SITE_ID', 'SITE_KEY', 'PRODUCT_ID'] as $k) {
            if (!Configuration::get('INOVIOPAYMENT_' . $k)) {
                return false;
            }
        }

        return true;
    }

    public function isThreeDsActive(): bool
    {
        return (bool) Configuration::get('INOVIOPAYMENT_THREEDS_ACTIVE');
    }

    public function isVaultActive(): bool
    {
        return (bool) Configuration::get('INOVIOPAYMENT_VAULT_ACTIVE');
    }

    /** Where the browser POSTs the PAN — derived exactly as the SDK derives it. */
    public function getTokenEndpoint(): string
    {
        $endpoint = (string) Configuration::get('INOVIOPAYMENT_ENDPOINT') ?: self::PRODUCTION_ENDPOINT;

        return (string) preg_replace('/pmt_service\.cfm$/', 'token_service.cfm', $endpoint);
    }

    // ----------------------------------------------------------------- hooks

    public function hookActionFrontControllerSetMedia(): void
    {
        // The checkout JS is needed on the checkout page AND on this module's
        // own 3DS challenge page (rendered by the validation controller),
        // which calls window.inovioRunChallenge to open the ACS iframe.
        $selfs = ['order', 'module-inoviopayment-validation'];
        if (!in_array((string) $this->context->controller->php_self, $selfs, true)
            || !$this->isConfigured()
        ) {
            return;
        }

        $this->context->controller->registerStylesheet(
            'inoviopayment-checkout',
            'modules/' . $this->name . '/views/css/checkout.css',
            ['media' => 'all', 'priority' => 200]
        );
        $this->context->controller->registerJavascript(
            'inoviopayment-checkout',
            'modules/' . $this->name . '/views/js/inovio-checkout.js',
            ['position' => 'bottom', 'priority' => 200]
        );

        Media::addJsDef(['inovioConfig' => $this->getCheckoutJsConfig()]);
    }

    /** @return array<string,mixed> */
    private function getCheckoutJsConfig(): array
    {
        return [
            'signatureUrl' => $this->context->link->getModuleLink($this->name, 'signature', ['ajax' => 1], true),
            'prepareUrl' => $this->context->link->getModuleLink($this->name, 'threeds', ['ajax' => 1], true),
            'tokenUrl' => $this->getTokenEndpoint(),
            'threeDsActive' => $this->isThreeDsActive(),
            'vaultActive' => $this->isVaultActive() && (int) $this->context->customer->id > 0,
            'translations' => [
                'invalidCard' => $this->trans('Please enter a valid card number.', [], 'Modules.Inoviopayment.Shop'),
                'invalidExpiry' => $this->trans('Please enter a valid expiration date.', [], 'Modules.Inoviopayment.Shop'),
                'invalidCvv' => $this->trans('Please enter a valid security code.', [], 'Modules.Inoviopayment.Shop'),
                'tokenizeFailed' => $this->trans('Card could not be processed. Please try again.', [], 'Modules.Inoviopayment.Shop'),
                'unreachable' => $this->trans('Could not reach the payment service. Please try again.', [], 'Modules.Inoviopayment.Shop'),
                'signFailed' => $this->trans('Payment signing failed. Please refresh and try again.', [], 'Modules.Inoviopayment.Shop'),
                'processing' => $this->trans('Processing your card…', [], 'Modules.Inoviopayment.Shop'),
                'authFailed' => $this->trans('Payment authentication failed.', [], 'Modules.Inoviopayment.Shop'),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return PaymentOption[]
     */
    public function hookPaymentOptions(array $params): array
    {
        if (!$this->active || !$this->isConfigured()) {
            return [];
        }
        /** @var Cart $cart */
        $cart = $params['cart'];
        if (!$this->checkCurrency($cart)) {
            return [];
        }

        $option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $option->setModuleName($this->name);
        $option->setCallToActionText($this->trans('Pay by credit card', [], 'Modules.Inoviopayment.Shop'));
        $option->setForm($this->renderPaymentForm());
        $option->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/cards.png'));

        return [$option];
    }

    private function renderPaymentForm(): string
    {
        $savedCards = [];
        if ($this->isVaultActive() && (int) $this->context->customer->id > 0) {
            $savedCards = InovioStoredCard::getByCustomer(
                (int) $this->context->customer->id,
                (int) $this->context->shop->id
            );
        }

        $this->context->smarty->assign([
            'inovioAction' => $this->context->link->getModuleLink($this->name, 'validation', [], true),
            'inovioSavedCards' => $savedCards,
            'inovioVaultActive' => $this->isVaultActive() && (int) $this->context->customer->id > 0,
            'inovioThreeDsActive' => $this->isThreeDsActive(),
            'inovioMonths' => range(1, 12),
            'inovioYears' => range((int) date('Y'), (int) date('Y') + 11),
            'inovioToken' => Tools::getToken(false),
        ]);

        return $this->context->smarty->fetch(
            'module:' . $this->name . '/views/templates/front/payment_form.tpl'
        );
    }

    public function checkCurrency(Cart $cart): bool
    {
        $currency = new Currency((int) $cart->id_currency);
        $accepted = $this->getCurrency((int) $cart->id_currency);
        if (!is_array($accepted)) {
            return false;
        }
        foreach ($accepted as $one) {
            if ($currency->id == $one['id_currency']) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $params */
    public function hookPaymentReturn(array $params): string
    {
        if (!$this->active) {
            return '';
        }
        /** @var Order $order */
        $order = $params['order'];

        $this->context->smarty->assign([
            'inovioReference' => $order->reference,
            // PrestaShop 9 removed Tools::displayPrice(); format through the
            // context locale instead.
            'inovioTotal' => Tools::getContextLocale($this->context)->formatPrice(
                (float) $order->getOrdersTotalPaid(),
                (new Currency((int) $order->id_currency))->iso_code
            ),
            'inovioStatus' => $order->getCurrentStateFull((int) $this->context->language->id)['name'] ?? '',
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/front/payment_return.tpl');
    }

    /** Saved-cards entry point in the customer account. */
    public function hookDisplayCustomerAccount(): string
    {
        if (!$this->isVaultActive()) {
            return '';
        }
        $this->context->smarty->assign([
            'inovioStoredCardsUrl' => $this->context->link->getModuleLink($this->name, 'storedcards', [], true),
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/front/customer_account.tpl');
    }

    /**
     * Refunds. PrestaShop routes BOTH full and partial refunds through CQRS
     * commands that surface here, so this single hook covers both.
     *
     * @param array<string,mixed> $params
     */
    public function hookActionProductCancel(array $params): void
    {
        if (!isset($params['order']) || !($params['order'] instanceof Order)) {
            return;
        }
        $order = $params['order'];
        if ($order->module !== $this->name) {
            return;
        }

        // Only act on genuine refund actions, not plain cancellations.
        $action = $params['action'] ?? null;
        $isRefund = defined('\PrestaShop\PrestaShop\Core\Domain\Order\CancellationActionType::STANDARD_REFUND')
            ? in_array($action, [
                \PrestaShop\PrestaShop\Core\Domain\Order\CancellationActionType::STANDARD_REFUND,
                \PrestaShop\PrestaShop\Core\Domain\Order\CancellationActionType::PARTIAL_REFUND,
            ], true)
            : $action !== null;
        if (!$isRefund) {
            return;
        }

        try {
            InovioGateway::refundOrder($order, $this->refundAmount($order, $params));
        } catch (\Throwable $e) {
            InovioGateway::log('refund failed for order ' . (int) $order->id . ': ' . $e->getMessage(), true);
        }
    }

    /** @param array<string,mixed> $params */
    private function refundAmount(Order $order, array $params): ?string
    {
        $refunds = $params['cancel_quantity'] ?? null;
        if (!is_array($refunds) || $refunds === []) {
            return null; // full refund
        }

        $total = 0.0;
        foreach ($refunds as $idOrderDetail => $quantity) {
            $detail = new OrderDetail((int) $idOrderDetail);
            if (Validate::isLoadedObject($detail)) {
                $total += (float) $detail->unit_price_tax_incl * (int) $quantity;
            }
        }

        return $total > 0 ? number_format($total, 2, '.', '') : null;
    }

    /** Back-office capture/void controls (PrestaShop offers no hook for these). */
    /**
     * Handle the panel's Capture / Void submissions.
     *
     * The form in order_panel.tpl POSTs back to the order page, so the submit
     * has to be picked up from a hook that runs on that request. Without this
     * the buttons render but do nothing — the order sits in "Awaiting capture"
     * forever with no log line. (Caught by the e2e suite, 2026-08-28.)
     */
    private function handleAdminOrderActions(Order $order): ?string
    {
        if (!Tools::isSubmit('inovio_capture') && !Tools::isSubmit('inovio_void')) {
            return null;
        }
        if ((int) Tools::getValue('inovio_order_id') !== (int) $order->id) {
            return null;
        }

        try {
            if (Tools::isSubmit('inovio_void')) {
                $result = InovioGateway::voidOrder($order);
                $newState = (int) Configuration::get('PS_OS_CANCELED');
                $ok = $this->trans('Authorization voided.', [], 'Modules.Inoviopayment.Admin');
            } else {
                $raw = trim((string) Tools::getValue('inovio_capture_amount'));
                $amount = $raw === '' ? null : number_format((float) $raw, 2, '.', '');
                $result = InovioGateway::captureOrder($order, $amount);

                /*
                 * A PARTIAL capture must leave the order in "Awaiting capture"
                 * so the remainder can still be taken (design doc §3.1). Only a
                 * full capture moves it to Payment accepted. Comparing against
                 * the order total — blank amount means "capture everything".
                 */
                $isPartial = $amount !== null
                    && (float) $amount + 0.001 < (float) $order->total_paid;

                $newState = $isPartial
                    ? (int) Configuration::getGlobalValue(self::STATE_AWAITING_CAPTURE)
                    : (int) Configuration::get('PS_OS_PAYMENT');
                $ok = $isPartial
                    ? $this->trans('Partial payment captured; the order remains open for the balance.', [], 'Modules.Inoviopayment.Admin')
                    : $this->trans('Payment captured.', [], 'Modules.Inoviopayment.Admin');
            }

            if (!InovioGateway::isApproved($result)) {
                return $this->displayError(
                    (string) (InovioGateway::advice($result)
                        ?? $this->trans('The gateway declined the request.', [], 'Modules.Inoviopayment.Admin'))
                );
            }

            // A partial capture leaves the order in the state it is already in,
            // so there is no transition to record — skip the history write
            // rather than logging a no-op state change.
            if ($newState !== (int) $order->getCurrentState()) {
                $history = new OrderHistory();
                $history->id_order = (int) $order->id;
                $history->changeIdOrderState($newState, (int) $order->id);
                $history->add();
            }

            return $this->displayConfirmation($ok);
        } catch (\Throwable $e) {
            InovioGateway::log('admin action failed on order ' . (int) $order->id . ': ' . $e->getMessage(), true);

            return $this->displayError($e->getMessage());
        }
    }

    public function hookDisplayAdminOrderMainBottom(array $params): string
    {
        $order = new Order((int) ($params['id_order'] ?? 0));
        if (!Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return '';
        }

        // Act on a Capture/Void submit before rendering, so the panel below
        // reflects the new state in the same request.
        $notice = (string) $this->handleAdminOrderActions($order);
        $order = new Order((int) $order->id);

        $awaitingCapture = (int) Configuration::getGlobalValue(self::STATE_AWAITING_CAPTURE);
        $this->context->smarty->assign([
            'inovioOrderId' => (int) $order->id,
            'inovioCanCapture' => (int) $order->getCurrentState() === $awaitingCapture,
            'inovioPoId' => InovioGateway::getOrderRefValue($order, 'po_id'),
            'inovioTransId' => InovioGateway::getOrderRefValue($order, 'trans_id'),
            'inovioAdminToken' => Tools::getAdminTokenLite('AdminOrders'),
        ]);

        return $notice . $this->fetch('module:' . $this->name . '/views/templates/admin/order_panel.tpl');
    }

    // --------------------------------------------------------------- config UI

    public function getContent(): string
    {
        $output = '';
        if (Tools::isSubmit('submit' . $this->name)) {
            $output .= $this->postProcessConfig();
        }

        return $output . $this->renderConfigForm();
    }

    private function postProcessConfig(): string
    {
        $errors = [];
        foreach ($this->configKeys as $key) {
            $value = Tools::getValue($key);
            if ($value === false || $value === null) {
                continue;
            }
            if (in_array($key, ['INOVIOPAYMENT_REQ_PASSWORD', 'INOVIOPAYMENT_SITE_KEY'], true) && $value === '') {
                continue; // leave the stored secret alone when the field is blank
            }
            Configuration::updateValue($key, $value);
        }

        if (!$this->isConfigured()) {
            $errors[] = $this->trans(
                'Credentials are incomplete — the payment method stays hidden until all required fields are set.',
                [],
                'Modules.Inoviopayment.Admin'
            );
        }

        return $errors
            ? $this->displayWarning(implode('<br>', $errors))
            : $this->displayConfirmation($this->trans('Settings updated.', [], 'Modules.Inoviopayment.Admin'));
    }

    private function renderConfigForm(): string
    {
        $fields = [
            ['type' => 'text', 'label' => 'API Username', 'name' => 'INOVIOPAYMENT_REQ_USERNAME', 'required' => true],
            ['type' => 'password', 'label' => 'API Password', 'name' => 'INOVIOPAYMENT_REQ_PASSWORD',
                'desc' => 'Leave blank to keep the stored password.'],
            ['type' => 'text', 'label' => 'Site ID', 'name' => 'INOVIOPAYMENT_SITE_ID', 'required' => true],
            ['type' => 'text', 'label' => 'Merchant Account ID', 'name' => 'INOVIOPAYMENT_MERCH_ACCT_ID',
                'desc' => 'Optional — leave empty to let the gateway distribute by currency/country.'],
            ['type' => 'password', 'label' => 'Site Key', 'name' => 'INOVIOPAYMENT_SITE_KEY',
                'desc' => 'Per-site HMAC secret for browser tokenization, issued by Inovio support. NOT the API password.'],
            ['type' => 'text', 'label' => 'Gateway Product ID', 'name' => 'INOVIOPAYMENT_PRODUCT_ID', 'required' => true,
                'desc' => 'The Inovio product (LI_PROD_ID) orders are billed under.'],
            ['type' => 'select', 'label' => 'Payment Action', 'name' => 'INOVIOPAYMENT_PAYMENT_ACTION',
                'options' => ['query' => [
                    ['id' => 'sale', 'name' => 'Sale (authorize and capture)'],
                    ['id' => 'authorize', 'name' => 'Authorize only'],
                ], 'id' => 'id', 'name' => 'name']],
            ['type' => 'switch', 'label' => 'Enable 3D Secure', 'name' => 'INOVIOPAYMENT_THREEDS_ACTIVE',
                'values' => $this->switchValues('threeds')],
            ['type' => 'switch', 'label' => 'Enable Saved Cards', 'name' => 'INOVIOPAYMENT_VAULT_ACTIVE',
                'values' => $this->switchValues('vault')],
            ['type' => 'text', 'label' => 'Statement Descriptor', 'name' => 'INOVIOPAYMENT_DESCRIPTOR'],
            ['type' => 'text', 'label' => 'Descriptor Phone', 'name' => 'INOVIOPAYMENT_DESCRIPTOR_PHONE'],
            ['type' => 'text', 'label' => 'Gateway Endpoint', 'name' => 'INOVIOPAYMENT_ENDPOINT',
                'desc' => 'pmt_service.cfm URL. Token and 3DS endpoints are derived from it.'],
            ['type' => 'switch', 'label' => 'Debug Logging', 'name' => 'INOVIOPAYMENT_DEBUG',
                'values' => $this->switchValues('debug'),
                'desc' => 'Card numbers are never logged — they never reach this server.'],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submit' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->fields_value = $this->configFieldValues();

        return $helper->generateForm([[
            'form' => [
                'legend' => ['title' => $this->displayName, 'icon' => 'icon-credit-card'],
                'input' => $fields,
                'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions')],
            ],
        ]]);
    }

    /** @return array<int,array<string,mixed>> */
    private function switchValues(string $id): array
    {
        return [
            ['id' => $id . '_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')],
            ['id' => $id . '_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')],
        ];
    }

    /** @return array<string,string> */
    private function configFieldValues(): array
    {
        $values = [];
        foreach ($this->configKeys as $key) {
            // Never echo stored secrets back into the form.
            $values[$key] = in_array($key, ['INOVIOPAYMENT_REQ_PASSWORD', 'INOVIOPAYMENT_SITE_KEY'], true)
                ? ''
                : (string) Configuration::get($key);
        }

        return $values;
    }
}
