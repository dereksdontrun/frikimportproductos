<?php
require dirname(__FILE__).'/../../../../config/config.inc.php';
require dirname(__FILE__).'/../../../../init.php';
require_once _PS_MODULE_DIR_.'frikimportproductos/classes/ManufacturerAliasHelper.php';

// https://test.lafrikileria.com/modules/frikimportproductos/utils/manufacturers/init_manufacturer_aliases.php

// 1) alias base para todos los fabricantes ACTIVOS
$rows = Db::getInstance()->executeS('
    SELECT id_manufacturer, name
    FROM '._DB_PREFIX_.'manufacturer
    WHERE active = 1
');

foreach ($rows as $r) {
    $id   = (int)$r['id_manufacturer'];
    $name = $r['name'];
    $norm = ManufacturerAliasHelper::normalizeName($name);

    ManufacturerAliasHelper::createAlias($id, $name, $norm, 'INIT_ACTIVOS', 0);
}

echo "Alias base generados para fabricantes activos.<br>";
