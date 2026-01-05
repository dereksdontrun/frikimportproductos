<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_ROOT_DIR_ . '/classes/utils/LoggerFrik.php';
if (!class_exists('ManufacturerAliasHelper')) {
    require_once _PS_MODULE_DIR_ . 'frikimportproductos/classes/ManufacturerAliasHelper.php';
}

class ManufacturerMaintenanceTools
{
    /**
     * Reintenta resolver manufacturer_alias_pending usando ManufacturerAliasHelper::resolveName()
     *
     * @param int              $limit
     * @param LoggerFrik|null  $logger
     * @param bool             $dryRun  Si true, no escribe en BD
     *
     * @return array [ 'processed' => X, 'resolved' => Y, 'still_pending' => Z ]
     */
    public static function retryPendingAliases($limit = 100, LoggerFrik $logger = null, $dryRun = false)
    {
        $db = Db::getInstance();
        $limit = (int) $limit > 0 ? (int) $limit : 100;

        if ($logger) {
            $logger->log(
                'Inicio retryPendingAliases | limit=' . $limit . ' | dryRun=' . (int) $dryRun,
                'INFO'
            );
        }

        // Sacamos pendientes sin resolver
        $rows = $db->executeS('
            SELECT *
            FROM ' . _DB_PREFIX_ . 'manufacturer_alias_pending
            WHERE resolved = 0
            ORDER BY times_seen DESC, date_add ASC
            LIMIT ' . (int) $limit
        );

        if (!$rows) {
            if ($logger) {
                $logger->log('No hay manufacturer_alias_pending sin resolver para reintentar.', 'INFO');
            }

            return array(
                'processed' => 0,
                'resolved' => 0,
                'still_pending' => 0,
            );
        }

        $processed = 0;
        $resolvedCount = 0;
        $stillPending = 0;

        foreach ($rows as $row) {
            $processed++;

            $idPending = (int) $row['id_pending'];
            $rawName = $row['raw_name'];
            $source = $row['source'];       // puede venir null o vacío

            if ($logger) {
                $logger->log(
                    'Reintentando pending #' . $idPending .
                    ' | raw_name="' . $rawName . '"' .
                    ' | source="' . $source . '"',
                    'DEBUG'
                );
            }

            // volvemos a intentar la resolución
            $resolvedId = ManufacturerAliasHelper::resolveName($rawName, $source);

            if ($resolvedId) {
                $resolvedCount++;

                if ($logger) {
                    $logger->log(
                        'Pending #' . $idPending . ' RESUELTO -> id_manufacturer=' . (int) $resolvedId,
                        'INFO'
                    );
                }

                if (!$dryRun) {
                    $db->update(
                        'manufacturer_alias_pending',
                        array(
                            'resolved' => 1,
                            'id_manufacturer' => (int) $resolvedId,
                            'date_upd' => date('Y-m-d H:i:s'),
                            // opcional: podrías setear aquí un id_employee "sistema" si quisieras
                        ),
                        'id_pending = ' . (int) $idPending
                    );
                }
            } else {
                $stillPending++;
                if ($logger) {
                    $logger->log(
                        'Pending #' . $idPending . ' sigue sin resolverse.',
                        'DEBUG'
                    );
                }
            }

            usleep(50000); // 0.05s, por si algún día esto crece mucho
        }

        if ($logger) {
            $logger->log(
                'Fin retryPendingAliases | procesados=' . $processed .
                ' | resueltos=' . $resolvedCount .
                ' | siguen pendientes=' . $stillPending,
                'INFO'
            );
        }

        return array(
            'processed' => $processed,
            'resolved' => $resolvedCount,
            'still_pending' => $stillPending,
        );
    }

