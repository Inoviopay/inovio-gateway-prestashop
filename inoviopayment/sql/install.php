<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/*
 * Vault storage for the Inovio saved-cards feature.
 *
 * SECURITY: this table holds gateway references only — CUST_ID / PMT_ID —
 * plus display-only card metadata (brand, last 4, expiry). It MUST NEVER
 * hold a PAN or CVV; see docs/prestashop-integration-design.md §2.1 and §5.
 */
$sql = [];

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'inovio_stored_card` (
    `id_inovio_stored_card` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_customer` INT UNSIGNED NOT NULL,
    `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
    `cust_id` VARCHAR(64) NOT NULL,
    `pmt_id` VARCHAR(64) NOT NULL,
    `card_brand` VARCHAR(16) NOT NULL DEFAULT \'\',
    `card_last4` VARCHAR(4) NOT NULL DEFAULT \'\',
    `card_exp_month` VARCHAR(2) NOT NULL DEFAULT \'\',
    `card_exp_year` VARCHAR(4) NOT NULL DEFAULT \'\',
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_inovio_stored_card`),
    KEY `idx_inovio_stored_card_customer` (`id_customer`),
    KEY `idx_inovio_stored_card_pmt_id` (`pmt_id`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

/*
 * Gateway references per order (PO_ID, TRANS_ID, REQ_ID, 3DS challenge data).
 * PrestaShop has no per-order key/value store for payment modules, and these
 * references are what capture / void / refund and the 3DS completion leg need.
 * Card data is never stored here either.
 */
$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'inovio_order_ref` (
    `id_order` INT UNSIGNED NOT NULL,
    `ref_key` VARCHAR(32) NOT NULL,
    `ref_value` TEXT NOT NULL,
    `date_add` DATETIME NOT NULL,
    PRIMARY KEY (`id_order`, `ref_key`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

/*
 * Server-side rate-limit hits for the signing (signature.php) and 3DS
 * prepare (threeds.php) endpoints — both are paid/mintable gateway calls
 * and must not be throttled by a client-resettable cookie. Keyed by
 * cart id + IP + endpoint; pruned on every write (see InovioGateway::withinRateLimit()).
 */
$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'inovio_rate_limit_hit` (
    `id_inovio_rate_limit_hit` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rl_key` VARCHAR(64) NOT NULL,
    `date_add` DATETIME NOT NULL,
    PRIMARY KEY (`id_inovio_rate_limit_hit`),
    KEY `idx_inovio_rate_limit_hit_key` (`rl_key`, `date_add`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

foreach ($sql as $query) {
    if (Db::getInstance()->execute($query) === false) {
        return false;
    }
}

return true;
