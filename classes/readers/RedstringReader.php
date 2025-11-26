<?php

require_once(_PS_MODULE_DIR_ . 'frikimportproductos/classes/AbstractCatalogReader.php');
require_once _PS_ROOT_DIR_ . '/classes/utils/LoggerFrik.php';

require_once(_PS_MODULE_DIR_ . 'frikimportproductos/utils/SimpleXLS/src/SimpleXLS.php');

class RedstringReader extends AbstractCatalogReader
{
    /** @var array */
    protected $config;

    /** @var LoggerFrik */
    protected $logger;

    /** @var int */
    protected $id_supplier;

    /** @var string */
    protected $supplier = 'Redstring';

    /** @var string */
    protected $path_descarga_web = _PS_MODULE_DIR_ . 'frikimportproductos/import/redstring/catalogo_web/';

    /** @var string */
    protected $path_descarga_servidor = _PS_MODULE_DIR_ . 'frikimportproductos/import/redstring/catalogo_costes_servidor/';

    /** @var string|null */
    protected $path_catalogo_web;

    /** @var string|null */
    protected $path_catalogo_servidor;

    /** @var string */
    protected $delimitador_servidor = ';';

    /** @var array */
    protected $catalogo_web = [];

    /**
     * Mapa de posibles nombres de cabecera en el CSV del servidor (FTP)
     * referencia / ean / tarifa cliente
     */
    protected $mapa_campos_servidor = [
        'referencia' => [
            'referencia',
            'codigo producto',
            'codigo de producto',
            'codigo de articulo',
            'codigo articulo',
        ],
        'ean' => [
            'ean',
            'codigo barras',
            'codigo de barras',
        ],
        'coste' => [
            'tarifa frikileria',
            'tarifa',
            'tarifa cliente',
        ],
    ];

    /**
     * Índices de columnas detectados en el CSV de servidor
     */
    protected $indices_servidor = [
        'referencia' => -1,
        'ean' => -1,
        'coste' => -1,
    ];

    /**
     * Constructor
     *
     * @param int        $id_supplier  id_supplier Redstring (24)
     * @param LoggerFrik $logger
     *
     * @throws Exception
     */
    public function __construct($id_supplier, LoggerFrik $logger)
    {
        $this->id_supplier = (int) $id_supplier;
        $this->logger = $logger;

        // OJO: aquí usamos executeS, no getRow. Como tenemos que poder descaragr el catálogo de la web o del servidor, en lafrips_import_proveedores tenemos dos líneas para Redstring, con tipo 'ftp' y 'web'
        $configs = Db::getInstance()->executeS('
            SELECT *
            FROM ' . _DB_PREFIX_ . 'import_proveedores
            WHERE id_supplier = ' . (int) $id_supplier
        );

        if (!$configs) {
            throw new Exception('No se encontró configuración para Redstring con id_supplier ' . $id_supplier);
        }

        foreach ($configs as $row) {
            if (!isset($row['tipo'])) {
                continue;
            }

            switch (strtolower($row['tipo'])) {
                case 'web':
                    $this->config_web = $row;
                    break;

                case 'ftp':
                    $this->config_ftp = $row;
                    break;
            }
        }

        if (!$this->config_web) {
            throw new Exception('No se encontró configuración de tipo "web" para Redstring (id_supplier ' . $id_supplier . ')');
        }

        if (!$this->config_ftp) {
            // Si quieres puedes no lanzar excepción y solo loguear,
            // pero lo lógico es exigir ambas:
            throw new Exception('No se encontró configuración de tipo "ftp" para Redstring (id_supplier ' . $id_supplier . ')');
        }
    }

    /* ============================================================
     *   BLOQUE 1: CATÁLOGO DE SERVIDOR (FTP) → COSTES
     * ============================================================ */

