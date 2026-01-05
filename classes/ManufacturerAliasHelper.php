<?php

/**
 * Helper para resolver nombres de fabricantes de catálogos
 * a un id_manufacturer de Prestashop, usando:
 *  - Tabla de alias
 *  - Normalización
 *  - Heurísticas (separadores, sufijos)
 *  - Si nada encaja, match final buscando todos los nombres de fabricante en Prestashop dentro del fabricante en catálogo, y viceversa
 */
/*
el flujo de resolución de nombre del catálogo debería ser:

Buscar primero nombre tal cual en lafrips_manufacturer.name.

Si nada:

buscar en manufacturer_alias.alias.

Si nada:

normalizar y buscar por normalized_alias.

Si nada:

heurística separadores (, ; : |).

heurística sufijos (“Toys”, “Co.”, “Ltd”…).

Si nada:

fuzzy “contains” entre normalizados (cache de fabricantes).

Si aún así nada:

mandarlo a pending y devolver null.
*/
class ManufacturerAliasHelper
{
    /**
     * Cache en memoria de fabricantes Presta normalizados
     * [
     *   [
     *     'id_manufacturer' => 1,
     *     'name'            => 'Funko',
     *     'normalized_name' => 'funko',
     *   ],
     *   ...
     * ]
     */
    protected static $manufacturersCache = null;

    /**
     * Punto de entrada principal.
     *
     * @param string      $rawName Nombre tal como llega del catálogo
     * @param string|null $source  Identificador del proveedor / fuente (opcional, ej: 'HEO', 'MEGASUR', etc.)
     *
     * @return int|null   id_manufacturer o null si no se ha podido resolver (se habrá registrado en pending)
     */
    public static function resolveName($rawName, $source = null)
    {
        $rawName = trim((string) $rawName);
        if ($rawName === '') {
            return null;
        }

        // 0) Buscar directamente en lafrips_manufacturer.name
        $id = self::findManufacturerByNameExact($rawName);
        if ($id) {
            // Nos aseguramos de que haya alias base
            $norm = self::normalizeName($rawName);
            self::createAlias($id, $rawName, $norm, $source, 0);
            return $id;
        }

        // 1) Buscar alias exacto (case-insensitive a nivel de SQL collation)
        $id = self::findByAlias($rawName, $source);
        if ($id) {
            return $id;
        }

        // 2) Buscar por normalized_alias
        $norm = self::normalizeName($rawName);
        if ($norm !== '') {
            $id = self::findByNormalizedAlias($norm, $source);
            if ($id) {
                return $id;
            }
        }

        // 3) Heurística: separar por delimitadores "raros" (coma, punto y coma, dos puntos, barra vertical...)
        //    Ej: "Funko,12127ec8d1" -> "Funko"
        $beforeSep = self::extractBeforeSeparator($rawName);
        if ($beforeSep && $beforeSep !== $rawName) {
            // 3.1 Alias exacto de la parte "limpia"
            $id = self::findByAlias($beforeSep, $source);
            if ($id) {
                // Creamos alias para el nombre sucio completo y salimos
                self::createAlias($id, $rawName, $norm, $source, 1);
                return $id;
            }

            // 3.2 Fabricante Presta por nombre exacto (o LIKE)
            $id = self::findManufacturerByName($beforeSep);
            if ($id) {
                self::createAlias($id, $rawName, $norm, $source, 1);
                return $id;
            }
        }

        // 4) Heurística: quitar sufijos genéricos ("Toys", "Co.", "Ltd", "GmbH", etc.)
        //    Ej: "Beast Kingdom Toys" -> "Beast Kingdom"
        $stripped = self::stripGenericSuffixes($rawName);
        if ($stripped && $stripped !== $rawName) {
            // 4.1 Alias exacto con el nombre base
            $id = self::findByAlias($stripped, $source);
            if ($id) {
                self::createAlias($id, $rawName, $norm, $source, 1);
                return $id;
            }

            // 4.2 Fabricante Presta por nombre base
            $id = self::findManufacturerByName($stripped);
            if ($id) {
                self::createAlias($id, $rawName, $norm, $source, 1);
                return $id;
            }
        }

        // 5) Fuzzy match final entre el nombre normalizado del catálogo
        //    y el normalized_name de TODOS los fabricantes de Presta
        $id = self::fuzzyMatchManufacturer($rawName);
        if ($id) {
            // Creamos alias para no repetir este trabajo la próxima vez
            self::createAlias($id, $rawName, $norm, $source, 1);
            return $id;
        }

        // 6) Si nada ha funcionado, lo registramos en pending
        self::registerPending($rawName, $norm, $source);
        return null;
    }