    /**
     * Reintenta la resolución de manufacturer_amazon_lookup sin tocar la API.
     *
     * @param int         $limit
     * @param string      $status        (normalmente 'pending')
     * @param string      $marketplaceId '' para cualquiera
     * @param bool        $dryRun
     * @param LoggerFrik|null $logger
     *
     * @return array [ 'processed' => X, 'resolved' => Y, 'still_pending' => Z ]
     */
    public static function retryAmazonLookupResolves(
        $limit = 100,
        $status = 'pending',
        $marketplaceId = '',
        $dryRun = false,
        LoggerFrik $logger = null
    ) {
        $db = Db::getInstance();
        $limit = (int) $limit > 0 ? (int) $limit : 100;

        if ($logger) {
            $logger->log(
                'Inicio retryAmazonLookupResolves | limit=' . $limit .
                ' | status=' . $status .
                ' | marketplace=' . ($marketplaceId ?: '(todos)') .
                ' | dryRun=' . (int) $dryRun,
                'INFO'
            );
        }

        $where = array();
        $where[] = 'status = "' . pSQL($status) . '"';
        $where[] = 'id_manufacturer_resolved IS NULL';
        $where[] = '(raw_manufacturer <> "" OR raw_brand <> "")';

        if ($marketplaceId !== '') {
            $where[] = 'marketplace_id = "' . pSQL($marketplaceId) . '"';
        }

        $sql = '
            SELECT *
            FROM ' . _DB_PREFIX_ . 'manufacturer_amazon_lookup
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY date_add ASC
            LIMIT ' . $limit;

        $rows = $db->executeS($sql);

        if (!$rows) {
            if ($logger) {
                $logger->log('No hay registros que cumplan el filtro para reintentar.', 'INFO');
            }
            return array(
                'processed' => 0,
                'resolved' => 0,
                'still_pending' => 0,
            );
        }

        $processed = 0;
        $resolvedCount = 0;
        $stillPending = 0;

        foreach ($rows as $row) {
            $processed++;
            $idLookup = (int) $row['id_manufacturer_amazon_lookup'];
            $ean = $row['ean13'];
            $rawMf = trim((string) $row['raw_manufacturer']);
            $rawBrand = trim((string) $row['raw_brand']);

            if ($logger) {
                $logger->log(
                    'Procesando lookup #' . $idLookup .
                    ' | EAN=' . $ean .
                    ' | raw_manufacturer="' . $rawMf . '"' .
                    ' | raw_brand="' . $rawBrand . '"',
                    'DEBUG'
                );
            }

            $resolvedId = null;
            $resolvedFrom = 'none';

            // 1) manufacturer (Amazon)
            if ($rawMf !== '') {
                $resolvedId = ManufacturerAliasHelper::resolveName($rawMf, 'AMAZON_MANUFACTURER');
                if ($resolvedId) {
                    $resolvedFrom = 'manufacturer';
                }
            }

            // 2) brand (Amazon)
            if (!$resolvedId && $rawBrand !== '') {
                $resolvedId = ManufacturerAliasHelper::resolveName($rawBrand, 'AMAZON_BRAND');
                if ($resolvedId) {
                    $resolvedFrom = 'brand';
                }
            }

            if ($resolvedId) {
                $resolvedCount++;

                if ($logger) {
                    $logger->log(
                        'Lookup #' . $idLookup . ' RESUELTO -> id_manufacturer=' . (int) $resolvedId .
                        ' (from=' . $resolvedFrom . ')',
                        'INFO'
                    );
                }

                if (!$dryRun) {
                    $db->update(
                        'manufacturer_amazon_lookup',
                        array(
                            'id_manufacturer_resolved' => (int) $resolvedId,
                            'resolved_from' => pSQL($resolvedFrom),
                            'status' => 'resolved',
                            'error_message' => null,
                            'date_upd' => date('Y-m-d H:i:s'),
                        ),
                        'id_manufacturer_amazon_lookup = ' . (int) $idLookup
                    );
                }
            } else {
                $stillPending++;
                if ($logger) {
                    $logger->log(
                        'Lookup #' . $idLookup .
                        ' sigue sin poder resolverse (status=' . $row['status'] . ')',
                        'DEBUG'
                    );
                }
            }

            usleep(50000);
        }

        if ($logger) {
            $logger->log(
                'Fin retryAmazonLookupResolves | procesados=' . $processed .
                ' | resueltos=' . $resolvedCount .
                ' | siguen pendientes=' . $stillPending,
                'INFO'
            );
        }

        return array(
            'processed' => $processed,
            'resolved' => $resolvedCount,
            'still_pending' => $stillPending,
        );
    }

