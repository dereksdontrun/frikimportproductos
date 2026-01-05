<?php

class AdminImportExternalProductsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true; // para estilos de backoffice
        parent::__construct();
    }

    public function setMedia()
    {
        parent::setMedia();

        //08/10/2025 Para evitar tener que limpiar caché continuamente, o modificar el nombre del archivo cada vez que se modifica css o js, lo que hacemos es generar una "extensión" numérica con time() basada en la fecha de última modificación. Si se modifica el archivo cambia la extensión y el navegador ignora lo cacheado. Dejo debajo la función addVersionedAsset() para cuando quiera utilizar esto por defecto

        // Rutas físicas de los archivos
        $cssPath = _PS_MODULE_DIR_ . $this->module->name . '/views/css/products.css';
        $jsPath  = _PS_MODULE_DIR_ . $this->module->name . '/views/js/products.js';

        // Fecha de última modificación (timestamp)
        $cssVersion = file_exists($cssPath) ? filemtime($cssPath) : time();
        $jsVersion  = file_exists($jsPath) ? filemtime($jsPath) : time();

        // CSS con "cache buster". Hay que añadir más parámetros para que acepte v=XXX dado que en Parámetros Avanzados->Rendimeinto->CCC tenemos activo "Smart cache" para las hojas de estilo (CSS)
        $this->addCSS(
            __PS_BASE_URI__ . 'modules/' . $this->module->name . '/views/css/products.css?v=' . $cssVersion,
            'all',
            null,
            false // Evita que lo minifique/combina y respeta el ?v=
        );
        // $this->addCSS(__PS_BASE_URI__ . 'modules/' . $this->module->name . '/views/css/products.css?v=1.3');

        // JS con "cache buster"
        $this->addJS(
            __PS_BASE_URI__ . 'modules/' . $this->module->name . '/views/js/products.js?v=' . $jsVersion
        );

        // Variables JS globales
        Media::addJsDef([
            'token_import_products' => $this->token,
            'controller_url' => 'index.php?controller=AdminImportExternalProducts'
        ]);
    }

    //esta función se llamará desde setMedia() para mantener las "versiones" de css y js modificadas cada vez que se modifica el archivo
    /*
    En setMedia():
        $this->addVersionedAsset('products.css', 'css');
        $this->addVersionedAsset('products.js', 'js');
    */
    private function addVersionedAsset($fileName, $type = 'css')
    {
        // Ruta física del archivo (en /modules/tu_modulo/views/css|js/)
        $filePath = _PS_MODULE_DIR_ . $this->module->name . '/views/' . $type . '/' . $fileName;
        $version  = file_exists($filePath) ? filemtime($filePath) : time();

        // Ruta pública (URL)
        $url = __PS_BASE_URI__ . 'modules/' . $this->module->name . '/views/' . $type . '/' . $fileName . '?v=' . $version;

        // Añadir según tipo
        if ($type === 'css') {
            // El parámetro “false” evita que el CCC (Smart cache) lo combine
            $this->addCSS($url, 'all', null, false);
        } elseif ($type === 'js') {
            $this->addJS($url);
        } else {
            $this->logger->log("Tipo de asset no reconocido: $type", 'WARNING');
        }
    }


    public function initContent()
    {
        parent::initContent();

        $suppliers = Db::getInstance()->executeS('
            SELECT DISTINCT s.id_supplier, s.name
            FROM ' . _DB_PREFIX_ . 'productos_proveedores pp
            INNER JOIN ' . _DB_PREFIX_ . 'supplier s ON pp.id_supplier = s.id_supplier
            ORDER BY s.name ASC
        ');

        $manufacturers = Db::getInstance()->executeS('
            SELECT DISTINCT m.id_manufacturer, m.name
            FROM ' . _DB_PREFIX_ . 'productos_proveedores pp
            INNER JOIN ' . _DB_PREFIX_ . 'manufacturer m ON pp.id_manufacturer = m.id_manufacturer
            WHERE pp.id_manufacturer IS NOT NULL
            ORDER BY m.name ASC
        ');

        $estados = Db::getInstance()->executeS('
            SELECT DISTINCT estado
            FROM ' . _DB_PREFIX_ . 'productos_proveedores
            ORDER BY estado ASC
        ');

        // Cargar 100 productos más recientes por defecto
        // Reutilizamos la misma función para los 100 más recientes
        $productos = $this->getProductosFiltrados(0, 0, 0, 0, '', 100);

        $this->context->smarty->assign([
            'token' => $this->token,
            'proveedores' => $suppliers,
            'fabricantes' => $manufacturers,
            'estados' => $estados,
            'productos' => $productos,
        ]);

        $tpl = _PS_MODULE_DIR_ . 'frikimportproductos/views/templates/admin/import_products.tpl';
        $html = $this->context->smarty->fetch($tpl);

        $this->context->smarty->assign([
            'content' => $html,
        ]);
    }



    protected function getProductosFiltrados(
        $id_supplier = 0,
        $id_manufacturer = 0,
        $ocultar_existentes = 0,
        $ocultar_no_disponibles = 0,
        $search = '',
        $buscar_descripcion = 0,
        $limit = 100,
        $estado = '',
        $coste_min = 0,
        $coste_max = 0,
        $orden_coste = ''
    ) {
        $sql = new DbQuery();
        $sql->select('
            pp.id_productos_proveedores, 
            pp.ean_norm AS ean, 
            pp.id_supplier, 
            sup.name AS supplier, 
            pp.id_manufacturer, 
            -- man.name AS manufacturer, 
            pp.manufacturer_name AS manufacturer, 
            pp.referencia_proveedor, 
            pp.url_proveedor, 
            pp.nombre, 
            pp.description_short, 
            pp.coste, 
            pp.imagen_principal, 
            pp.otras_imagenes, 
            CONCAT(UCASE(LEFT(pp.estado, 1)), LCASE(SUBSTRING(pp.estado, 2))) AS estado, 
            pp.disponibilidad, 
            DATE_FORMAT(pp.last_update_info, "%d-%m-%Y %H:%i:%s") AS last_update_info,
            IFNULL(
                GROUP_CONCAT(
                    CONCAT(
                        otro_sup.name, " - ", 
                        FORMAT(ppro.coste, 2, "es_ES"), " €"
                    )
                    SEPARATOR "<br>"
                ),
                "—"
            ) AS otros_proveedores
        ')
            ->from('productos_proveedores', 'pp')
            ->innerJoin('supplier', 'sup', 'sup.id_supplier = pp.id_supplier')
            ->leftJoin('manufacturer', 'man', 'man.id_manufacturer = pp.id_manufacturer')
            // self join con la misma tabla para buscar otros proveedores
            ->leftJoin('productos_proveedores', 'ppro', 'ppro.ean_norm = pp.ean_norm AND ppro.id_supplier != pp.id_supplier AND ppro.ean_norm != "" AND ppro.ean_norm != "0000000000000"')
            ->leftJoin('supplier', 'otro_sup', 'otro_sup.id_supplier = ppro.id_supplier');
       
        if ($id_supplier) {
            $sql->where('pp.id_supplier = ' . (int) $id_supplier);
        }

        if ($id_manufacturer) {
            $sql->where('pp.id_manufacturer = ' . (int) $id_manufacturer);
        }

        if ($ocultar_existentes) {
            // Solo productos que aún no existen en PS
            $sql->where('pp.existe_prestashop = 0');

            // 🚀 Usar LEFT JOIN contra la tabla de identificadores
            $sql->leftJoin('productos_identificadores', 'pi_ean', "pi_ean.tipo='ean' AND pi_ean.valor=pp.ean_norm");
            $sql->leftJoin(
                'productos_identificadores',
                'pi_ref',
                "pi_ref.tipo='referencia_proveedor' 
                AND pi_ref.valor=pp.referencia_proveedor 
                AND pi_ref.id_supplier=pp.id_supplier"
            );

            // Filtrar solo los que no tengan coincidencias
            $sql->where('pi_ean.id_identificador IS NULL');
            $sql->where('pi_ref.id_identificador IS NULL');
        }

        if ($ocultar_no_disponibles) {
            $sql->where('pp.disponibilidad = 1');
        }

        //los ignorados solo los mostraremos si se selecciona estado ignorado
        if ($estado !== 'ignorado') {
            $sql->where('pp.estado != "ignorado"');
        }

        //los eliminados solo los mostraremos si se selecciona estado eliminado
        if ($estado !== 'eliminado') {
            $sql->where('pp.estado != "eliminado"');
        }

        if ($estado !== '') {
            $sql->where('pp.estado = "' . pSQL($estado) . '"');
        }

        if ($coste_min > 0) {
            $sql->where('pp.coste >= '.(float)$coste_min);            
        }
        if ($coste_max > 0) {
            $sql->where('pp.coste <= '.(float)$coste_max);            
        }      

        if ($search) {
            $like = '%' . pSQL($search) . '%';

            if ($buscar_descripcion) {
                $descripcion = " OR pp.description_short LIKE '$like' ";
            } else {
                $descripcion = "";
            }
            
            $sql->where("(pp.nombre LIKE '$like' 
                OR pp.referencia_proveedor LIKE '$like' 
                OR pp.ean LIKE '$like'                 
                OR pp.manufacturer_name LIKE '$like'
                $descripcion)");
        }

        $sql->groupBy('pp.id_productos_proveedores');

        if ($orden_coste === 'asc') {
            $sql->orderBy('pp.coste ASC');            
        } elseif ($orden_coste === 'desc') {
            $sql->orderBy('pp.coste DESC');            
        } else {
            $sql->orderBy('pp.date_add DESC');
        }        

        if ($limit > 0) {
            $sql->limit($limit);
        }

        // var_dump($sql->build());
        $productos = Db::getInstance()->executeS($sql);

        if ($productos) {
            $productos = $this->getCoincidencias($productos);
        }

        return $productos;
    }



    //función para buscar eans o referencias de proveedor que ya existan en Prestashop, recibe el resultado de la búsqueda y devuelve el resultado ampliado con las coincidencias, si existen, formateadas ya para la tabla, con enlaces a backoffice, etc
    public function getCoincidencias($productos)
    {
        $link = new LinkCore();

        foreach ($productos as &$producto) {
            $coincidencias = [];

            // Coincidencias por referencia proveedor
            $matchesSupplier = Db::getInstance()->executeS('
                SELECT id_product
                FROM lafrips_product_supplier
                WHERE product_supplier_reference = "' . pSQL($producto['referencia_proveedor']) . '"
                AND id_supplier = ' . (int) $producto['id_supplier']
            );

            $matchesRef = [];
            foreach ($matchesSupplier as $m) {
                $baseUrl = $link->getAdminLink('AdminProducts');
                $url = $baseUrl . '&id_product=' . $m['id_product'] . '&updateproduct';

                $matchesRef[] = '<a href="' . $url . '" target="_blank">' . $m['id_product'] . '</a>';
            }
            if (!empty($matchesRef)) {
                $coincidencias[] = 'REF: ' . implode(' , ', $matchesRef);
            }

            // Coincidencias por EAN en producto base
            $matchesEanProduct = Db::getInstance()->executeS('
                SELECT id_product
                FROM lafrips_product
                WHERE ean_norm = "' . pSQL($producto['ean']) . '"
                AND ean_norm != ""
                AND ean_norm != "0000000000000"'
            );

            // Coincidencias por EAN en atributos
            $matchesEanAttr = Db::getInstance()->executeS('
                SELECT id_product
                FROM lafrips_product_attribute
                WHERE ean_norm = "' . pSQL($producto['ean']) . '"
                AND ean_norm != ""
                AND ean_norm != "0000000000000"'
            );

            $matchesEan = [];
            foreach (array_merge($matchesEanProduct, $matchesEanAttr) as $m) {
                $baseUrl = $link->getAdminLink('AdminProducts');
                $url = $baseUrl . '&id_product=' . $m['id_product'] . '&updateproduct';

                $matchesEan[] = '<a href="' . $url . '" target="_blank">' . $m['id_product'] . '</a>';
            }
            if (!empty($matchesEan)) {
                $coincidencias[] = 'EAN: ' . implode(' , ', $matchesEan);
            }

            // Montar resultado final
            $producto['coincidencias'] = !empty($coincidencias)
                ? implode('<br>', $coincidencias)
                : '—'; // guion si no hay coincidencias
        }

        return $productos;
    }

    public function ajaxProcessBuscarProductos()
    {
        $id_supplier = (int) Tools::getValue('id_supplier');
        $id_manufacturer = (int) Tools::getValue('id_manufacturer');
        $ocultar_existentes = (int) Tools::getValue('ocultar_existentes');
        $ocultar_no_disponibles = (int) Tools::getValue('ocultar_no_disponibles');
        $search = trim(Tools::getValue('search'));
        $buscar_descripcion = (int) Tools::getValue('buscar_descripcion');
        $limit = (int) Tools::getValue('limit');
        $estado = Tools::getValue('estado', '');
        $coste_min = (float) Tools::getValue('coste_min');
        $coste_max = (float) Tools::getValue('coste_max');
        $orden_coste = Tools::getValue('orden_coste');

        $productos = $this->getProductosFiltrados(
            $id_supplier,
            $id_manufacturer,
            $ocultar_existentes,
            $ocultar_no_disponibles,
            $search,
            $buscar_descripcion,
            $limit,
            $estado,
            $coste_min,
            $coste_max,
            $orden_coste
        );

        die(Tools::jsonEncode($productos));
    }

    /**
     * Marcar producto como IGNORADO
     */
    public function ajaxProcessIgnorarProducto()
    {
        $id = (int) Tools::getValue('id');
        $id_employee = (int) $this->context->employee->id ?? 44;

        try {
            // actualizar estado
            $ok = Db::getInstance()->update('productos_proveedores', [
                'estado' => 'ignorado',
                'date_upd' => date('Y-m-d H:i:s')
            ], 'id_productos_proveedores = ' . $id);

            if ($ok) {
                // registrar log
                Db::getInstance()->insert('productos_proveedores_log', [
                    'id_productos_proveedores' => $id,
                    'id_employee' => $id_employee,
                    'accion' => 'ignorar',
                    'resultado' => 'exito',
                    'mensaje' => null,
                    'date_add' => date('Y-m-d H:i:s')
                ]);

                die(Tools::jsonEncode(['success' => true]));
            } else {
                throw new Exception('No se pudo actualizar el estado.');
            }
        } catch (Exception $e) {
            Db::getInstance()->insert('productos_proveedores_log', [
                'id_productos_proveedores' => $id,
                'id_employee' => $id_employee,
                'accion' => 'ignorar',
                'resultado' => 'error',
                'mensaje' => pSQL($e->getMessage()),
                'date_add' => date('Y-m-d H:i:s')
            ]);
            die(Tools::jsonEncode(['success' => false, 'message' => $e->getMessage()]));
        }
    }

    /**
     * Quitar estado Ignorado al producto
     */
    public function ajaxProcessDesignorarProducto()
    {
        $id = (int) Tools::getValue('id');
        $id_employee = (int) ($this->context->employee->id ?? 44);

        try {
            // Obtener datos del producto
            $producto = Db::getInstance()->getRow('
                SELECT id_product_prestashop, referencia_proveedor, ean_norm, id_supplier 
                FROM '._DB_PREFIX_.'productos_proveedores 
                WHERE id_productos_proveedores = '.(int)$id
            );

            if (!$producto) {
                throw new Exception('Producto no encontrado en la tabla productos_proveedores');
            }

            $nuevoEstado = 'pendiente';

            // Si existe en PrestaShop, se marca como creado
            if (!empty($producto['id_product_prestashop'])) {
                $nuevoEstado = 'creado';
            } else {
                // Comprobamos también si existe por referencia_proveedor o ean_norm
                $existe = Db::getInstance()->getValue('
                    SELECT id_product 
                    FROM '._DB_PREFIX_.'product_supplier 
                    WHERE product_supplier_reference = "'.pSQL($producto['referencia_proveedor']).'"
                    AND id_supplier = '.(int)$producto['id_supplier']
                );

                if (!$existe && !empty($producto['ean_norm'])) {
                    $existe = Db::getInstance()->getValue('
                        SELECT id_product 
                        FROM '._DB_PREFIX_.'product 
                        WHERE ean_norm = "'.pSQL($producto['ean_norm']).'"
                        AND ean_norm != ""
                        AND ean_norm != "0000000000000"
                    ');
                }

                if ($existe) {
                    $nuevoEstado = 'creado';
                }
            }

            // Actualizar estado
            $ok = Db::getInstance()->update('productos_proveedores', [
                'estado' => pSQL($nuevoEstado),
                'date_upd' => date('Y-m-d H:i:s')
            ], 'id_productos_proveedores = ' . (int)$id);

            if (!$ok) {
                throw new Exception('No se pudo actualizar el estado');
            }

            // Registrar log
            Db::getInstance()->insert('productos_proveedores_log', [
                'id_productos_proveedores' => $id,
                'id_employee' => $id_employee,
                'accion' => 'designorar',
                'resultado' => 'exito',
                'mensaje' => 'Producto designorado y marcado como '.$nuevoEstado,
                'date_add' => date('Y-m-d H:i:s')
            ]);

            die(Tools::jsonEncode([
                'success' => true,
                'nuevoEstado' => ucfirst($nuevoEstado)
            ]));

        } catch (Exception $e) {
            Db::getInstance()->insert('productos_proveedores_log', [
                'id_productos_proveedores' => $id,
                'id_employee' => $id_employee,
                'accion' => 'designorar',
                'resultado' => 'error',
                'mensaje' => pSQL($e->getMessage()),
                'date_add' => date('Y-m-d H:i:s')
            ]);
            die(Tools::jsonEncode(['success' => false, 'message' => $e->getMessage()]));
        }
    }

    /**
     * Encolar producto para creación
     */
    public function ajaxProcessEncolarProducto()
    {
        $id = (int) Tools::getValue('id');
        $id_employee = (int) $this->context->employee->id ?? 44;

        try {
            $ok = Db::getInstance()->update('productos_proveedores', [
                'estado' => 'encolado',
                'id_employee_encolado' => $id_employee,
                'date_upd' => date('Y-m-d H:i:s')
            ], 'id_productos_proveedores = ' . $id);

            if ($ok) {
                Db::getInstance()->insert('productos_proveedores_log', [
                    'id_productos_proveedores' => $id,
                    'id_employee' => $id_employee,
                    'accion' => 'encolar',
                    'resultado' => 'exito',
                    'mensaje' => null,
                    'date_add' => date('Y-m-d H:i:s')
                ]);

                die(Tools::jsonEncode(['success' => true]));
            } else {
                throw new Exception('No se pudo encolar el producto.');
            }
        } catch (Exception $e) {
            Db::getInstance()->insert('productos_proveedores_log', [
                'id_productos_proveedores' => $id,
                'id_employee' => $id_employee,
                'accion' => 'encolar',
                'resultado' => 'error',
                'mensaje' => pSQL($e->getMessage()),
                'date_add' => date('Y-m-d H:i:s')
            ]);
            die(Tools::jsonEncode(['success' => false, 'message' => $e->getMessage()]));
        }
    }

    /**
     * Des encolar producto
     */
    public function ajaxProcessDesencolarProducto()
    {
        $id = (int) Tools::getValue('id');
        $id_employee = (int) ($this->context->employee->id ?? 44);

        try {
            $ok = Db::getInstance()->update('productos_proveedores', [
                'estado' => 'pendiente',
                'id_employee_encolado' => 0,
                'date_upd' => date('Y-m-d H:i:s')
            ], 'id_productos_proveedores = ' . $id);

            if ($ok) {
                Db::getInstance()->insert('productos_proveedores_log', [
                    'id_productos_proveedores' => $id,
                    'id_employee' => $id_employee,
                    'accion' => 'desencolar',
                    'resultado' => 'exito',
                    'mensaje' => null,
                    'date_add' => date('Y-m-d H:i:s')
                ]);

                die(Tools::jsonEncode(['success' => true]));
            } else {
                throw new Exception('No se pudo desencolar el producto.');
            }
        } catch (Exception $e) {
            Db::getInstance()->insert('productos_proveedores_log', [
                'id_productos_proveedores' => $id,
                'id_employee' => $id_employee,
                'accion' => 'desencolar',
                'resultado' => 'error',
                'mensaje' => pSQL($e->getMessage()),
                'date_add' => date('Y-m-d H:i:s')
            ]);
            die(Tools::jsonEncode(['success' => false, 'message' => $e->getMessage()]));
        }
    }


    /**
     * Crear producto directamente en Prestashop
     */
    public function ajaxProcessCrearProducto()
    {
        $id = (int) Tools::getValue('id');
        $id_employee = (int) ($this->context->employee->id ?? 44);

        try {
            // Recuperar datos del proveedor
            $producto = Db::getInstance()->getRow('
                SELECT *
                FROM ' . _DB_PREFIX_ . 'productos_proveedores
                WHERE id_productos_proveedores = ' . (int) $id
            );

            if (!$producto) {
                throw new Exception("No se encontró el producto en productos_proveedores.");
            }

            // Preparar datos para CreaProducto
            $datos = [
                'referencia_proveedor' => $producto['referencia_proveedor'],
                'ean' => $producto['ean'],
                'ean_norm' => $producto['ean_norm'],
                'nombre' => $producto['nombre'],
                'description_short' => $producto['description_short'],
                'iva' => $producto['iva'],
                'coste' => $producto['coste'],
                'peso' => $producto['peso'],
                'id_supplier' => $producto['id_supplier'],
                'manufacturer_name' => $producto['manufacturer_name'],
                'imagen_principal' => $producto['imagen_principal'],
                'otras_imagenes' => $producto['otras_imagenes'],
            ];

            require_once _PS_MODULE_DIR_ . 'frikimportproductos/classes/CreaProducto.php';

            $creador = new CreaProducto();
            $res = $creador->crearDesdeProveedor($datos, $id, 'manual', $id_employee);

            die(Tools::jsonEncode($res));

        } catch (Exception $e) {
            // log y respuesta de error
            Db::getInstance()->insert('productos_proveedores_log', [
                'id_productos_proveedores' => $id,
                'id_employee' => $id_employee,
                'accion' => 'crear',
                'resultado' => 'error',
                'mensaje' => pSQL($e->getMessage()),
                'date_add' => date('Y-m-d H:i:s'),
            ]);

            die(Tools::jsonEncode([
                'success' => false,
                'message' => $e->getMessage()
            ]));
        }
    }

    public function ajaxProcessBulkEncolarProductos()
    {
        $ids = Tools::getValue('ids');
        $id_employee = (int) ($this->context->employee->id ?? 0);

        if (!$ids) {
            die(Tools::jsonEncode(['success' => false, 'message' => 'No se recibieron productos']));
        }

        $ids = json_decode($ids, true);
        if (!is_array($ids) || empty($ids)) {
            die(Tools::jsonEncode(['success' => false, 'message' => 'Formato inválido']));
        }

        $count = 0;
        foreach ($ids as $id) {
            $ok = Db::getInstance()->update('productos_proveedores', [
                'estado' => 'encolado',
                'id_employee_encolado' => $id_employee,
                'date_upd' => date('Y-m-d H:i:s')
            ], 'id_productos_proveedores = ' . (int) $id);

            if ($ok) {
                $count++;
                // log individual
                Db::getInstance()->insert('productos_proveedores_log', [
                    'id_productos_proveedores' => (int) $id,
                    'id_employee' => $id_employee,
                    'accion' => 'encolar',
                    'resultado' => 'exito',
                    'mensaje' => null,
                    'date_add' => date('Y-m-d H:i:s')
                ]);
            }
        }

        // log batch
        Db::getInstance()->insert('productos_proveedores_batch_log', [
            'id_employee' => $id_employee,
            'accion' => 'encolar',
            'total_productos' => $count,
            'detalles' => pSQL(json_encode($ids)),
            'date_add' => date('Y-m-d H:i:s')
        ]);

        die(Tools::jsonEncode(['success' => true, 'count' => $count]));
    }

    // public function ajaxProcessBulkDesencolarProductos()
    // {
    //     $ids = Tools::getValue('ids');
    //     $id_employee = (int)($this->context->employee->id ?? 0);

    //     if (!$ids) {
    //         die(Tools::jsonEncode(['success' => false, 'message' => 'No se recibieron productos']));
    //     }

    //     $ids = json_decode($ids, true);
    //     if (!is_array($ids) || empty($ids)) {
    //         die(Tools::jsonEncode(['success' => false, 'message' => 'Formato inválido']));
    //     }

    //     $count = 0;
    //     foreach ($ids as $id) {
    //         $ok = Db::getInstance()->update('productos_proveedores', [
    //             'estado' => 'pendiente',
    //             'date_upd' => date('Y-m-d H:i:s')
    //         ], 'id_productos_proveedores = ' . (int)$id);

    //         if ($ok) {
    //             $count++;
    //             // log individual
    //             Db::getInstance()->insert('productos_proveedores_log', [
    //                 'id_productos_proveedores' => (int)$id,
    //                 'id_employee' => $id_employee,
    //                 'accion' => 'desencolar',
    //                 'resultado' => 'exito',
    //                 'mensaje' => null,
    //                 'date_add' => date('Y-m-d H:i:s')
    //             ]);
    //         }
    //     }

    //     // log batch
    //     Db::getInstance()->insert('productos_proveedores_batch_log', [
    //         'id_employee' => $id_employee,
    //         'accion' => 'desencolar',
    //         'total_productos' => $count,
    //         'detalles' => pSQL(json_encode($ids)),
    //         'date_add' => date('Y-m-d H:i:s')
    //     ]);

    //     die(Tools::jsonEncode(['success' => true, 'count' => $count]));
    // }



}