    /* ============================================================
     *  NORMALIZACIÓN
     * ============================================================
     */

    /**
     * Normaliza el nombre:
     *  - trim
     *  - minúsculas
     *  - quita acentos
     *  - reemplaza & por "and"
     *  - elimina símbolos raros
     *  - se queda solo con [a-z0-9]
     */
    public static function normalizeName($name)
    {
        $name = Tools::strtolower(trim((string) $name));
        if ($name === '') {
            return '';
        }

        //sustituimos las letras con acento por su equivalenete sin el
        $name = Tools::replaceAccentedChars($name);

        //cambiamos & por and
        $name = strtr($name, array(
            '&' => 'and',
        ));

        //quitamos todo lo que no sean letras o números
        $name = preg_replace('/[^a-z0-9]+/', '', $name);

        return $name;
    }

    /* ============================================================
     *  BÚSQUEDAS EN TABLA DE ALIAS
     * ============================================================
     */

    /**
     * Busca nombre exacto en tabla oficial de Prestashop.
     *
     * @return int|null id_manufacturer o null
     */
    public static function findManufacturerByNameExact($name)
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $id = Db::getInstance()->getValue('
        SELECT id_manufacturer
        FROM ' . _DB_PREFIX_ . 'manufacturer
        WHERE name = "' . pSQL($name) . '"
          AND active = 1        
    ');

        return $id ? (int) $id : null;
    }

    /**
     * Busca alias exacto (campo alias), opcionalmente filtrando por source.
     *
     * @return int|null id_manufacturer o null
     */
    public static function findByAlias($alias, $source = null)
    {
        $alias = trim((string) $alias);
        if ($alias === '') {
            return null;
        }

        $sql = 'SELECT id_manufacturer
                FROM ' . _DB_PREFIX_ . 'manufacturer_alias
                WHERE alias = "' . pSQL($alias) . '"
                  AND active = 1';

        if ($source !== null && $source !== '') {
            // alias específico de una fuente o global (NULL)
            $sql .= ' AND (source = "' . pSQL($source) . '" OR source IS NULL OR source = "")';
        }

        // $sql .= ' ORDER BY id_manufacturer_alias ASC';

        $id = Db::getInstance()->getValue($sql);
        return $id ? (int) $id : null;
    }

    /**
     * Busca por normalized_alias, opcionalmente filtrando por source.
     *
     * @return int|null id_manufacturer o null
     */
    public static function findByNormalizedAlias($norm, $source = null)
    {
        $norm = trim((string) $norm);
        if ($norm === '') {
            return null;
        }

        $sql = 'SELECT id_manufacturer
                FROM ' . _DB_PREFIX_ . 'manufacturer_alias
                WHERE normalized_alias = "' . pSQL($norm) . '"
                  AND active = 1';

        if ($source !== null && $source !== '') {
            $sql .= ' AND (source = "' . pSQL($source) . '" OR source IS NULL OR source = "")';
        }

        // $sql .= ' ORDER BY id_manufacturer_alias ASC';

        $id = Db::getInstance()->getValue($sql);
        return $id ? (int) $id : null;
    }

