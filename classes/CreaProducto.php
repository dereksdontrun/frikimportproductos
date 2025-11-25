<?php

require_once _PS_ROOT_DIR_.'/classes/utils/LoggerFrik.php';
require_once _PS_ROOT_DIR_.'/controllers/admin/AdminImportController.php'; //para el proceso de imágenes

class CreaProducto
{
    /** @var LoggerFrik */
    protected $logger;

    /** @var int */
    protected $id_employee;

    protected $origen;

    //una categoría principal por defecto. 2920 importados completo
    protected $id_category_default = 2920;

    protected $log_file = _PS_ROOT_DIR_.'/modules/frikimportproductos/logs/crear_manual.log';

    public function __construct(LoggerFrik $logger = null)
    {        
        if ($logger) {
            $this->logger = $logger;
        } else {
            $this->logger = new LoggerFrik($this->log_file);
        }        
    }

    /**
     * Crear producto desde un registro de la tabla productos_proveedores. Origen manual o cola
     */
    public function crearDesdeProveedor(array $datos, $id_productos_proveedores, string $origen = 'manual', $id_employee = null)
    {
        $this->id_employee = $id_employee ?: (Context::getContext()->employee->id ?? 44);

        try {
            $this->origen = $origen;

            $this->logger->log("---------------------------------------------------------", 'INFO');
            $this->logger->log("Iniciando creación de producto #$id_productos_proveedores, Origen: ".strtoupper($this->origen), 'INFO');

            $ref = addslashes($datos['referencia_proveedor'] ?? '');
            $ean = addslashes($datos['ean'] ?? '');
            // comprobar duplicados
            $id_existente = $this->existeEnPrestashop($datos);
            if ($id_existente) {                
                $msg = "El producto ya existe en PrestaShop (ref={$ref}, ean={$ean}, id_productos_proveedores=$id_productos_proveedores), con id_product=$id_existente";
                $this->logger->log($msg, 'ERROR');
                $this->logAccion($id_productos_proveedores, 'crear', 'error', $msg);

                if ($this->origen === 'manual') {
                    $this->guardarErrorProducto($id_productos_proveedores, $msg);
                }

                return ['success' => false, 'message' => $msg];
            }

            //el fabricante puede que lo conozcamos o no, si  viene id_manufacturer se lo asignamos, si no buscamos por coincidencia del nombre, si no se encuentra se crea uno nuevo. Si no hay id ni nombre, asignamos Otros fabricantes
            $id_manufacturer = $datos['id_manufacturer'];
            if (!$id_manufacturer) {
                $id_manufacturer = $this->getManufacturerId($datos['manufacturer_name']);
            }

            //comprobamos iva, si no hay aplicamos 21
            $iva = $datos['iva'] ? (int)$datos['iva'] : 21;

            $pvp_sin_iva = $this->calculaPvpRecomendado((float)$datos['coste'], $id_manufacturer, $iva);

            if (!$pvp_sin_iva) {                
                $msg = "No pudo calcularse el PVP sin IVA para producto (ref={$ref}, ean={$ean}, id_productos_proveedores=$id_productos_proveedores)";
                $this->logger->log($msg, 'ERROR');
                $this->logAccion($id_productos_proveedores, 'crear', 'error', $msg);

                if ($this->origen === 'manual') {
                    $this->guardarErrorProducto($id_productos_proveedores, $msg);
                }

                return ['success' => false, 'message' => $msg];
            }

            //comprobamos que el ean sea un ean, si no ponemos ""
            if (!$this->checkEan($datos['ean'])) {
                //hay algún problema con el ean, lo dejamos vacío
                $datos['ean'] = "";

                $msg = "Ean de producto no es ean13 válido, lo vaciamos (ref={$ref}, ean={$ean}, id_productos_proveedores=$id_productos_proveedores)";
                $this->logger->log($msg, 'ERROR');
            }

            // Normalizar imágenes antes de seguir
            $datos['imagenes'] = $this->decodeImagenes($datos);

            //revisamos las longitudes de textos y truncamos si es necesario
            $nombre = $this->truncarCampo($datos['nombre'] ?? '', 128, 'name', $ref);
            $descripcion_corta = $this->truncarCampo($datos['description_short'] ?? '', 4000, 'description_short', $ref);
            $ean = $this->truncarCampo($datos['ean'] ?? '', 13, 'ean13', $ref);            
          
            // crear objeto producto
            $product = new Product();
            $product->reference = $this->generarReferenciaTemporal();
            $product->ean13 = $ean; //asignamos el ean que viene en el catálogo, pero para buscar duplicados usamos ean_norm
            $product->name = [Configuration::get('PS_LANG_DEFAULT') => $nombre]; // es como $product->name[1] = $nombre;
            $product->description_short = [Configuration::get('PS_LANG_DEFAULT') => $descripcion_corta];
            $product->link_rewrite[1] = Tools::link_rewrite($nombre);        
            $product->available_now = Tools::mensajeAvailable((int)$datos['id_supplier'])[0];
            $product->available_later = Tools::mensajeAvailable((int)$datos['id_supplier'])[1];
            $product->wholesale_price = (float)$datos['coste'];            
            $product->id_tax_rules_group = $this->getIdTaxRulesGroup($iva);
            $product->price = $pvp_sin_iva; 
            $product->id_supplier = (int)$datos['id_supplier'];
            $product->id_manufacturer = $id_manufacturer;
            $product->weight = (float)$datos['peso'] ? (float)$datos['peso'] : 0.444;            
            $product->redirect_type = '404';
            $product->advanced_stock_management = 1;        
            $product->visibility = 'both'; 
            $product->active = 0;             
            
            try {
                $product->add();
            } catch (Throwable $e) {
                $exception = $e->getMessage();
                $file = $e->getFile();
                $line = $e->getLine(); 
                $code = $e->getCode();
                
                $msg = "Error haciendo product->add() para crear producto (ref={$ref}, ean={$ean}, id_productos_proveedores=$id_productos_proveedores) - Excepción:".$exception." - Exception thrown in ".$file." on line ".$line.": [Code ".$code."]";                   
                
                $this->logger->log($msg, 'ERROR');
                $this->logAccion($id_productos_proveedores, 'crear', 'error', $msg);

                //Solo guardar el mensaje de error si el origen es manual, los de cola se hace en ColaCreacion
                if ($this->origen === 'manual') {
                    $this->guardarErrorProducto($id_productos_proveedores, $msg);
                }

                return ['success' => false, 'message' => $msg];                
            }                      

            $this->logger->log("Producto creado en PrestaShop con id_product={$product->id}", 'INFO');

            // asignar categoría principal (id_category_default)
            $id_category_default = Configuration::get('FRIKIMPORTPROVEEDORES_CATEGORIA_DEFECTO') ? (int)Configuration::get('FRIKIMPORTPROVEEDORES_CATEGORIA_DEFECTO') : $this->id_category_default; //importados
            if ($id_category_default) {
                $product->id_category_default = $id_category_default;
                $product->update();
                $product->addToCategories([$id_category_default]);
            }

            // proveedor: insertar en product_supplier
            if (!$this->addProveedor($product->id, $datos)) {
                $msg = "Error asignando proveedor ({$product->id_supplier}) a producto (ref={$ref}, ean={$ean}, id_productos_proveedores=$id_productos_proveedores)";                   
                
                $this->logger->log($msg, 'ERROR');
                $this->logAccion($id_productos_proveedores, 'crear', 'error', $msg);

                $this->deleteProduct($product->id);

                if ($this->origen === 'manual') {
                    $this->guardarErrorProducto($id_productos_proveedores, $msg);
                }

                return ['success' => false, 'message' => $msg];
            }

            //asignar almacén
            if (!$this->addAlmacen($product->id)) {
                $msg = "Error asignando almacén a producto (ref={$ref}, ean={$ean}, id_productos_proveedores=$id_productos_proveedores)";                   
                
                $this->logger->log($msg, 'ERROR');
                $this->logAccion($id_productos_proveedores, 'crear', 'error', $msg);

                $this->deleteProduct($product->id);

                if ($this->origen === 'manual') {
                    $this->guardarErrorProducto($id_productos_proveedores, $msg);
                }

                return ['success' => false, 'message' => $msg];
            }

            //configurar gestión stock avanzado
            if (!$this->addGestionStock($product->id)) {
                $msg = "Error gestionando comportamiento de stock para producto (ref={$ref}, ean={$ean}, id_productos_proveedores=$id_productos_proveedores)";                   
                
                $this->logger->log($msg, 'ERROR');
                $this->logAccion($id_productos_proveedores, 'crear', 'error', $msg);

                $this->deleteProduct($product->id);

                if ($this->origen === 'manual') {
                    $this->guardarErrorProducto($id_productos_proveedores, $msg);
                }

                return ['success' => false, 'message' => $msg];
            }

            // imágenes
            $resultImg = $this->procesarImagenes($product->id, $datos['imagenes'] ?? [], $datos['nombre']);

            if (empty($resultImg['ok'])) {
                $msg = "No se pudo importar ninguna imagen para el producto id_product={$product->id}, ref={$ref}, ean={$ean}";
                $this->logger->log($msg, 'ERROR');
                $this->logAccion($id_productos_proveedores, 'crear', 'error', $msg);

                $this->deleteProduct($product->id);

                if ($this->origen === 'manual') {
                    $this->guardarErrorProducto($id_productos_proveedores, $msg);
                }

                return ['success' => false, 'message' => $msg];
            }            

            $msg = "Producto con referencia de proveedor $ref, de ".Supplier::getNameById($datos['id_supplier'])." creado correctamente con id_product ".$product->id." y referencia temporal ".$product->reference;

            $this->logger->log($msg, 'SUCCESS');

            // actualizar tabla productos_proveedores con creado si el origen es manual (controlador). Si viene de cola lo haremos allí para cada producto
            // if ($origen === 'manual') {
                Db::getInstance()->update('productos_proveedores', [
                    'existe_prestashop' => 1,
                    'estado' => 'creado',
                    'id_employee_creado' => $this->id_employee,
                    'referencia_base_prestashop' => $product->reference,
                    'id_product_prestashop' => (int)$product->id,
                    'date_creado' => date('Y-m-d H:i:s'),
                    'date_upd' => date('Y-m-d H:i:s'),
                ], 'id_productos_proveedores = '.(int)$id_productos_proveedores);
            // }

            $this->logAccion($id_productos_proveedores, 'crear', 'exito', 'Producto creado con éxito.', $product->id);

            $this->logImportCatalogos($product->id, $product->reference, $datos);

            return ['success' => true, 'id_product' => $product->id];

        } catch (Exception $e) {
            $msg = "Error al crear producto: ".$e->getMessage();
            $this->logger->log($msg, 'ERROR');
            $this->logAccion($id_productos_proveedores, 'crear', 'error', $msg);

            if ($this->origen === 'manual') {
                $this->guardarErrorProducto($id_productos_proveedores, $msg);
            }

            return ['success' => false, 'message' => $msg];
        }
    }

