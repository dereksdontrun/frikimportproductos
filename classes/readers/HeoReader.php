<?php

require_once(_PS_MODULE_DIR_.'frikimportproductos/classes/AbstractCatalogReader.php');
require_once _PS_ROOT_DIR_.'/classes/utils/LoggerFrik.php';

class HeoReader extends AbstractCatalogReader
{
    protected $config;
    protected $logger;
    protected $pathDescarga;

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


    public function __construct($idProveedor, LoggerFrik $logger)
    {
        $this->config = Db::getInstance()->getRow('
            SELECT * FROM '._DB_PREFIX_.'import_proveedores 
            WHERE id_supplier = '.(int)$idProveedor
        );

        if (!$this->config) {
            throw new Exception('No se encontró configuración para el proveedor con ID '.$idProveedor);
        }

        $this->logger = $logger;
        $this->pathDescarga = _PS_MODULE_DIR_.'frikimportproductos/import/heo/';
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
            . base64_encode($username.':'.$password) 
            . '" "'.$endpoint.'"';

        $this->logger->log('Ejecutando descarga Heo con comando: '.$cmd, 'INFO');

        $response = shell_exec($cmd);

        if (!$response) {
            $this->logger->log('Respuesta vacía al descargar catálogo de Heo', 'ERROR');
            return false;
        }

        $archivo = 'catalogo_completo_heo.txt';
        $rutaArchivo = $this->pathDescarga.$archivo;

        if (file_put_contents($rutaArchivo, $response) === false) {
            $this->logger->log('Error guardando catálogo Heo en '.$rutaArchivo, 'ERROR');
            return false;
        }

        $this->logger->log('Catálogo Heo guardado correctamente en '.$rutaArchivo, 'INFO');

        return $rutaArchivo;
    }

    public function checkCatalogo($filename)
    {
        $handle = fopen($filename, "r");
        if ($handle === false) {
            $this->logger->log('Error abriendo archivo de catálogo '.$filename, 'ERROR');
            return false;
        }

        $linea = fgetcsv($handle, 0, ";");

        // número de columnas
        if (count($linea) != count($this->columnas_catalogo)) {
            $this->logger->log('Número de columnas incorrecto: '.count($linea).' en vez de '.count($this->columnas_catalogo), 'ERROR');
            return false;
        }

        // comprobar nombres de cabecera
        foreach ($linea as $i => $col) {
            if ($i == 0) continue; // saltamos la primera
            if (trim($col) != trim($this->columnas_catalogo[$i])) {
                $this->logger->log("Columna $i incorrecta: '".trim($col)."' debería ser '".trim($this->columnas_catalogo[$i])."'", 'ERROR');
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

        $this->logger->log("Parseados ".count($productos)." productos de Heo", 'INFO');
        return $productos;
    }

    public function normalizeRow($campos)
    {
        $referencia = trim($campos[0]);
        if (!$referencia) return false;

        $nombre = pSQL(trim($campos[4]));
        if (!$nombre) return false;

        $ean = trim($campos[35]) ?: '';

        $estado = trim($campos[63]);
        $disponibilidad = in_array($estado, ['GREEN','YELLOW']) ? 1 : 0;

        $precio = str_replace(',','.',trim($campos[61])) ?: 0;
        $packaging_quantity = (int)trim($campos[31]) ?: 1;
        $precio = round($precio / $packaging_quantity, 2);

        $pvp = str_replace(',','.',trim($campos[59])) ?: 0;

        $peso = trim($campos[16]) ? $campos[16]/1000 : 0.444;

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
        
        $url_producto = 'https://www.heo.com/de/es/product/'.$referencia;

        //Cogemos de varias columnas los valores categoria, tema, fabricante y tipo, que vienen con un código separado de : del valor que queremos
        $heo_categorie = explode(':', pSQL(trim($campos[40])))[1] ?? '';
        $heo_theme = explode(':', pSQL(trim($campos[44])))[1] ?? '';
        $heo_manufacturer = explode(':', pSQL(trim($campos[48])))[1] ?? '';
        $heo_type = explode(':', pSQL(trim($campos[52])))[1] ?? '';

        $datos_heo = '<br>Categoria: '.$heo_categorie.'.<br>Tema: '.$heo_theme.'.<br>Fabricante: '.$heo_manufacturer.'.<br>Tipo: '.$heo_type.'.';       
        
        $para_ia = '<br>Producto con licencia oficial.<br> Un artículo perfecto para un regalo original o para un capricho.';

        $descripcion = $descripcion.$datos_heo.$para_ia;

        // Buscar o crear fabricante
        $id_manufacturer = $this->getManufacturerId($heo_manufacturer);
        if (is_null($id_manufacturer)) {
            $this->logger->log("Error obteniendo fabricante para referencia ".$referencia, 'ERROR');

            return null;
        }
        
        return [
            'referencia_proveedor' => $referencia,
            'url_proveedor'        => $url_producto,
            'nombre'               => $nombre,
            'ean'                  => $ean,
            'coste'                => $precio,
            'pvp_sin_iva'          => $pvp,
            'peso'                 => $peso,
            'estado'               => $estado,
            'disponibilidad'       => $disponibilidad,
            'description_short'    => $descripcion,
            'manufacturer'         => $heo_manufacturer,
            'id_manufacturer'      => $id_manufacturer,
            'imagenes'             => array_filter($imagenes),
            'fuente'               => $this->config['tipo']
        ];
    }

    protected function getManufacturerId($nombre)
    {
        if (!$nombre) {
            return null;
        }

        // 1. Buscar si ya existe un fabricante con ese nombre
        $id = Manufacturer::getIdByName($nombre);
        if ($id) {
            return (int) $id;
        }
        // else {return 9999;}

        // 2. Si no existe, crearlo
        $manufacturer = new Manufacturer();
        $manufacturer->name = $nombre;
        $manufacturer->active = 1;

        foreach (Language::getLanguages(false) as $lang) {
            $manufacturer->description[$lang['id_lang']] = '';
            $manufacturer->short_description[$lang['id_lang']] = '';
            $manufacturer->meta_title[$lang['id_lang']] = $nombre;
            $manufacturer->meta_description[$lang['id_lang']] = '';
            $manufacturer->meta_keywords[$lang['id_lang']] = '';
        }

        if ($manufacturer->add()) {
            $this->logger->log("Fabricante creado: $nombre (ID ".$manufacturer->id.")", 'INFO');
            return (int) $manufacturer->id;
        } else {
            $this->logger->log("Error al crear fabricante: $nombre", 'ERROR');
            return null;
        }
    }

}