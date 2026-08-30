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
    /** A gateway PENDING/RUNNING result outside the 3DS challenge flow — e.g. an async processor. */
    public const STATE_PAYMENT_PENDING = 'INOVIOPAYMENT_OS_PAYMENT_PENDING';

    public const PRODUCTION_ENDPOINT = 'https://api.inoviopay.com/payment/pmt_service.cfm';

    /** Cookie key for the per-visitor CSRF nonce (see csrfToken()). */
    private const CSRF_COOKIE_KEY = 'inovio_csrf';

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
        $this->version = '1.0.1';
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
            // The requirement is correct — the vendored SDK's ResultMapper
            // uses bcadd() to sum leg amounts — but the module itself does
            // no arbitrary-precision arithmetic of its own; it is a
            // dependency of the SDK, not of this module's code.
            $this->_errors[] = $this->trans(
                'The bcmath PHP extension is required by the Inovio gateway SDK.',
                [],
                'Modules.Inoviopayment.Admin'
            );

            return false;
        }

        return parent::install()
            && $this->installSql()
            && $this->installOrderStates()
            && $this->installTabs()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('actionOrderSlipAdd')
            && $this->registerHook('displayCustomerAccount')
            && $this->registerHook('displayAdminOrderMainBottom')
            && $this->setDefaults();
    }

    public function uninstall(): bool
    {
        foreach ($this->configKeys as $key) {
            Configuration::deleteByName($key);
        }

        return $this->uninstallTabs() && $this->uninstallSql() && parent::uninstall();
    }

    /**
     * AdminInovioOrderActionsController (capture/void) has no menu entry —
     * it is invisible, POST-only, reached from the order panel's own form.
     */
    public function installTabs(): bool
    {
        if ((int) Tab::getIdFromClassName('AdminInovioOrderActions') > 0) {
            return true;
        }

        $tab = new Tab();
        $tab->class_name = 'AdminInovioOrderActions';
        $tab->module = $this->name;
        $tab->id_parent = -1;
        $tab->active = true;
        $tab->name = array_fill_keys(Language::getIDs(false), 'Inovio Order Actions');

        return (bool) $tab->add();
    }

    public function uninstallTabs(): bool
    {
        $idTab = (int) Tab::getIdFromClassName('AdminInovioOrderActions');
        if ($idTab <= 0) {
            return true;
        }

        $tab = new Tab($idTab);

        return (bool) $tab->delete();
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
     * PrestaShop has no pending-authentication, awaiting-capture, or
     * generic-pending state, so the module creates its own. All three are
     * unpaid and non-loggable: an order sitting in them must not count as
     * revenue.
     */
    public function installOrderStates(): bool
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
            self::STATE_PAYMENT_PENDING => [
                'name' => 'Payment pending (Inovio)',
                'color' => '#DAA520',
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

    /**
     * Per-visitor CSRF nonce for the module's own front controllers
     * (signature.php, threeds.php, validation.php).
     *
     * Tools::getToken(false) is a SHOP-WIDE constant for guests — identical
     * for every anonymous visitor, so a guest can read it once and use it as
     * a standing card-testing oracle against signature.php. This nonce is
     * instead random per visitor and stored server-side in the PrestaShop
     * cookie, which is Blowfish-signed server-side: the value a visitor's
     * browser holds cannot be forged or predicted, only echoed back.
     *
     * Reused for the lifetime of the cookie session rather than regenerated
     * per render, so a page held open across multiple AJAX calls (signature,
     * then 3DS prepare, then validation submit) keeps working.
     */
    public function csrfToken(): string
    {
        $existing = (string) ($this->context->cookie->{self::CSRF_COOKIE_KEY} ?? '');
        if ($existing !== '' && preg_match('/^[a-f0-9]{32}$/', $existing)) {
            return $existing;
        }

        $token = bin2hex(random_bytes(16));
        $this->context->cookie->{self::CSRF_COOKIE_KEY} = $token;

        return $token;
    }

    public function verifyCsrfToken(string $submitted): bool
    {
        $stored = (string) ($this->context->cookie->{self::CSRF_COOKIE_KEY} ?? '');

        return $stored !== '' && hash_equals($stored, $submitted);
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
            // Keys MUST match what inovio-checkout.js reads (translate() calls
            // there are the source of truth — do not rename these without
            // updating the JS too).
            'translations' => [
                'invalid_card_number' => $this->trans('Please enter a valid card number.', [], 'Modules.Inoviopayment.Shop'),
                'invalid_expiry' => $this->trans('Please enter a valid expiration date.', [], 'Modules.Inoviopayment.Shop'),
                'invalid_cvv' => $this->trans('Please enter a valid security code.', [], 'Modules.Inoviopayment.Shop'),
                'card_failed' => $this->trans('Card could not be processed. Please try again.', [], 'Modules.Inoviopayment.Shop'),
                'token_service_unreachable' => $this->trans('Could not reach the payment service. Please try again.', [], 'Modules.Inoviopayment.Shop'),
                'signing_failed' => $this->trans('Payment signing failed. Please refresh and try again.', [], 'Modules.Inoviopayment.Shop'),
                'processing' => $this->trans('Processing your card…', [], 'Modules.Inoviopayment.Shop'),
                'auth_failed' => $this->trans('Payment authentication failed.', [], 'Modules.Inoviopayment.Shop'),
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
            'inovioToken' => $this->csrfToken(),
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
     * Refunds. PrestaShop fires this hook exactly ONCE per refund operation
     * (standard refund, partial refund, or return-product — all three flows
     * converge on OrderSlipCreator::create()), carrying the OrderSlip that
     * was just created. That slip's totals are the ONLY correct source for
     * the refunded amount — do not use actionProductCancel, which fires per
     * order line with a scalar cancel_quantity and is ALSO fired by
     * cancel-product flows where no money moves at all.
     *
     * @param array<string,mixed> $params ['order' => Order, 'productList' => ..., 'qtyList' => ..., 'orderSlipCreated' => OrderSlip]
     */
    public function hookActionOrderSlipAdd(array $params): void
    {
        if (!isset($params['order']) || !($params['order'] instanceof Order)) {
            return;
        }
        $order = $params['order'];
        if ($order->module !== $this->name) {
            return;
        }
        $slip = $params['orderSlipCreated'] ?? null;
        if (!($slip instanceof OrderSlip)) {
            return;
        }

        $slipAmount = number_format(
            (float) $slip->total_products_tax_incl + (float) $slip->total_shipping_tax_incl,
            2,
            '.',
            ''
        );

        $priorSlipCount = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_slip` WHERE `id_order` = ' . (int) $order->id
        );
        $isFull = $priorSlipCount <= 1 && $slipAmount === number_format((float) $order->total_paid, 2, '.', '');

        try {
            if ($isFull) {
                InovioGateway::refundOrderFull($order);
            } else {
                InovioGateway::refundOrderPartial($order, $slipAmount);
            }
        } catch (\Throwable $e) {
            InovioGateway::log('refund failed for order ' . (int) $order->id . ': ' . $e->getMessage(), true);

            throw $e;
        }
    }

    /**
     * Back-office capture/void controls (PrestaShop offers no hook for
     * these). Capture and void are POSTed to AdminInovioOrderActionsController
     * (registered as a tab in install()); this hook only renders the panel
     * and any notice/error the controller redirected back with.
     */
    public function hookDisplayAdminOrderMainBottom(array $params): string
    {
        $order = new Order((int) ($params['id_order'] ?? 0));
        if (!Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return '';
        }

        $notice = $this->adminActionNotice();

        $awaitingCapture = (int) Configuration::getGlobalValue(self::STATE_AWAITING_CAPTURE);
        $this->context->smarty->assign([
            'inovioOrderId' => (int) $order->id,
            'inovioCanCapture' => (int) $order->getCurrentState() === $awaitingCapture,
            'inovioPoId' => InovioGateway::getOrderRefValue($order, 'po_id'),
            'inovioTransId' => InovioGateway::getOrderRefValue($order, 'trans_id'),
            'inovioActionUrl' => $this->context->link->getAdminLink('AdminInovioOrderActions', true),
        ]);

        return $notice . $this->fetch('module:' . $this->name . '/views/templates/admin/order_panel.tpl');
    }

    /** Renders the notice/error code AdminInovioOrderActionsController redirected back with. */
    private function adminActionNotice(): string
    {
        $notices = [
            'voided' => $this->trans('Authorization voided.', [], 'Modules.Inoviopayment.Admin'),
            'captured' => $this->trans('Payment captured.', [], 'Modules.Inoviopayment.Admin'),
            'partial_captured' => $this->trans('Partial payment captured; the order remains open for the balance.', [], 'Modules.Inoviopayment.Admin'),
            'timeout_reconciled' => $this->trans('The gateway timed out, but the action completed successfully.', [], 'Modules.Inoviopayment.Admin'),
        ];
        $errors = [
            'timeout_unreconciled' => $this->trans('The gateway timed out and no completed action was found. Please check the order status before retrying.', [], 'Modules.Inoviopayment.Admin'),
        ];

        $notice = (string) Tools::getValue('inovio_notice');
        if ($notice !== '' && isset($notices[$notice])) {
            return $this->displayConfirmation($notices[$notice]);
        }

        $error = (string) Tools::getValue('inovio_error');
        if ($error !== '') {
            return $this->displayError($errors[$error] ?? $error);
        }

        return '';
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