    protected function getManufacturerId($nombre)
    {
        if (!$nombre) {
            //si no hay nombre, devolvemos id 34, Otros Fabricantes
            $this->logger->log("Nombre fabricante inválido: $nombre. Utilizamos 'Otros fabricantes'", 'WARNING');

            return 34;
        }

        // $excluir = ['GENERIC', 'VARIOS', 'NO BRAND', 'SIN MARCA'];
        // if (in_array(strtoupper($nombre), $excluir)) {
        //     return 34;
        // }

        // 1. Buscar si ya existe un fabricante con ese nombre (insensible a mayúsculas/minúsculas)
        $id = Db::getInstance()->getValue('
            SELECT id_manufacturer 
            FROM '._DB_PREFIX_.'manufacturer 
            WHERE LOWER(name) = "'.pSQL(strtolower($nombre)).'"
        ');
        if ($id) {
            return (int) $id;
        }
        // else {return 9999;}

        // 2. Si no existe, crearlo
        $manufacturer = new Manufacturer();
        $manufacturer->name = $nombre;
        $manufacturer->active = 1;
        $manufacturer->date_add = date('Y-m-d H:i:s');
        $manufacturer->date_upd = date('Y-m-d H:i:s');

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
            $this->logger->log("Error al crear fabricante: $nombre. Utilizamos 'Otros fabricantes'", 'ERROR');

            return 34;
        }
    }

