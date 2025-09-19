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

        // CSS
        $this->addCSS(__PS_BASE_URI__ . 'modules/' . $this->module->name . '/views/css/products.css');

        // JS
        $this->addJS(__PS_BASE_URI__ . 'modules/' . $this->module->name . '/views/js/products.js');

        // Variables JS globales
        Media::addJsDef([
            'token_import_products' => $this->token,
            'controller_url' => 'index.php?controller=AdminImportExternalProducts'
        ]);
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
        $limit = 100,
        $estado = ''
    ) {
        $sql = new DbQuery();
        $sql->select('
            pp.id_productos_proveedores, 
            pp.ean_norm AS ean, 
            pp.id_supplier, 
            sup.name AS supplier, 
            pp.id_manufacturer, 
            man.name AS manufacturer, 
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
            ->leftJoin('productos_proveedores', 'ppro', 'ppro.ean_norm = pp.ean_norm AND ppro.id_supplier != pp.id_supplier')
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

        if ($estado !== '') {
            $sql->where('pp.estado = "' . pSQL($estado) . '"');
        }

        if ($search) {
            $like = '%' . pSQL($search) . '%';
            $sql->where("(pp.nombre LIKE '$like' OR pp.referencia_proveedor LIKE '$like' OR pp.ean LIKE '$like' OR pp.description_short LIKE '$like')");
        }

        $sql->groupBy('pp.id_productos_proveedores');
        $sql->orderBy('pp.date_add DESC');

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
        $search = Tools::getValue('search');
        $limit = (int) Tools::getValue('limit');
        $estado = Tools::getValue('estado', '');

        $productos = $this->getProductosFiltrados($id_supplier, $id_manufacturer, $ocultar_existentes, $ocultar_no_disponibles, $search, $limit, $estado);

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
                'id_manufacturer' => $producto['id_manufacturer'],
                'imagen_principal' => $producto['imagen_principal'],
                'otras_imagenes' => $producto['otras_imagenes'],
            ];

            require_once _PS_MODULE_DIR_ . 'frikimportproductos/classes/CreaProducto.php';

            $creador = new CreaProducto();
            $res = $creador->crearDesdeProveedor($datos, $id, 'manual');

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
