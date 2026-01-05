<?php

require_once(_PS_MODULE_DIR_ . 'frikimportproductos/classes/AbstractCatalogReader.php');
require_once _PS_ROOT_DIR_ . '/classes/utils/LoggerFrik.php';


class KaractermaniaReader extends AbstractCatalogReader
{
    /** @var array */
    protected $config;

    /** @var LoggerFrik */
    protected $logger;

    /** @var int */
    protected $id_supplier;

    /** @var string */
    protected $supplier = 'Karactermania';

    // Descuento de coste aplicado por Karactermania (30%)
    protected $price_discount = 0.30;

    /** @var string */
    protected $path_descarga = _PS_MODULE_DIR_ . 'frikimportproductos/import/karactermania/';

    /** @var string|null */
    protected $path_catalogo;

    /** @var string */
    protected $delimitador = '~';

    /** @var array */
    protected $catalogo = [];

    protected $columnas_catalogo = array(
        0 => "SKU",
        1 => "Product Name",
        2 => "Brand Name",
        3 => "Product ID",
        4 => "Product ID Type",
        5 => "Suggested ASIN",
        6 => "Product Category",
        7 => "Product Subcategory",
        8 => "Recommended Browse Node",
        9 => "Item Type Name",
        10 => "Model Number",
        11 => "Manufacturer",
        12 => "MSRP Currency",
        13 => "MSRP",
        14 => "Cost Price",
        15 => "Cost Price Currency",
        16 => "Bullet Point 1",
        17 => "Bullet Point 2",
        18 => "Bullet Point 3",
        19 => "Bullet Point 4",
        20 => "Bullet Point 5",
        21 => "Search Keywords",
        22 => "Style",
        23 => "Stock",
        24 => "Outer Material",
        25 => "Innter Material",
        26 => "Product Description",
        27 => "Model Name",
        28 => "Color",
        29 => "Color Map",
        30 => "Country of Origin",
        31 => "Item Booking Date",
        32 => "Earliest Shipping Date",
        33 => "Item Length",
        34 => "Item Length Unit",
        35 => "Product Width",
        36 => "Item Width Unit",
        37 => "Product Height",
        38 => "Item Height Unit",
        39 => "Item Package Length",
        40 => "Package Length Unit",
        41 => "Item Package Width",
        42 => "Package Width Unit",
        43 => "Item Package Height",
        44 => "Package Height Unit",
        45 => "Item Weight",
        46 => "Item Weight Unit",
        47 => "Package Weight",
        48 => "Package Weight Unit",
        49 => "Capacity",
        50 => "Capacity Unit",
        51 => "Retailer Minimum",
        52 => "Wholesaler Minimum",
        53 => "License",
        54 => "URL Image 1",
        55 => "URL Image 2",
        56 => "URL Image 3",
        57 => "URL Image 4",
        58 => "URL Image 5",
        59 => "St.Teorico",
        60 => "Entrada",
        61 => "Codi.Arancelari"
    );

    /**
     * Constructor
     *
     * @param int        $id_supplier  id_supplier Karactermania (53)
     * @param LoggerFrik $logger
     *
     * @throws Exception
     */
    public function __construct($id_supplier, LoggerFrik $logger)
    {
        $this->id_supplier = (int) $id_supplier;
        $this->logger = $logger;

        $this->config = Db::getInstance()->getRow('
            SELECT *
            FROM ' . _DB_PREFIX_ . 'import_proveedores
            WHERE id_supplier = ' . (int) $id_supplier
        );

        if (!$this->config) {
            throw new Exception('No se encontró configuración para Karactermanía con id_supplier ' . $id_supplier);
        }
    }

