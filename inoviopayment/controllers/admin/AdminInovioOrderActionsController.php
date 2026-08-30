<?php
/**
 * Back-office capture / void actions.
 *
 * PrestaShop exposes no merchant-triggered capture hook, so the module posts
 * here instead of processing the submit inline in a display hook — a proper
 * token-carrying admin controller rather than reading $_POST from
 * hookDisplayAdminOrderMainBottom (design note: a display hook must only
 * render; it must not double as an action endpoint).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'inoviopayment/vendor/inovio/autoload.php';
require_once _PS_MODULE_DIR_ . 'inoviopayment/classes/InovioGateway.php';

use Inovio\Gateway\Errors\GatewayTimeoutException;

class AdminInovioOrderActionsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'order';
        $this->className = 'Order';

        parent::__construct();
    }

    /**
     * This controller only ever processes a POST and redirects back — every
     * path through postProcess() ends in Tools::redirectAdmin(), which exits
     * before Controller::run() would reach initContent(). Override it as a
     * no-op anyway (rather than inheriting AdminController's CRUD/list
     * rendering) in case that invariant ever changes.
     */
    public function initContent(): void
    {
    }

    public function postProcess(): void
    {
        $idOrder = (int) Tools::getValue('inovio_order_id');
        $order = new Order($idOrder);

        if (!Validate::isLoadedObject($order) || $order->module !== $this->module->name) {
            $this->redirectToOrder($idOrder, null, null);

            return;
        }

        if (Tools::isSubmit('inovio_void')) {
            $this->handleVoid($order);
        } elseif (Tools::isSubmit('inovio_capture')) {
            $this->handleCapture($order);
        } else {
            $this->redirectToOrder($idOrder, null, null);
        }
    }

    private function handleVoid(Order $order): void
    {
        try {
            $result = InovioGateway::voidOrder($order);
        } catch (GatewayTimeoutException $e) {
            $this->reconcileTimeoutOrFail($order, $e);

            return;
        } catch (\Throwable $e) {
            InovioGateway::log('admin void failed on order ' . (int) $order->id . ': ' . $e->getMessage(), true);
            $this->redirectToOrder((int) $order->id, null, $e->getMessage());

            return;
        }

        if (!InovioGateway::isApproved($result)) {
            $this->redirectToOrder(
                (int) $order->id,
                null,
                (string) (InovioGateway::advice($result) ?? 'declined')
            );

            return;
        }

        $this->changeOrderState($order, (int) Configuration::get('PS_OS_CANCELED'));
        $this->redirectToOrder((int) $order->id, 'voided', null);
    }

    private function handleCapture(Order $order): void
    {
        $raw = trim((string) Tools::getValue('inovio_capture_amount'));
        $amount = $raw === '' ? null : number_format((float) $raw, 2, '.', '');

        try {
            $result = InovioGateway::captureOrder($order, $amount);
        } catch (GatewayTimeoutException $e) {
            $this->reconcileTimeoutOrFail($order, $e);

            return;
        } catch (\Throwable $e) {
            InovioGateway::log('admin capture failed on order ' . (int) $order->id . ': ' . $e->getMessage(), true);
            $this->redirectToOrder((int) $order->id, null, $e->getMessage());

            return;
        }

        if (!InovioGateway::isApproved($result)) {
            $this->redirectToOrder(
                (int) $order->id,
                null,
                (string) (InovioGateway::advice($result) ?? 'declined')
            );

            return;
        }

        /*
         * A PARTIAL capture must leave the order in "Awaiting capture" so the
         * remainder can still be taken. Only a full capture moves it to
         * Payment accepted. Comparing against the order total — blank amount
         * means "capture everything".
         */
        $isPartial = $amount !== null && (float) $amount + 0.001 < (float) $order->total_paid;

        $newState = $isPartial
            ? (int) Configuration::getGlobalValue(Inoviopayment::STATE_AWAITING_CAPTURE)
            : (int) Configuration::get('PS_OS_PAYMENT');

        $this->changeOrderState($order, $newState);
        $this->redirectToOrder((int) $order->id, $isPartial ? 'partial_captured' : 'captured', null);
    }

    /**
     * On a gateway timeout the transaction state is UNKNOWN — reconcile via
     * status() before showing an error that would invite the merchant to
     * retry the same capture/void and risk a double-capture/double-void.
     *
     * Unlike the 3DS completion reconcile (which looks specifically for a
     * CCAUTHCAP/CCAUTHORIZE leg), a capture or void's completion leg is
     * CCCAPTURE / CCREVERSE / CCREVERSECAP — so any APPROVED leg newer than
     * the action just attempted is evidence the action went through.
     */
    private function reconcileTimeoutOrFail(Order $order, GatewayTimeoutException $e): void
    {
        $status = InovioGateway::client()->status(InovioGateway::orderRef($order));
        foreach ($status->transactions as $leg) {
            if ($leg->status === 'APPROVED') {
                InovioGateway::recordReferences($order, $leg);
                InovioGateway::log('admin action timeout reconciled to APPROVED (' . $leg->action . ') on order ' . (int) $order->id, true);
                $this->redirectToOrder((int) $order->id, 'timeout_reconciled', null);

                return;
            }
        }

        InovioGateway::log('admin action timeout on order ' . (int) $order->id . ': ' . $e->getMessage(), true);
        $this->redirectToOrder((int) $order->id, null, 'timeout_unreconciled');
    }

    private function changeOrderState(Order $order, int $newState): void
    {
        if ($newState === (int) $order->getCurrentState()) {
            return;
        }

        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->changeIdOrderState($newState, (int) $order->id);
        $history->add();
    }

    /**
     * $notice/$error are short codes, never free text — hookDisplayAdminOrderMainBottom
     * maps them to translated strings. $error falls back to a raw gateway
     * advice string when the code isn't a recognized key, which is safe
     * because it is HTML-escaped by Smarty at render time, never executed.
     */
    private function redirectToOrder(int $idOrder, ?string $notice, ?string $error): void
    {
        $params = ['vieworder' => '', 'id_order' => $idOrder];
        if ($notice !== null) {
            $params['inovio_notice'] = $notice;
        }
        if ($error !== null) {
            $params['inovio_error'] = $error;
        }

        Tools::redirectAdmin($this->context->link->getAdminLink('AdminOrders', true, [], $params));
    }
}