    /**
     * Crea un alias nuevo si no existe ya ese normalized_alias para ese fabricante.
     *
     * @param int         $idManufacturer
     * @param string      $alias
     * @param string|null $norm
     * @param string|null $source
     * @param int         $autoCreated 1 si es alias creado automáticamente
     */
    public static function createAlias($idManufacturer, $alias, $norm = null, $source = null, $autoCreated = 0)
    {
        $idManufacturer = (int) $idManufacturer;
        if ($idManufacturer <= 0) {
            return 'invalid';
        }

        $alias = trim((string) $alias);
        if ($alias === '') {
            return 'invalid';
        }

        $norm = $norm !== null ? $norm : self::normalizeName($alias);

        // Evitar duplicados simples: ¿ya existe este normalized_alias para este fabricante? Si id_manufacturer canónico es igual, y nombre normalizado es igual, y el alias (nombre en catálogo) es igual, no hacemos nada. Pero si alias es diferente, lo almacenamos, podríamos ignorarlo también porque al tener el nombre normalizado, cuando busquemos el fabricnate por su alias ya obtendría respuesta.
        $exists = Db::getInstance()->getValue('
            SELECT id_manufacturer_alias
            FROM ' . _DB_PREFIX_ . 'manufacturer_alias
            WHERE id_manufacturer = ' . (int) $idManufacturer . '
              AND normalized_alias = "' . pSQL($norm) . '"
              AND alias = "' . pSQL($alias) . '"            
        ');

        if ($exists) {
            return 'exists';
        }

        $ok = Db::getInstance()->insert(
            'manufacturer_alias',
            array(
                'id_manufacturer' => $idManufacturer,
                'alias' => pSQL($alias),
                'normalized_alias' => pSQL($norm),
                'source' => $source !== null ? pSQL($source) : null,
                'auto_created' => (int) $autoCreated,
                'active' => 1,
                'date_add' => date('Y-m-d H:i:s'),
                'date_upd' => date('Y-m-d H:i:s'),
            )
        );

        if (!$ok) {
            return 'error';
        }

        return 'created';
    }

    /* ============================================================
     *  TABLA PENDING
     * ============================================================
     */

    /**
     * Registra en tabla de pendientes un alias no resuelto.
     * Si ya existe para ese normalized_alias + source, incrementa times_seen.
     */
    public static function registerPending($rawName, $norm, $source = null)
    {
        $rawName = trim((string) $rawName);
        $norm = trim((string) $norm);
        $source = trim((string) $source);

        if ($rawName === '') {
            return;
        }

        if ($norm === '') {
            //  decidir no registrar nada en pending en este caso
            return;
        }

        $pending = Db::getInstance()->getRow('
            SELECT id_pending, source
            FROM ' . _DB_PREFIX_ . 'manufacturer_alias_pending
            WHERE normalized_alias = "' . pSQL($norm) . '"        
        ');

        $now = date('Y-m-d H:i:s');

        if ($pending) {
            // Fusionar sources (informativo)
            $mergedSource = self::mergeSources($pending['source'], $source);

            Db::getInstance()->update(
                'manufacturer_alias_pending',
                array(
                    'raw_name' => pSQL($rawName), // último visto
                    'source' => $mergedSource !== '' ? pSQL($mergedSource) : null,
                    'times_seen' => array('type' => 'sql', 'value' => 'times_seen+1'),
                    'date_upd' => $now,
                ),
                'id_pending = ' . (int) $pending['id_pending']
            );
        } else {
            Db::getInstance()->insert(
                'manufacturer_alias_pending',
                array(
                    'raw_name' => pSQL($rawName),
                    'normalized_alias' => pSQL($norm),
                    'source' => $source !== '' ? pSQL($source) : null,
                    'times_seen' => 1,
                    'resolved' => 0,
                    'id_manufacturer' => null,
                    'date_add' => date('Y-m-d H:i:s'),
                    'date_upd' => date('Y-m-d H:i:s'),
                )
            );
        }
    }

    /**
     * Fusiona sources en una lista única separada por comas.
     * Ej:
     *   mergeSources('HEO', 'MEGASUR') => 'HEO, MEGASUR'
     *   mergeSources('HEO, MEGASUR', 'HEO') => 'HEO, MEGASUR'
     */
    protected static function mergeSources($existing, $incoming)
    {
        $existing = trim((string) $existing);
        $incoming = trim((string) $incoming);

        // Si no viene source nuevo, devolvemos lo que ya hubiera
        if ($incoming === '') {
            return $existing;
        }

        // Si no había ninguno antes, devolvemos solo el nuevo
        if ($existing === '') {
            return $incoming;
        }

        // Normalizamos a array
        $parts = array_map('trim', explode(',', $existing));
        $parts = array_filter($parts, function ($v) {
            return $v !== '';
        });

        // ¿Ya existe?
        if (!in_array($incoming, $parts, true)) {
            $parts[] = $incoming;
        }

        return implode(', ', $parts);
    }


    /* ============================================================
     *  FABRICANTES PRESTA: CARGA Y BÚSQUEDAS
     * ============================================================
     */

    /**
     * Carga en memoria todos los fabricantes de Prestashop
     * con su nombre y nombre normalizado (una sola vez por ejecución).
     */
    public static function loadManufacturersCache()
    {
        if (self::$manufacturersCache !== null) {
            return self::$manufacturersCache;
        }

        $rows = Db::getInstance()->executeS('
            SELECT id_manufacturer, name
            FROM ' . _DB_PREFIX_ . 'manufacturer
            WHERE active = 1
        ');

        if ($rows === false) {
            // Opcional: log/debug
            // die('SQL error: '.Db::getInstance()->getMsgError());
            self::$manufacturersCache = array();
            return self::$manufacturersCache;
        }

        $cache = array();
        foreach ($rows as $r) {
            $cache[] = array(
                'id_manufacturer' => (int) $r['id_manufacturer'],
                'name' => $r['name'],
                'normalized_name' => self::normalizeName($r['name']),
            );
        }

        self::$manufacturersCache = $cache;
        return self::$manufacturersCache;
    }

    /**
     * Busca fabricante por nombre "humano" (primero exacto, luego LIKE).
     *
     * @return int|null id_manufacturer o null
     */
    public static function findManufacturerByName($name)
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        // 1) Exacto
        $id = Db::getInstance()->getValue('
            SELECT id_manufacturer
            FROM ' . _DB_PREFIX_ . 'manufacturer
            WHERE name = "' . pSQL($name) . '"        
            AND active = 1    
        ');

        if ($id) {
            return (int) $id;
        }

        // 2) LIKE "name%" por si hay pequeñas variaciones
        $id = Db::getInstance()->getValue('
            SELECT id_manufacturer
            FROM ' . _DB_PREFIX_ . 'manufacturer
            WHERE name LIKE "' . pSQL($name) . '%"       
            AND active = 1     
        ');

        return $id ? (int) $id : null;
    }

    /* ============================================================
     *  HEURÍSTICAS: SEPARADORES Y SUFIJOS
     * ============================================================
     */

    /**
     * Extrae la parte antes de un separador raro (coma, punto y coma, dos puntos, barra vertical...)
     * Ej: "Funko,12127ec8d1" -> "Funko"
     *
     * @param string $rawName
     * @return string|null
     */
    public static function extractBeforeSeparator($rawName)
    {
        $rawName = (string) $rawName;
        if ($rawName === '') {
            return null;
        }

        // Cualquier primer carácter de esta lista se considera separador "basura"
        $separators = array(',', ';', ':', '|');

        $pos = false;
        foreach ($separators as $sep) {
            $p = strpos($rawName, $sep);
            if ($p !== false && ($pos === false || $p < $pos)) {
                $pos = $p;
            }
        }

        if ($pos === false) {
            return null;
        }

        $before = trim(substr($rawName, 0, $pos));
        return $before !== '' ? $before : null;
    }

    /**
     * Quita sufijos genéricos típicos de fabricantes / empresas,
     * solo al final, y puede quitar varios seguidos.
     *
     * Ej:
     *  "Beast Kingdom Toys" -> "Beast Kingdom"
     *  "Algo Co. Ltd"       -> "Algo"
     */
    public static function stripGenericSuffixes($name)
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }

        $suffixes = array(
            'toys',
            'toy',
            'co',
            'co.',
            'company',
            'companies',
            'intl',
            'international',
            'gmbh',
            'srl',
            'sl',
            's.l.',
            'sa',
            's.a.',
            'sas',
            's.a.s.',
            'bv',
            'bvba',
            'inc',
            'inc.',
            'corp',
            'corp.',
            'corporation',
            'ltd',
            'ltd.',
            'ltda',
            'spa',
            's.p.a.',
            'studio',
            'studios',
        );

        $parts = preg_split('/\s+/', $name);
        if (!$parts || count($parts) < 2) {
            return $name;
        }

        while (count($parts) > 1) {
            $last = Tools::strtolower(trim(end($parts)));
            $cleanLast = rtrim($last, '.');

            if (in_array($last, $suffixes) || in_array($cleanLast, $suffixes)) {
                array_pop($parts);
                continue;
            }
            break;
        }

        $stripped = trim(implode(' ', $parts));
        return $stripped === '' ? $name : $stripped;
    }

    /* ============================================================
     *  FUZZY MATCH FINAL
     * ============================================================
     */

    /**
     * Como último recurso, intentamos casar el normalized del catálogo
     * con el normalized_name de TODOS los fabricantes, mediante "contains".
     *
     * @param string $rawName
     * @return int|null id_manufacturer o null
     */
    public static function fuzzyMatchManufacturer($rawName)
    {
        $normCatalog = self::normalizeName($rawName);
        if ($normCatalog === '') {
            return null;
        }

        $manufacturers = self::loadManufacturersCache();
        if (!$manufacturers) {
            return array();
        }

        $bestId = null;
        $bestScore = 0.0;

        foreach ($manufacturers as $m) {
            $normPresta = $m['normalized_name'];
            if ($normPresta === '') {
                continue;
            }

            // ¿Alguno contiene al otro?
            if (
                strpos($normCatalog, $normPresta) === false &&
                strpos($normPresta, $normCatalog) === false
            ) {
                continue;
            }

            $lenCat = Tools::strlen($normCatalog);
            $lenPrest = Tools::strlen($normPresta);
            $shorter = min($lenCat, $lenPrest);
            $longer = max($lenCat, $lenPrest);

            if ($longer == 0) {
                continue;
            }

            $ratio = $shorter / $longer; // 0..1

            // Evitar matches ridículos tipo "dc" en "dccomics"
            if ($shorter < 4) {
                continue;
            }
            if ($ratio < 0.6) { // ajustable tras ver casos reales
                continue;
            }

            if ($ratio > $bestScore) {
                $bestScore = $ratio;
                $bestId = (int) $m['id_manufacturer'];
            }
        }

        return $bestId ?: null;
    }

    /**
     * Devuelve sugerencias de fabricantes en base a un nombre "sucio" de catálogo.
     *
     * @param string $rawName   Nombre tal como viene en catálogo.
     * @param float  $minRatio  Ratio mínimo "genérico" para coincidencias normales (0..1).
     *
     * @return array [
     *   ['id_manufacturer' => int, 'name' => string, 'ratio' => float],
     *   ...
     * ]
     */
    public static function fuzzySuggestManufacturers($rawName, $minRatio = 0.5)
    {
        $normCatalog = self::normalizeName($rawName);
        if ($normCatalog === '') {
            return array();
        }

        $manufacturers = self::loadManufacturersCache(); // solo activos
        if (!$manufacturers) {
            return array();
        }

        // echo 'hola<pre>';
        // print_r($manufacturers);
        // echo '</pre>';

        $candidates = array();

        foreach ($manufacturers as $m) {
            $normPresta = $m['normalized_name'];
            if ($normPresta === '') {
                continue;
            }

            // ¿Alguno contiene al otro?
            if (
                strpos($normCatalog, $normPresta) === false &&
                strpos($normPresta, $normCatalog) === false
            ) {
                continue;
            }

            $lenCat = Tools::strlen($normCatalog);
            $lenPrest = Tools::strlen($normPresta);
            $shorter = min($lenCat, $lenPrest);
            $longer = max($lenCat, $lenPrest);

            if ($longer == 0) {
                continue;
            }

            $ratio = $shorter / $longer; // 0..1

            // Evitar matches ridículos tipo "dc" en "dccomics"
            if ($shorter < 3) {
                continue;
            }

            // ¿Hay match claro por prefijo? Buscamos si la coincidencia es al inicio del nombre, no solo que la contenga
            $isPrefixMatch =
                (strpos($normCatalog, $normPresta) === 0) ||
                (strpos($normPresta, $normCatalog) === 0);

            if ($isPrefixMatch) {
                // Para prefijos (casos "funkoXXXXXXXX"), aceptamos ratios más bajos
                // Ajusta el 0.25 si quieres ser más o menos agresivo
                if ($ratio < 0.25) {
                    continue;
                }
            } elseif ($shorter <= 4) {// Ajuste: para nombres muy cortos, aceptamos ratio algo más bajo. Como esta función no hace cambios, solo sugiere posibilidades, bajamos el ratio, 0.2
                if ($ratio < 0.2) {
                    continue;
                }
            } else {
                if ($ratio < $minRatio) { // p.ej. 0.5
                    continue;
                }
            }

            $candidates[] = array(
                'id_manufacturer' => (int) $m['id_manufacturer'],
                'name' => $m['name'],
                'ratio' => $ratio,
            );
        }

        // Ordenamos mejor coincidencia primero
        usort($candidates, function ($a, $b) {
            if ($a['ratio'] == $b['ratio']) {
                return 0;
            }
            return ($a['ratio'] > $b['ratio']) ? -1 : 1;
        });

        return $candidates;
    }

}
