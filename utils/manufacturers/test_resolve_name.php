<?php
// modules/frikimportproductos/utils/manufacturers/test_resolve_name.php

// https://test.lafrikileria.com/modules/frikimportproductos/utils/manufacturers/test_resolve_name.php

// Carga entorno Presta
require dirname(__FILE__) . '/../../../../config/config.inc.php';
require dirname(__FILE__) . '/../../../../init.php';

require_once _PS_MODULE_DIR_ . 'frikimportproductos/classes/ManufacturerAliasHelper.php';

header('Content-Type: text/html; charset=utf-8');

// Permitir probar un nombre suelto desde la URL:
// ?name=Funko,12127ec8d1&source=HEO
$nameFromGet   = Tools::getValue('name');
$sourceFromGet = Tools::getValue('source');

if ($nameFromGet) {
    $tests = array(
        array(
            'label'  => 'FROM GET',
            'name'   => $nameFromGet,
            'source' => $sourceFromGet ?: null,
        ),
    );
} else {
    // Lote de pruebas por defecto
    $tests = array(
        array('label' => 'Funko limpio',           'name' => 'Funko',                 'source' => null),
        array('label' => 'Funko con hash 1',       'name' => 'Funko,12127ec8d1',      'source' => null),
        array('label' => 'Funko con hash 2',       'name' => 'Funko,6eb1862d0a',      'source' => null),
        array('label' => 'Arcade 1Up',             'name' => 'Arcade 1Up',            'source' => null),
        array('label' => 'Beadle & Grimms',        'name' => 'Beadle & Grimms',       'source' => null),
        array('label' => 'Beast Kingdom Toys',     'name' => 'Beast Kingdom Toys',    'source' => null),
        array('label' => 'Nemesis',                'name' => 'Nemesis',               'source' => null),
        array('label' => 'Diamond',                'name' => 'Diamond',               'source' => null),
        array('label' => 'World\'s Smallest Toys', 'name' => "World's Smallest Toys", 'source' => null),
        array('label' => 'Maskfy',                 'name' => 'Maskfy',                'source' => null),
        array('label' => 'Icon Heroes',            'name' => 'Icon Heroes',           'source' => null),
    );
}

echo '<h1>Test ManufacturerAliasHelper::resolveName()</h1>';
if ($nameFromGet) {
    echo '<p><strong>Probando nombre desde GET:</strong> ' . htmlspecialchars($nameFromGet) .
         ' (source=' . htmlspecialchars($sourceFromGet) . ')</p>';
} else {
    echo '<p>Puedes probar un nombre concreto con:<br>
          <code>?name=Funko,12127ec8d1&amp;source=HEO</code></p>';
}

foreach ($tests as $t) {
    $label  = $t['label'];
    $name   = $t['name'];
    $source = $t['source'];

    echo '<hr>';
    echo '<h3>' . htmlspecialchars($label) . '</h3>';
    echo '<p><strong>rawName:</strong> ' . htmlspecialchars($name) . '<br>';
    echo '<strong>source:</strong> ' . htmlspecialchars((string)$source) . '</p>';

    $norm = ManufacturerAliasHelper::normalizeName($name);
    echo '<p><strong>normalized:</strong> <code>' . htmlspecialchars($norm) . '</code></p>';

    $idManufacturer = ManufacturerAliasHelper::resolveName($name, $source);

    if ($idManufacturer) {
        $manName = Db::getInstance()->getValue('
            SELECT name FROM ' . _DB_PREFIX_ . 'manufacturer
            WHERE id_manufacturer = ' . (int)$idManufacturer
        );

        echo '<p style="color:green;"><strong>RESULTADO:</strong> resuelto a ' .
             'id_manufacturer = <strong>' . (int)$idManufacturer . '</strong> ' .
             '("' . htmlspecialchars($manName) . '")</p>';

        // ¿Qué alias se ha generado para este rawName?
        $aliasRow = Db::getInstance()->getRow('
            SELECT *
            FROM ' . _DB_PREFIX_ . 'manufacturer_alias
            WHERE id_manufacturer = ' . (int)$idManufacturer . '
              AND normalized_alias = "' . pSQL($norm) . '"
            ORDER BY id_manufacturer_alias DESC            
        ');

        if ($aliasRow) {
            echo '<p><strong>Alias registrado:</strong><br>';
            echo 'id_manufacturer_alias = ' . (int)$aliasRow['id_manufacturer_alias'] . '<br>';
            echo 'alias = "' . htmlspecialchars($aliasRow['alias']) . '"<br>';
            echo 'normalized_alias = <code>' . htmlspecialchars($aliasRow['normalized_alias']) . '</code><br>';
            echo 'source = "' . htmlspecialchars($aliasRow['source']) . '"';
            echo '</p>';
        } else {
            echo '<p><em>No se ha encontrado alias en la tabla, pero la resolución ha devuelto un fabricante.</em></p>';
        }
    } else {
        echo '<p style="color:#c00;"><strong>RESULTADO:</strong> no se ha podido resolver (devuelve null).</strong></p>';

        // Miramos si se ha creado/actualizado un pending para este normalized + source
        $where = 'normalized_alias = "' . pSQL($norm) . '"';
        // if ($source !== null && $source !== '') {
        //     $where .= ' AND source = "' . pSQL($source) . '"';
        // } else {
        //     $where .= ' AND source IS NULL';
        // }

        $pending = Db::getInstance()->getRow('
            SELECT *
            FROM ' . _DB_PREFIX_ . 'manufacturer_alias_pending
            WHERE ' . $where . '
            ORDER BY id_pending DESC            
        ');

        if ($pending) {
            echo '<p><strong>Pending detectado:</strong><br>';
            echo 'id_pending = ' . (int)$pending['id_pending'] . '<br>';
            echo 'raw_name = "' . htmlspecialchars($pending['raw_name']) . '"<br>';
            echo 'times_seen = ' . (int)$pending['times_seen'] . '<br>';
            echo 'resolved = ' . (int)$pending['resolved'] . '<br>';
            echo '</p>';
        } else {
            echo '<p><em>No hay pending para este normalized_alias + source.</em></p>';
        }
    }
}

echo '<hr><p>Fin del test.</p>';
