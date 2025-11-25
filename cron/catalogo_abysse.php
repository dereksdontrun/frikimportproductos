<?php
require dirname(__FILE__).'/../../../config/config.inc.php';
require dirname(__FILE__).'/../../../init.php';

require_once _PS_MODULE_DIR_.'frikimportproductos/classes/readers/AbysseReader.php';
require_once _PS_MODULE_DIR_.'frikimportproductos/classes/CatalogImporter.php';
require_once _PS_ROOT_DIR_.'/classes/utils/LoggerFrik.php';

// Ejemplo de llamada:
// https://lafrikileria.com/modules/frikimportproductos/cron/catalogo_abysse.php?file=ABY_13_01_2025

// id_supplier de Abysse en tu tabla import_proveedores
$idSupplier = 14; // ABYSSE

// log separado para Abysse
$logger = new LoggerFrik(
    _PS_MODULE_DIR_.'frikimportproductos/logs/abysse/abysse_import.log',
    true,
    'sergio@lafrikileria.com'
);

// El catálogo de Abysse se sube manualmente a /frikimportproductos/import/abysse/
// y le pasamos en ?file=ABY_DD_MM_AAAA (sin .csv)
if (!isset($_GET['file'])) {
    echo "El proceso necesita un parámetro GET 'file' con el nombre del catálogo Abysse en formato ABY_DD_MM_AAAA\n";
    exit;
}

$catalogo = $_GET['file'];

// Validación básica del nombre 
$pattern = '/^ABY_[0-9]{2}_[0-1][0-9]_[0-9]{4}$/';
if (!preg_match($pattern, $catalogo)) {
    echo "El formato del nombre del archivo pasado como parámetro GET no es correcto (ABY_DD_MM_AAAA)\n";
    exit;
}

// Añadimos la extensión .csv si no viene
$nombreFichero = $catalogo;
if (substr($nombreFichero, -4) !== '.csv') {
    $nombreFichero .= '.csv';
}

try {
    // Paso 0: Instanciar reader de Abysse
    $abysse = new AbysseReader($idSupplier, $logger, $nombreFichero);

    // Paso 1: "Descargar" => en este caso, solo comprobar que el archivo existe
    $file = $abysse->fetch();
    if (!$file) {
        echo "Error: no se encontró el catálogo Abysse en la ruta configurada\n";
        exit;
    }
    echo "Catálogo Abysse encontrado en: $file\n";

    // Paso 2: Validar cabecera y localizar índices de columnas
    if (!$abysse->checkCatalogo($file)) {
        echo "Error en la validación de cabeceras del catálogo Abysse\n";
        exit;
    }
    echo "Cabecera del catálogo Abysse validada correctamente\n";

    // Paso 3: Parsear fichero a array normalizado
    $productos = $abysse->parse($file);
    echo "Parseados ".count($productos)." productos desde Abysse\n\n";

    // Paso 4: Insertar/actualizar en frik_import_catalogos vía CatalogImporter
    $importer  = new CatalogImporter($idSupplier, $logger);
    $resultado = $importer->saveProducts($productos);

    echo "Importación Abysse terminada\n";
    echo "Procesados: {$resultado['procesados']}\n";
    echo "Insertados: {$resultado['insertados']}\n";
    echo "Actualizados: {$resultado['actualizados']}\n";
    echo "Errores: {$resultado['errores']}\n";
    echo "Eliminados: {$resultado['eliminados']}\n";
    echo "Ignorados: {$resultado['ignorados']}\n";

} catch (Exception $e) {
    $logger->log("Excepción en cron Abysse: ".$e->getMessage(), 'ERROR');
    echo "Error: ".$e->getMessage()."\n";
}
