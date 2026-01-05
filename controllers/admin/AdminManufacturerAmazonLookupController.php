<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'frikimportproductos/classes/ManufacturerMaintenanceTools.php';
require_once _PS_ROOT_DIR_ . '/classes/utils/LoggerFrik.php';


class AdminManufacturerAmazonLookupController extends AdminController
{
    public function __construct()
    {
        // Tabla base
        $this->table = 'manufacturer_amazon_lookup';
        $this->identifier = 'id_manufacturer_amazon_lookup';
        $this->className = ''; // sin ObjectModel
        $this->lang = false;
        $this->bootstrap = true;

        parent::__construct();

        $this->show_toolbar = true;
        $this->show_page_header_toolbar = true;
        // opcional pero suele ir bien
        $this->toolbar_scroll = true;

        if (!is_string($this->_where)) {
            $this->_where = '';
        }

        // SELECT extra para la lista
        $this->_select = '
            p.id_product,
            p.reference AS product_reference_presta,
            pl.name AS product_name_presta,
            m_cur.name AS manufacturer_current_name_presta,
            m_res.name AS manufacturer_resolved_name,            
            CASE
                WHEN a.id_manufacturer_resolved IS NULL
                    OR a.id_manufacturer_resolved = 0
                    THEN "not_resolved"
                WHEN a.id_manufacturer_resolved = a.id_manufacturer_current
                    THEN "ok"
                ELSE "pending"
            END AS sync_status
        ';

        // JOINs para la lista
        $this->_join = '
            LEFT JOIN ' . _DB_PREFIX_ . 'product p
                ON (p.id_product = a.id_product)
            LEFT JOIN ' . _DB_PREFIX_ . 'product_lang pl
                ON (pl.id_product = p.id_product AND pl.id_lang = ' . (int) $this->context->language->id . ')
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m_cur
                ON (m_cur.id_manufacturer = a.id_manufacturer_current)
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m_res
                ON (m_res.id_manufacturer = a.id_manufacturer_resolved)            
        ';

        // Por defecto no filtramos nada más
        // $this->_where = '';

        // Campos listados
        $this->fields_list = array(
            'id_manufacturer_amazon_lookup' => array(
                'title' => $this->l('ID'),
                'align' => 'center',
                'class' => 'fixed-width-xs',
            ),
            'id_product' => array(
                'title' => $this->l('ID Prod'),
                'align' => 'center',
                'class' => 'fixed-width-xs',
                'filter_key' => 'p!id_product',
                'callback' => 'renderIdProductColumn',
                'remove_onclick' => true, //para que al pulsar el link de id_product no entre en modificar, pero solo ene sta columna
            ),
            'product_reference_presta' => array(
                'title' => $this->l('Referencia'),
                'filter_key' => 'p!reference',
                'class' => 'fixed-width-sm',
            ),
            'product_name_presta' => array(
                'title' => $this->l('Producto'),
                'filter_key' => 'pl!name',
            ),
            'ean13' => array(
                'title' => $this->l('EAN'),
                'filter_key' => 'a!ean13',
                'class' => 'fixed-width-sm',
            ),
            'marketplace_id' => array(
                'title' => $this->l('Marketplace'),
                'align' => 'center',
                'orderby' => false,
                'search' => false,
                'callback' => 'renderMarketplaceColumn',
                'class' => 'fixed-width-xs',
            ),
            'asin' => array(
                'title' => $this->l('ASIN'),
                'class' => 'fixed-width-sm',
            ),
            'raw_brand' => array(
                'title' => $this->l('Brand (Amazon)'),
            ),
            'raw_manufacturer' => array(
                'title' => $this->l('Manufacturer (Amazon)'),
            ),
            'manufacturer_current_name_presta' => array(
                'title' => $this->l('Fabricante actual'),
                'filter_key' => 'm_cur!name',
            ),
            'manufacturer_resolved_name' => array(
                'title' => $this->l('Fabricante resuelto'),
                'filter_key' => 'm_res!name',
            ),
            'status' => array(
                'title' => $this->l('Estado'),
                'type' => 'select',
                'list' => array(
                    'no_ean' => $this->l('Sin EAN'),
                    'not_found' => $this->l('No encontrado'),
                    'resolved' => $this->l('Resuelto'),
                    'pending' => $this->l('Pendiente'),
                    'error' => $this->l('Error'),
                ),
                'callback' => 'renderStatusColumn',
                'filter_key' => 'a!status',
                'class' => 'fixed-width-sm',
            ),
            // 'resolved_from' => array(
            //     'title' => $this->l('Origen'),
            //     'type' => 'select',
            //     'list' => array(
            //         'none' => $this->l('Ninguno'),
            //         'manufacturer' => $this->l('Manufacturer'),
            //         'brand' => $this->l('Brand'),
            //         'manual' => $this->l('Manual'),
            //     ),
            //     'filter_key' => 'a!resolved_from',
            //     'class' => 'fixed-width-xs',
            // ),            
            'sync_status' => array(
                'title' => $this->l('Sincronizado'),
                'type' => 'select',
                'list' => array(
                    'ok' => $this->l('OK'),
                    'pending' => $this->l('Pendiente'),
                    'not_resolved' => $this->l('No resuelto'),
                ),
                'filter_key' => 'sync_status',   // alias definido en el CASE
                'havingFilter' => true,            // IMPORTANTÍSIMO para alias
                'callback' => 'renderSyncColumn',
                'orderby' => false,
                'search' => true,
                'align' => 'center',
                'class' => 'fixed-width-xs',
            ),
            // 'error_message' => array(
            //     'title' => $this->l('Error / nota'),
            //     'callback' => 'renderErrorColumn',
            //     'orderby' => false,
            // ),
            'date_add' => array(
                'title' => $this->l('Alta'),
                'type' => 'datetime',
            ),
            'date_upd' => array(
                'title' => $this->l('Actualizado'),
                'type' => 'datetime',
            ),
        );

        $this->addRowAction('edit');
        $this->addRowAction('apply_resolved');
        $this->addRowAction('delete');

        $this->bulk_actions = array(
            'delete' => array(
                'text' => $this->l('Eliminar seleccionados'),
                'confirm' => $this->l('¿Eliminar los elementos seleccionados?'),
            ),
            'apply_resolved' => array(
                'text' => $this->l('Aplicar fabricante resuelto a producto'),
                'icon' => 'icon-refresh',
                'confirm' => $this->l('¿Aplicar el fabricante resuelto a los productos seleccionados?'),
            ),
        );
    }

