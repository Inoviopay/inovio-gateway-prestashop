<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * "My saved cards" customer-account page: list + delete.
 *
 * Reachable at /module/inoviopayment/storedcards
 * (or index.php?fc=module&module=inoviopayment&controller=storedcards).
 */
class InoviopaymentStoredcardsModuleFrontController extends ModuleFrontController
{
    public $auth = true;

    public $ssl = true;

    public $authRedirection = 'my-account';

    /**
     * Handles the delete action. Two checks are mandatory here, in order:
     *  1. a valid CSRF token (Tools::getToken()) — this is a state-changing
     *     POST reachable by any logged-in shopper's browser;
     *  2. ownership of the row via InovioStoredCard::findForCustomer(), the
     *     IDOR guard — a shopper must never be able to delete another
     *     shopper's saved card by guessing/incrementing the id.
     */
    public function postProcess()
    {
        if (!Tools::isSubmit('deleteInovioStoredCard')) {
            return;
        }

        $token = Tools::getValue('token');
        if (!is_string($token) || !hash_equals(Tools::getToken(false), $token)) {
            // Invalid/missing CSRF token — refuse silently rather than
            // revealing whether the card id exists.
            Tools::redirect($this->context->link->getModuleLink(
                'inoviopayment',
                'storedcards',
                ['inovio_error' => 'csrf'],
                true
            ));

            return;
        }

        $idCard = (int) Tools::getValue('id_inovio_stored_card');
        $idCustomer = (int) $this->context->customer->id;

        $card = InovioStoredCard::findForCustomer($idCard, $idCustomer);

        if ($card === null) {
            // Either the row does not exist, or it does not belong to this
            // customer — both cases are handled identically (IDOR guard).
            Tools::redirect($this->context->link->getModuleLink(
                'inoviopayment',
                'storedcards',
                ['inovio_error' => 'notfound'],
                true
            ));

            return;
        }

        // NOTE: this deletes the local row only. Whether deletion should
        // also revoke the gateway-side payment method (i.e. call the SDK
        // to invalidate PMT_ID at Inovio) is open item #1 in
        // docs/prestashop-integration-design.md §11 — deliberately not
        // implemented yet, pending confirmation of the gateway's customer
        // API for revocation.
        $card->delete();

        Tools::redirect($this->context->link->getModuleLink(
            'inoviopayment',
            'storedcards',
            ['inovio_deleted' => '1'],
            true
        ));
    }

    public function initContent()
    {
        parent::initContent();

        $idCustomer = (int) $this->context->customer->id;
        $idShop = (int) $this->context->shop->id;

        $rows = InovioStoredCard::getByCustomer($idCustomer, $idShop);

        $cards = [];
        foreach ($rows as $row) {
            $card = new InovioStoredCard((int) $row['id_inovio_stored_card']);
            $cards[] = [
                'id_inovio_stored_card' => (int) $card->id,
                'card_brand' => $card->card_brand,
                'masked_number' => $card->getMaskedNumber(),
                'expiry_label' => $card->getExpiryLabel(),
                'date_add' => $card->date_add,
            ];
        }

        $this->context->smarty->assign([
            'inovio_stored_cards' => $cards,
            'inovio_delete_token' => Tools::getToken(false),
            'inovio_deleted' => (bool) Tools::getValue('inovio_deleted'),
            'inovio_error' => Tools::getValue('inovio_error'),
        ]);

        $this->context->smarty->assign('page_title', $this->trans('My saved cards', [], 'Modules.Inoviopayment.Shop'));

        $this->setTemplate('module:inoviopayment/views/templates/front/stored_cards.tpl');
    }

    public function getBreadcrumbLinks()
    {
        $breadcrumb = parent::getBreadcrumbLinks();

        $breadcrumb['links'][] = [
            'title' => $this->trans('My saved cards', [], 'Modules.Inoviopayment.Shop'),
            'url' => $this->context->link->getModuleLink('inoviopayment', 'storedcards'),
        ];

        return $breadcrumb;
    }
}