    /**
     * Descarga el catálogo de costes desde el FTP de Redstring
     * y deja $this->path_catalogo_servidor apuntando al fichero local.
     * 
     *
     * @return string|false Ruta al archivo descargado o false si error
     */
    public function fetchServidor()
    {
        $this->logger->log('Comienzo descarga catálogo costes Redstring desde servidor FTP', 'INFO');

        $ftp_server = $this->config_ftp['url'];
        $ftp_username = $this->config_ftp['usuario'];
        $ftp_password = $this->config_ftp['password'];

        // Conexión FTP
        $ftp_connection = @ftp_connect($ftp_server);
        if (!$ftp_connection) {
            $this->logger->log(
                'Error conectando al servidor FTP Redstring: ' . $ftp_server,
                'ERROR'
            );
            return false;
        }

        // Login
        $ftp_login = @ftp_login($ftp_connection, $ftp_username, $ftp_password);
        if (!$ftp_login) {
            $this->logger->log(
                'Error haciendo login en FTP Redstring: ' . $ftp_server,
                'ERROR'
            );
            ftp_close($ftp_connection);
            return false;
        }

        // Forzar modo pasivo (tema Arsys)
        ftp_pasv($ftp_connection, true);

        // Archivo remoto y local
        $remote_file_path = 'Frikileria.csv';
        $nombre_catalogo = 'redstring_catalogo_costes_servidor_' . date('Y-m-d_His') . '.csv';
        $this->path_catalogo_servidor = $this->path_descarga_servidor . $nombre_catalogo;

        // Descargar
        if (
            !@ftp_get(
                $ftp_connection,
                $this->path_catalogo_servidor,
                $remote_file_path,
                FTP_BINARY
            )
        ) {
            $error_ftp = error_get_last();
            $msg = isset($error_ftp['message']) ? $error_ftp['message'] : 'Desconocido';

            $this->logger->log(
                'Error copiando catálogo costes Redstring desde FTP - Mensaje error: ' . $msg,
                'ERROR'
            );
            ftp_close($ftp_connection);
            return false;
        }

        ftp_close($ftp_connection);

        $this->logger->log(
            'Catálogo costes servidor Redstring descargado correctamente: ' . $this->path_catalogo_servidor,
            'INFO'
        );

        return $this->path_catalogo_servidor;
    }

    /**
     * Valida el archivo de costes del servidor y detecta delimitador + cabeceras
     *
     * @param string $filename
     * @return bool
     */
    public function checkCatalogoServidor($filename)
    {
        if (!file_exists($filename)) {
            $this->logger->log(
                'Archivo de catálogo costes de servidor Redstring no encontrado: ' . $filename,
                'ERROR'
            );
            return false;
        }

        // Detectar delimitador automáticamente
        $delimitador = $this->detectarDelimitador($filename);
        if (!$delimitador) {
            $delimitador = ';';
        }
        $this->delimitador_servidor = $delimitador;

        $handle = fopen($filename, 'r');
        if ($handle === false) {
            $this->logger->log(
                'Error abriendo archivo de catálogo costes de servidor Redstring',
                'ERROR'
            );
            return false;
        }

        // Cabecera
        $linea = fgetcsv($handle, 0, $this->delimitador_servidor);
        if (!$linea) {
            $this->logger->log(
                'No se pudo leer la cabecera del catálogo costes de servidor Redstring',
                'ERROR'
            );
            fclose($handle);
            return false;
        }

        // Reset indices
        foreach ($this->indices_servidor as $clave => $v) {
            $this->indices_servidor[$clave] = -1;
        }

        // Intento 1: Mapear columnas por cabeceras según $this->mapa_campos_servidor
        $cabeceras_ok = true;

        foreach ($this->mapa_campos_servidor as $campo => $variantes) {
            $encontrado = false;
            foreach ($variantes as $posible) {
                foreach ($linea as $idx => $cabecera) {
                    if ($this->normalizaTexto($cabecera) === $this->normalizaTexto($posible)) {
                        $this->indices_servidor[$campo] = $idx;
                        $encontrado = true;
                        break 2;
                    }
                }
            }

            if (!$encontrado) {
                $cabeceras_ok = false;
                $this->logger->log(
                    'Campo obligatorio no encontrado por cabecera en catálogo costes servidor Redstring: ' . $campo,
                    'WARNING'
                );
                // no hacemos return aún, probaremos el fallback por contenido
                break;
            }
        }

        fclose($handle);

        if ($cabeceras_ok) {
            $this->logger->log(
                'Cabeceras catálogo costes servidor Redstring OK: ' . json_encode($this->indices_servidor),
                'INFO'
            );
            return true;
        }

        // Intento 2: inferir columnas por contenido (referencia RS, EAN, precio)
        $this->logger->log(
            'Cabeceras de catálogo costes servidor Redstring no reconocidas. ' .
            'Intentando inferir columnas por contenido...',
            'WARNING'
        );

        if (!$this->inferirColumnasServidorPorContenido($filename)) {
            $this->logger->log(
                'No se pudieron inferir columnas de catálogo costes servidor Redstring. Abortando.',
                'ERROR'
            );
            return false;
        }

        return true;
    }

