<?php

require_once(_PS_MODULE_DIR_.'frikimportproductos/classes/AbstractCatalogReader.php');
require_once _PS_ROOT_DIR_.'/classes/utils/LoggerFrik.php';
require_once _PS_MODULE_DIR_ . 'frikimportproductos/classes/ManufacturerAliasHelper.php';

class MegasurReader extends AbstractCatalogReader
{
    protected $config;
    protected $logger;
    
    protected $path_descarga;

    private $columnas_catalogo = array(
        0  => "ID_FAMILIA",
        1  => "FAMILIA",
        2  => "ID_SUBFAMILIA",
        3  => "SUBFAMILIA",
        4  => "CATEGORY",
        5  => "FABRICANTE",
        6  => "ID_FABRICANTE",
        7  => "REF",
        8  => "EAN",
        9  => "PART_NUMBER",
        10 => "NAME",
        11 => "NAME_SMALL",
        12 => "DESCRIPTION",
        13 => "PVD",
        14 => "PVP",
        15 => "MARGIN",
        16 => "MARGIN_PVP",
        17 => "OFFER",
        18 => "OFFER_PVD",
        19 => "OFFER_PVP",
        20 => "OFFER_DISCOUNT",
        21 => "OFFER_OF_DATE",
        22 => "OFFER_TO_DATE",
        23 => "PESO",
        24 => "STOCK",
        25 => "ACTIVE",
        26 => "AVAILABILITY",
        27 => "URL_IMG",
        28 => "URL_IMG_COMPRESS",
        29 => "FECHA_ALTA",
        30 => "TextNoStock",
        31 => "PVD_ESTANDAR",
        32 => "CANON",
        33 => "STOCK_DISPONIBLE",
        34 => "FECHA_LIMITE_UNIDADES_HASTA",
        35 => "DELIVERY_DAYS"
        // 36 => "ID"
    );

    //aumentamos el coste para compensar el envío cuando nos hacen dropshipping
    protected $incremento_coste = 0;


    public function __construct($id_proveedor, LoggerFrik $logger)
    {
        $this->config = Db::getInstance()->getRow('
            SELECT * FROM '._DB_PREFIX_.'import_proveedores 
            WHERE id_supplier = '.(int)$id_proveedor
        );

        if (!$this->config) {
            throw new Exception('No se encontró configuración para el proveedor con ID '.$id_proveedor);
        }

        $this->logger = $logger;
        $this->path_descarga = _PS_MODULE_DIR_.'frikimportproductos/import/megasur/';
    }

