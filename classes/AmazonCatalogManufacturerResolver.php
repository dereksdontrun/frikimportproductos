<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Clase para consultar Amazon Catalog API por EAN
 * y guardar resultados de fabricante / marca en la tabla
 * lafrips_manufacturer_amazon_lookup.
 *
 * Integra con ManufacturerAliasHelper para intentar resolver
 * el id_manufacturer de Prestashop automáticamente.
 */
class AmazonCatalogManufacturerResolver
{
    /**
     * Punto de entrada principal.
     *
     * @param int         $limit         Nº máximo de productos a procesar en esta pasada
     * @param bool        $dryRun        Si true, no escribe en BD (solo log)
     * @param string      $marketplaceId Marketplace de Amazon (por defecto ES)
     * @param LoggerFrik  $logger        Logger opcional
     */
    public static function resolveMissingManufacturers(
        $limit = 50,
        $dryRun = false,
        $marketplaceId = 'A1RKKUPIHCS9HS',
        $idManufacturerFilter = 0,
        LoggerFrik $logger = null,
        CsvLoggerFrik $csvLogger = null
    ) {
        $db = Db::getInstance();

        // 1) Buscar productos a resolver.       
        $whereMf = '';
        if ($idManufacturerFilter > 0) {
            $whereMf = ' AND p.id_manufacturer = ' . (int) $idManufacturerFilter . ' ';
        }

        $sql = '
            SELECT 
                p.id_product,
                NULL AS id_product_attribute,
                p.reference,
                pl.name AS product_name,
                p.ean13,
                p.id_manufacturer AS id_manufacturer_current,
                IFNULL(m.name, "SIN FABRICANTE") AS manufacturer_current_name
            FROM ' . _DB_PREFIX_ . 'product p
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl
                ON (pl.id_product = p.id_product AND pl.id_lang = 1)
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m
                ON (m.id_manufacturer = p.id_manufacturer)
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer_amazon_lookup aml
                ON (
                    aml.id_product = p.id_product
                    AND aml.id_product_attribute IS NULL
                    AND aml.marketplace_id = "' . pSQL($marketplaceId) . '"
                )
            WHERE p.ean13 <> ""              
              AND aml.id_manufacturer_amazon_lookup IS NULL
              ' . $whereMf . '
            ORDER BY p.id_product ASC
            LIMIT ' . (int) $limit;

        $rows = $db->executeS($sql);

        if (!$rows) {
            if ($logger) {
                $logger->log('No hay productos sin fabricante y sin lookup en Amazon para procesar.', 'INFO');
            }
            return;
        }

        if ($logger) {
            $logger->log(
                'Encontrados ' . count($rows) . ' productos para resolver fabricante con Amazon (dry_run=' . (int) $dryRun . ')',
                'INFO'
            );
        }

        // 2) Obtener access token una sola vez
        $accessToken = self::getAccessToken($logger);
        if (!$accessToken) {
            if ($logger) {
                $logger->log('No se ha podido obtener access_token de Amazon. Abortando.', 'ERROR');
            }
            return;
        }

        // Aseguramos que el helper de fabricantes está cargado
        if (!class_exists('ManufacturerAliasHelper')) {
            require_once _PS_MODULE_DIR_ . 'frikimportproductos/classes/ManufacturerAliasHelper.php';
        }

        foreach ($rows as $row) {
            $idProduct = (int) $row['id_product'];
            $idProductAttribute = !empty($row['id_product_attribute']) ? (int) $row['id_product_attribute'] : null;
            $ean = trim($row['ean13']);
            $ref = $row['reference'];
            $prodName = $row['product_name'];
            $idMfCur = (int) $row['id_manufacturer_current'];
            $mfCurName = $row['manufacturer_current_name'];

            if ($logger) {
                $logger->log(
                    'Procesando id_product=' . $idProduct . ' (id_product_attribute='
                    . ($idProductAttribute ?: 'NULL') . '), EAN=' . $ean,
                    'INFO'
                );
            }

            // ====================================
            // Caso seguridad: sin EAN
            // ====================================
            if ($ean === '') {
                $statusInfo = array(
                    'status' => 'no_ean',
                    'error_message' => 'Producto sin EAN',
                    'raw_json' => null,
                    'asin' => null,
                    'raw_brand' => null,
                    'raw_manufacturer' => null,
                    'id_manufacturer_resolved' => null,
                    'resolved_from' => 'none',
                );

                // CSV siempre
                if ($csvLogger instanceof CsvLoggerFrik) {
                    $csvLogger->addRow(array(
                        'id_product' => $idProduct,
                        'reference' => $ref,
                        'product_name' => $prodName,
                        'ean13' => '',
                        'id_manufacturer_current' => $idMfCur,
                        'manufacturer_current_name' => $mfCurName,
                        'raw_manufacturer' => '',
                        'raw_brand' => '',
                        'asin' => '',
                        'status' => 'no_ean',
                        'resolved_from' => 'none',
                        'id_manufacturer_resolved' => '',
                        'marketplace_id' => $marketplaceId,
                        'error_message' => 'Producto sin EAN',
                        'dry_run' => (int) $dryRun,
                    ));
                }

                if (!$dryRun) {
                    self::logLookup($row, $idProductAttribute, $statusInfo, $marketplaceId);
                }

                continue;
            }

            try {
                // 3) Llamar a Amazon para ese EAN
                $apiResult = self::fetchCatalogItemByEan($ean, $marketplaceId, $accessToken, $logger);
                // $apiResult = ['found','asin','brand','manufacturer','raw_json','rate_limited']

                // Si la API nos ha limitado (HTTP 429), NO tocamos BD para este producto,
                // y cortamos el lote. En la siguiente ejecución este producto seguirá
                // sin lookup y volverá a entrar de forma natural.
                if (!empty($apiResult['rate_limited'])) {
                    if ($logger) {
                        $logger->log(
                            'Rate limit detectado en resolveMissingManufacturers para id_product=' . $idProduct .
                            '. Se detiene el procesamiento del resto del lote para reintentar en la siguiente pasada.',
                            'WARNING'
                        );
                    }
                    break; // salimos del foreach
                }


                $statusInfo = array(
                    'status' => '',
                    'error_message' => null,
                    'raw_json' => $apiResult['raw_json'],
                    'asin' => $apiResult['asin'],
                    'raw_brand' => $apiResult['brand'],
                    'raw_manufacturer' => $apiResult['manufacturer'],
                    'id_manufacturer_resolved' => null,
                    'resolved_from' => 'none',
                );

                // ====================================
                // 3.1 No encontrado en Amazon
                // ====================================
                if (!$apiResult['found']) {
                    $statusInfo['status'] = 'not_found';
                    $statusInfo['error_message'] = 'Producto no encontrado en Amazon Catalog';

                    if ($logger) {
                        $logger->log(
                            'EAN ' . $ean . ' no encontrado en Amazon Catalog.',
                            'WARNING'
                        );
                    }

                    if ($csvLogger instanceof CsvLoggerFrik) {
                        $csvLogger->addRow(array(
                            'id_product' => $idProduct,
                            'reference' => $ref,
                            'product_name' => $prodName,
                            'ean13' => $ean,
                            'id_manufacturer_current' => $idMfCur,
                            'manufacturer_current_name' => $mfCurName,
                            'raw_manufacturer' => '',
                            'raw_brand' => '',
                            'asin' => '',
                            'status' => 'not_found',
                            'resolved_from' => 'none',
                            'id_manufacturer_resolved' => '',
                            'marketplace_id' => $marketplaceId,
                            'error_message' => 'Producto no encontrado en Amazon Catalog',
                            'dry_run' => (int) $dryRun,
                        ));
                    }

                    if (!$dryRun) {
                        self::logLookup($row, $idProductAttribute, $statusInfo, $marketplaceId);
                    }

                    usleep(200000);
                    continue;
                }

                // ====================================
                // 4) Intentar resolver fabricante Presta
                // ====================================
                $resolvedId = null;
                $resolvedFrom = 'none';

                if (!empty($apiResult['manufacturer'])) {
                    $resolvedId = ManufacturerAliasHelper::resolveName(
                        $apiResult['manufacturer'],
                        'MANUFACTURER_AMAZON'
                    );
                    if ($resolvedId) {
                        $resolvedFrom = 'manufacturer';
                    }
                }

                if (!$resolvedId && !empty($apiResult['brand'])) {
                    $resolvedId = ManufacturerAliasHelper::resolveName(
                        $apiResult['brand'],
                        'AMAZON_BRAND'
                    );
                    if ($resolvedId) {
                        $resolvedFrom = 'brand';
                    }
                }

                if ($resolvedId) {
                    $statusInfo['status'] = 'resolved';
                    $statusInfo['id_manufacturer_resolved'] = (int) $resolvedId;
                    $statusInfo['resolved_from'] = $resolvedFrom;

                    if ($logger) {
                        $logger->log(
                            'EAN ' . $ean . ' resuelto a id_manufacturer=' . $resolvedId . ' (from=' . $resolvedFrom . ')',
                            'INFO'
                        );
                    }
                } else {
                    $statusInfo['status'] = 'pending';
                    $statusInfo['error_message'] = 'No se pudo resolver fabricante con alias helper';

                    if ($logger) {
                        $logger->log(
                            'EAN ' . $ean . ' con manufacturer="' . $apiResult['manufacturer']
                            . '" y brand="' . $apiResult['brand']
                            . '" sin match en ManufacturerAliasHelper -> marcado como pending',
                            'INFO'
                        );
                    }
                }

                // ====================================
                // 5) CSV
                // ====================================
                if ($csvLogger instanceof CsvLoggerFrik) {
                    $csvLogger->addRow(array(
                        'id_product' => $idProduct,
                        'reference' => $ref,
                        'product_name' => $prodName,
                        'ean13' => $ean,
                        'id_manufacturer_current' => $idMfCur,
                        'manufacturer_current_name' => $mfCurName,
                        'raw_manufacturer' => $apiResult['manufacturer'],
                        'raw_brand' => $apiResult['brand'],
                        'asin' => $apiResult['asin'],
                        'status' => $statusInfo['status'],
                        'resolved_from' => $statusInfo['resolved_from'],
                        'id_manufacturer_resolved' => $statusInfo['id_manufacturer_resolved'] ?: '',
                        'marketplace_id' => $marketplaceId,
                        'error_message' => $statusInfo['error_message'] ?: '',
                        'dry_run' => (int) $dryRun,
                    ));
                }

                // ====================================
                // 6) Escritura BD (si no dry-run)
                // ====================================
                if ($dryRun) {
                    if ($logger) {
                        $logger->log(
                            'DRY-RUN: Resultado lookup para id_product=' . $idProduct . ' -> '
                            . json_encode($statusInfo),
                            'DEBUG'
                        );
                    }
                } else {
                    self::logLookup($row, $idProductAttribute, $statusInfo, $marketplaceId);
                }

                usleep(200000);

            } catch (Exception $e) {
                if ($logger) {
                    $logger->log(
                        'Error procesando id_product=' . $idProduct . ' EAN=' . $ean . ' -> ' . $e->getMessage(),
                        'ERROR'
                    );
                }

                $statusInfo = array(
                    'status' => 'error',
                    'error_message' => $e->getMessage(),
                    'raw_json' => null,
                    'asin' => null,
                    'raw_brand' => null,
                    'raw_manufacturer' => null,
                    'id_manufacturer_resolved' => null,
                    'resolved_from' => 'none',
                );

                if ($csvLogger instanceof CsvLoggerFrik) {
                    $csvLogger->addRow(array(
                        'id_product' => $idProduct,
                        'reference' => $ref,
                        'product_name' => $prodName,
                        'ean13' => $ean,
                        'id_manufacturer_current' => $idMfCur,
                        'manufacturer_current_name' => $mfCurName,
                        'raw_manufacturer' => '',
                        'raw_brand' => '',
                        'asin' => '',
                        'status' => 'error',
                        'resolved_from' => 'none',
                        'id_manufacturer_resolved' => '',
                        'marketplace_id' => $marketplaceId,
                        'error_message' => $e->getMessage(),
                        'dry_run' => (int) $dryRun,
                    ));
                }

                if (!$dryRun) {
                    self::logLookup($row, $idProductAttribute, $statusInfo, $marketplaceId);
                }
            }
        }
    }

