<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use Inovio\Gateway\Result\TransactionResult;

/**
 * Vault persistence from a completed gateway call.
 *
 * This is deliberately the ONLY write path into `inovio_stored_card`.
 * It must be called ONLY when the shopper has explicitly opted in to
 * saving the card (an unchecked "save this card" box must never reach
 * this method) — mirrors the opt-in guard in the Magento module's
 * Gateway\VaultTokenBuilder::attach().
 *
 * SECURITY: this never touches a PAN or CVV. It persists only the
 * gateway's own references (CUST_ID, PMT_ID) plus display-only card
 * metadata already known to the caller (brand, last4, expiry) — see
 * docs/prestashop-integration-design.md §2.1 and §5.
 */
class InovioVault
{
    /**
     * Persist a saved card from an approved sale/authorize result.
     *
     * Returns null (does nothing) when:
     *  - the gateway did not return a savedCardRef/customerRef (not every
     *    transaction vaults — e.g. the shopper did not opt in, or the
     *    processor/account is not vault-capable), or
     *  - a row for this PMT_ID already exists for this customer (avoids
     *    duplicate rows when the same saved card is reused across
     *    multiple opted-in checkouts).
     *
     * @param TransactionResult $result the SDK result from sale()/authorize()
     * @param string $expiryMMYYYY card expiry as MMYYYY (matches the wire
     *                             format used elsewhere in this module)
     * @param string $brand card brand code, e.g. 'VI', 'MC' — display only
     * @param string $last4 last 4 digits of the PAN — display only
     */
    public static function saveFromResult(
        int $idCustomer,
        int $idShop,
        $result,
        string $expiryMMYYYY,
        string $brand,
        string $last4
    ): ?InovioStoredCard {
        if ($idCustomer <= 0 || !$result instanceof TransactionResult) {
            return null;
        }

        $savedCardRef = $result->savedCardRef;
        $customerRef = $result->customerRef;

        if ($savedCardRef === null || $customerRef === null) {
            return null;
        }

        $pmtId = $savedCardRef->pmtId();
        $custId = $customerRef->custId();

        if ($pmtId === null || $pmtId === '' || $custId === null || $custId === '') {
            return null;
        }

        if (InovioStoredCard::existsForCustomer($idCustomer, $pmtId)) {
            return null;
        }

        [$expMonth, $expYear] = self::splitExpiry($expiryMMYYYY);

        $card = new InovioStoredCard();
        $card->id_customer = $idCustomer;
        $card->id_shop = $idShop;
        $card->cust_id = $custId;
        $card->pmt_id = $pmtId;
        $card->card_brand = $brand;
        $card->card_last4 = $last4;
        $card->card_exp_month = $expMonth;
        $card->card_exp_year = $expYear;

        if ($card->add() === false) {
            return null;
        }

        return $card;
    }

    /**
     * @return array{0:string,1:string} [MM, YYYY]
     */
    private static function splitExpiry(string $mmYyyy): array
    {
        if (strlen($mmYyyy) === 6 && ctype_digit($mmYyyy)) {
            return [substr($mmYyyy, 0, 2), substr($mmYyyy, 2)];
        }

        return ['', ''];
    }
}
