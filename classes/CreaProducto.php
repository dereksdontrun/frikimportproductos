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
            $idExistente = $this->existeEnPrestashop($datos);
            if ($idExistente) {                
                $msg = "El producto ya existe en PrestaShop (ref={$ref}, ean={$ean}, id_productos_proveedores=$id_productos_proveedores), con id_product=$idExistente";
                $this->logger->log($msg, 'ERROR');
                $this->logAccion($id_productos_proveedores, 'crear', 'error', $msg);
                return ['success' => false, 'message' => $msg];
            }

            //comprobamos iva, si no hay aplicamos 21
            $iva = $datos['iva'] ? (int)$datos['iva'] : 21;
            $pvp_sin_iva = $this->calculaPvpSinIva((float)$datos['coste'], $iva);
            if (!$pvp_sin_iva) {                
                $msg = "No pudo calcularse el PVP sin IVA para producto (ref={$ref}, ean={$ean}, id_productos_proveedores=$id_productos_proveedores)";
                $this->logger->log($msg, 'ERROR');
                $this->logAccion($id_productos_proveedores, 'crear', 'error', $msg);
                return ['success' => false, 'message' => $msg];
            }

            // Normalizar imágenes antes de seguir
            $datos['imagenes'] = $this->decodeImagenes($datos);

            // crear objeto producto
            $product = new Product();
            $product->reference = $this->generarReferenciaTemporal();
            $product->ean13 = $datos['ean']; //asignamos el ean que viene en el catálogo, pero para buscar duplicados usamos ean_norm
            $product->name = [Configuration::get('PS_LANG_DEFAULT') => $datos['nombre']]; // es como $product->name[1] = $nombre;
            $product->description_short = [Configuration::get('PS_LANG_DEFAULT') => $datos['description_short']];
            $product->link_rewrite[1] = Tools::link_rewrite($datos['nombre']);        
            $product->available_now = Tools::mensajeAvailable((int)$datos['id_supplier'])[0];
            $product->available_later = Tools::mensajeAvailable((int)$datos['id_supplier'])[1];
            $product->wholesale_price = (float)$datos['coste'];            
            $product->id_tax_rules_group = $this->getIdTaxRulesGroup($iva);
            $product->price = $pvp_sin_iva; 
            $product->id_supplier = (int)$datos['id_supplier'];
            $product->id_manufacturer = (int)$datos['id_manufacturer'];
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

                return ['success' => false, 'message' => $msg];
            }

            //asignar almacén
            if (!$this->addAlmacen($product->id)) {
                $msg = "Error asignando almacén a producto (ref={$ref}, ean={$ean}, id_productos_proveedores=$id_productos_proveedores)";                   
                
                $this->logger->log($msg, 'ERROR');
                $this->logAccion($id_productos_proveedores, 'crear', 'error', $msg);

                $this->deleteProduct($product->id);

                return ['success' => false, 'message' => $msg];
            }

            //configurar gestión stock avanzado
            if (!$this->addGestionStock($product->id)) {
                $msg = "Error gestionando comportamiento de stock para producto (ref={$ref}, ean={$ean}, id_productos_proveedores=$id_productos_proveedores)";                   
                
                $this->logger->log($msg, 'ERROR');
                $this->logAccion($id_productos_proveedores, 'crear', 'error', $msg);

                $this->deleteProduct($product->id);

                return ['success' => false, 'message' => $msg];
            }

            // imágenes
            $resultImg = $this->procesarImagenes($product->id, $datos['imagenes'] ?? [], $datos['nombre']);

            if (empty($resultImg['ok'])) {
                $msg = "No se pudo importar ninguna imagen para el producto id_product={$product->id}, ref={$ref}, ean={$ean}";
                $this->logger->log($msg, 'ERROR');
                $this->logAccion($id_productos_proveedores, 'crear', 'error', $msg);

                $this->deleteProduct($product->id);

                return ['success' => false, 'message' => $msg];
            }            

            $msg = "Producto con referencia de proveedor $ref, de ".Supplier::getNameById($datos['id_supplier'])." creado correctamente con id_product ".$product->id." y referencia temporal ".$product->reference;

            $this->logger->log($msg, 'SUCCESS');

            // actualizar tabla productos_proveedores
            Db::getInstance()->update('productos_proveedores', [
                'existe_prestashop' => 1,
                'estado' => 'creado',
                'referencia_base_prestashop' => $product->reference,
                'id_product_prestashop' => (int)$product->id,
                'date_importado' => date('Y-m-d H:i:s'),
                'date_upd' => date('Y-m-d H:i:s'),
            ], 'id_productos_proveedores = '.(int)$id_productos_proveedores);

            $this->logAccion($id_productos_proveedores, 'crear', 'exito', 'Producto creado con éxito.', $product->id);

            $this->logImportCatalogos($product->id, $product->reference, $datos);

            return ['success' => true, 'id_product' => $product->id];

        } catch (Exception $e) {
            $msg = "Error al crear producto: ".$e->getMessage();
            $this->logger->log($msg, 'ERROR');
            $this->logAccion($id_productos_proveedores, 'crear', 'error', $msg);
            return ['success' => false, 'message' => $msg];
        }
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
            }
        }

        return $resultados;
    }

    //función de AdminImportController.php Las he traido aquí porque la función del controlador es protected
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

    //función que devuelve el pvp o el pvp sin iva ajustado a un precio con iva redondeado, dado el iva, y el coste, buscando en lafrips_factor_coste_productos el factor por el que multiplicar el coste para obtener el pvp sin iva en bruto.
    //el campo $impuestos true o false indica si se devuelve pvp con o sin iva
    protected function calculaPvpSinIva($coste, $iva = 21, $impuestos = false) {
        //primero obtenemos el factor en función del coste
        $sql = 'SELECT factor 
                FROM lafrips_factor_coste_productos
                WHERE '.(float)$coste.' >= coste_min 
                AND '.(float)$coste.' < coste_max';

        $factor = Db::getInstance()->getValue($sql);

        if ($factor) {
            // PVP sin IVA inicial
            $pvp_sin_iva = $coste * $factor;
            // PVP con IVA inicial
            $pvp_con_iva = $pvp_sin_iva * (1 + $iva / 100);

            // Redondear a 5 centimos si el pvp es menor de 15€ y a 10 centimos si no
            if ($pvp_con_iva < 15) {
                // múltiplos de 0.05
                $pvp_final = ceil($pvp_con_iva / 0.05) * 0.05;
            } else {
                // múltiplos de 0.10
                $pvp_final = ceil($pvp_con_iva / 0.10) * 0.10;
            }

            if ($impuestos) {
                return $pvp_final;
            } else {
                //ahora calculamos el nuevo pvp sin iva para obtener con el iva el pvp redondeado
                $pvp_sin_iva_final = $pvp_final / (1 + $iva / 100);

                // Redondear a 6 decimales
                return round($pvp_sin_iva_final, 6);
            }            
        } else {
            return 0;
        }
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
                WHERE p.ean_norm = "'.$eanNorm.'"
            ');
            if ($id) {
                return (int)$id;
            }

            // en product_attribute
            $id = Db::getInstance()->getValue('
                SELECT pa.id_product 
                FROM '._DB_PREFIX_.'product_attribute pa
                WHERE pa.ean_norm = "'.$eanNorm.'"
            ');
            if ($id) {
                return (int)$id;
            }
        }

        return null;
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
}
