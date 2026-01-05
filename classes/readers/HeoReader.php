<?php

require_once(_PS_MODULE_DIR_ . 'frikimportproductos/classes/AbstractCatalogReader.php');
require_once _PS_ROOT_DIR_ . '/classes/utils/LoggerFrik.php';
require_once _PS_MODULE_DIR_ . 'frikimportproductos/classes/ManufacturerAliasHelper.php';

class HeoReader extends AbstractCatalogReader
{
    protected $config;
    protected $logger;
    protected $path_descarga;

    protected $columnas_catalogo = array(
        0 => "productNumber",
        1 => "nameDe",
        2 => "nameEn",
        3 => "nameFr",
        4 => "nameEs",
        5 => "descriptionDe",
        6 => "descriptionEn",
        7 => "descriptionFr",
        8 => "descriptionEs",
        9 => "manufacturerDimensionsWidth",
        10 => "manufacturerDimensionsLength",
        11 => "manufacturerDimensionsHeight",
        12 => "manufacturerDimensionsWeight",
        13 => "validatedDimensionsWidth",
        14 => "validatedDimensionsLength",
        15 => "validatedDimensionsHeight",
        16 => "validatedDimensionsWeight",
        17 => "countryOfOrigin",
        18 => "tariffNumber",
        19 => "ageRating",
        20 => "vatType",
        21 => "releaseDate",
        22 => "mainImageLargeUrl",
        23 => "mainImageThumbnailUrl",
        24 => "image1Url",
        25 => "image2Url",
        26 => "image3Url",
        27 => "image4Url",
        28 => "image5Url",
        29 => "additionalImages",
        30 => "packagingUnit",
        31 => "packagingQuantity",
        32 => "packagingType",
        33 => "deliveryType",
        34 => "isEndOfLife",
        35 => "gtin",
        36 => "barcodes",
        37 => "categoriesDe",
        38 => "categoriesEn",
        39 => "categoriesFr",
        40 => "categoriesEs",
        41 => "themesDe",
        42 => "themesEn",
        43 => "themesFr",
        44 => "themesEs",
        45 => "manufacturersDe",
        46 => "manufacturersEn",
        47 => "manufacturersFr",
        48 => "manufacturersEs",
        49 => "typesDe",
        50 => "typesEn",
        51 => "typesFr",
        52 => "typesEs",
        53 => "specialsDe",
        54 => "specialsEn",
        55 => "specialsFr",
        56 => "specialsEs",
        57 => "scaledDiscounts",
        58 => "basePricePerUnit",
        59 => "suggestedRetailPricePerUnit",
        60 => "retailerProductDiscount",
        61 => "discountedPricePerUnit",
        62 => "currencyIsoCode",
        63 => "availability",
        64 => "intakeDate",
        65 => "intakeConfirmationStatus",
        66 => "intakeAvailability",
        67 => "intakeQuantity",
        68 => "preorderDeadline",
        69 => "availabilityState",
        70 => "availabilityEta",
        71 => "gpsrSafetyLabels",
        72 => "gpsrDistributors",
        73 => "gpsrManufacturers",
        74 => "gpsrMedia",
        75 => "isGpsrCompliant",
        76 => "isMarketplaceCompliant",
        77 => "languageEditions",
        78 => "availableToOrder",
        79 => "inStockAvailability",
        80 => "strikePricePerUnit"
    );


    public function __construct($id_proveedor, LoggerFrik $logger)
    {
        $this->config = Db::getInstance()->getRow('
            SELECT * FROM ' . _DB_PREFIX_ . 'import_proveedores 
            WHERE id_supplier = ' . (int) $id_proveedor
        );

        if (!$this->config) {
            throw new Exception('No se encontró configuración para el proveedor con ID ' . $id_proveedor);
        }

        $this->logger = $logger;
        $this->path_descarga = _PS_MODULE_DIR_ . 'frikimportproductos/import/heo/';
    }

    /**
     * Descarga el catálogo completo de Heo vía shell_exec y cURL
     */
    public function fetch()
    {
        $username = $this->config['usuario'];
        $password = $this->config['password'];
        $endpoint = $this->config['url'];

        $cmd = 'curl -s -H "Authorization: Basic '
            . base64_encode($username . ':' . $password)
            . '" "' . $endpoint . '"';

        $this->logger->log('Ejecutando descarga Heo con comando: ' . $cmd, 'INFO');

        $response = shell_exec($cmd);

        if (!$response) {
            $this->logger->log('Respuesta vacía al descargar catálogo de Heo', 'ERROR');
            return false;
        }

        $archivo = 'catalogo_completo_heo.txt';
        $ruta_archivo = $this->path_descarga . $archivo;

        if (file_put_contents($ruta_archivo, $response) === false) {
            $this->logger->log('Error guardando catálogo Heo en ' . $ruta_archivo, 'ERROR');
            return false;
        }

        $this->logger->log('Catálogo Heo guardado correctamente en ' . $ruta_archivo, 'INFO');

        return $ruta_archivo;
    }

