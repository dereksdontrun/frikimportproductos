<?php
require dirname(__FILE__).'/../../../config/config.inc.php';
require dirname(__FILE__).'/../../../init.php';

require_once _PS_MODULE_DIR_.'frikimportproductos/classes/readers/MegasurReader.php';
require_once _PS_MODULE_DIR_.'frikimportproductos/classes/CatalogImporter.php';
require_once _PS_ROOT_DIR_.'/classes/utils/LoggerFrik.php';

// https://lafrikileria.com/modules/frikimportproductos/cron/catalogo_megasur.php

//el proceso se ejecutará una vez al día, descargando el catálogo completo de Megasur, e insertando/actualizando las entradas en lafrips_productos_proveedores

$idSupplier = 181; // Megasur
$logger = new LoggerFrik(_PS_MODULE_DIR_.'frikimportproductos/logs/megasur/megasur_import.log', true, 'sergio@lafrikileria.com');

try {
    $megasur = new MegasurReader($idSupplier, $logger); 
    // Paso 1: Descargar
    $file = $megasur->fetch();
    if (!$file) {
        echo "Error en la descarga del catálogo\n";
        exit;
    }
    echo "Catálogo descargado en: $file\n";

    // Paso 2: Validar cabecera
    if (!$megasur->checkCatalogo($file)) {
        echo "Error en la validación de cabecera\n";
        exit;
    }
    echo "Cabecera validada correctamente\n";

    // Paso 3: Parsear
    $productos = $megasur->parse($file);
    echo "Parseados ".count($productos)." productos\n\n";    

    // insertar o updatear
    $importer = new CatalogImporter($idSupplier, $logger);
    $resultado = $importer->saveProducts($productos);

    echo "Importación terminada<br>";
    echo "Procesados: {$resultado['procesados']}<br>";
    echo "Insertados: {$resultado['insertados']}<br>";
    echo "Actualizados: {$resultado['actualizados']}<br>";
    echo "Errores: {$resultado['errores']}<br>";
    echo "Eliminados: {$resultado['eliminados']}<br>";
    echo "Ignorados: {$resultado['ignorados']}<br>";

} catch (Exception $e) {
    $logger->log("Excepción en cron Megasur: ".$e->getMessage(), 'ERROR');

    echo "Error: ".$e->getMessage();
}