    /**
     * Trunca un texto (descripción, nombre etc con su límite de longitud) y registra el evento si supera el límite.
     */
    protected function truncarCampo($texto, $limite, $campo, $ref)
    {
        if (Tools::strlen($texto) > $limite) {
            $this->logger->log("Campo '$campo' truncado de " . Tools::strlen($texto) . " a $limite caracteres (ref=$ref)", 'WARNING');
            return Tools::substr($texto, 0, $limite - 3) . '...';
        }
        return $texto;
    }

    /**
     * Añade proveedor a product_supplier
     */
    protected function addProveedor($id_product, $datos)
    {
        //Asignar proveedor, se asigna como producto base, si se crean los atributos se generará cada uno
        $product_supplier = new ProductSupplier();
        $product_supplier->id_product = (int)$id_product;
        $product_supplier->id_product_attribute = 0;
        $product_supplier->id_supplier = (int)$datos['id_supplier'];
        //si es producto sin atributos asignamos aquí la referencia de proveedor - POR AHORA NO
        // if ($this->es_atributo) {
        //     $product_supplier->product_supplier_reference = '';
        // } else {
        //     $product_supplier->product_supplier_reference = pSQL($datos['referencia_proveedor']);
        // }     
        $product_supplier->product_supplier_reference = pSQL($datos['referencia_proveedor']);   
        $product_supplier->product_supplier_price_te = (float)$datos['coste']; 
        $product_supplier->id_currency = (int)Configuration::get('PS_CURRENCY_DEFAULT');

        if ($product_supplier->save()) {
            $this->logger->log("Proveedor añadido a product_supplier (id_product=$id_product)", 'INFO');
            return true;
        }    
        
        return false;        
    }