    /**
     * Reintenta consultar la API de Amazon para filas de manufacturer_amazon_lookup
     * que quedaron como not_found / error / rate_limited.
     *
     * NO toca a Amazon para los "pending" (esos se gestionan con retryAmazonLookupResolves).
     *
     * @param int              $limit
     * @param LoggerFrik|null  $logger
     * @param string           $marketplaceFilter  p.ej. 'A1RKKUPIHCS9HS' o '' para todos
     * @param bool             $dryRun             Si true, no escribe en BD
     *
     * @return array [
     *   'processed'    => X,
     *   'resolved'     => Y,
     *   'not_found'    => Z,
     *   'error'        => W,
     *   'rate_limited' => R
     * ]
     */
    public static function retryAmazonLookupApi(
        $limit = 50,
        LoggerFrik $logger = null,
        $marketplaceFilter = '',
        $dryRun = false
    ) {
        $db = Db::getInstance();
        $limit = (int) $limit > 0 ? (int) $limit : 50;

        if ($logger) {
            $logger->log(
                'Inicio retryAmazonLookupApi | limit=' . $limit .
                ' | marketplaceFilter="' . $marketplaceFilter . '"' .
                ' | dryRun=' . (int) $dryRun,
                'INFO'
            );
        }

        // 1) Acceso a credenciales + access token una sola vez
        try {
            $accessToken = self::getAmazonAccessTokenForCatalog($logger);
        } catch (Exception $e) {
            if ($logger) {
                $logger->log(
                    'No se pudo obtener access token de Amazon en retryAmazonLookupApi: ' . $e->getMessage(),
                    'ERROR'
                );
            }
            return array(
                'processed' => 0,
                'resolved' => 0,
                'not_found' => 0,
                'error' => 0,
                'rate_limited' => 0,
            );
        }

        // 2) Seleccionar filas a reintentar
        $where = array();
        $where[] = 'ean13 <> ""';
        $where[] = 'status IN ("not_found","error","rate_limited")';
        // opcional: aseguramos que no haya ya fabricante resuelto
        $where[] = 'id_manufacturer_resolved IS NULL';

        if ($marketplaceFilter !== '') {
            $where[] = 'marketplace_id = "' . pSQL($marketplaceFilter) . '"';
        }

        $sql = '
            SELECT *
            FROM ' . _DB_PREFIX_ . 'manufacturer_amazon_lookup
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY date_add ASC
            LIMIT ' . (int) $limit;

        $rows = $db->executeS($sql);

        if (!$rows) {
            if ($logger) {
                $logger->log('No hay registros manufacturer_amazon_lookup candidatos para retryAmazonLookupApi.', 'INFO');
            }

            return array(
                'processed' => 0,
                'resolved' => 0,
                'not_found' => 0,
                'error' => 0,
                'rate_limited' => 0,
            );
        }

        if ($logger) {
            $logger->log('Filas a reintentar contra Amazon API: ' . count($rows), 'INFO');
        }

        $processed = 0;
        $resolvedCount = 0;
        $notFoundCount = 0;
        $errorCount = 0;
        $rateLimitCount = 0;

        foreach ($rows as $row) {
            $processed++;

            $idLookup = (int) $row['id_manufacturer_amazon_lookup'];
            $ean = $row['ean13'];
            $marketRow = $row['marketplace_id'];
            $marketId = $marketRow !== '' ? $marketRow : 'A1RKKUPIHCS9HS'; // por si alguna fila está sin marketplace

            if ($logger) {
                $logger->log(
                    'Retry API para lookup #' . $idLookup .
                    ' | EAN=' . $ean .
                    ' | marketplace=' . $marketId,
                    'DEBUG'
                );
            }

            try {
                $apiResult = self::fetchAmazonCatalogItemByEanForRetry(
                    $ean,
                    $marketId,
                    $accessToken,
                    $logger
                );

                // Control especial rate limit (429)
                if (!empty($apiResult['rate_limited'])) {
                    $rateLimitCount++;

                    if ($logger) {
                        $logger->log(
                            'Rate limit detectado para EAN ' . $ean .
                            ' (lookup #' . $idLookup . '). Marcando como rate_limited y cortando el bucle.',
                            'WARNING'
                        );
                    }

                    if (!$dryRun) {
                        $db->update(
                            'manufacturer_amazon_lookup',
                            array(
                                'status' => 'rate_limited',
                                'error_message' => pSQL('Rate limited por Amazon (HTTP 429)'),
                                'date_upd' => date('Y-m-d H:i:s'),
                            ),
                            'id_manufacturer_amazon_lookup = ' . (int) $idLookup
                        );
                    }

                    // Salimos del bucle, dejamos el resto para otra pasada
                    break;
                }

                // No rate-limited → analizamos si hay item o no
                if (!$apiResult['found']) {
                    $notFoundCount++;

                    if ($logger) {
                        $logger->log(
                            'EAN ' . $ean . ' no encontrado en Amazon al reintentar (lookup #' . $idLookup . ').',
                            'INFO'
                        );
                    }

                    if (!$dryRun) {
                        $db->update(
                            'manufacturer_amazon_lookup',
                            array(
                                'status' => 'not_found',
                                'error_message' => pSQL('Producto no encontrado en Amazon Catalog'),
                                'raw_response_json' => pSQL($apiResult['raw_json'], true),
                                'asin' => pSQL($apiResult['asin']),
                                'raw_brand' => pSQL($apiResult['brand']),
                                'raw_manufacturer' => pSQL($apiResult['manufacturer']),
                                'date_upd' => date('Y-m-d H:i:s'),
                            ),
                            'id_manufacturer_amazon_lookup = ' . (int) $idLookup
                        );
                    }

                    continue;
                }

                // Hay respuesta con brand/manufacturer -> intentamos casarlo con ManufacturerAliasHelper
                $brand = $apiResult['brand'];
                $mf = $apiResult['manufacturer'];

                $resolvedId = null;
                $resolvedFrom = 'none';

                if ($mf !== '') {
                    $resolvedId = ManufacturerAliasHelper::resolveName($mf, 'AMAZON_MANUFACTURER');
                    if ($resolvedId) {
                        $resolvedFrom = 'manufacturer';
                    }
                }

                if (!$resolvedId && $brand !== '') {
                    $resolvedId = ManufacturerAliasHelper::resolveName($brand, 'AMAZON_BRAND');
                    if ($resolvedId) {
                        $resolvedFrom = 'brand';
                    }
                }

                if ($resolvedId) {
                    $resolvedCount++;

                    if ($logger) {
                        $logger->log(
                            'Lookup #' . $idLookup . ' ahora RESUELTO por API. id_manufacturer=' . (int) $resolvedId .
                            ' (from=' . $resolvedFrom . ')',
                            'INFO'
                        );
                    }

                    if (!$dryRun) {
                        $db->update(
                            'manufacturer_amazon_lookup',
                            array(
                                'status' => 'resolved',
                                'id_manufacturer_resolved' => (int) $resolvedId,
                                'resolved_from' => pSQL($resolvedFrom),
                                'error_message' => null,
                                'raw_response_json' => pSQL($apiResult['raw_json'], true),
                                'asin' => pSQL($apiResult['asin']),
                                'raw_brand' => pSQL($brand),
                                'raw_manufacturer' => pSQL($mf),
                                'date_upd' => date('Y-m-d H:i:s'),
                                // id_employee_resolved lo dejamos NULL, resolución automática
                            ),
                            'id_manufacturer_amazon_lookup = ' . (int) $idLookup
                        );
                    }
                } else {
                    // Seguimos sin poder casar fabricante → lo marcamos como pending con datos frescos
                    if ($logger) {
                        $logger->log(
                            'Lookup #' . $idLookup . ' sigue sin poder resolverse tras reintentar API. '
                            . 'Se marca como pending.',
                            'INFO'
                        );
                    }

                    if (!$dryRun) {
                        $db->update(
                            'manufacturer_amazon_lookup',
                            array(
                                'status' => 'pending',
                                'error_message' => pSQL('No se pudo casar fabricante tras reintentar API'),
                                'raw_response_json' => pSQL($apiResult['raw_json'], true),
                                'asin' => pSQL($apiResult['asin']),
                                'raw_brand' => pSQL($brand),
                                'raw_manufacturer' => pSQL($mf),
                                'date_upd' => date('Y-m-d H:i:s'),
                            ),
                            'id_manufacturer_amazon_lookup = ' . (int) $idLookup
                        );
                    }
                }

            } catch (Exception $e) {
                $errorCount++;

                if ($logger) {
                    $logger->log(
                        'Error en retryAmazonLookupApi para lookup #' . $idLookup .
                        ' EAN=' . $ean . ' -> ' . $e->getMessage(),
                        'ERROR'
                    );
                }

                if (!$dryRun) {
                    $db->update(
                        'manufacturer_amazon_lookup',
                        array(
                            'status' => 'error',
                            'error_message' => pSQL(Tools::substr($e->getMessage(), 0, 250)),
                            'date_upd' => date('Y-m-d H:i:s'),
                        ),
                        'id_manufacturer_amazon_lookup = ' . (int) $idLookup
                    );
                }
            }

            usleep(200000); // 0.2s, por si acaso
        }

        if ($logger) {
            $logger->log(
                'Fin retryAmazonLookupApi | procesados=' . $processed .
                ' | resueltos=' . $resolvedCount .
                ' | not_found=' . $notFoundCount .
                ' | error=' . $errorCount .
                ' | rate_limited=' . $rateLimitCount,
                'INFO'
            );
        }

        return array(
            'processed' => $processed,
            'resolved' => $resolvedCount,
            'not_found' => $notFoundCount,
            'error' => $errorCount,
            'rate_limited' => $rateLimitCount,
        );
    }