    /**
     * Parseo del catálogo de servidor:
     * devuelve un array de arrays con referencia / ean / coste que contiene todos los productos, en el cron se unirá el precio a la información de producto en catálogo web
     *
     * @param string $filename
     * @return array
     */
    public function parseServidor($filename)
    {
        if ($this->indices_servidor['referencia'] < 0) {
            if (!$this->checkCatalogoServidor($filename)) {
                return [];
            }
        }

        $handle = fopen($filename, 'r');
        if ($handle === false) {
            $this->logger->log(
                'No se pudo abrir archivo servidor Redstring en parseServidor()',
                'ERROR'
            );
            return [];
        }

        // Saltar cabecera
        fgetcsv($handle, 0, $this->delimitador_servidor);

        $productos = [];

        while (($campos = fgetcsv($handle, 0, $this->delimitador_servidor)) !== false) {
            if ($campos === [null] || $campos === false) {
                continue;
            }

            $ref_raw = isset($campos[$this->indices_servidor['referencia']])
                ? trim($campos[$this->indices_servidor['referencia']])
                : '';
            if ($ref_raw === '') {
                continue;
            }

            $ean_raw = isset($campos[$this->indices_servidor['ean']])
                ? trim($campos[$this->indices_servidor['ean']])
                : '';
            $coste_raw = isset($campos[$this->indices_servidor['coste']])
                ? trim($campos[$this->indices_servidor['coste']])
                : '';

            $ean_limpio = preg_replace('/\D/', '', $ean_raw);
            $coste = (float) str_replace(',', '.', preg_replace('/[^\d,\.]/', '', $coste_raw));

            $productos[] = [
                'referencia_proveedor' => $ref_raw,
                'ean' => $ean_limpio,
                'coste' => $coste,
            ];
        }

        fclose($handle);

        $this->logger->log(
            'Parseados ' . count($productos) . ' registros de costes Redstring (servidor)',
            'INFO'
        );

        return $productos;
    }

    /**
     * Detecta el delimitador más probable en la primera línea del CSV
     *
     * @param string $ruta_csv
     * @param array  $delimitadores
     * @return string|false
     */
    protected function detectarDelimitador($ruta_csv, $delimitadores = [',', ';', "\t", '|'])
    {
        $handle = fopen($ruta_csv, 'r');
        if (!$handle) {
            return false;
        }

        $linea = fgets($handle);
        fclose($handle);

        $conteos = [];
        //obtenemos cuantos encontramos de los posibles delimitadores en la línea. Nos quedaremosel que tenga más repeticiones
        foreach ($delimitadores as $del) {
            $conteos[$del] = substr_count($linea, $del);
        }

        arsort($conteos);
        return key($conteos);
    }

    // $this->indices_servidor = ['referencia' => -1, 'ean' => -1, 'coste' => -1];

