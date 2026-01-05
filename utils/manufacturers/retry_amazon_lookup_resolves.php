<?php

// https://test.lafrikileria.com/modules/frikimportproductos/utils/manufacturers/retry_amazon_lookup_resolves.php?limit=100&dry_run=1

// Repasar lafrips_manufacturer_amazon_lookup sin tocar la API de Amazon, usando los datos ya guardados (raw_manufacturer, raw_brand) y el ManufacturerAliasHelper.
// Sirve para: Casar ahora nombres que antes eran “pendientes” porque aún no existía alias/fabricante. Aprovechar todos los alias que vayas creando a mano desde el BO.

require dirname(__FILE__) . '/../../../../config/config.inc.php';
require dirname(__FILE__) . '/../../../../init.php';
require_once _PS_ROOT_DIR_ . '/classes/utils/LoggerFrik.php';


if (!class_exists('ManufacturerAliasHelper')) {
    require_once _PS_MODULE_DIR_ . 'frikimportproductos/classes/ManufacturerAliasHelper.php';
}

$db = Db::getInstance();

$logFile = _PS_MODULE_DIR_ . 'frikimportproductos/logs/manufacturers/retry_amazon_lookup_resolves_' . date('Ymd') . '.log';
$logger  = new LoggerFrik($logFile);

// Parámetros
$limit   = (int) Tools::getValue('limit', 100);
$dryRun  = (int) Tools::getValue('dry_run', 0);
$status  = Tools::getValue('status', 'pending');         // por defecto solo pendientes
$market  = Tools::getValue('marketplace_id', '');        // opcional: filtrar por marketplace

if ($limit <= 0) {
    $limit = 100;
}

echo '<h2>Reintento de resolución en manufacturer_amazon_lookup</h2>';
echo 'limit = ' . (int) $limit . '<br>';
echo 'dry_run = ' . (int) $dryRun . '<br>';
echo 'status filter = ' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '<br>';
if ($market !== '') {
    echo 'marketplace_id = ' . htmlspecialchars($market, ENT_QUOTES, 'UTF-8') . '<br>';
}
echo '<hr>';

$logger->log(
    'Inicio retry_amazon_lookup_resolves | limit=' . (int) $limit .
    ' | dry_run=' . (int) $dryRun .
    ' | status=' . $status .
    ' | marketplace_id=' . ($market !== '' ? $market : '(cualquiera)'),
    'INFO'
);

// Construimos la SELECT
$where = array();
$where[] = 'status = "' . pSQL($status) . '"';
$where[] = 'id_manufacturer_resolved IS NULL';
$where[] = '(raw_manufacturer <> "" OR raw_brand <> "")';

if ($market !== '') {
    $where[] = 'marketplace_id = "' . pSQL($market) . '"';
}

$sql = '
    SELECT *
    FROM ' . _DB_PREFIX_ . 'manufacturer_amazon_lookup
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY date_add ASC
    LIMIT ' . (int) $limit;

$rows = $db->executeS($sql);

if (!$rows) {
    echo 'No hay registros Amazon con ese filtro para reintentar.<br>';
    $logger->log('No hay registros que cumplan el filtro para reintentar.', 'INFO');
    exit;
}

echo 'Registros a reintentar: ' . count($rows) . '<br><br>';
$logger->log('Registros a reintentar: ' . count($rows), 'INFO');

$resolvedCount = 0;
$stillPending  = 0;

foreach ($rows as $row) {
    $idLookup = (int) $row['id_manufacturer_amazon_lookup'];
    $ean      = $row['ean13'];
    $rawMf    = trim((string) $row['raw_manufacturer']);
    $rawBrand = trim((string) $row['raw_brand']);

    echo '<strong>[#' . $idLookup . ']</strong> EAN=' . htmlspecialchars($ean, ENT_QUOTES, 'UTF-8') . '<br>';
    echo '&nbsp;&nbsp;raw_manufacturer = "' . htmlspecialchars($rawMf, ENT_QUOTES, 'UTF-8') . '"<br>';
    echo '&nbsp;&nbsp;raw_brand        = "' . htmlspecialchars($rawBrand, ENT_QUOTES, 'UTF-8') . '"<br>';

    $logger->log(
        'Procesando lookup #' . $idLookup .
        ' | EAN=' . $ean .
        ' | raw_manufacturer="' . $rawMf . '"' .
        ' | raw_brand="' . $rawBrand . '"',
        'DEBUG'
    );

    $resolvedId   = null;
    $resolvedFrom = 'none';

    // 1) Primero probamos con manufacturer (Amazon)
    if ($rawMf !== '') {
        $resolvedId = ManufacturerAliasHelper::resolveName($rawMf, 'AMAZON_MANUFACTURER');
        if ($resolvedId) {
            $resolvedFrom = 'manufacturer';
        }
    }

    // 2) Si no ha resuelto y hay brand, probamos con brand
    if (!$resolvedId && $rawBrand !== '') {
        $resolvedId = ManufacturerAliasHelper::resolveName($rawBrand, 'AMAZON_BRAND');
        if ($resolvedId) {
            $resolvedFrom = 'brand';
        }
    }

    if ($resolvedId) {
        echo '&nbsp;&nbsp;→ RESUELTO ahora: id_manufacturer = ' . (int) $resolvedId .
             ' (from=' . $resolvedFrom . ')';

        $logger->log(
            'Lookup #' . $idLookup . ' RESUELTO ahora -> id_manufacturer=' . (int) $resolvedId .
            ' (from=' . $resolvedFrom . ')',
            'INFO'
        );

        if (!$dryRun) {
            $db->update(
                'manufacturer_amazon_lookup',
                array(
                    'id_manufacturer_resolved' => (int) $resolvedId,
                    'resolved_from'            => pSQL($resolvedFrom),
                    'status'                   => 'resolved',
                    // limpiamos error_message si la hubiera
                    'error_message'            => null,
                    'date_upd'                 => date('Y-m-d H:i:s'),
                    // id_employee_resolved lo dejamos NULL, esto es resolución automática
                ),
                'id_manufacturer_amazon_lookup = ' . (int) $idLookup
            );
        } else {
            echo ' (DRY-RUN, no se escribe en BD)';
        }

        echo '<br><br>';
        $resolvedCount++;
    } else {
        echo '&nbsp;&nbsp;→ sigue sin poder resolverse, se mantiene status="' .
             htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') . '"<br><br>';

        $logger->log(
            'Lookup #' . $idLookup .
            ' NO se ha podido resolver de nuevo. Sigue con status=' . $row['status'],
            'DEBUG'
        );

        $stillPending++;
    }

    usleep(50000); // 0.05s, por si algún día esto crece mucho
}

echo '<hr>';
echo 'Total resueltos en esta pasada: ' . (int) $resolvedCount . '<br>';
echo 'Total que siguen sin resolver: ' . (int) $stillPending . '<br>';

$logger->log('Fin retry_amazon_lookup_resolves | resueltos=' . (int) $resolvedCount .
    ' | siguen sin resolver=' . (int) $stillPending, 'INFO');