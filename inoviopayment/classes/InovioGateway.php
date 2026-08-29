<?php
/**
 * Gateway service: builds SDK requests from PrestaShop orders/carts and runs
 * the transaction verbs.
 *
 * Everything gateway-facing goes through the SDK's typed objects — never raw
 * REQUEST_ACTION wire fields (design doc D1).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

use Inovio\Gateway\Credentials;
use Inovio\Gateway\Errors\GatewayTimeoutException;
use Inovio\Gateway\InovioClient;
use Inovio\Gateway\Model\Address as SdkAddress;
use Inovio\Gateway\Model\BrowserData;
use Inovio\Gateway\Model\Customer as SdkCustomer;
use Inovio\Gateway\Model\Descriptor;
use Inovio\Gateway\Model\LineItem;
use Inovio\Gateway\Model\Money;
use Inovio\Gateway\Model\PaymentMethods;
use Inovio\Gateway\Model\ThreeDS;
use Inovio\Gateway\Refs\Refs;
use Inovio\Gateway\Request\TransactionRequest;
use Inovio\Gateway\Result\TransactionResult;

class InovioGateway
{
    public static function client(): InovioClient
    {
        $endpoint = (string) Configuration::get('INOVIOPAYMENT_ENDPOINT') ?: Inoviopayment::PRODUCTION_ENDPOINT;

        // NOTE: the SDK's 2nd parameter is the environment NAME; the explicit
        // URL belongs in $endpoint. Passing the URL positionally silently
        // leaves the client pointed at the default sandbox host.
        return new InovioClient(
            new Credentials(
                (string) Configuration::get('INOVIOPAYMENT_REQ_USERNAME'),
                (string) Configuration::get('INOVIOPAYMENT_REQ_PASSWORD'),
                (string) Configuration::get('INOVIOPAYMENT_SITE_ID')
            ),
            endpoint: $endpoint,
            siteKey: (string) Configuration::get('INOVIOPAYMENT_SITE_KEY') ?: null
        );
    }

    /**
     * Build a transaction request from a cart.
     *
     * @param array<string,mixed> $payment tokens + metadata collected in checkout (never a PAN)
     */
    public static function buildRequest(Cart $cart, array $payment, bool $forCompletion = false): TransactionRequest
    {
        $productId = (string) Configuration::get('INOVIOPAYMENT_PRODUCT_ID');
        if ($productId === '') {
            throw new RuntimeException('Inovio gateway product ID is not configured.');
        }

        $currency = new Currency((int) $cart->id_currency);
        $amount = number_format((float) $cart->getOrderTotal(true, Cart::BOTH), 2, '.', '');

        $req = new TransactionRequest(
            self::paymentMethod($payment, $forCompletion),
            [new LineItem($productId, 1, Money::of($amount, $currency->iso_code))]
        );

        // Cart id is a stable per-order reference: retry-safe idempotency.
        $req->withIdempotency(self::xtlOrderId($cart));

        $merchAcct = trim((string) Configuration::get('INOVIOPAYMENT_MERCH_ACCT_ID'));
        $req->merchAcctId = $merchAcct !== '' ? $merchAcct : null;

        $customer = new Customer((int) $cart->id_customer);
        $cust = new SdkCustomer();
        $cust->firstName = (string) $customer->firstname;
        $cust->lastName = (string) $customer->lastname;
        $cust->email = (string) $customer->email;
        $cust->ip = (string) Tools::getRemoteAddr();
        $req->customer = $cust;

        $req->billingAddress = self::address((int) $cart->id_address_invoice);
        if ((int) $cart->id_address_delivery > 0) {
            $req->shippingAddress = self::address((int) $cart->id_address_delivery);
        }

        $descriptor = trim((string) Configuration::get('INOVIOPAYMENT_DESCRIPTOR'));
        if ($descriptor !== '') {
            // The name is a CONSTRUCTOR argument — it is required, and passing
            // it there is also what triggers the SDK's character validation.
            $d = new Descriptor($descriptor);
            $phone = trim((string) Configuration::get('INOVIOPAYMENT_DESCRIPTOR_PHONE'));
            $d->phone = $phone !== '' ? $phone : null;
            $req->descriptor = $d;
        }

        $browser = self::browserData($payment);
        if ($browser !== null) {
            $req->browser = $browser;
        }

        $ddcRef = (string) ($payment['ddc_reference_id'] ?? '');
        if ($ddcRef !== '' && $browser !== null && Configuration::get('INOVIOPAYMENT_THREEDS_ACTIVE')) {
            // The ACS return is a cross-site POST that may arrive without the
            // session cookie, so the cart reference rides on the URL.
            $returnUrl = Context::getContext()->link->getModuleLink(
                'inoviopayment',
                'threedsreturn',
                ['cart' => (int) $cart->id],
                true
            );
            $req->threeDS = new ThreeDS($ddcRef, $returnUrl);
        }

        return $req;
    }

    /** Stable external order id used for idempotency and status reconcile. */
    public static function xtlOrderId(Cart $cart): string
    {
        return 'PS-' . (int) $cart->id;
    }

    /**
     * @param array<string,mixed> $payment
     */
    private static function paymentMethod(array $payment, bool $forCompletion): \Inovio\Gateway\Model\PaymentMethod
    {
        $savedCardId = (int) ($payment['saved_card_id'] ?? 0);
        if ($savedCardId > 0) {
            $card = $payment['saved_card'] ?? null;
            if (!$card instanceof InovioStoredCard) {
                throw new RuntimeException('Saved card could not be loaded.');
            }

            return PaymentMethods::savedCard((string) $card->pmt_id, null, (string) $card->cust_id);
        }

        // Gateway TOKEN_GUIDs are single-use: the 3DS enrollment leg consumes
        // the first token, so the completion leg uses the second. Both resolve
        // to the same card at the gateway.
        $guid = (string) ($forCompletion ? ($payment['token_guid_completion'] ?? '') : ($payment['token_guid'] ?? ''));
        $expiry = (string) ($payment['pmt_expiry'] ?? '');
        if ($guid === '' || $expiry === '') {
            throw new RuntimeException(
                $forCompletion
                    ? '3DS completion token is missing — checkout did not mint the second token.'
                    : 'Payment token is missing — the card was not tokenized in checkout.'
            );
        }

        return PaymentMethods::token($guid, $expiry);
    }

    private static function address(int $idAddress): SdkAddress
    {
        $a = new SdkAddress();
        $addr = new Address($idAddress);
        if (!Validate::isLoadedObject($addr)) {
            return $a;
        }
        $a->line1 = (string) $addr->address1;
        $a->line2 = $addr->address2 !== '' ? (string) $addr->address2 : null;
        $a->city = (string) $addr->city;
        $a->zip = (string) $addr->postcode;
        $a->country = (string) Country::getIsoById((int) $addr->id_country);
        if ((int) $addr->id_state > 0) {
            $a->state = (string) State::getNameById((int) $addr->id_state);
        }

        return $a;
    }

    /** @param array<string,mixed> $payment */
    private static function browserData(array $payment): ?BrowserData
    {
        $raw = $payment['browser'] ?? null;
        $b = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        if (!is_array($b) || empty($b['language']) || empty($b['userAgent']) || empty($b['header'])) {
            return null;
        }

        return new BrowserData(
            (string) $b['language'],
            (string) $b['userAgent'],
            (string) $b['header'],
            javaEnabled: isset($b['javaEnabled']) ? (bool) $b['javaEnabled'] : null,
            javascriptEnabled: true,
            colorDepth: isset($b['colorDepth']) ? (int) $b['colorDepth'] : null,
            screenHeight: isset($b['screenHeight']) ? (int) $b['screenHeight'] : null,
            screenWidth: isset($b['screenWidth']) ? (int) $b['screenWidth'] : null,
            timeZoneOffsetMinutes: isset($b['timeZoneOffset']) ? (int) $b['timeZoneOffset'] : null,
            ipAddress: (string) Tools::getRemoteAddr() ?: null
        );
    }

    // ------------------------------------------------------------ order refs

    /** Gateway references are persisted on the order for later legs and support. */
    public static function recordReferences(Order $order, TransactionResult $result): void
    {
        $refs = [
            'po_id' => $result->orderRef?->poId(),
            'trans_id' => $result->transactionId?->value(),
            'req_id' => $result->requestId?->value(),
            'eci' => $result->threeDS?->eci,
        ];
        foreach ($refs as $key => $value) {
            if ($value !== null && $value !== '') {
                self::setOrderRefValue($order, $key, (string) $value);
            }
        }
    }

    public static function setOrderRefValue(Order $order, string $key, string $value): void
    {
        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'inovio_order_ref` (`id_order`, `ref_key`, `ref_value`, `date_add`)
             VALUES (' . (int) $order->id . ", '" . pSQL($key) . "', '" . pSQL($value) . "', NOW())
             ON DUPLICATE KEY UPDATE `ref_value` = '" . pSQL($value) . "'"
        );
    }

    public static function getOrderRefValue(Order $order, string $key): string
    {
        return (string) Db::getInstance()->getValue(
            'SELECT `ref_value` FROM `' . _DB_PREFIX_ . 'inovio_order_ref`
             WHERE `id_order` = ' . (int) $order->id . " AND `ref_key` = '" . pSQL($key) . "'"
        );
    }

    /** The gateway order reference needed by capture/void/refund. */
    public static function orderRef(Order $order): \Inovio\Gateway\Refs\OrderRef
    {
        $poId = self::getOrderRefValue($order, 'po_id');
        if ($poId === '') {
            throw new RuntimeException('No Inovio gateway reference stored for order ' . (int) $order->id);
        }

        return Refs::order($poId);
    }

    // --------------------------------------------------------------- verbs

    /**
     * Undo a captured sale.
     *
     * VERIFIED against the gateway (2026-08-28): a refund (CCCREDIT) on an
     * order that has not settled yet is rejected with SERVICE 536 "Order not
     * settled: Please reverse". Before settlement the correct undo is
     * reverseCapture(); refund() only applies once the batch has settled. So
     * the verb is chosen from the order's settlement state, and a 536 is
     * retried as a reversal rather than surfaced to the merchant.
     */
    public static function refundOrder(Order $order, ?string $amount = null): TransactionResult
    {
        $currency = new Currency((int) $order->id_currency);
        $money = $amount !== null ? Money::of($amount, $currency->iso_code) : null;
        $ref = self::orderRef($order);
        $client = self::client();

        if (!self::isSettled($order)) {
            $result = $client->reverseCapture($ref);
            self::recordReferences($order, $result);
            self::log('reverseCapture (unsettled) order ' . (int) $order->id . ' -> ' . $result->status);

            return $result;
        }

        $result = $client->refund($ref, $money);

        // Settlement can lag our view of it; fall back rather than fail.
        if ($result->status === 'FAILED' && (int) ($result->outcome->service->code ?? 0) === self::SERVICE_NOT_SETTLED) {
            self::log('refund returned 536 on order ' . (int) $order->id . '; reversing instead');
            $result = $client->reverseCapture($ref);
        }

        self::recordReferences($order, $result);
        self::log('refund order ' . (int) $order->id . ' -> ' . $result->status);

        return $result;
    }

    /** Gateway service code for "Order not settled: Please reverse". */
    public const SERVICE_NOT_SETTLED = 536;

    /**
     * Whether the gateway order has settled. Asks the gateway rather than
     * inferring from PrestaShop's order state, which knows nothing of batches.
     */
    private static function isSettled(Order $order): bool
    {
        try {
            $status = self::client()->status(self::orderRef($order));
            foreach ($status->transactions as $leg) {
                if ($leg->settled) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            self::log('settlement check failed on order ' . (int) $order->id . ': ' . $e->getMessage(), true);
        }

        return false;
    }

    public static function captureOrder(Order $order, ?string $amount = null): TransactionResult
    {
        $currency = new Currency((int) $order->id_currency);
        $money = $amount !== null ? Money::of($amount, $currency->iso_code) : null;
        $result = self::client()->capture(self::orderRef($order), $money);
        self::recordReferences($order, $result);
        self::log('capture order ' . (int) $order->id . ' -> ' . $result->status);

        return $result;
    }

    public static function voidOrder(Order $order): TransactionResult
    {
        $result = self::client()->reverse(self::orderRef($order));
        self::recordReferences($order, $result);
        self::log('void order ' . (int) $order->id . ' -> ' . $result->status);

        return $result;
    }

    /**
     * On a gateway timeout the transaction state is UNKNOWN — reconcile via
     * status() before failing, per the SDK's recovery contract.
     */
    public static function reconcileTimeout(Cart $cart, GatewayTimeoutException $e): ?TransactionResult
    {
        try {
            $status = self::client()->status(Refs::xtlOrder(self::xtlOrderId($cart)));
            foreach ($status->transactions as $leg) {
                if ($leg->status === 'APPROVED') {
                    self::log('timeout reconciled to APPROVED for cart ' . (int) $cart->id, true);

                    return $leg;
                }
            }
        } catch (\Throwable $statusError) {
            self::log('timeout reconcile failed: ' . $statusError->getMessage(), true);
        }

        return null;
    }

    /** Most specific decline advice available. */
    public static function advice(TransactionResult $result): ?string
    {
        return $result->outcome->processor->advice
            ?? $result->outcome->service->advice
            ?? $result->outcome->industry->advice
            ?? $result->outcome->api->advice
            ?? null;
    }

    public static function isApproved(TransactionResult $result): bool
    {
        return $result->status === 'APPROVED';
    }

    /** Never logs card data — it never reaches this server. */
    public static function log(string $message, bool $force = false): void
    {
        if ($force || Configuration::get('INOVIOPAYMENT_DEBUG')) {
            PrestaShopLogger::addLog('[inovio] ' . $message, $force ? 2 : 1);
        }
    }
}
