<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

$sql = [];

$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'inovio_stored_card`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'inovio_order_ref`;';

foreach ($sql as $query) {
    if (Db::getInstance()->execute($query) === false) {
        return false;
    }
}

return true;
