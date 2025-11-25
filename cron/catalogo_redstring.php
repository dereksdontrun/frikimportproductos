<?php
require dirname(__FILE__).'/../../../config/config.inc.php';
require dirname(__FILE__).'/../../../init.php';

require_once _PS_MODULE_DIR_.'frikimportproductos/classes/readers/RedstringReader.php';
require_once _PS_MODULE_DIR_.'frikimportproductos/classes/CatalogImporter.php';
require_once _PS_ROOT_DIR_.'/classes/utils/LoggerFrik.php';

// Ejemplo de llamada:
// https://lafrikileria.com/modules/frikimportproductos/cron/catalogo_redstring.php?token=GTESCg0xUmeqjXBKJfJpZr3jFv575AuL   &debug=1
// https://test.lafrikileria.com/modules/frikimportproductos/cron/catalogo_redstring.php?token=GTESCg0xUmeqjXBKJfJpZr3jFv575AuL   &debug=1

// Opcional: proteger con token
// Llamada: https://lafrikileria.com/modules/frikimportproductos/cron/catalogo_redstring.php?token=GTESCg0xUmeqjXBKJfJpZr3jFv575AuL
$tokenEsperado = 'GTESCg0xUmeqjXBKJfJpZr3jFv575AuL';
if (!isset($_GET['token']) || $_GET['token'] !== $tokenEsperado) {
    die('Token incorrecto');
}

// ¿Modo debug?
$debug = isset($_GET['debug']) && $_GET['debug'] == '1';

// id_supplier de Redstring
$idSupplier = 24;

// log separado para Abysse
$logger = new LoggerFrik(
    _PS_MODULE_DIR_.'frikimportproductos/logs/redstring/redstring_import.log',
    true,
    'sergio@lafrikileria.com'
);


try {
    $logger->log('=== Inicio cron catálogo Redstring combinado (servidor + web) '.($debug ? 'DEBUG' : '').' ===', 'INFO', false);
    echo "=== Inicio cron catálogo Redstring combinado (servidor + web) ===<br>";
    echo "Modo debug: ".($debug ? 'SÍ' : 'NO')."<br><br>";

    $reader = new RedstringReader($idSupplier, $logger);

    /*
     * 1) Descarga catálogo de costes (servidor FTP)
     */
    $fileServidor = $reader->fetchServidor();

    if (!$fileServidor) {
        $msg = "Error en la descarga del catálogo de servidor";
        echo $msg."<br>";
        $logger->log($msg, 'ERROR');
        exit;
    }
    echo "Catálogo servidor descargado en: $fileServidor<br>";

    /*
     * 2) Descarga catálogo web (stock / datos)
     */
    $fileWeb = $reader->fetchWeb();

    if (!$fileWeb) {
        $msg = "Error en la descarga del catálogo web";
        echo $msg."<br>";
        $logger->log($msg, 'ERROR');
        exit;
    }
    echo "Catálogo web descargado en: $fileWeb<br>";

    /*
     * 3) Parseo combinado: servidor + web + merge de costes
     *
     * parseCombinado() debe:
     *   - llamar internamente a checkCatalogoServidor($fileServidor)
     *   - llamar internamente a checkCatalogoWeb($fileWeb)
     *   - parsear servidor -> parseServidor()
     *   - construir mapa costes -> buildCostMap()
     *   - parsear web -> parseWeb()
     *   - aplicar costes -> aplicarCostesEnProductos()
     *   - devolver array de productos listo para CatalogImporter
     */
    $productos = $reader->parseCombinado($fileServidor, $fileWeb);

    $totalProductos = count($productos);

    echo "Productos combinados: ".$totalProductos."<br>";

    $logger->log('Redstring: productos combinados: '.$totalProductos, 'INFO');

    if ($totalProductos === 0) {
        $msg = "No hay productos que importar/actualizar";
        echo $msg."<br>";
        $logger->log('Redstring: parseCombinado() devolvió 0 productos', 'WARNING');
        exit;
    }

    /*
     * 4) MODO DEBUG: solo mostramos una muestra y salimos
     */
    if ($debug) {
        echo "<br>=== MODO DEBUG ACTIVADO: NO SE HACEN INSERTS/UPDATES ===<br><br>";

        // Mostramos los 3 primeros productos como muestra
        $muestra = array_slice($productos, 0, 3);

        foreach ($muestra as $idx => $p) {
            echo "---------- Producto ".($idx+1)." ----------<br>";

            // Para que sea más legible, mostramos clave => valor
            foreach ($p as $key => $value) {
                if (is_array($value)) {
                    echo $key.": ".print_r($value, true)."<br>";
                } else {
                    echo $key.": ".$value."<br>";
                }
            }

            echo "<br>";
        }

        echo "=== FIN MODO DEBUG: revisar estructura y campos ===<br>";
        $logger->log('Redstring: MODO DEBUG, no se ha llamado a CatalogImporter->saveProducts()', 'INFO');
        exit;
    }


    /*
     * 5) MODO NORMAL: Guardar en lafrips_productos_proveedores vía CatalogImporter
     */
    $importer  = new CatalogImporter($idSupplier, $logger);
    $resultado = $importer->saveProducts($productos);

    echo "Importación terminada<br>";
    echo "Procesados:   {$resultado['procesados']}<br>";
    echo "Insertados:   {$resultado['insertados']}<br>";
    echo "Actualizados: {$resultado['actualizados']}<br>";
    echo "Errores:      {$resultado['errores']}<br>";
    echo "Eliminados:   {$resultado['eliminados']}<br>";
    echo "Ignorados:    {$resultado['ignorados']}<br>";

    $logger->log('Importación Redstring terminada', 'INFO');
    $logger->log('Procesados:   '.$resultado['procesados'], 'INFO');
    $logger->log('Insertados:   '.$resultado['insertados'], 'INFO');
    $logger->log('Actualizados: '.$resultado['actualizados'], 'INFO');
    $logger->log('Errores:      '.$resultado['errores'], 'INFO');
    $logger->log('Eliminados:   '.$resultado['eliminados'], 'INFO');
    $logger->log('Ignorados:    '.$resultado['ignorados'], 'INFO');

    echo "=== Fin cron catálogo Redstring ===<br>";
    $logger->log('=== Fin cron catálogo Redstring ===', 'INFO');

} catch (Exception $e) {
    $msg = 'Excepción en cron Redstring: '.$e->getMessage();
    echo $msg."<br>";
    $logger->log($msg, 'ERROR');
}