    /* ============================================================
     *  Credenciales y access token
     * ============================================================
     */

    /**
     * Lee las credenciales desde secrets/amazon_credentials.json
     */
    protected static function getCredentials()
    {
        $path = dirname(__FILE__) . '/../secrets/amazon_credentials.json';
        if (!file_exists($path)) {
            throw new Exception('Fichero de credenciales no encontrado: ' . $path);
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new Exception('No se pudo leer el fichero de credenciales.');
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new Exception('Credenciales Amazon en formato JSON inválido.');
        }

        return $data;
    }

    /**
     * Obtiene access_token usando grant_type=refresh_token
     * similar a tu método actual.
     */
    protected static function getAccessToken(LoggerFrik $logger = null)
    {
        $credentials = self::getCredentials();

        $array = array(
            'grant_type' => 'refresh_token',
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'refresh_token' => $credentials['refresh_token'],
        );

        $postFields = http_build_query($array);

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.amazon.com/auth/o2/token',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/x-www-form-urlencoded',
            ),
        ));

        $response = curl_exec($curl);

        if (curl_errno($curl)) {
            $err = curl_error($curl);
            curl_close($curl);
            if ($logger) {
                $logger->log('Error cURL al pedir access_token: ' . $err, 'ERROR');
            }
            return null;
        }

        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            $msg = isset($data['error'])
                ? $data['error'] . ': ' . $data['error_description']
                : 'Respuesta HTTP ' . $httpCode . ' sin mensaje de error claro.';
            if ($logger) {
                $logger->log('Respuesta no OK al pedir access_token: ' . $msg, 'ERROR');
            }
            return null;
        }

        if (empty($data['access_token'])) {
            if ($logger) {
                $logger->log('Respuesta de access_token sin access_token.', 'ERROR');
            }
            return null;
        }

        if ($logger) {
            $logger->log('Access token de Amazon obtenido correctamente.', 'INFO');
        }

        return $data['access_token'];
    }

    /* ============================================================
     *  Llamada a Amazon por EAN
     * ============================================================
     */

    /**
     * Llama a Catalog Items por un EAN concreto.
     *
     * @return array [
     *   'found'        => bool,
     *   'asin'         => ?string,
     *   'brand'        => ?string,
     *   'manufacturer' => ?string,
     *   'raw_json'     => string
     * ]
     *
     * @throws Exception en caso de error HTTP o cURL
     */
    protected static function fetchCatalogItemByEan($ean, $marketplaceId, $accessToken, LoggerFrik $logger = null)
    {
        $url = 'https://sellingpartnerapi-eu.amazon.com/catalog/2022-04-01/items'
            . '?identifiers=' . rawurlencode($ean)
            . '&identifiersType=EAN'
            . '&marketplaceIds=' . rawurlencode($marketplaceId)
            . '&includedData=Summaries';

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'x-amz-access-token: ' . $accessToken,
            ),
        ));

        $response = curl_exec($curl);

        if (curl_errno($curl)) {
            $err = curl_error($curl);
            curl_close($curl);
            throw new Exception('Error cURL en fetchCatalogItemByEan: ' . $err);
        }

        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        // Caso especial: rate limit
        if ($httpCode === 429) {
            if ($logger) {
                $logger->log(
                    'HTTP 429 (rate limit) en Catalog Items para EAN=' . $ean . '. Se detiene el lote para reintentar en la siguiente ejecución.',
                    'WARNING'
                );
            }

            // Devolvemos un flag rate_limited y NO lanzamos excepción
            return array(
                'found' => false,
                'asin' => null,
                'brand' => null,
                'manufacturer' => null,
                'raw_json' => $response,
                'rate_limited' => true,
            );
        }

        // Otros errores HTTP: se siguen tratando como error "duro"
        if ($httpCode !== 200) {
            throw new Exception('HTTP ' . $httpCode . ' al consultar Catalog Items. Body: ' . $response);
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new Exception('Respuesta JSON inválida en Catalog Items.');
        }

        $found = false;
        $asin = null;
        $brand = null;
        $mfName = null;

        if (!empty($data['items']) && is_array($data['items'])) {
            // Tomamos el primer item que venga
            $item = $data['items'][0];

            if (isset($item['asin'])) {
                $asin = $item['asin'];
            }

            if (!empty($item['summaries']) && is_array($item['summaries'])) {
                $summary = $item['summaries'][0];

                if (isset($summary['brand'])) {
                    $brand = $summary['brand'];
                }
                if (isset($summary['manufacturer'])) {
                    $mfName = $summary['manufacturer'];
                }
            }

            $found = true;
        }

        if ($logger) {
            $logger->log(
                'Respuesta Amazon para EAN=' . $ean .
                ' found=' . ($found ? '1' : '0') .
                ', asin=' . $asin .
                ', brand=' . $brand .
                ', manufacturer=' . $mfName,
                'DEBUG'
            );
        }

        return array(
            'found' => $found,
            'asin' => $asin,
            'brand' => $brand,
            'manufacturer' => $mfName,
            'raw_json' => $response,
            'rate_limited' => false,
        );
    }

    /* ============================================================
     *  Escritura en la tabla lookup
     * ============================================================
     */

    /**
     * Inserta o actualiza una fila en la tabla lafrips_manufacturer_amazon_lookup.
     *
     * @param array      $row               Fila original del SELECT (id_product, reference, name, ean, etc.)
     * @param int|null   $idProductAttribute
     * @param array      $statusInfo        [
     *                                        'status',
     *                                        'error_message',
     *                                        'raw_json',
     *                                        'asin',
     *                                        'raw_brand',
     *                                        'raw_manufacturer',
     *                                        'id_manufacturer_resolved',
     *                                        'resolved_from'
     *                                      ]
     * @param string     $marketplaceId
     */
    protected static function logLookup(array $row, $idProductAttribute, array $statusInfo, $marketplaceId)
    {
        $db = Db::getInstance();

        $idProduct = (int) $row['id_product'];
        $reference = isset($row['reference']) ? $row['reference'] : null;
        $name = isset($row['product_name']) ? $row['product_name'] : null;
        $ean = isset($row['ean13']) ? $row['ean13'] : null;
        $idMfCur = isset($row['id_manufacturer_current']) ? (int) $row['id_manufacturer_current'] : null;
        $mfCurName = isset($row['manufacturer_current_name']) ? $row['manufacturer_current_name'] : null;

        $status = $statusInfo['status'];
        $errMsg = $statusInfo['error_message'];
        $rawJson = $statusInfo['raw_json'];
        $asin = $statusInfo['asin'];
        $rawBrand = $statusInfo['raw_brand'];
        $rawMf = $statusInfo['raw_manufacturer'];
        $idMfRes = $statusInfo['id_manufacturer_resolved'];
        $resolvedFrom = $statusInfo['resolved_from'];

        $now = date('Y-m-d H:i:s');

        // Usamos INSERT ... ON DUPLICATE KEY UPDATE apoyándonos en el UNIQUE (id_product,id_product_attribute,marketplace_id)
        $insertData = array(
            'id_product' => $idProduct,
            'id_product_attribute' => $idProductAttribute ? (int) $idProductAttribute : null,
            'product_reference' => pSQL($reference),
            'product_name' => pSQL($name),
            'id_manufacturer_current' => $idMfCur > 0 ? $idMfCur : null,
            'manufacturer_current_name' => pSQL($mfCurName),
            'ean13' => pSQL($ean),
            'asin' => pSQL($asin),
            'raw_manufacturer' => pSQL($rawMf),
            'raw_brand' => pSQL($rawBrand),
            'id_manufacturer_resolved' => $idMfRes ? (int) $idMfRes : null,
            'id_employee_resolved' => null, // esto ya se meterá cuando se resuelva manualmente
            'resolved_from' => pSQL($resolvedFrom),
            'status' => pSQL($status),
            'error_message' => $errMsg !== null ? pSQL($errMsg) : null,
            'raw_response_json' => $rawJson,
            'marketplace_id' => pSQL($marketplaceId),
            'date_add' => $now,
            'date_upd' => $now,
        );

        // No hay helper de Presta para ON DUPLICATE, así que montamos la SQL a mano
        $columns = array();
        $values = array();
        foreach ($insertData as $col => $val) {
            $columns[] = '`' . $col . '`';
            if ($val === null) {
                $values[] = 'NULL';
            } else {
                // raw_response_json puede contener comillas, etc.
                if ($col === 'raw_response_json') {
                    $values[] = '"' . pSQL($val, true) . '"';
                } else {
                    $values[] = '"' . pSQL($val) . '"';
                }
            }
        }

        $sql = 'INSERT INTO ' . _DB_PREFIX_ . 'manufacturer_amazon_lookup ('
            . implode(',', $columns) . ') VALUES ('
            . implode(',', $values) . ') ON DUPLICATE KEY UPDATE ' .
            'product_reference = VALUES(product_reference), ' .
            'product_name = VALUES(product_name), ' .
            'id_manufacturer_current = VALUES(id_manufacturer_current), ' .
            'manufacturer_current_name = VALUES(manufacturer_current_name), ' .
            'ean13 = VALUES(ean13), ' .
            'asin = VALUES(asin), ' .
            'raw_manufacturer = VALUES(raw_manufacturer), ' .
            'raw_brand = VALUES(raw_brand), ' .
            'id_manufacturer_resolved = VALUES(id_manufacturer_resolved), ' .
            'resolved_from = VALUES(resolved_from), ' .
            'status = VALUES(status), ' .
            'error_message = VALUES(error_message), ' .
            'raw_response_json = VALUES(raw_response_json), ' .
            'marketplace_id = VALUES(marketplace_id), ' .
            'date_upd = VALUES(date_upd)';

        $db->execute($sql);
    }

    /**
     * Reintenta la consulta a Amazon para registros ya existentes en
     * lafrips_manufacturer_amazon_lookup con status = 'not_found'
     * (u otro que se indique), SIN volver a hacer la SELECT de productos.
     *
     * Vuelve a llamar a Amazon por EAN, intenta resolver fabricante
     * con ManufacturerAliasHelper y actualiza la fila.
     *
     * @param int        $limit              Nº máximo de filas lookup a reintentar
     * @param bool       $dryRun             Si true, no escribe en BD (solo log/echo)
     * @param string     $statusFilter       Estado a reintentar (por defecto 'not_found')
     * @param string     $marketplaceOverride Si lo indicas, se usa este marketplace en la llamada;
     *                                        si está vacío, se usa el marketplace_id de cada fila.
     * @param LoggerFrik $logger             Opcional
     */
    public static function retryAmazonLookups(
        $limit = 50,
        $dryRun = false,
        $statusFilter = 'not_found',
        $marketplaceOverride = '',
        LoggerFrik $logger = null
    ) {
        $db = Db::getInstance();

        // 1) Seleccionamos de la tabla lookup
        $where = array();
        $where[] = 'status = "' . pSQL($statusFilter) . '"';
        $where[] = 'ean13 <> ""';

        $sql = '
        SELECT *
        FROM ' . _DB_PREFIX_ . 'manufacturer_amazon_lookup
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY id_manufacturer_amazon_lookup ASC
        LIMIT ' . (int) $limit;

        $rows = $db->executeS($sql);

        if (!$rows) {
            if ($logger) {
                $logger->log(
                    'No hay registros en manufacturer_amazon_lookup con status="' . $statusFilter . '" para reintentar.',
                    'INFO'
                );
            }
            return;
        }

        if ($logger) {
            $logger->log(
                'Reintentando ' . count($rows) . ' lookups Amazon (status="' . $statusFilter . '", dry_run=' . (int) $dryRun . ')',
                'INFO'
            );
        }

        // 2) Access token una sola vez
        $accessToken = self::getAccessToken($logger);
        if (!$accessToken) {
            if ($logger) {
                $logger->log('No se ha podido obtener access_token de Amazon en retryAmazonLookups. Abortando.', 'ERROR');
            }
            return;
        }

        if (!class_exists('ManufacturerAliasHelper')) {
            require_once _PS_MODULE_DIR_ . 'frikimportproductos/classes/ManufacturerAliasHelper.php';
        }

        $resolvedCount = 0;
        $notFoundAgain = 0;
        $errorCount = 0;

        foreach ($rows as $row) {
            $idLookup = (int) $row['id_manufacturer_amazon_lookup'];
            $idProduct = (int) $row['id_product'];
            $ean = trim($row['ean13']);
            $currentMfId = (int) $row['id_manufacturer_current'];

            // Marketplace para esta llamada: override o el de la fila
            $marketplaceId = $marketplaceOverride !== ''
                ? $marketplaceOverride
                : (string) $row['marketplace_id'];

            if ($marketplaceId === '') {
                // Por seguridad, si viniera vacío usamos ES
                $marketplaceId = 'A1RKKUPIHCS9HS';
            }

            if ($logger) {
                $logger->log(
                    'Reintentando lookup #' . $idLookup . ' (id_product=' . $idProduct .
                    ', EAN=' . $ean . ', marketplace=' . $marketplaceId . ')',
                    'INFO'
                );
            }

            if ($ean === '') {
                if ($logger) {
                    $logger->log('Lookup #' . $idLookup . ' sin EAN. Se salta.', 'WARNING');
                }
                continue;
            }

            try {
                // 3) Volvemos a llamar a Amazon por este EAN
                $apiResult = self::fetchCatalogItemByEan($ean, $marketplaceId, $accessToken, $logger);
                // $apiResult = [found, asin, brand, manufacturer, raw_json, rate_limited]

                if (!empty($apiResult['rate_limited'])) {
                    if ($logger) {
                        $logger->log(
                            'Rate limit detectado en retryAmazonLookups para lookup #' . $idLookup .
                            '. Se detiene el procesamiento del resto del lote; se reintentará en la siguiente ejecución.',
                            'WARNING'
                        );
                    }
                    break;
                }

                $statusInfo = array(
                    'status' => '',
                    'error_message' => null,
                    'raw_json' => $apiResult['raw_json'],
                    'asin' => $apiResult['asin'],
                    'raw_brand' => $apiResult['brand'],
                    'raw_manufacturer' => $apiResult['manufacturer'],
                    'id_manufacturer_resolved' => null,
                    'resolved_from' => 'none',
                );

                if (!$apiResult['found']) {
                    // Sigue sin encontrarse en Amazon
                    $statusInfo['status'] = 'not_found';
                    $statusInfo['error_message'] = 'Producto no encontrado en Amazon Catalog (reintento)';

                    if ($logger) {
                        $logger->log(
                            'EAN ' . $ean . ' sigue sin encontrarse en Amazon Catalog en el reintento.',
                            'INFO'
                        );
                    }

                    if (!$dryRun) {
                        // Actualizamos SOLO la fila existente, no usamos logLookup para evitar tocar id_product, etc.
                        $db->update(
                            'manufacturer_amazon_lookup',
                            array(
                                'status' => pSQL($statusInfo['status']),
                                'error_message' => pSQL($statusInfo['error_message']),
                                'asin' => pSQL($statusInfo['asin']),
                                'raw_brand' => pSQL($statusInfo['raw_brand']),
                                'raw_manufacturer' => pSQL($statusInfo['raw_manufacturer']),
                                'raw_response_json' => $statusInfo['raw_json'],
                                'date_upd' => date('Y-m-d H:i:s'),
                            ),
                            'id_manufacturer_amazon_lookup = ' . (int) $idLookup
                        );
                    }

                    $notFoundAgain++;
                    usleep(200000);
                    continue;
                }

                // 4) Intentamos resolver fabricante en Presta con el helper
                $resolvedId = null;
                $resolvedFrom = 'none';

                if (!empty($apiResult['manufacturer'])) {
                    $resolvedId = ManufacturerAliasHelper::resolveName(
                        $apiResult['manufacturer'],
                        'AMAZON_MANUFACTURER'
                    );
                    if ($resolvedId) {
                        $resolvedFrom = 'manufacturer';
                    }
                }

                if (!$resolvedId && !empty($apiResult['brand'])) {
                    $resolvedId = ManufacturerAliasHelper::resolveName(
                        $apiResult['brand'],
                        'AMAZON_BRAND'
                    );
                    if ($resolvedId) {
                        $resolvedFrom = 'brand';
                    }
                }

                if ($resolvedId) {
                    $statusInfo['status'] = 'resolved';
                    $statusInfo['id_manufacturer_resolved'] = (int) $resolvedId;
                    $statusInfo['resolved_from'] = $resolvedFrom;

                    if ($logger) {
                        $logger->log(
                            'Lookup #' . $idLookup . ' ahora RESUELTO a id_manufacturer=' . $resolvedId .
                            ' (from=' . $resolvedFrom . ')',
                            'INFO'
                        );
                    }
                } else {
                    $statusInfo['status'] = 'pending';
                    $statusInfo['error_message'] = 'No se pudo resolver fabricante en el reintento';
                    if ($logger) {
                        $logger->log(
                            'Lookup #' . $idLookup . ' encontrado en Amazon pero sin match en ManufacturerAliasHelper (reintento).',
                            'INFO'
                        );
                    }
                }

                if ($dryRun) {
                    if ($logger) {
                        $logger->log(
                            'DRY-RUN: actualizaríamos lookup #' . $idLookup . ' con: ' . json_encode($statusInfo),
                            'DEBUG'
                        );
                    }
                } else {
                    $db->update(
                        'manufacturer_amazon_lookup',
                        array(
                            'status' => pSQL($statusInfo['status']),
                            'error_message' => $statusInfo['error_message'] !== null ? pSQL($statusInfo['error_message']) : null,
                            'asin' => pSQL($statusInfo['asin']),
                            'raw_brand' => pSQL($statusInfo['raw_brand']),
                            'raw_manufacturer' => pSQL($statusInfo['raw_manufacturer']),
                            'id_manufacturer_resolved' => $statusInfo['id_manufacturer_resolved'] ? (int) $statusInfo['id_manufacturer_resolved'] : null,
                            'resolved_from' => pSQL($statusInfo['resolved_from']),
                            'raw_response_json' => $statusInfo['raw_json'],
                            'date_upd' => date('Y-m-d H:i:s'),
                        ),
                        'id_manufacturer_amazon_lookup = ' . (int) $idLookup
                    );
                }

                if ($statusInfo['status'] === 'resolved') {
                    $resolvedCount++;
                }

                usleep(200000); // 0.2s entre llamadas

            } catch (Exception $e) {
                $errorCount++;
                if ($logger) {
                    $logger->log(
                        'Error en reintento lookup #' . $idLookup . ' (EAN=' . $ean . '): ' . $e->getMessage(),
                        'ERROR'
                    );
                }

                if (!$dryRun) {
                    $db->update(
                        'manufacturer_amazon_lookup',
                        array(
                            'status' => 'error',
                            'error_message' => pSQL($e->getMessage()),
                            'date_upd' => date('Y-m-d H:i:s'),
                        ),
                        'id_manufacturer_amazon_lookup = ' . (int) $idLookup
                    );
                }
            }
        }

        if ($logger) {
            $logger->log(
                'Reintento Amazon lookups finalizado. Resueltos: ' . $resolvedCount .
                ', siguen not_found: ' . $notFoundAgain .
                ', errores: ' . $errorCount,
                'INFO'
            );
        }
    }

}