    public function initPageHeaderToolbar()
    {
        parent::initPageHeaderToolbar();

        $urlBase = self::$currentIndex . '&token=' . $this->token;

        // Botón: reintentar resoluciones (sin API, solo alias helper)
        $this->page_header_toolbar_btn['retry_amazon_resolves'] = array(
            // 'href' => $urlBase . '&run_retry_amazon_resolves=1',
            'href' => '#',                                          // DESHABILITADO
            'desc' => $this->l('Reintentar resoluciones (Amazon)  DESHABILITADO'),
            'icon' => 'process-icon-refresh',
        );

        // Botón para reintentar contra Amazon API los not_found/error/rate_limited
        $this->page_header_toolbar_btn['retry_amazon_api'] = array(
            // 'href' => $urlBase . '&run_retry_amazon_api=1',
            'href' => '#',                                          // DESHABILITADO
            'desc' => $this->l('Reintentar con Amazon API  DESHABILITADO'),
            'icon' => 'process-icon-refresh',
        );
    }

    public function displayApply_resolvedLink($token = null, $id = 0)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return '';
        }

        // Cargamos lo mínimo para decidir si mostrar el botón
        $row = Db::getInstance()->getRow('
            SELECT id_product, id_manufacturer_current, id_manufacturer_resolved, status
            FROM ' . _DB_PREFIX_ . 'manufacturer_amazon_lookup
            WHERE id_manufacturer_amazon_lookup = ' . (int) $id . '
        ');

        //comprobamos que el producto sea "resolvible"
        if (!$row || !$this->canApplyResolvedRow($row)) {
            return '';
        }

        // En PS 1.6 construimos el link a mano
        $href = self::$currentIndex
            . '&' . $this->identifier . '=' . (int) $id
            . '&apply_resolved=1'
            . '&token=' . $this->token;

        return '
            <a class="btn btn-default btn-xs" href="' . $href . '">
                <i class="icon-refresh"></i> ' . $this->l('Aplicar fabricante') . '
            </a>
        ';
    }

    /* ============================================================
     *  Callbacks columnas de la lista
     * ============================================================
     */
    public function renderMarketplaceColumn($value, $row)
    {
        $map = array(
            'A1RKKUPIHCS9HS' => 'ES',
            'A13V1IB3VIYZZH' => 'FR',
            'A1PA6795UKMFR9' => 'DE',
            'A1F83G8C2ARO7P' => 'UK',
            'APJ6JRA9NG5V4' => 'IT',
            'A1805IZSGTT6HS' => 'NL',
            'AMEN7PMS3EDWL' => 'BE',
        );

        if (isset($map[$value])) {
            return '<span class="label label-default">' . $map[$value] . '</span>';
        }

        // fallback: mostramos el código tal cual
        return '<span title="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
            . '</span>';
    }


    public function renderStatusColumn($value, $row)
    {
        $label = '';
        $class = 'label-default';

        switch ($value) {
            case 'resolved':
                $label = $this->l('Resuelto');
                $class = 'label-success';
                break;
            case 'pending':
                $label = $this->l('Pendiente');
                $class = 'label-warning';
                break;
            case 'not_found':
                $label = $this->l('No encontrado');
                $class = 'label-info';
                break;
            case 'no_ean':
                $label = $this->l('Sin EAN');
                $class = 'label-default';
                break;
            case 'error':
                $label = $this->l('Error');
                $class = 'label-danger';
                break;
            default:
                $label = $value;
                break;
        }

        return '<span class="label ' . $class . '">' . $label . '</span>';
    }

    public function renderIdProductColumn($value, $row)
    {
        $idProduct = (int) $value;
        if ($idProduct <= 0) {
            return '-';
        }

        $link = $this->context->link->getAdminLink('AdminProducts', true)
            . '&id_product=' . (int) $idProduct . '&updateproduct';

        return '<a href="' . $link . '" target="_blank">' . $idProduct . '</a>';
    }


    public function renderErrorColumn($value, $row)
    {
        if (!$value) {
            return '';
        }

        // Si es muy largo, recortamos un poco y mostramos el resto en title
        $short = Tools::substr($value, 0, 60);
        if (Tools::strlen($value) > 60) {
            $short .= '...';
        }

        return '<span title="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">' .
            htmlspecialchars($short, ENT_QUOTES, 'UTF-8') .
            '</span>';
    }

    public function renderSyncColumn($value, $row)
    {
        $idCurrent = isset($row['id_manufacturer_current']) ? (int) $row['id_manufacturer_current'] : 0;
        $idResolved = isset($row['id_manufacturer_resolved']) ? (int) $row['id_manufacturer_resolved'] : 0;

        // Si no hay fabricante resuelto, no tiene sentido hablar de sincronizado
        if ($idResolved <= 0) {
            return '<span class="label label-danger" title="Fabricante del producto sin resolver">'
                . $this->l('No resuelto')
                . '</span>';
        }

        if ($idCurrent === $idResolved) {
            return '<span class="label label-success" title="Fabricante del producto sincronizado">'
                . $this->l('OK')
                . '</span>';
        }

        // Hay resuelto pero el producto aún tiene otro fabricante
        return '<span class="label label-warning" title="Fabricante resuelto distinto al del producto">'
            . $this->l('Pendiente')
            . '</span>';
    }

    /* ============================================================
     *  Bulk delete (como en los otros controladores)
     * ============================================================
     */

    public function processBulkDelete()
    {
        $ids = Tools::getValue($this->table . 'Box');

        if (is_array($ids) && !empty($ids)) {
            $ids = array_map('intval', $ids);
            $ids = array_filter($ids);

            if (!empty($ids)) {
                $idList = implode(',', $ids);
                Db::getInstance()->execute(
                    'DELETE FROM ' . _DB_PREFIX_ . 'manufacturer_amazon_lookup
                 WHERE id_manufacturer_amazon_lookup IN (' . $idList . ')'
                );
                $this->confirmations[] = $this->l('Registros seleccionados eliminados correctamente.');
            }
        }

        // No llamamos a parent para evitar doble lógica
        return true;
    }

    public function processBulkApply_resolved()
    {
        $ids = Tools::getValue($this->table . 'Box'); // manufacturer_amazon_lookupBox

        if (!is_array($ids) || empty($ids)) {
            return;
        }

        $updated = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $result = $this->applyResolvedToLookup((int) $id);

            if ($result === 'updated') {
                $updated++;
            } else {
                $skipped++;
            }
        }

        if ($updated) {
            $this->confirmations[] = sprintf(
                $this->l('%d producto(s) actualizados con el fabricante resuelto.'),
                $updated
            );
        }

        if ($skipped) {
            $this->warnings[] = sprintf(
                $this->l('%d registro(s) omitidos (no resueltos, sin fabricante o ya sincronizados).'),
                $skipped
            );
        }

        return true;
    }


    public function postProcess()
    {
        // Bulk "Sincronizar con producto"
        if (Tools::isSubmit('submitBulkapply_resolved' . $this->table)) {
            $this->processBulkApply_resolved();
        }

        if (Tools::getIsset('apply_resolved')) {
            $this->processApplyResolved();
            // processApplyResolved hace redirect, así que no hace falta seguir
            return;
        }

        // Acción especial: reintentar resoluciones Amazon sin API
        if (Tools::getValue('run_retry_amazon_resolves')) {

            $limit = (int) Tools::getValue('retry_limit', 100);
            $status = Tools::getValue('retry_status', 'pending');
            $market = Tools::getValue('retry_marketplace_id', '');

            $logFile = _PS_MODULE_DIR_ . 'frikimportproductos/logs/manufacturers/retry_amazon_lookup_resolves_' . date('Ymd') . '.txt';
            $logger = new LoggerFrik($logFile);

            $result = ManufacturerMaintenanceTools::retryAmazonLookupResolves(
                $limit,
                $status,
                $market,
                false,   // dryRun = false (de verdad)
                $logger
            );

            $this->confirmations[] = sprintf(
                $this->l('Reintento completado: procesados %d, resueltos %d, siguen sin resolver %d.'),
                (int) $result['processed'],
                (int) $result['resolved'],
                (int) $result['still_pending']
            );

            // Redirigimos para evitar reenvío y que no repita el proceso al refrescar
            Tools::redirectAdmin(self::$currentIndex . '&token=' . $this->token);
        }

        // reintentar llamadas a Amazon API
        if (Tools::getIsset('run_retry_amazon_api')) {

            // Puedes parametrizar el límite vía GET si quieres (por ahora fijo)
            $limit = (int) Tools::getValue('retry_limit', 50);
            if ($limit <= 0) {
                $limit = 50;
            }

            // Opcional: permitir filtrar por marketplace en el futuro (?retry_marketplace_id=A1R...)
            $market = Tools::getValue('retry_marketplace_id', '');

            $logFile = _PS_MODULE_DIR_ . 'frikimportproductos/logs/manufacturers/retry_amazon_api_' . date('Ymd') . '.txt';
            $logger = new LoggerFrik($logFile);

            $result = ManufacturerMaintenanceTools::retryAmazonLookupApi(
                $limit,
                $logger,
                $market,
                false // dryRun = false, queremos que escriba en BD
            );

            $this->confirmations[] = sprintf(
                $this->l('Reintento con Amazon API completado: procesados %d, resueltos %d, no encontrados %d, errores %d, rate limited %d.'),
                (int) $result['processed'],
                (int) $result['resolved'],
                (int) $result['not_found'],
                (int) $result['error'],
                (int) $result['rate_limited']
            );

            Tools::redirectAdmin(self::$currentIndex . '&token=' . $this->token);
        }

        return parent::postProcess();
    }

    protected function processApplyResolved()
    {
        $id = (int) Tools::getValue($this->identifier);

        $result = $this->applyResolvedToLookup($id);

        switch ($result) {
            case 'updated':
                $this->confirmations[] = $this->l('Fabricante del producto actualizado con el fabricante resuelto.');
                break;

            case 'already_synced':
                $this->warnings[] = $this->l('El producto ya tenía asignado el fabricante resuelto.');
                break;

            case 'not_resolved':
                $this->errors[] = $this->l('El registro no está en estado "resolved".');
                break;

            case 'invalid':
                $this->errors[] = $this->l('Datos incompletos: falta producto o fabricante resuelto.');
                break;

            case 'not_found':
            default:
                $this->errors[] = $this->l('Registro de lookup no encontrado.');
                break;
        }

        // Volvemos a la lista
        Tools::redirectAdmin(self::$currentIndex . '&token=' . $this->token);
    }


    /**
     * Aplica el fabricante resuelto a un registro de lookup concreto.
     *
     * @param int $idLookup
     * @return string
     *   'updated'          → producto + lookup actualizados
     *   'not_found'        → no existe fila en lookup
     *   'not_resolved'     → status != "resolved"
     *   'invalid'          → sin producto o sin fabricante resuelto
     *   'already_synced'   → el producto ya tiene ese fabricante
     */
    protected function applyResolvedToLookup($idLookup)
    {
        $idLookup = (int) $idLookup;
        if ($idLookup <= 0) {
            return 'invalid';
        }

        $row = Db::getInstance()->getRow('
            SELECT *
            FROM ' . _DB_PREFIX_ . 'manufacturer_amazon_lookup
            WHERE id_manufacturer_amazon_lookup = ' . (int) $idLookup . '
        ');

        if (!$row) {
            return 'not_found';
        }

        if (!$this->canApplyResolvedRow($row)) {
            // diferenciamos un poco el motivo
            if ((int) $row['id_manufacturer_resolved'] <= 0 || $row['status'] !== 'resolved') {
                return 'not_resolved';
            }
            return 'already_synced';
        }

        $idProduct = (int) $row['id_product'];
        $idMfResolved = (int) $row['id_manufacturer_resolved'];

        // 1) Actualizar producto
        Db::getInstance()->update(
            'product',
            array(
                'id_manufacturer' => (int) $idMfResolved,
            ),
            'id_product = ' . (int) $idProduct
        );

        // 2) Actualizar lookup
        $mfNameResolved = Manufacturer::getNameById($idMfResolved);

        Db::getInstance()->update(
            'manufacturer_amazon_lookup',
            array(
                'id_manufacturer_current' => (int) $idMfResolved,
                'manufacturer_current_name' => pSQL($mfNameResolved),
                'date_upd' => date('Y-m-d H:i:s'),
            ),
            'id_manufacturer_amazon_lookup = ' . (int) $idLookup
        );

        return 'updated';
    }

    /**
     * Devuelve true si este registro de lookup es "aplicable" (asignarle el fabricante resuelto):
     *  - status = resolved
     *  - tiene id_product e id_manufacturer_resolved
     *  - el fabricante actual es distinto del resuelto
     */
    protected function canApplyResolvedRow(array $row)
    {
        $idProduct = (int) $row['id_product'];
        $idMfCurrent = (int) $row['id_manufacturer_current'];
        $idMfResolved = (int) $row['id_manufacturer_resolved'];
        $status = isset($row['status']) ? $row['status'] : '';

        if (
            $status !== 'resolved'
            || $idProduct <= 0
            || $idMfResolved <= 0
        ) {
            return false;
        }

        // Si ya está sincronizado, tampoco tiene sentido
        if ($idMfCurrent === $idMfResolved) {
            return false;
        }

        return true;
    }


    /* ============================================================
     *  Formulario de edición / resolución manual
     * ============================================================
     */

    public function renderForm()
    {
        $id_lookup = (int) Tools::getValue($this->identifier);
        if ($id_lookup <= 0) {
            $this->errors[] = $this->l('ID de lookup no válido.');
            return parent::renderForm();
        }

        // Cargamos la fila directamente de la tabla
        $row = Db::getInstance()->getRow('
        SELECT *
        FROM ' . _DB_PREFIX_ . 'manufacturer_amazon_lookup
        WHERE id_manufacturer_amazon_lookup = ' . (int) $id_lookup
        );

        if (!$row) {
            $this->errors[] = $this->l('Registro de lookup no encontrado.');
            return parent::renderForm();
        }

        // Lista de fabricantes para el select
        $manufacturers = Manufacturer::getManufacturers(false, $this->context->language->id, true);
        $optionsMf = array();
        foreach ($manufacturers as $m) {
            $optionsMf[] = array(
                'id_manufacturer' => (int) $m['id_manufacturer'],
                'name' => $m['name'],
            );
        }

        // Si hay empleado resuelto, sacamos su nombre
        $employee_resolved_name = '';
        if (!empty($row['id_employee_resolved'])) {
            $emp = new Employee((int) $row['id_employee_resolved']);
            if (Validate::isLoadedObject($emp)) {
                $employee_resolved_name = $emp->firstname . ' ' . $emp->lastname;
            }
        }

        $statusOptions = array(
            array('id' => 'no_ean', 'name' => $this->l('Sin EAN')),
            array('id' => 'not_found', 'name' => $this->l('No encontrado')),
            array('id' => 'resolved', 'name' => $this->l('Resuelto')),
            array('id' => 'pending', 'name' => $this->l('Pendiente')),
            array('id' => 'error', 'name' => $this->l('Error')),
        );

        $resolvedFromOptions = array(
            array('id' => 'none', 'name' => $this->l('Ninguno')),
            array('id' => 'manufacturer', 'name' => $this->l('Manufacturer')),
            array('id' => 'brand', 'name' => $this->l('Brand')),
            array('id' => 'manual', 'name' => $this->l('Manual')),
        );

        $this->fields_form = array(
            'legend' => array(
                'title' => $this->l('Resolver fabricante desde Amazon'),
                'icon' => 'icon-cloud',
            ),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => $this->l('ID lookup'),
                    'name' => 'id_manufacturer_amazon_lookup',
                    'readonly' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('ID Producto'),
                    'name' => 'id_product',
                    'readonly' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Referencia producto'),
                    'name' => 'product_reference',
                    'readonly' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Nombre producto'),
                    'name' => 'product_name',
                    'readonly' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('EAN'),
                    'name' => 'ean13',
                    'readonly' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Marketplace'),
                    'name' => 'marketplace_id',
                    'readonly' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('ASIN'),
                    'name' => 'asin',
                    'readonly' => true,
                ),

                array(
                    'type' => 'text',
                    'label' => $this->l('Fabricante actual Presta'),
                    'name' => 'manufacturer_current_name',
                    'readonly' => true,
                ),

                array(
                    'type' => 'text',
                    'label' => $this->l('Brand (Amazon)'),
                    'name' => 'raw_brand',
                    'readonly' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Manufacturer (Amazon)'),
                    'name' => 'raw_manufacturer',
                    'readonly' => true,
                ),

                array(
                    'type' => 'select',
                    'label' => $this->l('Estado'),
                    'name' => 'status',
                    'options' => array(
                        'query' => $statusOptions,
                        'id' => 'id',
                        'name' => 'name',
                    ),
                    'desc' => $this->l('Normalmente "resolved" si se ha asignado un fabricante correcto.'),
                ),

                array(
                    'type' => 'select',
                    'label' => $this->l('Fabricante resuelto'),
                    'name' => 'id_manufacturer_resolved',
                    'options' => array(
                        'query' => $optionsMf,
                        'id' => 'id_manufacturer',
                        'name' => 'name',
                    ),
                    'required' => false,
                    'desc' => $this->l('Selecciona el fabricante canónico si quieres resolver este registro.'),
                ),

                array(
                    'type' => 'select',
                    'label' => $this->l('Origen resolución'),
                    'name' => 'resolved_from',
                    'options' => array(
                        'query' => $resolvedFromOptions,
                        'id' => 'id',
                        'name' => 'name',
                    ),
                    'desc' => $this->l('Marca "manual" si lo estás resolviendo desde aquí.'),
                ),

                array(
                    'type' => 'textarea',
                    'label' => $this->l('Mensaje error / nota'),
                    'name' => 'error_message',
                    'cols' => 60,
                    'rows' => 3,
                ),

                array(
                    'type' => 'text',
                    'label' => $this->l('Resuelto por'),
                    'name' => 'employee_resolved_name',
                    'readonly' => true,
                    'desc' => $this->l('Se rellenará automáticamente con tu usuario al guardar.'),
                ),

                array(
                    'type' => 'switch',
                    'label' => $this->l('Aplicar al producto'),
                    'name' => 'apply_to_product',
                    'is_bool' => true,
                    'values' => array(
                        array(
                            'id' => 'apply_to_product_on',
                            'value' => 1,
                            'label' => $this->l('Sí'),
                        ),
                        array(
                            'id' => 'apply_to_product_off',
                            'value' => 0,
                            'label' => $this->l('No'),
                        ),
                    ),
                    'desc' => $this->l('Si marcas "Sí" y hay fabricante resuelto, se actualizará el fabricante del producto en PrestaShop.'),
                ),
            ),
            'submit' => array(
                'title' => $this->l('Guardar'),
            ),
        );

        // Valores para el formulario
        $this->fields_value['id_manufacturer_amazon_lookup'] = (int) $row['id_manufacturer_amazon_lookup'];
        $this->fields_value['id_product'] = (int) $row['id_product'];
        $this->fields_value['product_reference'] = $row['product_reference'];
        $this->fields_value['product_name'] = $row['product_name'];
        $this->fields_value['ean13'] = $row['ean13'];
        $this->fields_value['marketplace_id'] = $this->getMarketplaceLabel($row['marketplace_id']);
        $this->fields_value['asin'] = $row['asin'];
        $this->fields_value['manufacturer_current_name'] = $row['manufacturer_current_name'];
        $this->fields_value['raw_brand'] = $row['raw_brand'];
        $this->fields_value['raw_manufacturer'] = $row['raw_manufacturer'];
        $this->fields_value['status'] = $row['status'];
        $this->fields_value['id_manufacturer_resolved'] = (int) $row['id_manufacturer_resolved'];
        $this->fields_value['resolved_from'] = $row['resolved_from'];
        $this->fields_value['error_message'] = $row['error_message'];
        $this->fields_value['employee_resolved_name'] = $employee_resolved_name;
        $this->fields_value['apply_to_product'] = 0;

        return parent::renderForm();
    }

    protected function getMarketplaceLabel($marketplaceId)
    {
        $map = array(
            'A1RKKUPIHCS9HS' => 'ES',
            'A13V1IB3VIYZZH' => 'FR',
            'A1F83G8C2ARO7P' => 'UK',
            'A1PA6795UKMFR9' => 'DE',
            'APJ6JRA9NG5V4' => 'IT',
            'A1805IZSGTT6HS' => 'NL',
            'AMEN7PMS3EDWL' => 'BE'
            // añadir los que se usen
        );

        if (isset($map[$marketplaceId])) {
            return $marketplaceId . ' (' . $map[$marketplaceId] . ')';
        }

        return $marketplaceId;
    }


    /* ============================================================
     *  Guardar resolución manual
     * ============================================================
     */

    public function processSave()
    {
        $id_lookup = (int) Tools::getValue('id_manufacturer_amazon_lookup');
        $id_product = (int) Tools::getValue('id_product');
        $id_mf_res = (int) Tools::getValue('id_manufacturer_resolved');
        $status = Tools::getValue('status');
        $resolved_from = Tools::getValue('resolved_from');
        $error_msg = Tools::getValue('error_message');
        $apply_to_product = (int) Tools::getValue('apply_to_product', 0);

        if ($id_lookup <= 0) {
            $this->errors[] = $this->l('ID de lookup no válido.');
            return false;
        }

        // Validaciones básicas
        if ($apply_to_product && ($status !== 'resolved' || $id_mf_res <= 0)) {
            $this->errors[] = $this->l('Para aplicar al producto, el estado debe ser "resolved" y debe haber un fabricante resuelto.');
            return false;
        }

        // Confirmamos que el registro existe
        $lookupRow = Db::getInstance()->getRow('
            SELECT *
            FROM ' . _DB_PREFIX_ . 'manufacturer_amazon_lookup
            WHERE id_manufacturer_amazon_lookup = ' . (int) $id_lookup
        );

        if (!$lookupRow) {
            $this->errors[] = $this->l('Registro de lookup no encontrado.');
            return false;
        }

        // Si el registro se marca como resuelto y hay fabricante resuelto,
        // creamos alias en ManufacturerAliasHelper para los nombres de Amazon.
        if ($status === 'resolved' && $id_mf_res > 0) {
            if (!class_exists('ManufacturerAliasHelper')) {
                require_once _PS_MODULE_DIR_ . 'frikimportproductos/classes/ManufacturerAliasHelper.php';
            }

            // Intentamos crear alias con manufacturer de Amazon
            if (!empty($lookupRow['raw_manufacturer'])) {
                ManufacturerAliasHelper::createAlias(
                    $id_mf_res,
                    $lookupRow['raw_manufacturer'],
                    null,                   // que normalice él
                    'AMAZON_MANUFACTURER',  // source
                    0                       // auto_created = 0 (lo ha revisado una persona)
                );
            }

            // Y también podemos guardar brand como alias, si existe POR AHORA NO, CUANDO SE VEA SI COINCIDEN Y MERECE LA PENA
            // if (!empty($lookupRow['raw_brand'])) {
            //     ManufacturerAliasHelper::createAlias(
            //         $id_mf_res,
            //         $lookupRow['raw_brand'],
            //         null,
            //         'AMAZON_BRAND',
            //         0
            //     );
            // }

            // Si quieres ser aún más explícito, podrías forzar resolved_from = 'manual'
            // cuando se guarda desde aquí:
            if ($resolved_from === '' || $resolved_from === 'none') {
                $resolved_from = 'manual';
            }
        }

        // Actualizar producto si procede
        if ($apply_to_product && $id_product > 0 && $id_mf_res > 0) {
            // 1) Actualizar producto
            Db::getInstance()->update(
                'product',
                array(
                    'id_manufacturer' => (int) $id_mf_res,
                ),
                'id_product = ' . (int) $id_product
            );

            // 2) Sincronizar también el "fabricante actual" en la tabla de lookup
            $mfName = Db::getInstance()->getValue('
                SELECT name
                FROM ' . _DB_PREFIX_ . 'manufacturer
                WHERE id_manufacturer = ' . (int) $id_mf_res
            );

            Db::getInstance()->update(
                'manufacturer_amazon_lookup',
                array(
                    'id_manufacturer_current' => (int) $id_mf_res,
                    'manufacturer_current_name' => pSQL($mfName),
                ),
                'id_manufacturer_amazon_lookup = ' . (int) $id_lookup
            );
        }

        // Actualizar fila de lookup
        $id_employee = (int) $this->context->employee->id;
        $now = date('Y-m-d H:i:s');

        Db::getInstance()->update(
            'manufacturer_amazon_lookup',
            array(
                'id_manufacturer_resolved' => $id_mf_res > 0 ? (int) $id_mf_res : null,
                'resolved_from' => pSQL($resolved_from),
                'status' => pSQL($status),
                'error_message' => $error_msg !== '' ? pSQL($error_msg) : null,
                'id_employee_resolved' => $id_employee > 0 ? $id_employee : null,
                'date_upd' => $now,
            ),
            'id_manufacturer_amazon_lookup = ' . (int) $id_lookup
        );

        $this->confirmations[] = $this->l('Registro actualizado correctamente.');

        // Redirigimos a la lista para evitar reenvío de formulario
        Tools::redirectAdmin(self::$currentIndex . '&token=' . $this->token);

        return true;
    }
}
