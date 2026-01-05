<?php
require dirname(__FILE__) . '/../../../../config/config.inc.php';
require dirname(__FILE__) . '/../../../../init.php';
require_once _PS_MODULE_DIR_ . 'frikimportproductos/classes/ManufacturerAliasHelper.php';

// https://test.lafrikileria.com/modules/frikimportproductos/utils/manufacturers/init_aliases_from_inactive.php

$db = Db::getInstance();

// Cargar todos los fabricantes (activos e inactivos)
$rows = $db->executeS('
    SELECT id_manufacturer, name, active
    FROM ' . _DB_PREFIX_ . 'manufacturer
');

$groups = array();

// Agrupar por nombre normalizado
foreach ($rows as $r) {
    $id = (int) $r['id_manufacturer'];
    $name = $r['name'];
    $active = (int) $r['active'];

    $norm = ManufacturerAliasHelper::normalizeName($name);
    if ($norm === '') {
        continue;
    }

    //si no existe, creamos el groups de key => $norm
    if (!isset($groups[$norm])) {
        $groups[$norm] = array(
            'activos' => array(),
            'desactivados' => array(),
        );
    }

    //guardamos cada id y nombre que coincidan con $norm en el array key => $norm, como activo o desactivado, según el caso
    if ($active) {
        $groups[$norm]['activos'][] = array('id' => $id, 'name' => $name);
    } else {
        $groups[$norm]['desactivados'][] = array('id' => $id, 'name' => $name);
    }
}


// arrays para diagnosticar casos no resueltos automáticamente
$only_inactive_groups = array();   // grupos con solo desactivados
$multi_active_groups = array();   // grupos con varios activos + desactivados
$cont_created = 0;
$cont_exists = 0;
$cont_invalid = 0;
$cont_errors = 0;

foreach ($groups as $norm => $data) {
    $activos = $data['activos'];
    $desactivados = $data['desactivados'];

    // Caso 1: solo desactivados (ningún activo con este normalized_name). No hay coincidencia, los procesamos luego
    if (empty($activos) && !empty($desactivados)) {
        $only_inactive_groups[$norm] = $desactivados;
        continue;
    }

    // Caso 2: varios activos con este norm + desactivados => conflicto, mejor a mano. Hay más de un fabricante activo que coincide con los desactivados, hay que mirar a mano, luego
    if (!empty($desactivados) && count($activos) > 1) {
        $multi_active_groups[$norm] = $data;
        continue;
    }

    // Caso "bueno": exactamente 1 activo y >=1 desactivados. Se escoge el activo como canónico, y el resto serán aliases de él
    if (!empty($desactivados) && count($activos) === 1) {
        $canonicalId = (int) $activos[0]['id'];
        $canonicalName = $activos[0]['name'];

        foreach ($desactivados as $d) {
            $aliasName = $d['name'];

            $result = ManufacturerAliasHelper::createAlias(
                $canonicalId,
                $aliasName,
                $norm,
                'INIT_INACTIVOS',
                0 // vienen de limpieza controlada
            );
            if ($result === 'created') {
                $cont_created++;
                echo 'Alias NUEVO: "' . $aliasName . '" -> #' . $canonicalId . ' (' . $canonicalName . ")<br>";
            } elseif ($result === 'exists') {
                $cont_exists++;
                echo 'Alias YA EXISTÍA: "' . $aliasName . '" -> #' . $canonicalId . ' (' . $canonicalName . ")<br>";
            } elseif ($result === 'invalid') {
                $cont_invalid++;
                echo 'Alias INVÁLIDO (no insertado): "' . $aliasName . '" (norm="' . $norm . '")<br>';
            } else { // error
                $cont_errors++;
                echo 'ERROR al crear alias: "' . $aliasName . '" -> #' . $canonicalId . ' (' . $canonicalName . ")<br>";
            }
        }
    }
}

echo "<br><strong>Resumen alias desde desactivados:</strong><br>";
echo "Creado(s): " . $cont_created . "<br>";
echo "Ya existentes: " . $cont_exists . "<br>";
echo "Inválidos: " . $cont_invalid . "<br>";
echo "Errores: " . $cont_errors . "<br><br>";

// ==============================
// Resumen de casos no resueltos
// ==============================
echo "<h3>Grupos SOLO con desactivados (sin activo con mismo normalized_name):</h3>";
if (empty($only_inactive_groups)) {
    echo "Ninguno.<br>";
} else {
    // Nos aseguramos de tener el helper cargado
    if (!class_exists('ManufacturerAliasHelper')) {
        require_once _PS_MODULE_DIR_ . 'frikimportproductos/classes/ManufacturerAliasHelper.php';
    }

    foreach ($only_inactive_groups as $norm => $desactivados) {
        echo 'Norm: <code>' . $norm . '</code><br>';
        foreach ($desactivados as $d) {
            $rawName = $d['name'];
            echo '&bull; [ID ' . (int) $d['id'] . '] ' . $rawName . '<br>';

            // Registrar en manufacturer_alias_pending para resolver luego en BO
            ManufacturerAliasHelper::registerPending(
                $rawName,
                $norm,
                'INIT_ONLY_INACTIVE' // source de diagnóstico para saber de dónde viene
            );

            // Sugerencias fuzzy de fabricantes ACTIVOS (solo info visual)
            $suggestions = ManufacturerAliasHelper::fuzzySuggestManufacturers($rawName, 0.4);

            if (!empty($suggestions)) {
                echo "&nbsp;&nbsp;Sugerencias activas:<br>";
                // mostramos solo las 3 mejores
                $top = array_slice($suggestions, 0, 3);
                foreach ($top as $s) {
                    echo '&nbsp;&nbsp;&nbsp;&nbsp;⮡ [ID '
                        . (int) $s['id_manufacturer'] . '] '
                        . pSQL($s['name'])
                        . ' (ratio: ' . round($s['ratio'], 2) . ")<br>";
                }
            } else {
                echo "&nbsp;&nbsp;Sin sugerencias claras.<br>";
            }
        }
        echo "<br>";
    }
}


echo "<h3>Grupos con VARIOS activos + desactivados (conflictivos):</h3>";
if (empty($multi_active_groups)) {
    echo "Ninguno.<br>";
} else {
    foreach ($multi_active_groups as $norm => $data) {
        echo 'Norm: <code>' . $norm . '</code><br>';
        echo "Activos:<br>";
        foreach ($data['activos'] as $a) {
            echo '&bull; [ID ' . (int) $a['id'] . '] ' . $a['name'] . '<br>';
        }
        echo "Desactivados:<br>";
        foreach ($data['desactivados'] as $d) {
            $rawName = $d['name'];
            echo '&bull; [ID ' . (int) $d['id'] . '] ' . $rawName . '<br>';

            // También podemos registrarlos como pending con otra etiqueta de origen
            ManufacturerAliasHelper::registerPending(
                $rawName,
                $norm,
                'INIT_MULTI_ACTIVE'
            );
        }
        echo "<br>";
    }
}