    private function inferirColumnasServidorPorContenido($filename)
    {
        $delimitador = $this->delimitador_servidor ?: ';';

        $handle = fopen($filename, 'r');
        if (!$handle) {
            return false;
        }

        $header = fgetcsv($handle, 0, $delimitador);
        if (!$header) {
            fclose($handle);
            return false;
        }

        $numCols = count($header);

        // Inicializamos estadísticas
        $stats = [];
        for ($i = 0; $i < $numCols; $i++) {
            $stats[$i] = [
                'muestras' => 0,
                'ref_score' => 0,
                'ean_score' => 0,
                'precio_score' => 0,
                'precio_con_decimales' => 0,
            ];
        }

        $maxMuestras = 200; // número de filas que vamos a “sondear”

        while (($row = fgetcsv($handle, 0, $delimitador)) !== false && $maxMuestras-- > 0) {
            for ($i = 0; $i < $numCols; $i++) {
                if (!isset($row[$i])) {
                    continue;
                }
                $val = $row[$i];

                if ($val === '' || $val === null) {
                    continue;
                }

                $stats[$i]['muestras']++;

                if ($this->esReferenciaRedstring($val)) {
                    $stats[$i]['ref_score']++;
                }
                if ($this->esEanPosible($val)) {
                    $stats[$i]['ean_score']++;
                }
                if ($this->esPrecioPosible($val)) {
                    $stats[$i]['precio_score']++;
                    if (strpos($val, ',') !== false || strpos($val, '.') !== false) {
                        $stats[$i]['precio_con_decimales']++;
                    }
                }
            }
        }

        fclose($handle);

        // Pequeño helper para elegir la mejor columna para cada tipo
        $bestRef = $this->bestColumnIndex($stats, 'ref_score');
        $bestEan = $this->bestColumnIndex($stats, 'ean_score', [$bestRef]);
        $bestPrecio = $this->bestColumnIndex($stats, 'precio_score', [$bestRef, $bestEan]);

        if ($bestRef === null || $bestPrecio === null) {
            $this->logger->log(
                'No se pudieron inferir columnas de referencia/precio por contenido en catálogo servidor Redstring',
                'ERROR'
            );
            return false;
        }

        $this->indices_servidor['referencia'] = $bestRef;
        $this->indices_servidor['ean'] = $bestEan;
        $this->indices_servidor['coste'] = $bestPrecio;

        // Log de lo que hemos decidido
        $this->logger->log(
            'Columnas servidor inferidas por contenido: ref='
            . $bestRef . ', ean=' . $bestEan . ', coste=' . $bestPrecio,
            'INFO'
        );

        // Chequeo de decimales en precio
        $muestrasPrecio = $stats[$bestPrecio]['precio_score'];
        $conDecimales = $stats[$bestPrecio]['precio_con_decimales'];

        if ($muestrasPrecio > 0 && $conDecimales === 0) {
            // Aquí decides si considerarlo error duro o warning.
            $this->logger->log(
                'ATENCIÓN: la columna de precio no presenta ningún valor con decimales. ' .
                'Es posible que Redstring haya generado el archivo con precios redondeados.',
                'ERROR'
            );
            // Si quieres abortar:
            return false;
        }

        return true;
    }

    // Elige la columna con mayor "score" para un tipo dado (ref/ean/precio)
    private function bestColumnIndex($stats, $key, $excluir = [])
    {
        $bestIdx = null;
        $bestScore = 0;

        foreach ($stats as $idx => $col) {
            if (in_array($idx, $excluir, true)) {
                continue;
            }
            if ($col[$key] > $bestScore) {
                $bestScore = $col[$key];
                $bestIdx = $idx;
            }
        }

        // Exigimos un mínimo de “hits” para fiarnos (por ejemplo 3)
        if ($bestScore < 3) {
            return null;
        }

        return $bestIdx;
    }


    // ¿Tiene pinta de referencia Redstring? Ej: RS123456
    private function esReferenciaRedstring($valor)
    {
        $v = trim($valor);
        if ($v === '') {
            return false;
        }
        // RS seguido de letras/números
        return preg_match('/^RS[0-9A-Z]+$/i', $v) === 1;
    }

    // ¿Tiene pinta de EAN?
    private function esEanPosible($valor)
    {
        $v = preg_replace('/\D/', '', (string) $valor); // solo dígitos
        $len = strlen($v);
        if ($len === 0) {
            return false;
        }
        // EAN/UPC típicos: 8–14 dígitos
        return $len >= 8 && $len <= 14;
    }

