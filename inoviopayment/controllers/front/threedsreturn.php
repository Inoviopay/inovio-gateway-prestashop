<?php
/**
 * ACS return: after the cardholder finishes the challenge, the ACS POSTs
 * TransactionId / Response (the PARes — legitimately possibly empty) here,
 * rendered inside the challenge iframe.
 *
 * This is a cross-site POST from the ACS origin and may arrive without the
 * session cookie, so it cannot use PrestaShop's CSRF token. It is instead
 * bound to a legitimate order by (a) the cart id on the return URL and (b) a
 * constant-time comparison of the ACS TransactionId against the procTransId
 * stored at the enrollment leg.
 */

use Inovio\Gateway\Model\ThreeDSChallengeResult;
use Inovio\Gateway\Result\TransactionResult;

class InoviopaymentThreedsreturnModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    /** No theme: this document renders inside the ACS iframe. */
    public $ajax = true;

    public function postProcess(): void
    {
        $idCart = (int) Tools::getValue('cart');
        $acsTransId = (string) Tools::getValue('TransactionId');
        // The PARes; MAY be empty — that is not an error (spec §15.1.4).
        $pares = (string) Tools::getValue('Response');

        // PrestaShop 9: Order::getByCartId() returns the Order (there is no
        // Order::getOrderByCartId()).
        $order = Order::getByCartId($idCart);
        if (!($order instanceof Order) || !Validate::isLoadedObject($order)) {
            $this->respond(false, 'Order not found.');
        }

        $raw = InovioGateway::getOrderRefValue($order, 'challenge');
        $challenge = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($challenge) || empty($challenge['procTransId'])) {
            $this->respond(false, 'No pending authentication for this order.');
        }
        if ($acsTransId === '' || !hash_equals((string) $challenge['procTransId'], $acsTransId)) {
            InovioGateway::log('3DS return TransactionId mismatch on order ' . (int) $order->id, true);
            $this->respond(false, 'Authentication reference mismatch.');
        }

        // Replay guard: a completed order must not be completed twice.
        if ((int) $order->getCurrentState() !== (int) Configuration::getGlobalValue(Inoviopayment::STATE_AWAITING_3DS)) {
            $this->respond(false, 'This authentication has already been processed.');
        }

        try {
            $result = $this->complete($order, $acsTransId, $pares);
        } catch (\Throwable $e) {
            InovioGateway::log('3DS completion failed on order ' . (int) $order->id . ': ' . $e->getMessage(), true);
            $this->fail($order);
            $this->respond(false, 'Payment authentication failed.');
        }

        if (!InovioGateway::isApproved($result)) {
            $this->fail($order);
            $this->respond(false, InovioGateway::advice($result) ?? 'Payment authentication failed.');
        }

        $this->succeed($order, $result);
        $this->respond(true, 'Payment approved.');
    }

    private function complete(Order $order, string $acsTransId, string $pares): TransactionResult
    {
        $cart = new Cart((int) $order->id_cart);

        // The enrollment leg consumed the first token; use the second.
        $payment = [
            'token_guid_completion' => InovioGateway::getOrderRefValue($order, 'token_completion'),
            'pmt_expiry' => InovioGateway::getOrderRefValue($order, 'pmt_expiry'),
        ];
        $req = InovioGateway::buildRequest($cart, $payment, true);
        $challengeResult = new ThreeDSChallengeResult($acsTransId, $pares);

        $client = InovioGateway::client();

        return Configuration::get('INOVIOPAYMENT_PAYMENT_ACTION') === 'authorize'
            ? $client->threeDSecure()->completeAuthorize($req, $challengeResult)
            : $client->threeDSecure()->completeSale($req, $challengeResult);
    }

    private function succeed(Order $order, TransactionResult $result): void
    {
        InovioGateway::recordReferences($order, $result);

        $state = Configuration::get('INOVIOPAYMENT_PAYMENT_ACTION') === 'authorize'
            ? (int) Configuration::getGlobalValue(Inoviopayment::STATE_AWAITING_CAPTURE)
            : (int) Configuration::get('PS_OS_PAYMENT');

        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->changeIdOrderState($state, (int) $order->id);
        $history->addWithemail(true);
    }

    private function fail(Order $order): void
    {
        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->changeIdOrderState((int) Configuration::get('PS_OS_ERROR'), (int) $order->id);
        $history->add();
    }

    /** Minimal document rendered inside the iframe; tells the parent the outcome. */
    private function respond(bool $success, string $message): void
    {
        $payload = (string) json_encode([
            'inovio3ds' => 'complete',
            'success' => $success,
            'message' => $message,
        ]);

        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>3-D Secure</title></head>'
            . '<body><script>window.parent.postMessage(' . $payload . ', window.location.origin);</script>'
            . '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
        exit;
    }
}
