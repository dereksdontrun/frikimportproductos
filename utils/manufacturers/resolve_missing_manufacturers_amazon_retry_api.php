<?php

// https://test.lafrikileria.com/modules/frikimportproductos/utils/manufacturers/resolve_missing_manufacturers_amazon_retry_api.php?limit=50&dry_run=1&status=not_found

// Volver a llamar a la API de Amazon SOLO para filas de lafrips_manufacturer_amazon_lookup que:
// Tengan status = 'error' (por ejemplo por 429 o caída puntual).
// Opcionalmente, también status = 'not_found' si quieres darles una segunda oportunidad.
// Y sobre esa nueva respuesta de Amazon:
// Actualizar raw_manufacturer, raw_brand, status, error_message, etc.
// Volver a intentar casar fabricante con ManufacturerAliasHelper como hace el script principal.

require dirname(__FILE__) . '/../../../../config/config.inc.php';
require dirname(__FILE__) . '/../../../../init.php';

// Cargamos la clase del resolver
require_once _PS_MODULE_DIR_ . 'frikimportproductos/classes/AmazonCatalogManufacturerResolver.php';
require_once _PS_ROOT_DIR_ . '/classes/utils/LoggerFrik.php';

$limit   = (int) Tools::getValue('limit', 50);
$dryRun  = (int) Tools::getValue('dry_run', 1);
$status  = Tools::getValue('status', 'not_found');
$marketOverride = Tools::getValue('marketplace_override', ''); // opcional

if ($limit <= 0) {
    $limit = 50;
}

$logFile = _PS_ROOT_DIR_ . '/log/amazon_manufacturer_retry.log';
$logger  = new LoggerFrik($logFile);

echo '<h2>Reintento Amazon para lookups existentes</h2>';
echo 'limit = ' . (int) $limit . '<br>';
echo 'dry_run = ' . (int) $dryRun . '<br>';
echo 'status = ' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '<br>';
if ($marketOverride !== '') {
    echo 'marketplace_override = ' . htmlspecialchars($marketOverride, ENT_QUOTES, 'UTF-8') . '<br>';
}
echo '<hr>';

// Llamamos al método nuevo
AmazonCatalogManufacturerResolver::retryAmazonLookups(
    $limit,
    (bool) $dryRun,
    $status,
    $marketOverride,
    $logger
);

echo '<br>Proceso finalizado. Revisa el log: ' . htmlspecialchars($logFile, ENT_QUOTES, 'UTF-8');