    public function checkCatalogo($filename)
    {
        $handle = fopen($filename, "r");
        if ($handle === false) {
            $this->logger->log('Error abriendo archivo de catálogo ' . $filename, 'ERROR');
            return false;
        }

        $linea = fgetcsv($handle, 0, ";");

        // número de columnas
        if (count($linea) != count($this->columnas_catalogo)) {
            $this->logger->log('Número de columnas incorrecto: ' . count($linea) . ' en vez de ' . count($this->columnas_catalogo), 'ERROR');
            return false;
        }

        // comprobar nombres de cabecera
        foreach ($linea as $i => $col) {
            if ($i == 0)
                continue; // saltamos la primera
            if (trim($col) != trim($this->columnas_catalogo[$i])) {
                $this->logger->log("Columna $i incorrecta: '" . trim($col) . "' debería ser '" . trim($this->columnas_catalogo[$i]) . "'", 'ERROR');
                return false;
            }
        }

        $this->logger->log('Formato catálogo correcto', 'INFO');
        return true;
    }

    public function parse($filename)
    {
        $handle = fopen($filename, "r");
        if ($handle === false) {
            $this->logger->log("No se pudo abrir el archivo $filename", 'ERROR');
            return [];
        }

        // Saltar cabecera (ya validada con checkCatalogo)
        fgetcsv($handle, 0, ";");

        $productos = [];
        while (($campos = fgetcsv($handle, 0, ";")) !== false) {
            $producto = $this->normalizeRow($campos);
            if ($producto) {
                $productos[] = $producto;
            }
        }

        fclose($handle);

        $this->logger->log("Parseados " . count($productos) . " productos de Heo", 'INFO');
        return $productos;
    }

    public function normalizeRow($campos)
    {
        $referencia = trim($campos[0]);
        if (!$referencia)
            return false;

        $nombre = pSQL(trim($campos[4]));
        if (!$nombre)
            return false;

        $ean = trim($campos[35]) ?: '';

        $stock = trim($campos[63]);
        $disponibilidad = in_array($stock, ['GREEN', 'YELLOW']) ? 1 : 0;

        $precio = str_replace(',', '.', trim($campos[61])) ?: 0;
        $packaging_quantity = (int) trim($campos[31]) ?: 1;
        $precio = round($precio / $packaging_quantity, 2);

        //en el caso de Heo, queremos ignorar los productos que no tengan packaging_quantity 1, para poder aplicar esto a otros catálogos (la posibilidad de enviar como ignorado), enviaremos una variable 'ignorar' 1 o 0 desde aquí.
        $ignorar = $packaging_quantity != 1 ? 1 : 0;

        $pvp = str_replace(',', '.', trim($campos[59])) ?: 0;

        //ponemos peso volumétrico, para ello multiplicamos medidas del producto, longitud*anchura*altura y lo dividimos entre 6000000
        // $peso = trim($campos[16]) ? $campos[16]/1000 : 0.444;
        $volumetrico = ROUND(((int) trim($campos[9]) * (int) trim($campos[10]) * (int) trim($campos[11])) / 6000000);
        $peso = $volumetrico ? $volumetrico : 0.444;

        // $imagen_principal = trim($campos[22]) ?: '';

        $imagenes = [
            trim($campos[22]) ?: '',
            trim($campos[25]) ?: '',
            trim($campos[26]) ?: '',
            trim($campos[27]) ?: '',
            trim($campos[28]) ?: ''
        ];

        $adittional_images = trim($campos[29]) ?: '';

        if ($adittional_images) {
            $imagenes = array_merge($imagenes, explode(",", $adittional_images));
        }

        $descripcion = pSQL(trim($campos[8])) ?: '';

        $url_producto = 'https://www.heo.com/de/es/product/' . $referencia;

        //Cogemos de varias columnas los valores categoria, tema, fabricante y tipo, que vienen con un código separado de : del valor que queremos
        $heo_categorie = explode(':', pSQL(trim($campos[40])))[1] ?? '';
        $heo_theme = explode(':', pSQL(trim($campos[44])))[1] ?? '';
        $manufacturer_name = explode(':', pSQL(trim($campos[48])))[1] ?? '';
        $heo_type = explode(':', pSQL(trim($campos[52])))[1] ?? '';

        $datos_heo = '<br>Categoria: ' . $heo_categorie . '.<br>Tema: ' . $heo_theme . '.<br>Fabricante: ' . $manufacturer_name . '.<br>Tipo: ' . $heo_type . '.';

        $para_ia = '<br>Producto con licencia oficial.<br> Un artículo perfecto para un regalo original o para un capricho.';

        $descripcion = $descripcion . $datos_heo . $para_ia;

        // buscamos el fabricante por su nombre para ver si existe, en cuyo caso obtenemos su id. Si no existe o no hay nombre de fabricante, id_manufacturer queda null y se creará al crear el producto
        $id_manufacturer = null;
        if ($manufacturer_name) {           
            //si devuelve null quedará como pending y cuando se cree el producto se volverá a intentar resolver el nombre. Habrá que crear el alias asignado a un fabricante o crear un nuevo fabricante
            $id_manufacturer = ManufacturerAliasHelper::resolveName($manufacturer_name, 'Heo');            
        }

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
            'ignorar' => $ignorar
        ];
    }   

}