    /**
     * Descarga el catálogo desde el FTP de Karactermanía
     * y deja $this->path_catalogo apuntando al fichero local.
     * 
     *
     * @return string|false Ruta al archivo descargado o false si error
     */
    public function fetch()
    {
        $this->logger->log('Comienzo descarga catálogo Karactermanía desde servidor FTP', 'INFO');

        $ftp_server = $this->config['url'];
        $ftp_username = $this->config['usuario'];
        $ftp_password = $this->config['password'];

        // Conexión FTP
        $ftp_connection = @ftp_connect($ftp_server);
        if (!$ftp_connection) {
            $this->logger->log(
                'Error conectando al servidor FTP Karactermanía: ' . $ftp_server,
                'ERROR'
            );
            return false;
        }

        // Login
        $ftp_login = @ftp_login($ftp_connection, $ftp_username, $ftp_password);
        if (!$ftp_login) {
            $this->logger->log(
                'Error haciendo login en FTP Karactermanía: ' . $ftp_server,
                'ERROR'
            );
            ftp_close($ftp_connection);
            return false;
        }

        // Forzar modo pasivo (tema Arsys)
        ftp_pasv($ftp_connection, true);

        // Archivo remoto y local
        $remote_file_path = '@ARTICULOS.csv';
        $nombre_catalogo = 'catalogo_completo_karactermania_' . date('Y-m-d_His') . '.csv';
        $this->path_catalogo = $this->path_descarga . $nombre_catalogo;

        // Descargar
        if (
            !@ftp_get(
                $ftp_connection,
                $this->path_catalogo,
                $remote_file_path,
                FTP_BINARY
            )
        ) {
            $error_ftp = error_get_last();
            $msg = isset($error_ftp['message']) ? $error_ftp['message'] : 'Desconocido';

            $this->logger->log(
                'Error copiando catálogo Karactermanía desde FTP - Mensaje error: ' . $msg,
                'ERROR'
            );
            ftp_close($ftp_connection);
            return false;
        }

        ftp_close($ftp_connection);

        $this->logger->log(
            'Catálogo servidor Karactermanía descargado correctamente: ' . $this->path_catalogo,
            'INFO'
        );

        return $this->path_catalogo;
    }

    /**
     * Valida el archivo de catálogo
     *
     * @param string $filename
     * @return bool
     */
    public function checkCatalogo($filename)
    {
        if (!file_exists($filename)) {
            $this->logger->log(
                'Archivo de catálogo de servidor Karactermanía no encontrado: ' . $filename,
                'ERROR'
            );
            return false;
        }

        $handle = fopen($filename, "r");
        if ($handle === false) {
            $this->logger->log('Error abriendo archivo de catálogo de Karactermanía: ' . $filename, 'ERROR');
            return false;
        }

        $linea = fgetcsv($handle, 0, $this->delimitador);
        if (!$linea) {
            $this->logger->log(
                'No se pudo leer la cabecera del catálogo de Karactermanía',
                'ERROR'
            );
            fclose($handle);
            return false;
        }

        // número de columnas
        if (count($linea) != count($this->columnas_catalogo)) {
            $this->logger->log('Número de columnas incorrecto: ' . count($linea) . ' en vez de ' . count($this->columnas_catalogo), 'ERROR');
            return false;
        }

        // comprobar nombres de cabecera
        foreach ($linea as $i => $col) {
            // if ($i == 0) continue; // saltamos la primera
            if (strtolower(trim($col)) != strtolower(trim($this->columnas_catalogo[$i]))) {
                $this->logger->log("Columna $i incorrecta: '" . trim($col) . "' debería ser '" . trim($this->columnas_catalogo[$i]) . "'", 'ERROR');
                return false;
            }
        }

        $this->logger->log('Formato catálogo correcto', 'INFO');

        return true;
    }

    /**
     * Parseo del catálogo completo de Karactermanía.
     * Devuelve array de productos normalizados para CatalogImporter.
     *
     * @param string $filename
     * @return array
     */
    public function parse($filename)
    {
        $handle = fopen($filename, "r");
        if ($handle === false) {
            $this->logger->log("No se pudo abrir el archivo $filename", 'ERROR');
            return [];
        }

        // Saltar cabecera (ya validada con checkCatalogoServidor)
        fgetcsv($handle, 0, $this->delimitador);

        $productos = [];
        while (($campos = fgetcsv($handle, 0, $this->delimitador)) !== false) {
            $producto = $this->normalizeRow($campos);
            if ($producto) {
                $productos[] = $producto;
            }
        }

        fclose($handle);

        $this->logger->log(
            "Parseados " . count($productos) . " productos de Karactermanía",
            'INFO'
        );
        return $productos;
    }

