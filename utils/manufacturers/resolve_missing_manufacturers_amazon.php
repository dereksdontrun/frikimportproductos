<?php
// modules/frikimportproductos/utils/manufacturers/resolve_missing_manufacturers_amazon.php

// obtener productos de un manufacturer que se proporciona como GET y mediante su ean pedir vía API a Amazon si tiene info del producto y del fabricante

// https://test.lafrikileria.com/modules/frikimportproductos/utils/manufacturers/resolve_missing_manufacturers_amazon.php?limit=20&id_manufacturer_lookup=778&csv_log=1&dry_run=1

require dirname(__FILE__) . '/../../../../config/config.inc.php';
require dirname(__FILE__) . '/../../../../init.php';
require_once _PS_MODULE_DIR_ . 'frikimportproductos/classes/AmazonCatalogManufacturerResolver.php';
require_once _PS_ROOT_DIR_ . '/classes/utils/LoggerFrik.php';
require_once _PS_ROOT_DIR_ . '/classes/utils/CsvLoggerFrik.php';

$limit = (int) Tools::getValue('limit', 20);
$dryRun = (int) Tools::getValue('dry_run', 0) ? true : false;
$csvLog = (int) Tools::getValue('csv_log', 0) ? true : false;
$market = Tools::getValue('marketplace', 'A1RKKUPIHCS9HS'); // ES por defecto
$idManufacturerFilter = (int) Tools::getValue('id_manufacturer_lookup', 0);

$logFile = _PS_MODULE_DIR_ . 'frikimportproductos/logs/manufacturers/eans_amazon/manufacturer_amazon_' . date('Ymd') . '.txt';
$logger = new LoggerFrik($logFile);

if ($csvLog) {
    // CSV en la misma carpeta de logs
    $csvPath = _PS_MODULE_DIR_ . 'frikimportproductos/logs/manufacturers/eans_amazon/csv/manufacturer_amazon_' . date('Ymd_His') . '.csv';
    $csvHeaders = array(
        'id_product',
        'reference',
        'product_name',
        'ean13',
        'id_manufacturer_current',
        'manufacturer_current_name',
        'raw_manufacturer',
        'raw_brand',
        'asin',
        'status',
        'resolved_from',
        'id_manufacturer_resolved',
        'marketplace_id',
        'error_message',
        'dry_run'
    );
    $csvLogger = new CsvLoggerFrik($csvPath, $csvHeaders, ';');
} else {
    $csvLogger = null;
}


$logger->log('=== === === === === === === === === === === === === === === === === === === === === === === === === === === === === === === === === ===', 'INFO', false);
$logger->log('=== Inicio resolve_missing_manufacturers (limit=' . $limit . ', id_manufacturer_filter=' . $idManufacturerFilter . ', dry_run=' . (int) $dryRun . ', marketplace=' . $market . ') ===', 'INFO', false);

AmazonCatalogManufacturerResolver::resolveMissingManufacturers($limit, $dryRun, $market, $idManufacturerFilter, $logger, $csvLogger);

$logger->log('=== Fin resolve_missing_manufacturers ===', 'INFO');

echo 'OK';
if ($csvLog) {
    echo 'CSV: ' . basename($csvPath);
}