    /**
     * Normaliza las imágenes del registro productos_proveedores
     */
    protected function decodeImagenes(array $producto): array
    {
        $imagenes = [];

        if (!empty($producto['imagen_principal'])) {
            $imagenes[] = $producto['imagen_principal'];
        }

        if (!empty($producto['otras_imagenes'])) {
            $otras = json_decode($producto['otras_imagenes'], true);
            if (is_array($otras)) {
                foreach ($otras as $url) {
                    if (!in_array($url, $imagenes)) {
                        $imagenes[] = $url;
                    }
                }
            }
        }

        return $imagenes;
    }

    /**
     * Procesar imágenes y asociarlas al producto
     * Recibimos un array ya ordenado desde el controlador con la/s imágenes del producto, siendo la primera la principal
     */
    protected function procesarImagenes($id_product, array $imagenes, $nombreProducto = '')
    {
        $resultados = ['ok' => [], 'errores' => []];
        $id_langs = Language::getIDs(false); // todos los idiomas activos

        foreach ($imagenes as $i => $url) {
            try {
                $image = new Image();
                $image->id_product = (int)$id_product;
                $image->position = $i + 1;
                $image->cover = ($i === 0);

                if (!$image->add()) {
                    throw new Exception("No se pudo añadir registro de imagen para $url");
                }

                // Asociar a la tienda
                $image->associateTo([Context::getContext()->shop->id]);

                // Subir y generar miniaturas (usa copyImg, equivalente al AdminImportController)
                if (!$this->copyImg($id_product, $image->id, $url, 'products', true)) {
                    $image->delete();
                    throw new Exception("Fallo al copiar/generar imagen desde $url");
                }

                // Añadir leyenda/alt para todos los idiomas
                foreach ($id_langs as $id_lang) {
                    $image->legend[$id_lang] = $nombreProducto ?: 'Imagen de producto';
                }
                $image->update();

                $this->logger->log("Imagen importada correctamente (url=$url, id_product=$id_product)", 'INFO');
                $resultados['ok'][] = $url;

            } catch (Exception $e) {
                $msg = "Error al importar imagen $url → ".$e->getMessage();
                $this->logger->log($msg, 'ERROR');
                $resultados['errores'][] = $msg;

                // Si ya había al menos una imagen buena y ahora falla, detenemos
                if (count($resultados['ok']) > 0) {
                    $this->logger->log("Primera imagen fallida tras una válida, deteniendo importación de imágenes.", 'WARNING');
                    break;
                } else {
                    // Si falla la primera, continuamos para ver si la siguiente existe
                    continue;
                }
            }
        }

        return $resultados;
    }

