<?php
require dirname(__FILE__).'/../../../config/config.inc.php';
require dirname(__FILE__).'/../../../init.php';

require_once _PS_MODULE_DIR_.'frikimportproductos/classes/readers/KaractermaniaReader.php';
require_once _PS_MODULE_DIR_.'frikimportproductos/classes/CatalogImporter.php';
require_once _PS_ROOT_DIR_.'/classes/utils/LoggerFrik.php';

// https://lafrikileria.com/modules/frikimportproductos/cron/catalogo_karactermania.php?token=ewts91rr6fhagXdWHzHbB3qAUhxIvsu2 &debug=1

// --- Protección por token ---
$tokenEsperado = 'ewts91rr6fhagXdWHzHbB3qAUhxIvsu2';
if (!isset($_GET['token']) || $_GET['token'] !== $tokenEsperado) {
    die("Token incorrecto<br>");
}

// --- Modo debug (no inserta, solo muestra muestra de productos) ---
$debug = isset($_GET['debug']) && $_GET['debug'] == '1';

// El proceso se ejecutará una vez al día, descargando el catálogo completo de Karactermania,
// e insertando/actualizando las entradas en lafrips_productos_proveedores

$idSupplier = 53; // Karactermania
$logPath = _PS_MODULE_DIR_.'frikimportproductos/logs/karactermania/karactermania_import.log';

$logger = new LoggerFrik($logPath, true, 'sergio@lafrikileria.com');

try {
    $logger->log('=== Inicio cron catálogo Karactermania '.($debug ? 'DEBUG ' : '').'===', 'INFO', false);      
    echo "=== Inicio cron catálogo Karactermania ===<br>";
    echo "Modo debug: " . ($debug ? "SÍ" : "NO") . "<br><br>";

    $karactermania = new KaractermaniaReader($idSupplier, $logger); 

    // Paso 1: Descargar
    $file = $karactermania->fetch();
    if (!$file) {
        echo "Error en la descarga del catálogo<br>";
        $logger->log("Error en la descarga del catálogo Karactermania", 'ERROR');
        exit;
    }
    echo "Catálogo descargado en: $file<br>";

    // Paso 2: Validar cabecera
    if (!$karactermania->checkCatalogo($file)) {
        echo "Error en la validación de cabecera<br>";
        $logger->log("Error en la validación de cabecera del catálogo Karactermania", 'ERROR');
        exit;
    }
    echo "Cabecera validada correctamente<br>";

    // Paso 3: Parsear
    $productos = $karactermania->parse($file);
    $total = count($productos);
    echo "Parseados $total productos<br><br>";

    if ($total === 0) {
        echo "No se han obtenido productos del catálogo<br>";
        $logger->log("Karactermania: parse() devolvió 0 productos", 'WARNING');
        exit;
    }

    // --- MODO DEBUG: solo mostramos algunos productos y salimos ---
    if ($debug) {
        echo "=== MODO DEBUG: NO SE IMPORTA NADA EN BD ===<br><br>";

        $muestra = array_slice($productos, 0, 3);

        foreach ($muestra as $i => $p) {
            echo "---------- Producto " . ($i + 1) . " ----------<br>";
            foreach ($p as $key => $value) {
                if (is_array($value)) {
                    echo $key . ": " . print_r($value, true) . "<br>";
                } else {
                    echo $key . ": " . $value . "<br>";
                }
            }
            echo "<br>";
        }

        echo "=== FIN MODO DEBUG: revisar estructura y campos ===<br>";
        $logger->log(
            'Karactermania: MODO DEBUG, no se ha llamado a CatalogImporter->saveProducts()',
            'INFO'
        );
        exit;
    }

    // Paso 4: Insertar / actualizar en lafrips_productos_proveedores
    $importer = new CatalogImporter($idSupplier, $logger);
    $resultado = $importer->saveProducts($productos);

    echo "Importación terminada<br>";
    echo "Procesados:   {$resultado['procesados']}<br>";
    echo "Insertados:   {$resultado['insertados']}<br>";
    echo "Actualizados: {$resultado['actualizados']}<br>";
    echo "Errores:      {$resultado['errores']}<br>";
    echo "Eliminados:   {$resultado['eliminados']}<br>";
    echo "Ignorados:    {$resultado['ignorados']}<br>";

    $logger->log('Importación Karactermania terminada', 'INFO');
    $logger->log('Procesados:   '.$resultado['procesados'], 'INFO');
    $logger->log('Insertados:   '.$resultado['insertados'], 'INFO');
    $logger->log('Actualizados: '.$resultado['actualizados'], 'INFO');
    $logger->log('Errores:      '.$resultado['errores'], 'INFO');
    $logger->log('Eliminados:   '.$resultado['eliminados'], 'INFO');
    $logger->log('Ignorados:    '.$resultado['ignorados'], 'INFO');

    echo "=== Fin cron catálogo Karactermania ===<br>";
    $logger->log('=== Fin cron catálogo Karactermania ===', 'INFO');

} catch (Exception $e) {
    $msg = "Excepción en cron Karactermania: ".$e->getMessage();
    $logger->log($msg, 'ERROR');

    echo "Error: ".$e->getMessage()."<br>";
}
