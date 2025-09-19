<?php
require dirname(__FILE__).'/../../../config/config.inc.php';
require dirname(__FILE__).'/../../../init.php';

require_once _PS_MODULE_DIR_.'frikimportproductos/classes/readers/HeoReader.php';
require_once _PS_MODULE_DIR_.'frikimportproductos/classes/CatalogImporter.php';
require_once _PS_ROOT_DIR_.'/classes/utils/LoggerFrik.php';

// https://lafrikileria.com/modules/frikimportproductos/cron/test_heo.php

$idSupplier = 4; // HEO
$logger = new LoggerFrik(_PS_MODULE_DIR_.'frikimportproductos/logs/heo/heo_import.log', true, 'sergio@lafrikileria.com');

try {
    $heo = new HeoReader($idSupplier, $logger); 
    // Paso 1: Descargar
    $file = $heo->fetch();
    if (!$file) {
        echo "Error en la descarga del catálogo\n";
        exit;
    }
    echo "Catálogo descargado en: $file\n";

    // Paso 2: Validar cabecera
    if (!$heo->checkCatalogo($file)) {
        echo "Error en la validación de cabecera\n";
        exit;
    }
    echo "Cabecera validada correctamente\n";

    // Paso 3: Parsear
    $productos = $heo->parse($file);
    echo "Parseados ".count($productos)." productos\n\n";

     // Paso 4: Mostrar primeros 5 con key => value
    // foreach (array_slice($productos, 0, 5) as $i => $p) {
    // $fabricantes_sin_crear = [];
    // foreach ($productos as $i => $p) {
    //     echo "<strong>Producto ".($i+1)."</strong><br>";
    //     foreach ($p as $key => $value) {
    //         if (is_array($value)) {
    //             echo $key." => ".implode(", ", $value)."<br>";
    //         } else {
    //             echo $key." => ".$value."<br>";

    //             if ($key == 'id_manufacturer' && $value == 999) { //esto solo funciona si se modifica la función getManufacturerId() de HeoReader
    //                 $fabricantes_sin_crear[] = $p['manufacturer'];
    //             }
    //         }
    //     }
    //     echo "------------------------------------------<br><br>";
    // }

    // echo "------------------------------------------<br><br>";
    // echo "------------------------------------------<br><br>";
    // echo "productos con fabricante sin crear: ".count($fabricantes_sin_crear);
    // echo '<pre>';
    // print_r(array_unique($fabricantes_sin_crear));
    // echo '</pre>';
    // echo "------------------------------------------<br><br>";
    // echo "Total fabricante sin crear: ".count(array_unique($fabricantes_sin_crear));

    // insertar o updatear
    $importer = new CatalogImporter($idSupplier, $logger);
    $resultado = $importer->saveProducts($productos);

    echo "Importación terminada<br>";
    echo "Insertados: {$resultado['insertados']}<br>";
    echo "Actualizados: {$resultado['actualizados']}<br>";
    echo "Errores: {$resultado['errores']}<br>";

} catch (Exception $e) {
    $logger->log("Excepción en cron Heo: ".$e->getMessage(), 'ERROR');

    echo "Error: ".$e->getMessage();
}