    //función de AdminImportController.php Las he traido aquí porque la función del controlador es protected
    //Modificada para que devuelva false si la url no vale antes de descargar nada
    public function copyImg($id_entity, $id_image = null, $url, $entity = 'products', $regenerate = true)
    {
        $tmpfile = tempnam(_PS_TMP_IMG_DIR_, 'ps_import');
        $watermark_types = explode(',', Configuration::get('WATERMARK_TYPES'));

        switch ($entity) {
            default:
            case 'products':
                $image_obj = new Image($id_image);
                $path = $image_obj->getPathForCreation();
            break;
            case 'categories':
                $path = _PS_CAT_IMG_DIR_.(int)$id_entity;
            break;
            case 'manufacturers':
                $path = _PS_MANU_IMG_DIR_.(int)$id_entity;
            break;
            case 'suppliers':
                $path = _PS_SUPP_IMG_DIR_.(int)$id_entity;
            break;
        }

        $url = urldecode(trim($url));
        $parced_url = parse_url($url);

        if (isset($parced_url['path'])) {
            $uri = ltrim($parced_url['path'], '/');
            $parts = explode('/', $uri);
            foreach ($parts as &$part) {
                $part = rawurlencode($part);
            }
            unset($part);
            $parced_url['path'] = '/'.implode('/', $parts);
        }

        if (isset($parced_url['query'])) {
            $query_parts = array();
            parse_str($parced_url['query'], $query_parts);
            $parced_url['query'] = http_build_query($query_parts);
        }

        if (!function_exists('http_build_url')) {
            require_once(_PS_TOOL_DIR_.'http_build_url/http_build_url.php');
        }

        $url = http_build_url('', $parced_url);

        $orig_tmpfile = $tmpfile;

        // MODIFICACIÓN --- Verificar si la URL existe antes de copiar ---
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        // curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3); SI SE TARDA DEMASIADO, DADO QUE CADA PRODUCTO A CREAR LLEGARÁ A UNA IMAGEN QUE NO EXISTE, PONEMOS ESTE LÍMITE, Y 5 SEC EN CURLOPT_TIMEOUT
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            $this->logger->log("Imagen no accesible (HTTP $http_code): $url", 'WARNING');
            @unlink($tmpfile);
            return false;
        }

        if (Tools::copy($url, $tmpfile)) {
            // Evaluate the memory required to resize the image: if it's too much, you can't resize it.
            if (!ImageManager::checkImageMemoryLimit($tmpfile)) {
                @unlink($tmpfile);
                return false;
            }

            $tgt_width = $tgt_height = 0;
            $src_width = $src_height = 0;
            $error = 0;

            //MOD: 25/05/2023 Si enviamos los parámetros como los usa esta función sacada de AdminImportController parece que mete un marco blanco a las fotos, pero mirando en AdminProductsController en su función copyImage() se ve que solo envía hasta $file_type y no mete ese marco, parece que funciona

            // ImageManager::resize($tmpfile, $path.'.jpg', null, null, 'jpg', false, $error, $tgt_width, $tgt_height, 5,
            //                     $src_width, $src_height);
            ImageManager::resize($tmpfile, $path.'.jpg', null, null, 'jpg');
            $images_types = ImageType::getImagesTypes($entity, true);

            if ($regenerate) {
                $previous_path = null;
                $path_infos = array();
                $path_infos[] = array($tgt_width, $tgt_height, $path.'.jpg');
                foreach ($images_types as $image_type) {
                    //en AdminImportcontroller aquí utiliza self::get_best_path pero como no estamos en ese controlador, he traido la función get_best_path() justo debajo de esta
                    $tmpfile = $this->get_best_path($image_type['width'], $image_type['height'], $path_infos);

                    //MOD: 25/05/2023 Si enviamos los parámetros como los usa esta función sacada de AdminImportController parece que mete un marco blanco a las fotos, pero mirando en AdminProductsController en su función copyImage() se ve que solo envía hasta $file_type y no mete ese marco, parece que funciona

                    // if (ImageManager::resize($tmpfile, $path.'-'.stripslashes($image_type['name']).'.jpg', $image_type['width'],
                    //                     $image_type['height'], 'jpg', false, $error, $tgt_width, $tgt_height, 5,
                    //                     $src_width, $src_height)) {
                    if (ImageManager::resize($tmpfile, $path.'-'.stripslashes($image_type['name']).'.jpg', $image_type['width'],
                        $image_type['height'], 'jpg')) {
                        // the last image should not be added in the candidate list if it's bigger than the original image
                        if ($tgt_width <= $src_width && $tgt_height <= $src_height) {
                            $path_infos[] = array($tgt_width, $tgt_height, $path.'-'.stripslashes($image_type['name']).'.jpg');
                        }
                        if ($entity == 'products') {
                            if (is_file(_PS_TMP_IMG_DIR_.'product_mini_'.(int)$id_entity.'.jpg')) {
                            unlink(_PS_TMP_IMG_DIR_.'product_mini_'.(int)$id_entity.'.jpg');
                            }
                            if (is_file(_PS_TMP_IMG_DIR_.'product_mini_'.(int)$id_entity.'_'.(int)Context::getContext()->shop->id.'.jpg')) {
                            unlink(_PS_TMP_IMG_DIR_.'product_mini_'.(int)$id_entity.'_'.(int)Context::getContext()->shop->id.'.jpg');
                            }
                        }
                    }
                    if (in_array($image_type['id_image_type'], $watermark_types)) {
                        Hook::exec('actionWatermark', array('id_image' => $id_image, 'id_product' => $id_entity));
                    }
                }
            }
        } else {
            @unlink($orig_tmpfile);
            return false;
        }
        unlink($orig_tmpfile);