    /* ============================
     *  Helpers Amazon internos
     * ============================
     */

    protected static function getAmazonCredentialsForCatalog()
    {
        $path = dirname(__FILE__) . '/secrets/amazon_credentials.json';

        if (!file_exists($path)) {
            throw new Exception('Fichero de credenciales Amazon no encontrado: ' . $path);
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new Exception('No se pudo leer el fichero de credenciales Amazon.');
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new Exception('Credenciales Amazon en JSON inválido.');
        }

        return $data;
    }

    protected static function getAmazonAccessTokenForCatalog(LoggerFrik $logger = null)
    {
        $credentials = self::getAmazonCredentialsForCatalog();

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
            throw new Exception('Error cURL al pedir access_token a Amazon: ' . $err);
        }

        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            $msg = isset($data['error'])
                ? $data['error'] . ': ' . $data['error_description']
                : 'HTTP ' . $httpCode . ' sin mensaje claro.';
            throw new Exception('Respuesta no OK al pedir access_token Amazon: ' . $msg);
        }

        if (empty($data['access_token'])) {
            throw new Exception('Respuesta de Amazon sin access_token.');
        }

        if ($logger) {
            $logger->log('Access token de Amazon obtenido correctamente (retry API).', 'INFO');
        }

        return $data['access_token'];
    }

    protected static function fetchAmazonCatalogItemByEanForRetry(
        $ean,
        $marketplaceId,
        $accessToken,
        LoggerFrik $logger = null
    ) {
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
            throw new Exception('Error cURL en fetchAmazonCatalogItemByEanForRetry: ' . $err);
        }

        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        // Caso especial: rate limiting
        if ($httpCode === 429) {
            if ($logger) {
                $logger->log(
                    'HTTP 429 (rate limit) en Catalog Items para EAN=' . $ean,
                    'WARNING'
                );
            }

            return array(
                'rate_limited' => true,
                'found' => false,
                'asin' => null,
                'brand' => null,
                'manufacturer' => null,
                'raw_json' => $response,
            );
        }

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
        $mf = null;

        if (!empty($data['items']) && is_array($data['items'])) {
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
                    $mf = $summary['manufacturer'];
                }
            }

            $found = true;
        }

        if ($logger) {
            $logger->log(
                'Respuesta Amazon retry API para EAN=' . $ean . ' found=' . ($found ? '1' : '0')
                . ', asin=' . $asin . ', brand=' . $brand . ', manufacturer=' . $mf,
                'DEBUG'
            );
        }

        return array(
            'rate_limited' => false,
            'found' => $found,
            'asin' => $asin,
            'brand' => $brand,
            'manufacturer' => $mf,
            'raw_json' => $response,
        );
    }
}