    // ¿Tiene pinta de precio?
    private function esPrecioPosible($valor)
    {
        $v = trim((string) $valor);
        if ($v === '') {
            return false;
        }

        // Número con posible coma/punto decimal
        if (!preg_match('/^\d{1,5}([.,]\d{1,4})?$/', $v)) {
            return false;
        }

        $num = (float) str_replace(',', '.', $v);
        if ($num < 0 || $num > 9999) {
            return false;
        }

        return true;
    }

    /* ============================================================
     *   BLOQUE 2: CATÁLOGO WEB (XLS) → STOCK / CATÁLOGO
     * ============================================================ */

    /**
     * Descarga el catálogo XLS desde la web de Redstring
     * y deja $this->path_catalogo_web
     *
     * Equivalente a tu getCatalogoWeb()
     *
     * @return string|false
     */
    public function fetchWeb()
    {
        $url = $this->config_web['url'];

        // $url = 'https://www.redstring.es/mis_catalogos_accion.php'
        //     . '?acc=1&idsite=1&idioma=50'
        //     . '&campo4-1=1'   // Código producto
        //     . '&campo4-2=1'   // Nombre
        //     . '&campo4-5=1'   // Marca
        //     . '&campo4-8=1'   // Jerarquía marca
        //     . '&campo4-10=1'  // Tarifa cliente (no válida)
        //     . '&campo4-11=1'  // Cant.Stock
        //     . '&campo4-13=1'  // Imagen
        //     . '&campo4-14=1'; // Código Barras

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
        ]);

        $contenido_remoto = curl_exec($ch);
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            $this->logger->log(
                'Error haciendo cURL a Redstring (catálogo web): ' . $error,
                'ERROR'
            );
            curl_close($ch);
            return false;
        }
        curl_close($ch);

        if (!$contenido_remoto) {
            $this->logger->log(
                'Contenido vacío al descargar catálogo web Redstring',
                'ERROR'
            );
            return false;
        }

        $nombre_catalogo = 'catalogo_web_redstring_' . date('Y-m-d_His') . '.xls';
        $this->path_catalogo_web = $this->path_descarga_web . $nombre_catalogo;

        if (file_put_contents($this->path_catalogo_web, $contenido_remoto) === false) {
            $this->logger->log(
                'Error guardando catálogo web Redstring en ' . $this->path_catalogo_web,
                'ERROR'
            );
            return false;
        }

        $this->logger->log(
            'Catálogo web Redstring guardado correctamente: ' . $this->path_catalogo_web,
            'INFO'
        );

        return $this->path_catalogo_web;
    }

    /**
     * Valida el catálogo web XLS (cabeceras) y carga $this->catalogo_web
     *
     * Equivalente a tu checkCatalogoWeb(), pero encapsulado
     *
     * @param string $filename
     * @return bool
     */
    public function checkCatalogoWeb($filename)
    {
        // usar SimpleXLS
        if (!$xls = SimpleXLS::parse($filename)) {
            $this->logger->log(
                'Error abriendo catálogo web Redstring con SimpleXLS',
                'ERROR'
            );
            return false;
        }

        if (count($xls->rows()) < 2) {
            $this->logger->log(
                'Catálogo web Redstring: menos de 2 filas, archivo sospechoso',
                'ERROR'
            );
            return false;
        }

        $header_values = [];
        $this->catalogo_web = [];

        foreach ($xls->rows() as $fila => $row) {
            if ($fila === 0) {
                // Cabecera esperada:
                // Código producto, Nombre, Marca, Jerarquía marca,
                // Tarifa cliente, Cant.Stock, Talla, Cod Talla, Imagen, Código Barras
                if (!isset($row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $row[7], $row[8], $row[9])) {
                    $this->logger->log(
                        'Cabecera catálogo web Redstring incompleta',
                        'ERROR'
                    );
                    return false;
                }

                if (
                    $row[0] !== 'Código producto'
                    || $row[1] !== 'Nombre'
                    || $row[2] !== 'Marca'
                    || $row[3] !== 'Jerarquía marca'
                    || $row[4] !== 'Tarifa cliente'
                    || $row[5] !== 'Cant.Stock'
                    || $row[6] !== 'Talla'
                    || $row[7] !== 'Cod Talla'
                    || $row[8] !== 'Imagen'
                    || $row[9] !== 'Código Barras'
                ) {
                    $error_cabeceras = 'Cabeceras: ';
                    foreach ($row as $text) {
                        $error_cabeceras .= ($text ?: 'vacio') . ' - ';
                    }

                    $this->logger->log(
                        'Error analizando cabecera del catálogo web Redstring: ' . $error_cabeceras,
                        'ERROR'
                    );
                    return false;
                }

                $this->logger->log(
                    'Catálogo web Redstring con cabeceras correctas - '
                    . implode(' - ', array_slice($row, 0, 10)),
                    'INFO'
                );

                $header_values = $row;
                continue;
            }

            // Combinamos cabecera con cada fila
            if (!array_filter($row, 'strlen')) {
                // fila vacía
                continue;
            }

            $this->catalogo_web[] = array_combine($header_values, $row);
        }

        $this->logger->log(
            'Catálogo web Redstring cargado en memoria, filas: ' . count($this->catalogo_web),
            'INFO'
        );

        return true;
    }

    /**
     * Parseo del catálogo web a formato "normalizado"
     * para tu importador general (stock / datos básicos)
     *
     * @param string $filename
     * @return array
     */
    public function parseWeb($filename)
    {
        if (empty($this->catalogo_web)) {
            if (!$this->checkCatalogoWeb($filename)) {
                return [];
            }
        }

        $productos = [];

        foreach ($this->catalogo_web as $fila) {
            // seguridad por si falta algo
            $ref = isset($fila['Código producto']) ? trim($fila['Código producto']) : '';
            $nombre = isset($fila['Nombre']) ? trim($fila['Nombre']) : '';
            $marca = isset($fila['Marca']) ? trim($fila['Marca']) : '';
            $stock_r = isset($fila['Cant.Stock']) ? trim($fila['Cant.Stock']) : '';
            $ean_r = isset($fila['Código Barras']) ? trim($fila['Código Barras']) : '';
            $img_r = isset($fila['Imagen']) ? trim($fila['Imagen']) : '';
            $talla = isset($fila['Talla']) ? trim($fila['Talla']) : '';
            $cod_talla = isset($fila['Cod Talla']) ? trim($fila['Cod Talla']) : '';

            if ($ref === '' || $nombre === '') {
                continue;
            }

            $ean_limpio = preg_replace('/\D/', '', $ean_r);
            $stock_int = (int) preg_replace('/[^\d\-]/', '', $stock_r);
            $disponible = $stock_int > 0 ? 1 : 0;

            // Normalización de imagen: Redstring ya da URL, la usamos tal cual
            $imagenes = [];
            if ($img_r !== '') {
                $imagenes[] = $img_r;
            }

            // Descripción sencilla
            $descripcion = '';
            if ($marca) {
                $descripcion .= '<br>Marca: ' . $marca . '.';
            }
            if ($talla) {
                $descripcion .= $descripcion . '<br>Talla: ' . $talla . ' - ' . $cod_talla;
            }
            $para_ia = '<br>Producto con licencia oficial.<br> Un artículo perfecto para un regalo original o para un capricho.';
            $descripcion = $descripcion . $para_ia;

            //en este catálogo de moemnto no tenemos url de producto, vamos a meter la url a la web de redstring
            $url_proveedor = 'https://www.redstring.es/home-h-1-50/';

            // No tenemos coste ni pvp aquí, se actualiza desde el CSV de servidor
            $productos[] = [
                'referencia_proveedor' => $ref,
                'url_proveedor' => $url_proveedor,
                'nombre' => pSQL($nombre),
                'ean' => $ean_limpio,
                'coste' => 0,
                'pvp_sin_iva' => 0,
                'peso' => 0.444, // fallback
                'disponibilidad' => $disponible,
                'description_short' => $descripcion,
                'manufacturer_name' => $marca ?: null,
                'id_manufacturer' => $this->getManufacturerId($marca),
                'imagenes' => $imagenes,
                'fuente' => $this->config_web['tipo'] . '-' . $this->config_ftp['tipo'],
                'ignorar' => 0,
            ];
        }

        $this->logger->log(
            'Parseados ' . count($productos) . ' productos desde catálogo web Redstring',
            'INFO'
        );

        return $productos;
    }

    protected function buildCostMap(array $costes)
    {
        $costMap = [];

        foreach ($costes as $c) {
            if (empty($c['referencia_proveedor'])) {
                continue;
            }

            $ref = trim($c['referencia_proveedor']);
            $ean_norm = $this->normalizaEan(isset($c['ean']) ? $c['ean'] : '');
            $coste = isset($c['coste']) ? (float) $c['coste'] : 0.0;

            // clave principal ref + ean_norm (ya con tu regla de últimos 13 + ceros)
            $key = $ref . '|' . $ean_norm;
            $costMap[$key] = $coste;

            // clave secundaria solo por ref (para casos sin EAN o EAN loco)
            $keySoloRef = $ref . '|';
            if (!array_key_exists($keySoloRef, $costMap)) {
                $costMap[$keySoloRef] = $coste;
            }
        }

        $this->logger->log(
            'Redstring: construido mapa de costes con ' . count($costMap) . ' claves',
            'INFO'
        );

        return $costMap;
    }

    protected function aplicarCostesEnProductos(array $productos, array $costMap)
    {
        $sinCoste = 0;
        $soloRef = 0;
        $conCoste = 0;

        foreach ($productos as &$p) {
            if (empty($p['referencia_proveedor'])) {
                continue;
            }

            $ref = trim($p['referencia_proveedor']);
            $ean_norm = $this->normalizaEan(isset($p['ean']) ? $p['ean'] : '');

            $key = $ref . '|' . $ean_norm;
            $key2 = $ref . '|';

            if (isset($costMap[$key])) {
                $p['coste'] = (float) $costMap[$key];
                $conCoste++;
            } elseif (isset($costMap[$key2])) {
                $p['coste'] = (float) $costMap[$key2];
                $soloRef++;

                $this->logger->log(
                    'Redstring: coste asignado solo por referencia (EAN no coincidente o vacío) - ref '
                    . $ref . ' ean_norm ' . $ean_norm,
                    'WARNING'
                );
            } else {
                // sin coste encontrado
                $p['coste'] = isset($p['coste']) ? (float) $p['coste'] : 0.0;
                $sinCoste++;

                $this->logger->log(
                    'Redstring: sin coste en catálogo servidor para referencia ' . $ref . ' (EAN_norm ' . $ean_norm . ')',
                    'WARNING'
                );
            }
        }
        unset($p);

        $this->logger->log(
            'Redstring: resumen merge costes: conCoste=' . $conCoste
            . ', soloRef=' . $soloRef . ', sinCoste=' . $sinCoste,
            'INFO'
        );

        return $productos;
    }

    /**
     * Parseo combinado Redstring:
     *   - valida y parsea catálogo de servidor (costes)
     *   - construye mapa de costes
     *   - valida y parsea catálogo web (stock / datos)
     *   - aplica costes del servidor a los productos web
     *
     * Devuelve el array de productos listo para CatalogImporter->saveProducts()
     *
     * @param string $fileServidor  Ruta al CSV de costes del servidor
     * @param string $fileWeb       Ruta al XLS del catálogo web
     * @return array
     */
    public function parseCombinado($fileServidor, $fileWeb)
    {
        // 1) Validar catálogo servidor
        if (!$this->checkCatalogoServidor($fileServidor)) {
            $this->logger->log(
                'Redstring: checkCatalogoServidor() ha fallado en parseCombinado()',
                'ERROR'
            );
            return [];
        }

        // 2) Parsear costes servidor
        $costes = $this->parseServidor($fileServidor);
        $this->logger->log(
            'Redstring: parseServidor() devuelve ' . count($costes) . ' filas de costes',
            'INFO'
        );

        // 3) Construir mapa de costes
        $costMap = $this->buildCostMap($costes);

        // 4) Validar catálogo web
        if (!$this->checkCatalogoWeb($fileWeb)) {
            $this->logger->log(
                'Redstring: checkCatalogoWeb() ha fallado en parseCombinado()',
                'ERROR'
            );
            return [];
        }

        // 5) Parsear productos web (sin coste aún)
        $productos = $this->parseWeb($fileWeb);
        $this->logger->log(
            'Redstring: parseWeb() devuelve ' . count($productos) . ' productos web',
            'INFO'
        );

        // 6) Aplicar costes a los productos web
        $productosConCoste = $this->aplicarCostesEnProductos($productos, $costMap);

        return $productosConCoste;
    }



    /* ============================================================
     *   HELPERS COMUNES
     * ============================================================ */

    //aunque aquí normalicemos el ean como cuando enviamos a BD en CatalogImporter, no importa, porque quedará ya correcto para ese proceso. Aquí nos sirve para comparar el catálogo web con el catálogo servidor y poder asignar a la información del catálogo web las tarifas correctas, del catálogo servidor
    protected function normalizaEan($ean_raw)
    {
        // 1) Solo dígitos
        $ean_limpio = preg_replace('/\D/', '', (string) $ean_raw);

        // 2) Tomar los últimos 13 dígitos (o todos si hay menos)
        $last13 = strlen($ean_limpio) > 13
            ? substr($ean_limpio, -13)
            : $ean_limpio;

        // 3) Rellenar con ceros a la izquierda hasta 13
        $ean_norm = str_pad($last13, 13, '0', STR_PAD_LEFT);

        return $ean_norm;
    }


    protected function normalizaTexto($texto)
    {
        // Elimina BOM
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', (string) $texto);

        // Elimina chars de control
        $texto = preg_replace('/[\x00-\x1F\x7F]/u', '', $texto);

        // Trim
        $texto = trim($texto);

        // Normaliza acentos típicos (es, fr, de…)
        $acentos = [
            'Á' => 'A',
            'À' => 'A',
            'Â' => 'A',
            'Ä' => 'A',
            'á' => 'a',
            'à' => 'a',
            'â' => 'a',
            'ä' => 'a',
            'É' => 'E',
            'È' => 'E',
            'Ê' => 'E',
            'Ë' => 'E',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'Í' => 'I',
            'Ì' => 'I',
            'Î' => 'I',
            'Ï' => 'I',
            'í' => 'i',
            'ì' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'Ó' => 'O',
            'Ò' => 'O',
            'Ô' => 'O',
            'Ö' => 'O',
            'ó' => 'o',
            'ò' => 'o',
            'ô' => 'o',
            'ö' => 'o',
            'Ú' => 'U',
            'Ù' => 'U',
            'Û' => 'U',
            'Ü' => 'U',
            'ú' => 'u',
            'ù' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'Ñ' => 'N',
            'ñ' => 'n',
            'Ç' => 'C',
            'ç' => 'c',
        ];

        $texto = strtr($texto, $acentos);

        // Minúsculas
        return mb_strtolower($texto, 'UTF-8');
    }

    protected function getManufacturerId($nombre)
    {
        if (!$nombre) {
            return null;
        }

        $id = Db::getInstance()->getValue('
            SELECT id_manufacturer
            FROM ' . _DB_PREFIX_ . 'manufacturer
            WHERE LOWER(name) = "' . pSQL(strtolower($nombre)) . '"
        ');

        return $id ? (int) $id : null;
    }

    /* ============================================================
     *   INTERFAZ AbstractCatalogReader (si quisieras usarla)
     * ============================================================ */

    /**
     * Si en algún momento quieres usar este Reader en modo "combinado"
     * para CatalogImporter, podrías implementar los métodos estándar:
     *
     * - fetch() → descargar servidor + web
     * - checkCatalogo() → validar ambos
     * - parse() → cruzar datos de costes + web en un solo array
     *
     * Por ahora los dejamos vacíos o como alias, según tu flujo.
     */

    public function fetch()
    {
        // Ejemplo: solo dejar que CatalogImporter use el catálogo web
        return $this->fetchWeb();
    }

    public function checkCatalogo($filename)
    {
        // Ejemplo: delegar en checkCatalogoWeb()
        return $this->checkCatalogoWeb($filename);
    }

    public function parse($filename)
    {
        // Ejemplo: delegar en parseWeb()
        return $this->parseWeb($filename);
    }
}