    public function normalizeRow($campos)
    {
        // SKU / referencia
        $referencia = isset($campos[0]) ? pSQL(trim($campos[0])) : '';
        if (!$referencia) {
            return false;
        }

        // Stock
        $stock = isset($campos[23]) ? (int) trim($campos[23]) : 0;

        // Nombre (ANSI -> UTF-8)
        $nombre = isset($campos[1])
            ? pSQL(trim($this->toUtf8($campos[1])))
            : '';
        if (!$nombre) {
            return false;
        }

        // EAN (Product ID)
        $ean = isset($campos[3])
            ? pSQL(trim($campos[3]))
            : '';
        if (!$ean) {
            $ean = '';
        }

        // Coste (Cost Price) con coma y descuento
        $precio_raw = isset($campos[14])
            ? str_replace(',', '.', trim($campos[14]))
            : '';
        if ($precio_raw === '' || $precio_raw === null) {
            $precio = 0;
        } else {
            $precio = (float) $precio_raw;
            $precio = round($precio - $precio * $this->price_discount, 2);
        }

        // PVP (MSRP)
        $pvp_raw = isset($campos[13])
            ? str_replace(',', '.', trim($campos[13]))
            : '';
        $pvp = $pvp_raw !== '' ? (float) $pvp_raw : 0;

        // Peso (Item Weight, en kg con coma)
        $peso_raw = isset($campos[45])
            ? str_replace(',', '.', trim($campos[45]))
            : '';
        $peso = $peso_raw !== '' ? (float) $peso_raw : 0.444;

        // Imágenes (hasta 5)
        $imagenes = [];
        $indices_imagenes = [54, 55, 56, 57, 58];

        foreach ($indices_imagenes as $idx) {
            if (isset($campos[$idx])) {
                $url = trim($campos[$idx]);
                if ($url !== '' && !is_null($url)) {
                    $imagenes[] = $url;
                }
            }
        }

        // URL producto
        $url_producto = 'https://karactermania.com/es/catalogsearch/result?query=' . $referencia;

        // Descripción: varios campos concatenados + marca + licencia
        $partesDesc = [];

        // Product Description
        if (isset($campos[26])) {
            $txt = trim($campos[26]);
            if ($txt !== '') {
                $partesDesc[] = pSQL($this->toUtf8($txt));
            }
        }

        // Bullet Point 1–5 (16–20)
        for ($i = 16; $i <= 20; $i++) {
            if (isset($campos[$i])) {
                $txt = trim($campos[$i]);
                if ($txt !== '') {
                    $partesDesc[] = pSQL($this->toUtf8($txt));
                }
            }
        }

        // Marca (Brand Name, índice 2)
        $manufacturer_name = '';
        if (isset($campos[2])) {
            $manufacturer_name = trim($this->toUtf8($campos[2]));
            if ($manufacturer_name !== '') {
                $partesDesc[] = 'Marca: ' . pSQL($manufacturer_name);
            }
        }

        // Licencia (53)
        if (isset($campos[53])) {
            $lic = trim($this->toUtf8($campos[53]));
            if ($lic !== '') {
                $partesDesc[] = 'Licencia: ' . pSQL($lic);
            }
        }

        $descripcion = implode('<br><br>', $partesDesc);

        // Frase estándar para IA
        $para_ia = '<br>Producto con licencia oficial.<br> Un artículo perfecto para un regalo original o para un capricho.';
        $descripcion .= '<br><br>' . $para_ia;

        $disponibilidad = $stock > 0 ? 1 : 0;

        // Fabricante en Prestashop. Aunque se llamen Oh my Pop!, PRODG, Forecer Ninette, son todos Karactermanía ¿?
        $id_manufacturer = 47;
        $manufacturer_name = "Karactermanía";        

        // ignorar: de momento 0
        $ignorar = 0;

        return [
            'referencia_proveedor' => $referencia,
            'url_proveedor' => $url_producto,
            'nombre' => $nombre,
            'ean' => $ean,
            'coste' => $precio,
            'pvp_sin_iva' => $pvp,
            'peso' => $peso,
            'disponibilidad' => $disponibilidad,
            'description_short' => $descripcion,
            'manufacturer_name' => $manufacturer_name ?: null,
            'id_manufacturer' => $id_manufacturer,
            'imagenes' => array_filter($imagenes),
            'fuente' => $this->config['tipo'],
            'ignorar' => $ignorar,
        ];
    }

    /**
     * Convierte texto "ANSI" (normalmente Windows-1252) a UTF-8. utf8_encode() funciona en PHP 7.0.33 pero de esta manera valdrá para >8.2
     * Si ya está en UTF-8, lo deja tal cual.
     * mb_detect_encoding y iconv existen en PHP 7.0.33 sin problema (siempre que estén mbstring e iconv activados)
     *
     * @param string|null $texto
     * @return string
     */
    protected function toUtf8($texto)
    {
        if ($texto === null || $texto === '') {
            return '';
        }

        // Si ya es UTF-8, no tocamos
        if (mb_detect_encoding($texto, 'UTF-8', true)) {
            return $texto;
        }

        // Intentamos convertir desde Windows-1252 (típico "ANSI" de Excel/Windows)
        $convertido = @iconv('Windows-1252', 'UTF-8//IGNORE', $texto);

        if ($convertido === false || $convertido === '') {
            // Si por lo que sea falla, devolvemos el original
            // (en PHP 7.0.33 podrías usar utf8_encode como fallback si quieres)
            // $convertido = utf8_encode($texto);
            return $texto;
        }

        return $convertido;
    }

}