    /**
     * Descarga el catálogo completo de Megasur vía url
     */
    public function fetch()
    {
        $endpoint = $this->config['url'];

        $curl = curl_init();

        curl_setopt_array($curl, array(
        CURLOPT_URL => $endpoint,
        CURLOPT_RETURNTRANSFER => true,        
        CURLOPT_TIMEOUT => 400,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FAILONERROR => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        ));

        $this->logger->log('Ejecutando descarga Megasur accediendo a URL: '.$endpoint, 'INFO');

        try {
            $response = curl_exec($curl);

            if (curl_errno($curl)) {
                $this->logger->log('Error descargando catálogo: ' . curl_error($curl), 'ERROR');
                
                return false;                
            }
            
        } catch (Exception $e) {
            $exception = $e->getMessage();
            $file = $e->getFile();
            $line = $e->getLine(); 
            $code = $e->getCode();

            $this->logger->log('Excepción en descarga de catálogo Megasur: ' . $exception, 'ERROR');
            $this->logger->log('Exception thrown in: ' .$file, 'ERROR');
            $this->logger->log('On line: ' .$line, 'ERROR');
            $this->logger->log('[Code '.$code.']', 'ERROR');

            return false; 
        } finally {            
            curl_close($curl);

        }

        if ($response === false) {
            $this->logger->log('Respuesta vacía al descargar catálogo de Megasur', 'ERROR');

            return false;
        }

        curl_close($curl);
        
        $archivo = 'catalogo_completo_megasur.csv';
        $ruta_archivo = $this->path_descarga.$archivo;

        if (file_put_contents($ruta_archivo, $response) === false) {
            $this->logger->log('Error guardando catálogo Megasur en '.$ruta_archivo, 'ERROR');
            return false;
        }

        $this->logger->log('Catálogo Megasur guardado correctamente en '.$ruta_archivo, 'INFO');

        return $ruta_archivo;        
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
            // if ($i == 0) continue; // saltamos la primera
            if (strtolower(trim($col)) != strtolower(trim($this->columnas_catalogo[$i]))) {
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
            //El archivo está en codificación ANSI (Windows-1252 o ISO-8859-1)
            // Convertir cada valor del array de Windows-1252 a UTF-8
            $campos_utf8 = array_map(function($value) {
                return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
            }, $campos);

            $producto = $this->normalizeRow($campos_utf8);
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
        $referencia = pSQL(trim($campos[array_search("REF", $this->columnas_catalogo)]));
        if (!$referencia) return false;

        //hay otro NAME pero a veces incluye la referencia o es muy largo
        $nombre = pSQL(trim($campos[array_search("NAME_SMALL", $this->columnas_catalogo)]));         
        if (!$nombre) return false;

        $ean = trim($campos[array_search("EAN", $this->columnas_catalogo)]) ?: '';        

        $stock = trim($campos[array_search("STOCK", $this->columnas_catalogo)]); 

        $active = trim($campos[array_search("ACTIVE", $this->columnas_catalogo)]) ?: 0;            

        $availability = trim($campos[array_search("AVAILABILITY", $this->columnas_catalogo)]) ?: 0;         
        
        if (!$active || !$availability) {
            $stock = 0;
        }

        $disponibilidad = (int)$stock > 1 ? 1 : 0;

        //añadimos al coste para compensar envíos cuando es dropshipping, pero primero comprobamos que venga algún precio 
        $precio = str_replace(',','.',trim($campos[array_search("PVD", $this->columnas_catalogo)])) ?: 0;
        $precio = $precio ? $precio+$this->incremento_coste : 0;       

        $pvp = str_replace(',','.',trim($campos[array_search("PVP", $this->columnas_catalogo)])) ?: 0;
        
        $peso = $peso = str_replace(',','.',trim($campos[array_search("PESO", $this->columnas_catalogo)])) ?: 0.444;

        $manufacturer_name = trim($campos[array_search("FABRICANTE", $this->columnas_catalogo)]) ?: null;  
        
        // buscamos el fabricante por su nombre para ver si existe, en cuyo caso obtenemos su id. Si no existe o no hay nombre de fabricante, id_manufacturer queda null y se creará al crear el producto
        $id_manufacturer = null;
        if ($manufacturer_name) {           
            //si devuelve null quedará como pending y cuando se cree el producto se volverá a intentar resolver el nombre. Habrá que crear el alias asignado a un fabricante o crear un nuevo fabricante
            $id_manufacturer = ManufacturerAliasHelper::resolveName($manufacturer_name, 'Megasur');  
        }   

        $subfamilia = trim($campos[array_search("SUBFAMILIA", $this->columnas_catalogo)]) ?: '';      
        
        $descripcion = pSQL(trim($campos[array_search("DESCRIPTION", $this->columnas_catalogo)]), true);         

        if ($manufacturer_name) {
            $descripcion = $descripcion.'<br>Fabricante: '.$manufacturer_name;
        }

        if ($subfamilia) {
            $descripcion = $descripcion.'<br>Familia: '.$subfamilia;
        }

        $para_ia = '<br>Producto con licencia oficial.<br> Un artículo perfecto para un regalo original o para un capricho.';

        $descripcion = $descripcion.$para_ia;

        $url_producto = 'https://www.megasur.es/search?q='.$referencia;         

        //Megasur solo incluye una url de imagen en el catálogo, la principal, que acaba en -0. Si hay más imágenes llevan -1 -2 -3 etc, pero no sabemos is hay más o cuantas hay. Como sería muy pesado comprobar todos los miles de productos cada vez tratando de acceder a la foto, lo que hacemos es meter hasta -15 en el array de imágenes, para que cuando se cree el producto lo intente hasta que encuentre una que no existe, y ahí pare
        $url_imagen = pSQL(trim($campos[array_search("URL_IMG", $this->columnas_catalogo)]));  
        
        $imagenes = $this->generarImagenesMegasur($url_imagen);            
        
        //ignorar es un marcador para cuando queramos ignorar un producto por lo que sea, por ejemplo con heo son los que vienen surtidos etc
        $ignorar = 0;
        
        return [
            'referencia_proveedor' => $referencia,
            'url_proveedor'        => $url_producto,
            'nombre'               => $nombre,
            'ean'                  => $ean,
            'coste'                => $precio,
            'pvp_sin_iva'          => $pvp,
            'peso'                 => $peso,            
            'disponibilidad'       => $disponibilidad,
            'description_short'    => $descripcion,
            'manufacturer_name'    => pSQL($manufacturer_name),
            'id_manufacturer'      => $id_manufacturer, 
            'imagenes'             => array_filter($imagenes),
            'fuente'               => $this->config['tipo'],
            'ignorar'              => $ignorar
        ];
    }
    
    //esta función recibe la url de la imagen principal y devuelve un array con dicha imagen y hasta 15 urls más
    protected function generarImagenesMegasur($url_imagen_principal)
    {
        $imagenes = [];
        $imagenes[] = $url_imagen_principal;
        // url_imagen_principal: https://img.megasur.es/img/MGS0000006280-0.jpg

        //eliminamos el guión, el número y .jpg (i se pone para si llegara en mayúsculas JPG)
        $base = preg_replace('/-\d+\.jpg$/i', '', $url_imagen_principal);

        for ($i = 1; $i <= 15; $i++) {
            $url = $base . '-' . $i . '.jpg';
            $imagenes[] = $url;
        }

        return $imagenes;
    }  

}