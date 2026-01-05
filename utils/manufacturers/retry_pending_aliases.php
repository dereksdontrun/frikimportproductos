<?php

// https://test.lafrikileria.com/modules/frikimportproductos/utils/manufacturers/retry_pending_aliases.php?limit=100&dry_run=1

// Volver a pasar por ManufacturerAliasHelper::resolveName() todos los registros de lafrips_manufacturer_alias_pending que sigan sin resolver, para ver si con los nuevos alias / fabricantes que se hayan podido crear ya se pueden casar.


require dirname(__FILE__) . '/../../../../config/config.inc.php';
require dirname(__FILE__) . '/../../../../init.php';
require_once _PS_MODULE_DIR_ . 'frikimportproductos/classes/ManufacturerAliasHelper.php';
require_once _PS_ROOT_DIR_ . '/classes/utils/LoggerFrik.php';

$context = Context::getContext();

// Parámetro opcional ?limit=XX
$limit = (int) Tools::getValue('limit', 100);
if ($limit <= 0) {
    $limit = 100;
}
$dryRun = (int) Tools::getValue('dry_run', 0) ? true : false;

// Logger
$logFile = _PS_MODULE_DIR_ . 'frikimportproductos/logs/manufacturers/retry_pending_aliases_' . date('Ymd') . '.txt';
$logger = new LoggerFrik($logFile);

$logger->log(
    'Inicio retry_pending_aliases (limit=' . (int) $limit . ', dry_run=' . (int) $dryRun . ')',
    'INFO',
    false
);

echo '<h2>Reintento de manufacturer_alias_pending (limit = ' . (int) $limit . ')</h2>';

$db = Db::getInstance();

// 1) Sacamos pendientes sin resolver
$pendingRows = $db->executeS('
    SELECT *
    FROM ' . _DB_PREFIX_ . 'manufacturer_alias_pending
    WHERE resolved = 0
    ORDER BY times_seen DESC, date_add ASC
    LIMIT ' . (int) $limit . '
');

if (!$pendingRows) {
    $msg = 'No hay pendientes para reintentar.';
    echo $msg . '<br>';
    $logger->log($msg, 'INFO');
    exit;
}

echo 'Pendientes a revisar: ' . count($pendingRows) . '<br><br>';
$logger->log('Pendientes a revisar: ' . count($pendingRows), 'INFO');

$resolvedCount = 0;
$stillPending = 0;

foreach ($pendingRows as $row) {
    $idPending = (int) $row['id_pending'];
    $rawName = $row['raw_name'];
    $norm = $row['normalized_alias'];
    $source = $row['source'];

    $line = '[#' . $idPending . '] raw_name="' . $rawName . '"';
    if ($source !== null && $source !== '') {
        $line .= ' | source=' . $source;
    }

    echo '<strong>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</strong><br>';
    $logger->log($line, 'INFO');

    // Volvemos a intentar la resolución con la lógica completa
    $resolvedId = ManufacturerAliasHelper::resolveName($rawName, $source);

    if ($resolvedId) {
        $msg = '→ RESUELTO ahora: id_manufacturer = ' . (int) $resolvedId;

        echo '&nbsp;&nbsp;' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '<br><br>';
        $logger->log(
            'id_pending=' . (int) $idPending . ' resuelto a id_manufacturer=' . (int) $resolvedId
            . ' (dry_run=' . (int) $dryRun . ')',
            'INFO'
        );

        if (!$dryRun) {
            $db->update(
                'manufacturer_alias_pending',
                array(
                    'resolved' => 1,
                    'id_manufacturer' => (int) $resolvedId,
                    'date_upd' => date('Y-m-d H:i:s'),
                    // si quieres, aquí podrías añadir id_employee si más adelante lo metes en la tabla
                ),
                'id_pending = ' . (int) $idPending
            );
        }

        $resolvedCount++;
    } else {
        $msg = '→ sigue pendiente (sin match por ahora).';
        echo '&nbsp;&nbsp;' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '<br><br>';
        $logger->log(
            'id_pending=' . (int) $idPending . ' sigue pendiente (sin match).',
            'INFO'
        );
        $stillPending++;
    }

    // pequeño respiro por si algún día esto crece
    usleep(50000); // 0.05s
}

echo '<hr>';
echo 'Total resueltos en esta pasada: ' . (int) $resolvedCount . '<br>';
echo 'Total que siguen pendientes: ' . (int) $stillPending . '<br>';

$logger->log(
    'Fin retry_pending_aliases. Resueltos=' . (int) $resolvedCount
    . ', siguen pendientes=' . (int) $stillPending
    . ', dry_run=' . (int) $dryRun,
    'INFO'
);