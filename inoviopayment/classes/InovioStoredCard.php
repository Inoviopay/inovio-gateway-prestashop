<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Saved-card vault row.
 *
 * SECURITY: this table MUST NEVER hold a PAN or CVV. On an approved
 * sale/authorize where the shopper opted in, the gateway returns CUST_ID
 * (the vaulted customer) and PMT_ID (the vaulted payment method) — those
 * gateway references ARE the stored card. Everything else here (brand,
 * last4, expiry) is display-only metadata that is explicitly permitted
 * under PCI DSS. See docs/prestashop-integration-design.md §2.1 and §5.
 *
 * Any change to this class that adds a full card number / CVV field is a
 * defect, not a feature.
 */
class InovioStoredCard extends ObjectModel
{
    /** @var int */
    public $id_customer;

    /** @var int */
    public $id_shop;

    /** @var string Gateway CUST_ID (vaulted customer reference). */
    public $cust_id;

    /** @var string Gateway PMT_ID (vaulted payment-method reference). */
    public $pmt_id;

    /** @var string Card brand code, e.g. 'VI', 'MC', 'AX'. Display only. */
    public $card_brand;

    /** @var string Last 4 digits of the PAN. Display only — never the full PAN. */
    public $card_last4;

    /** @var string Two-digit expiry month, '01'-'12'. */
    public $card_exp_month;

    /** @var string Four-digit expiry year, e.g. '2029'. */
    public $card_exp_year;

    /** @var string */
    public $date_add;

    /** @var string */
    public $date_upd;

    /**
     * @var array<string,mixed>
     */
    public static $definition = [
        'table' => 'inovio_stored_card',
        'primary' => 'id_inovio_stored_card',
        'fields' => [
            'id_customer' => [
                'type' => ObjectModel::TYPE_INT,
                'validate' => 'isUnsignedInt',
                'required' => true,
            ],
            'id_shop' => [
                'type' => ObjectModel::TYPE_INT,
                'validate' => 'isUnsignedInt',
            ],
            'cust_id' => [
                'type' => ObjectModel::TYPE_STRING,
                'validate' => 'isString',
                'required' => true,
                'size' => 64,
            ],
            'pmt_id' => [
                'type' => ObjectModel::TYPE_STRING,
                'validate' => 'isString',
                'required' => true,
                'size' => 64,
            ],
            'card_brand' => [
                'type' => ObjectModel::TYPE_STRING,
                'validate' => 'isGenericName',
                'size' => 16,
            ],
            'card_last4' => [
                'type' => ObjectModel::TYPE_STRING,
                'validate' => 'isString',
                'size' => 4,
            ],
            'card_exp_month' => [
                'type' => ObjectModel::TYPE_STRING,
                'validate' => 'isString',
                'size' => 2,
            ],
            'card_exp_year' => [
                'type' => ObjectModel::TYPE_STRING,
                'validate' => 'isString',
                'size' => 4,
            ],
            'date_add' => [
                'type' => ObjectModel::TYPE_DATE,
                'validate' => 'isDate',
            ],
            'date_upd' => [
                'type' => ObjectModel::TYPE_DATE,
                'validate' => 'isDate',
            ],
        ],
    ];

    /**
     * Every saved card belonging to a customer (optionally scoped to a
     * shop, for multi-shop installs), newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getByCustomer(int $idCustomer, int $idShop = 0): array
    {
        if ($idCustomer <= 0) {
            return [];
        }

        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'inovio_stored_card`
            WHERE `id_customer` = ' . (int) $idCustomer;

        if ($idShop > 0) {
            $sql .= ' AND `id_shop` = ' . (int) $idShop;
        }

        $sql .= ' ORDER BY `date_add` DESC';

        $result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);

        return is_array($result) ? $result : [];
    }

    /**
     * Ownership-checked load — the IDOR guard. Returns null if the row
     * does not exist OR does not belong to $idCustomer; callers must
     * never branch on "not found" vs. "not yours", so both collapse here.
     */
    public static function findForCustomer(int $id, int $idCustomer): ?self
    {
        if ($id <= 0 || $idCustomer <= 0) {
            return null;
        }

        $card = new self($id);

        if (
            (int) $card->id === 0
            || (int) $card->id_customer !== (int) $idCustomer
        ) {
            return null;
        }

        return $card;
    }

    /**
     * Whether a card for this gateway PMT_ID is already vaulted for this
     * customer — guards against duplicate rows when a shopper reuses the
     * same saved card across multiple opted-in checkouts.
     */
    public static function existsForCustomer(int $idCustomer, string $pmtId): bool
    {
        if ($idCustomer <= 0 || $pmtId === '') {
            return false;
        }

        $sql = 'SELECT `id_inovio_stored_card` FROM `' . _DB_PREFIX_ . 'inovio_stored_card`
            WHERE `id_customer` = ' . (int) $idCustomer . '
            AND `pmt_id` = \'' . pSQL($pmtId) . '\'';

        $result = Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($sql);

        return $result !== false && $result !== null;
    }

    /**
     * e.g. "•••• 4242". Falls back gracefully if last4 is somehow short.
     */
    public function getMaskedNumber(): string
    {
        $last4 = (string) $this->card_last4;

        return '•••• ' . str_pad($last4, 4, '•', STR_PAD_LEFT);
    }

    /**
     * e.g. "04/2029".
     */
    public function getExpiryLabel(): string
    {
        $month = str_pad((string) $this->card_exp_month, 2, '0', STR_PAD_LEFT);
        $year = (string) $this->card_exp_year;

        return $month . '/' . $year;
    }
}
