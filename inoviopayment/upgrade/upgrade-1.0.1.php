<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.0.1 replaces the broken refund hook (actionProductCancel, which misread
 * PS9's per-line scalar cancel_quantity as a full refund on every line) with
 * actionOrderSlipAdd, adds the AdminInovioOrderActionsController tab for
 * capture/void so hookDisplayAdminOrderMainBottom no longer double-duties as
 * an action endpoint, and adds the server-side rate-limit table (the
 * signing/3DS-prepare endpoints previously counted hits in a
 * client-resettable cookie).
 *
 * @param Inoviopayment $module
 */
function upgrade_module_1_0_1($module): bool
{
    $result = true;

    $oldHookId = (int) Hook::getIdByName('actionProductCancel');
    if ($oldHookId > 0) {
        $result = $result && $module->unregisterHook($oldHookId);
    }

    $result = $result && $module->registerHook('actionOrderSlipAdd');
    $result = $result && $module->installTabs();
    $result = $result && $module->installOrderStates();

    $result = $result && (bool) Db::getInstance()->execute(
        'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'inovio_rate_limit_hit` (
            `id_inovio_rate_limit_hit` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `rl_key` VARCHAR(64) NOT NULL,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_inovio_rate_limit_hit`),
            KEY `idx_inovio_rate_limit_hit_key` (`rl_key`, `date_add`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;'
    );

    return $result;
}