        return true;
    }

    //función de AdminImportController.php Las he traido aquí porque la función del controlador es protected. A esta función se la llama desde dentro de copyImg()
    public function get_best_path($tgt_width, $tgt_height, $path_infos)
    {
        $path_infos = array_reverse($path_infos);
        $path = '';
        foreach ($path_infos as $path_info) {
            list($width, $height, $path) = $path_info;
            if ($width >= $tgt_width && $height >= $tgt_height) {
                return $path;
            }
        }
        return $path;
    }    


    //función que obtiene el id_tax_rules_group a asignar al producto en función del iva, suponiendo que estamos en España peninsular
    protected function getIdTaxRulesGroup($iva) {
        $sql_tax = 'SELECT trg.id_tax_rules_group
        FROM lafrips_tax_rules_group trg
        JOIN lafrips_tax_rule tar ON tar.id_tax_rules_group = trg.id_tax_rules_group
        JOIN lafrips_tax tax ON tax.id_tax = tar.id_tax
        WHERE trg.active = 1
        AND trg.deleted = 0
        AND tar.id_country = 6 
        AND tax.active = 1
        AND tax.deleted = 0
        AND tax.rate = '.$iva;

        return Db::getInstance()->getValue($sql_tax);   
    }

    //función que devuelve el pvp o el pvp sin iva ajustado a un precio con iva redondeado, dado el iva, y el coste, buscando en lafrips_factor_coste_fabricante el factor por el fabricante y el rango de coste, si no existe (por falta de datos generalmente, o bien pocos productos de esas características) se pasa a buscar en lafrips_factor_coste_productos (medias de factores solo por rango) el factor por el que multiplicar el coste para obtener el pvp sin iva en bruto.
    //el campo $impuestos true o false indica si se devuelve pvp con o sin iva
    protected function calculaPvpRecomendado($coste, $id_manufacturer, $iva = 21, $impuestos = false) {
        // Buscamos el factor por fabricante
        $sql_factor_fabricante = '
            SELECT factor
            FROM lafrips_factor_coste_fabricante
            WHERE id_manufacturer = '.(int)$id_manufacturer.'
            AND coste_min <= '.(float)$coste.'
            AND coste_max > '.(float)$coste;

        $factor = Db::getInstance()->getValue($sql_factor_fabricante);

        // Si no existe, buscamos en la tabla genérica
        if (!$factor) {
            $sql_general = '
                SELECT factor
                FROM lafrips_factor_coste_productos
                WHERE coste_min <= '.(float)$coste.'
                AND coste_max > '.(float)$coste;

            $factor = Db::getInstance()->getValue($sql_general);
        }

        // Si seguimos sin factor, podríamos poner un valor por defecto. Por ahora devolvemos 0 y se toma como error
        if (!$factor) return 0;       

        // PVP sin IVA inicial
        $pvp_sin_iva = $coste * $factor;
        // PVP con IVA inicial
        $pvp_con_iva = $pvp_sin_iva * (1 + $iva / 100);

        // Redondear a 5 centimos si el pvp es menor de 15€ y a 10 centimos si es menor de 100€ y a euro redondo si es mayor o igual a 100€
        if ($pvp_con_iva < 15) {
            // múltiplos de 0.05
            $pvp_final = ceil($pvp_con_iva / 0.05) * 0.05;
        } elseif ($pvp_con_iva < 100) {
            // múltiplos de 0.10
            $pvp_final = ceil($pvp_con_iva / 0.10) * 0.10;
        } else {
            // múltiplos de 1.00
            $pvp_final = ceil($pvp_con_iva);
        }

        if ($impuestos) return $pvp_final;
        
        //ahora calculamos el nuevo pvp sin iva para obtener con el iva el pvp redondeado
        $pvp_sin_iva_final = $pvp_final / (1 + $iva / 100);

        // Redondear a 6 decimales
        return round($pvp_sin_iva_final, 6); 
    }

    /**
     * Comprueba si un producto ya existe en Prestashop por referencia_proveedor o EAN.
     * Devuelve el id_product encontrado o null si no existe.
     */
    protected function existeEnPrestashop($datos)
    {
        // Buscar por referencia del proveedor en product_supplier
        if (!empty($datos['referencia_proveedor'])) {
            $id = Db::getInstance()->getValue('
                SELECT ps.id_product 
                FROM '._DB_PREFIX_.'product_supplier ps
                WHERE ps.product_supplier_reference = "'.pSQL($datos['referencia_proveedor']).'"
            ');
            if ($id) {
                return (int)$id;
            }
        }

        // Buscar por EAN en products (normalizado)
        if (!empty($datos['ean_norm'])) {
            $eanNorm = pSQL($datos['ean_norm']);

            // en product
            $id = Db::getInstance()->getValue('
                SELECT p.id_product 
                FROM '._DB_PREFIX_.'product p
                WHERE p.ean_norm <> ""
                AND p.ean_norm <> "0000000000000" 
                AND p.ean_norm = "'.$eanNorm.'"
            ');
            if ($id) {
                return (int)$id;
            }

            // en product_attribute
            $id = Db::getInstance()->getValue('
                SELECT pa.id_product 
                FROM '._DB_PREFIX_.'product_attribute pa
                WHERE  pa.ean_norm <> ""
                AND pa.ean_norm <> "0000000000000" 
                AND pa.ean_norm = "'.$eanNorm.'"
            ');
            if ($id) {
                return (int)$id;
            }
        }

        return null;
    }

    protected function checkEan($ean) {
        //comprobamos que el campo ean contenga un ean
        if (!$ean || $ean == ""|| $ean == "0000000000000" || !Validate::isEan13($ean)) {
            //Ean está vacío o no es un Ean'    
            return false;            
        }

        return true;
    }


    protected function logAccion($id_productos_proveedores, $accion, $resultado, $mensaje, $id_product = null)
    {
        Db::getInstance()->insert('productos_proveedores_log', [
            'id_productos_proveedores' => (int)$id_productos_proveedores,
            'id_employee' => (int)$this->id_employee,
            'accion' => pSQL($accion),
            'origen' => pSQL($this->origen),
            'resultado' => pSQL($resultado),
            'mensaje' => pSQL($mensaje),
            'id_product_prestashop' => $id_product ? (int)$id_product : null,
            'date_add' => date('Y-m-d H:i:s'),
        ]);
    }

    //se guardará en frik_log_import_catalogos si se creó mediante el importador y su botón o encolado, guardando la persona que hizo una u otra cosa
    protected function logImportCatalogos($id_product, $reference, $datos) {
        $operacion = "Crear producto - Importador ".$this->origen;

        $user_nombre = Db::getInstance()->getValue('SELECT firstname FROM '._DB_PREFIX_.'employee WHERE id_employee = '.(int)$this->id_employee);

        $nombre_proveedor = Supplier::getNameById($datos['id_supplier']);

        Db::getInstance()->Execute("INSERT INTO frik_log_import_catalogos
             (operacion, id_product, referencia_presta, ean, referencia_proveedor, id_proveedor, nombre_proveedor, user_id, user_nombre, date_add) 
             VALUES 
             ('".$operacion."',
             ".$id_product.",
             '".$reference."',
             '".$datos['ean']."',
             '".$datos['referencia_proveedor']."',
             ".$datos['id_supplier'].",
             '".$nombre_proveedor."',
             ".$this->id_employee.",
             '".$user_nombre."', 
             NOW())");
            
        return;
    }

    //función que con un id_product de Prestashop elimina el producto al que corresponde. La utilizamos para eliminar aquellos productos que han sido creados (addProduct()) pero el proceso ha fallado antes de finalizarlo, por ejemplo al subir imágenes, quedando sin añadir categorías o proveedor, que va después. De este modo limpiamos lo que creamos, en lugar de tener que repasar a mano.
    protected function deleteProduct($id_product, $id_productos_proveedores = null) {
        try {
            $product = new Product((int)$id_product);

            if (!Validate::isLoadedObject($product)) {
                $msg = "Intento de eliminar un producto inexistente id_product=$id_product";
                $this->logger->log($msg, 'ERROR');
                if ($id_productos_proveedores) {
                    $this->logAccion($id_productos_proveedores, 'eliminar', 'error', $msg);
                }
                return false;
            }

            // Intentar eliminar con PrestaShop nativo
            if ($product->delete()) {
                $msg = "Producto con error id_product=$id_product ELIMINADO correctamente";
                $this->logger->log($msg, 'WARNING');
                if ($id_productos_proveedores) {
                    $this->logAccion($id_productos_proveedores, 'eliminar', 'exito', $msg);
                }
                return true;
            } else {
                $msg = "Producto con error id_product=$id_product NO PUDO ELIMINARSE. DB error: ".Db::getInstance()->getMsgError();
                $this->logger->log($msg, 'ERROR');
                if ($id_productos_proveedores) {
                    $this->logAccion($id_productos_proveedores, 'eliminar', 'error', $msg);
                }
                return false;
            }

            //  // Borrar imágenes asociadas explícitamente
            // try {
            //     Image::deleteImages((int)$id_product);
            // } catch (Exception $eImg) {
            //     $this->logger->log("Aviso al borrar imágenes de id_product=$id_product → ".$eImg->getMessage(), 'WARNING');
            // }

            // // Borrar stock disponible
            // Db::getInstance()->delete('stock_available', 'id_product = '.(int)$id_product);

            // // Borrar sproduct supplier
            // Db::getInstance()->delete('product_supplier', 'id_product = '.(int)$id_product);


        } catch (Exception $e) {
            $msg = "Excepción al eliminar id_product=$id_product → ".$e->getMessage();
            $this->logger->log($msg, 'ERROR');
            if ($id_productos_proveedores) {
                $this->logAccion($id_productos_proveedores, 'eliminar', 'error', $msg);
            }
            return false;
        }
    }

    
    protected function generarReferenciaTemporal()
    {
        $prefijo = 'ZZZ';
        $longitudNumerica = 8;

        // Buscar el número máximo ya usado en referencias con formato ZZZ########
        $maxNum = Db::getInstance()->getValue('
            SELECT MAX(CAST(SUBSTRING(reference, 4) AS UNSIGNED)) 
            FROM (
                SELECT reference FROM '._DB_PREFIX_.'product WHERE reference LIKE "ZZZ%"
                UNION
                SELECT reference FROM '._DB_PREFIX_.'product_attribute WHERE reference LIKE "ZZZ%"
            ) tmp
        ');

        $siguiente = (int)$maxNum + 1;

        // Formatear
        $numero = str_pad((string)$siguiente, $longitudNumerica, '0', STR_PAD_LEFT);

        return $prefijo . $numero;
    }

    //dependiendo de si estamos con un producto con atributos hay que poner el almacén online por defecto al producto base o a los atributos. Por ahora solo generamos sin atributos
    protected function addAlmacen($id_product) {
        $product_warehouse = new WarehouseProductLocation();
        $product_warehouse->id_product = $id_product;
        $product_warehouse->id_product_attribute = 0;
        $product_warehouse->id_warehouse = 1;            
        
        if ($product_warehouse->save()) {
            return true;
        }   

        return false;        
    }

    //Asignar que las cantidades disponibles se basen en gestión avanzada  
    //StockAvailable::setProductDependsOnStock($id_product, $depends_on_stock = true, $id_shop = null, $id_product_attribute = 0)
    //Marcamos no permitir pedidos (0 no permitir, 1 permitir, 2 por defecto) 
    //StockAvailable::setProductOutOfStock($id_product, $out_of_stock = false, $id_shop = null, $id_product_attribute = 0)    
    //con atributos habrá que ir atributo a atributo
    public function addGestionStock($id_product) {    
        //primero al producto base, haya o no  atributos
        StockAvailable::setProductDependsOnStock($id_product, 1, 1, 0);
        
        StockAvailable::setProductOutOfStock($id_product, 2, 1, 0);        

        return true;
    }

    /**
     * Guarda (concatenando) un mensaje de error en productos_proveedores.
     * Igual que en ColaCreacion, pero pensada para ejecuciones manuales.
     */
    protected function guardarErrorProducto($id_productos_proveedores, $nuevo_error)
    {
        $id_productos_proveedores = (int)$id_productos_proveedores;
        $nuevo_error = trim($nuevo_error);

        $mensaje_anterior = Db::getInstance()->getValue('
            SELECT mensaje_error 
            FROM lafrips_productos_proveedores 
            WHERE id_productos_proveedores = '.$id_productos_proveedores
        );

        $mensaje_concatenado = trim(
            ($mensaje_anterior ? $mensaje_anterior.' | ' : '') .
            date('[Y-m-d H:i:s] ') .
            $nuevo_error
        );

        Db::getInstance()->update('productos_proveedores', [
            'mensaje_error' => pSQL($mensaje_concatenado),
            'date_upd'      => date('Y-m-d H:i:s')
        ], 'id_productos_proveedores = '.$id_productos_proveedores);

        $this->logger->log("Guardado error en producto #$id_productos_proveedores → $nuevo_error", 'ERROR');
    }

}
