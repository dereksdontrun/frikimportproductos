<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'frikimportproductos/classes/ManufacturerMaintenanceTools.php';
require_once _PS_ROOT_DIR_ . '/classes/utils/LoggerFrik.php';

class AdminManufacturerAliasPendingController extends ModuleAdminController
{
    public function __construct()
    {
        $this->table = 'manufacturer_alias_pending';
        $this->identifier = 'id_pending';
        $this->className = ''; // sin ObjectModel, vamos a mano
        $this->lang = false;
        $this->bootstrap = true;

        parent::__construct();

        $this->show_toolbar = true;
        $this->show_page_header_toolbar = true;
        // opcional pero suele ir bien
        $this->toolbar_scroll = true;

        // JOIN opcional para ver el fabricante resuelto (si ya tiene id_manufacturer)
        // En PrestaShop 1.6 el callback del fields_list solo se ejecuta si la columna existe en el resultado SQL. Si el campo no viene en el SELECT (ni en la tabla principal ni como alias), el callback ni se llama. Por eso hay que añadir en este caso suggestion al select, como algo vacío, y el callback se encargará de rellenarlo
        $this->_select = 'm.name AS manufacturer_name,
            "" AS suggestion';
        $this->_join = '
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m
              ON (m.id_manufacturer = a.id_manufacturer)
        ';

        // Por defecto, mostrar solo no resueltos
        // $this->_where = ' AND a.resolved = 0 ';

        $this->fields_list = array(
            'id_pending' => array(
                'title' => $this->l('ID'),
                'align' => 'center',
                'class' => 'fixed-width-xs',
            ),
            'raw_name' => array(
                'title' => $this->l('Nombre original'),
            ),
            'normalized_alias' => array(
                'title' => $this->l('Alias normalizado'),
            ),
            'source' => array(
                'title' => $this->l('Origen'),
                'filter_key' => 'a!source',
                'callback' => 'renderSourceColumn',
                'orderby' => true,
                'search' => true,
            ),
            'times_seen' => array(
                'title' => $this->l('Veces visto'),
                'align' => 'center',
                'class' => 'fixed-width-xs',
            ),
            'manufacturer_name' => array(
                'title' => $this->l('Fabricante resuelto'),
                'filter_key' => 'm!name',
            ),
            'suggestion' => array(
                'title' => $this->l('Sugerencia'),
                'callback' => 'renderSuggestionColumn',
                'orderby' => false,
                'search' => false,
            ),
            'resolved' => array(
                'title' => $this->l('Resuelto'),
                'type' => 'bool',
            ),
            'resolution_type' => array(
                'title' => $this->l('Tipo resolución'),
                'callback' => 'renderResolutionTypeColumn',
                'search' => false,
                'orderby' => false,
            ),
            'date_add' => array(
                'title' => $this->l('Fecha alta'),
                'type' => 'datetime',
            ),
            'date_upd' => array(
                'title' => $this->l('Última actualización'),
                'type' => 'datetime',
            ),
        );

        $this->addRowAction('edit');
        $this->addRowAction('createManufacturer');
        $this->addRowAction('delete');

        // Permitir filtrar también resueltos
        $this->bulk_actions = array(
            'delete' => array(
                'text' => $this->l('Eliminar seleccionados'),
                'confirm' => $this->l('¿Eliminar los elementos seleccionados?'),
            ),
        );
    }

    //botones de arriba
    public function initPageHeaderToolbar()
    {
        parent::initPageHeaderToolbar();

        $urlBase = self::$currentIndex . '&token=' . $this->token;

        // Botón para reintentar resolución automática de pendientes
        $this->page_header_toolbar_btn['retry_pending_aliases'] = array(
            // 'href' => $urlBase . '&run_retry_pending_aliases=1',
            'href' => '#',                                          // DESHABILITADO
            'desc' => $this->l('Reintentar resolución automática  DESHABILITADO'),
            'icon' => 'process-icon-refresh',
        );
    }

    public function renderSuggestionColumn($value, $row)
    {
        // Si ya está resuelto, no mostramos sugerencia
        if (isset($row['resolved']) && (int) $row['resolved'] === 1) {
            return '-';
        }

        // Si ya tiene fabricante asignado, tampoco tiene sentido sugerir
        if (isset($row['id_manufacturer']) && (int) $row['id_manufacturer'] > 0) {
            return '-';
        }

        require_once _PS_MODULE_DIR_ . 'frikimportproductos/classes/ManufacturerAliasHelper.php';

        if (!isset($row['raw_name'])) {
            return '— sin raw_name —';
        }

        $rawName = $row['raw_name'];

        // Usamos un ratio medio-bajo solo para sugerir (no para resolver automático)
        $suggestions = ManufacturerAliasHelper::fuzzySuggestManufacturers($rawName, 0.4);

        if (empty($suggestions)) {
            return '-';
        }

        // Nos quedamos con la mejor
        $best = $suggestions[0];

        $idPending = (int) $row['id_pending'];
        $idManufacturer = (int) $best['id_manufacturer'];
        $ratio = round($best['ratio'], 2);

        $manufacturerTxt = sprintf(
            '%s (ID %d, %s: %.2f)',
            $best['name'],
            $idManufacturer,
            $this->l('ratio'),
            $ratio
        );

        // Link a acción "usar sugerencia"
        // getAdminLink solo con controller+token
        $link = $this->context->link->getAdminLink('AdminManufacturerAliasPending', true);
        // añadimos los parámetros a mano (PS 1.6)
        $link .= '&' . $this->identifier . '=' . $idPending;
        $link .= '&use_suggestion=1&suggested_id_manufacturer=' . $idManufacturer;

        $btn = '<a class="btn btn-default btn-xs" href="' . $link . '">
                <i class="icon-magic"></i> ' . $this->l('Usar sugerencia') . '
            </a>';

        return $manufacturerTxt . '<br>' . $btn;
    }

    public function renderSourceColumn($value, $row)
    {
        $value = (string) $value;

        if ($value === '') {
            return '-';
        }

        // Máx. caracteres a mostrar en la tabla
        $maxLen = 30;

        // Si es corto, lo mostramos tal cual
        if (Tools::strlen($value) <= $maxLen) {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }

        // Si es largo, recortamos y mostramos todo en title (hover)
        $short = Tools::substr($value, 0, $maxLen) . '…';

        return '<span title="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($short, ENT_QUOTES, 'UTF-8')
            . '</span>';
    }

    public function displayCreateManufacturerLink($token = null, $id = 0)
    {
        // Comprobamos si el pending ya está resuelto
        $row = Db::getInstance()->getRow('
            SELECT resolved
            FROM ' . _DB_PREFIX_ . 'manufacturer_alias_pending
            WHERE id_pending = ' . (int) $id
        );

        if (!$row || (int) $row['resolved'] == 1) {
            // Si no existe o ya está resuelto, no mostramos botón
            return '';
        }

        // En PS 1.6: getAdminLink solo con controller y token
        $link = $this->context->link->getAdminLink('AdminManufacturerAliasPending', true);

        // añadimos parámetros a mano
        $link .= '&' . $this->identifier . '=' . (int) $id;
        $link .= '&create_manufacturer=1';

        return '<a class="btn btn-default btn-xs" href="' . $link . '">
                <i class="icon-plus"></i> ' . $this->l('Crear fabricante') . '
            </a>';
    }

    public function renderResolutionTypeColumn($value, $row)
    {
        if (empty($row['resolution_type'])) {
            return '-';
        }

        switch ($row['resolution_type']) {
            case 'existing':
                return $this->l('Fabricante existente');
            case 'created':
                return $this->l('Fabricante creado');
            case 'ignored':
                return $this->l('Ignorado');
            default:
                return pSQL($row['resolution_type']);
        }
    }

    public function postProcess()
    {
        if (Tools::getIsset('use_suggestion')) {
            $this->processUseSuggestion();
            // No llamamos todavía a parent si ya redirigimos dentro
        }

        if (Tools::getIsset('create_manufacturer')) {
            $this->processCreateManufacturerFromPending();
            return; // para evitar que luego haga más cosas raras
        }

        // reintentar manufacturer_alias_pending con lógica automática
        if (Tools::getIsset('run_retry_pending_aliases')) {

            // Puedes hacer que el límite sea fijo o parametrizable
            $limit = (int) Tools::getValue('retry_limit', 200);
            if ($limit <= 0) {
                $limit = 200;
            }

            // logger a archivo
            $logFile = _PS_MODULE_DIR_ . 'frikimportproductos/logs/manufacturers/retry_pending_aliases_' . date('Ymd') . '.txt';
            $logger = new LoggerFrik($logFile);

            $result = ManufacturerMaintenanceTools::retryPendingAliases(
                $limit,
                $logger,
                false  // dryRun = false, que haga cambios reales
            );

            $this->confirmations[] = sprintf(
                $this->l('Reintento completado: procesados %d, resueltos %d, siguen pendientes %d.'),
                (int) $result['processed'],
                (int) $result['resolved'],
                (int) $result['still_pending']
            );

            // Redirigimos a la lista para evitar reenvío al refrescar
            Tools::redirectAdmin(self::$currentIndex . '&token=' . $this->token);
        }


        return parent::postProcess();
    }

    protected function processUseSuggestion()
    {
        $id_pending = (int) Tools::getValue($this->identifier);
        $id_manufacturer = (int) Tools::getValue('suggested_id_manufacturer');

        if ($id_pending <= 0 || $id_manufacturer <= 0) {
            $this->errors[] = $this->l('Datos de sugerencia incompletos.');
            return false;
        }

        $pending = Db::getInstance()->getRow('
        SELECT *
        FROM ' . _DB_PREFIX_ . 'manufacturer_alias_pending
        WHERE id_pending = ' . (int) $id_pending
        );

        if (!$pending) {
            $this->errors[] = $this->l('Registro pendiente no encontrado.');
            return false;
        }

        $rawName = $pending['raw_name'];
        $norm = $pending['normalized_alias'];
        $source = $pending['source'];

        require_once _PS_MODULE_DIR_ . 'frikimportproductos/classes/ManufacturerAliasHelper.php';

        $employeeId = (int) $this->context->employee->id;

        // Creamos el alias apuntando al fabricante sugerido
        $result = ManufacturerAliasHelper::createAlias(
            $id_manufacturer,
            $rawName,
            $norm,
            $source !== '' ? $source : null,
            0 // auto_created = 0, lo has revisado tú
        );

        if ($result === 'created' || $result === 'exists') {
            // Marcamos el pending como resuelto
            Db::getInstance()->update(
                'manufacturer_alias_pending',
                array(
                    'resolved' => 1,
                    'id_manufacturer' => (int) $id_manufacturer,
                    'id_employee' => $employeeId,
                    'resolution_type' => 'existing',
                    'date_upd' => date('Y-m-d H:i:s'),
                ),
                'id_pending = ' . (int) $id_pending
            );

            $this->confirmations[] = $this->l('Pendiente resuelto usando la sugerencia.');
            // Redirigimos de vuelta a la lista para evitar re-envíos
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminManufacturerAliasPending'));
            return true;

        } elseif ($result === 'invalid') {
            $this->errors[] = $this->l('Datos inválidos al crear el alias para esta sugerencia.');
            return false;

        } else { // 'error'
            $this->errors[] = $this->l('Error al crear el alias a partir de la sugerencia.');
            return false;
        }
    }

    protected function processCreateManufacturerFromPending()
    {
        // Esto es seguro aunque no usemos ObjectModel,
        // porque $this->identifier = 'id_pending'
        $id_pending = (int) Tools::getValue($this->identifier);
        if ($id_pending <= 0) {
            $this->errors[] = $this->l('ID pendiente no válido.');
            return false;
        }

        $pending = Db::getInstance()->getRow('
            SELECT *
            FROM ' . _DB_PREFIX_ . 'manufacturer_alias_pending
            WHERE id_pending = ' . (int) $id_pending
        );

        if (!$pending) {
            $this->errors[] = $this->l('Registro pendiente no encontrado.');
            return false;
        }

        $rawName = trim($pending['raw_name']);
        if ($rawName === '') {
            $this->errors[] = $this->l('El nombre original está vacío, no se puede crear fabricante.');
            return false;
        }

        // Crear manufacturer “canónico”
        $manufacturer = new Manufacturer();
        $manufacturer->name = $rawName;
        $manufacturer->active = 1;

        // Rellenamos campos multiidioma mínimos
        $languages = Language::getLanguages(false);
        foreach ($languages as $lang) {
            $id_lang = (int) $lang['id_lang'];
            // puedes ajustar descripción / meta si quieres
            $manufacturer->description[$id_lang] = '';
            $manufacturer->short_description[$id_lang] = '';
            $manufacturer->meta_title[$id_lang] = '';
        }

        if (!$manufacturer->add()) {
            $this->errors[] = $this->l('Error al crear el nuevo fabricante.');
            return false;
        }

        $id_manufacturer = (int) $manufacturer->id;

        // Crear alias vinculado a este fabricante
        require_once _PS_MODULE_DIR_ . 'frikimportproductos/classes/ManufacturerAliasHelper.php';

        $norm = $pending['normalized_alias'];
        $source = $pending['source'] !== '' ? $pending['source'] : null;

        ManufacturerAliasHelper::createAlias(
            $id_manufacturer,
            $rawName,
            $norm,
            $source,
            0 // auto_created = 0 porque lo has revisado tú
        );

        // Marcar pending como resuelto y guardar quién lo ha hecho
        $id_employee = (int) $this->context->employee->id;

        Db::getInstance()->update(
            'manufacturer_alias_pending',
            array(
                'resolved' => 1,
                'id_manufacturer' => $id_manufacturer,
                'id_employee' => $id_employee,
                'resolution_type' => 'created',
                'date_upd' => date('Y-m-d H:i:s'),
            ),
            'id_pending = ' . (int) $id_pending
        );

        $this->confirmations[] = $this->l('Fabricante creado y pendiente resuelto correctamente.');

        // (Opcional) redirigir al editor de fabricantes para ajustar datos GPSR, logo, etc.
        // $link = $this->context->link->getAdminLink('AdminManufacturers', true, [], [
        //     'id_manufacturer' => $id_manufacturer,
        //     'updatemanufacturer' => 1,
        // ]);
        // Tools::redirectAdmin($link);

        return true;
    }


    public function renderForm()
    {
        // Obtenemos el id_pending desde la URL
        $id_pending = (int) Tools::getValue($this->identifier);
        if ($id_pending <= 0) {
            return;
        }

        // Leemos la fila directamente de la tabla
        $row = Db::getInstance()->getRow('
        SELECT *
        FROM ' . _DB_PREFIX_ . 'manufacturer_alias_pending
        WHERE id_pending = ' . (int) $id_pending
        );

        if (!$row) {
            $this->errors[] = $this->l('Registro pendiente no encontrado.');
            return parent::renderForm();
        }

        // Fabricantes disponibles
        $manufacturers = Manufacturer::getManufacturers(false, $this->context->language->id, true);
        $options = array();
        foreach ($manufacturers as $m) {
            $options[] = array(
                'id_manufacturer' => (int) $m['id_manufacturer'],
                'name' => $m['name'],
            );
        }

        // Cuando pulsas Editar en la lista, vas a una URL tipo:
        // index.php?controller=AdminManufacturerAliasPending&token=...&id_pending=12&update...
        // Pero el HelperForm que genera el <form> usa como action algo tipo:
        // index.php?controller=AdminManufacturerAliasPending&token=...
        // (sin id_pending).
        // Como no tenemos un ObjectModel de ManufacturerAliasPending, el helper no genera el <input type="hidden" name="id_pending">.
        // Resultado: al hacer submit, la petición POST no lleva id_pending ni en GET ni en POST →
        // Tools::getValue('id_pending') devuelve 0 → la SELECT no encuentra nada → “Registro pendiente no encontrado”.
        // Por eso processSave() entra en el error.
        // Hay que añadir un campo hidden en el fields_form y rellenar fields_value con el id_pending actual.

        $this->fields_form = array(
            'legend' => array(
                'title' => $this->l('Resolver alias pendiente'),
                'icon' => 'icon-wrench',
            ),
            'input' => array(
                // hidden para conservar el id_pending
                array(
                    'type' => 'hidden',
                    'name' => 'id_pending',
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Nombre original (raw_name)'),
                    'name' => 'raw_name',
                    'readonly' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Alias normalizado'),
                    'name' => 'normalized_alias',
                    'readonly' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Origen'),
                    'name' => 'source',
                    'readonly' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Veces visto'),
                    'name' => 'times_seen',
                    'readonly' => true,
                ),
                array(
                    'type' => 'select',
                    'label' => $this->l('Fabricante canónico'),
                    'name' => 'id_manufacturer',
                    'options' => array(
                        'query' => $options,
                        'id' => 'id_manufacturer',
                        'name' => 'name',
                    ),
                    'desc' => $this->l('Selecciona el fabricante al que debe apuntar este nombre.'),
                ),
                array(
                    'type' => 'switch',
                    'label' => $this->l('Ignorar sin crear alias'),
                    'name' => 'ignore_only',
                    'is_bool' => true,
                    'values' => array(
                        array(
                            'id' => 'ignore_only_on',
                            'value' => 1,
                            'label' => $this->l('Sí'),
                        ),
                        array(
                            'id' => 'ignore_only_off',
                            'value' => 0,
                            'label' => $this->l('No'),
                        ),
                    ),
                    'desc' => $this->l('Si marcas "Sí", se marcará como resuelto sin crear alias.'),
                ),
            ),
            'submit' => array(
                'title' => $this->l('Guardar'),
            ),
        );

        // Rellenar valores desde la fila
        $this->fields_value['id_pending'] = (int) $id_pending;
        $this->fields_value['raw_name'] = $row['raw_name'];
        $this->fields_value['normalized_alias'] = $row['normalized_alias'];
        $this->fields_value['source'] = $row['source'];
        $this->fields_value['times_seen'] = (int) $row['times_seen'];
        $this->fields_value['id_manufacturer'] = (int) $row['id_manufacturer'];
        $this->fields_value['ignore_only'] = 0;

        return parent::renderForm();
    }

    public function processSave()
    {
        $id_pending = (int) Tools::getValue($this->identifier);  // es lo mismo que poner Tools::getValue('id_pending'), pero vale porque hemos puesto el input hidden en renderForm()
        $id_manufacturer = (int) Tools::getValue('id_manufacturer');
        $ignore_only = (int) Tools::getValue('ignore_only', 0);

        // Cargamos pending
        $pending = Db::getInstance()->getRow('
        SELECT *
        FROM ' . _DB_PREFIX_ . 'manufacturer_alias_pending
        WHERE id_pending = ' . (int) $id_pending
        );

        if (!$pending) {
            $this->errors[] = $this->l('Registro pendiente no encontrado.');
            return false;
        }

        $rawName = $pending['raw_name'];
        $norm = $pending['normalized_alias'];
        $source = $pending['source'];

        require_once _PS_MODULE_DIR_ . 'frikimportproductos/classes/ManufacturerAliasHelper.php';

        $employeeId = (int) $this->context->employee->id;

        if ($ignore_only) {
            // Simplemente marcamos como resuelto, sin alias
            Db::getInstance()->update(
                'manufacturer_alias_pending',
                array(
                    'resolved' => 1,
                    'id_manufacturer' => null,
                    'id_employee' => $employeeId,
                    'resolution_type' => 'ignored',
                    'date_upd' => date('Y-m-d H:i:s'),
                ),
                'id_pending = ' . (int) $id_pending
            );

            $this->confirmations[] = $this->l('Pendiente marcado como resuelto (ignorado).');
            return true;
        }

        // Si no ignoramos, necesitamos un fabricante
        if ($id_manufacturer <= 0) {
            $this->errors[] = $this->l('Debes seleccionar un fabricante o marcar "Ignorar".');
            return false;
        }

        // Creamos alias para este raw_name apuntando al fabricante seleccionado
        $result = ManufacturerAliasHelper::createAlias(
            $id_manufacturer,
            $rawName,
            $norm,
            $source !== '' ? $source : null,
            0 // auto_created = 0, lo resolviste tú
        );

        if ($result === 'created' || $result === 'exists') {
            // Marcamos pending como resuelto
            Db::getInstance()->update(
                'manufacturer_alias_pending',
                array(
                    'resolved' => 1,
                    'id_manufacturer' => (int) $id_manufacturer,
                    'id_employee' => $employeeId,
                    'resolution_type' => 'existing',
                    'date_upd' => date('Y-m-d H:i:s'),
                ),
                'id_pending = ' . (int) $id_pending
            );

            $this->confirmations[] = $this->l('Pendiente resuelto y alias creado/asignado correctamente.');
            return true;

        } elseif ($result === 'invalid') {
            $this->errors[] = $this->l('Datos inválidos al crear el alias.');
            return false;

        } else { // 'error'
            $this->errors[] = $this->l('Error al crear el alias para este pendiente.');
            return false;
        }
    }

    // En un AdminController clásico de Presta:
    // El botón de bulk delete lanza un submitBulkdeletemi_tabla.
    // El core llama a processBulkDelete().
    // Esa función, por defecto, intenta usar el ObjectModel asociado a $this->className
    // Así que cuando el core intenta hacer algo tipo new $this->className(...) o cargar el objeto, se rompe → 500.
    // Solución: implementar processBulkDelete() a mano
    public function processBulkDelete()
    {
        // Nombre del checkbox que genera Presta para selección múltiple
        // será manufacturer_alias_pendingBox[]
        $ids = Tools::getValue($this->table . 'Box');

        if (!is_array($ids) || empty($ids)) {
            $this->errors[] = $this->l('No hay elementos seleccionados para eliminar.');
            return false;
        }

        $ids = array_map('intval', $ids);
        $ids = array_filter($ids);

        if (empty($ids)) {
            $this->errors[] = $this->l('IDs no válidos.');
            return false;
        }

        $idList = implode(',', $ids);

        $ok = Db::getInstance()->delete(
            'manufacturer_alias_pending',
            'id_pending IN (' . $idList . ')'
        );

        if (!$ok) {
            $this->errors[] = $this->l('Error al eliminar los registros seleccionados.');
            return false;
        }

        $this->confirmations[] = $this->l('Registros pendientes eliminados correctamente.');
        return true;
    }

    //lo mismo, al no usar objectmodel también hay que implementar función de borrado individual
    public function processDelete()
    {
        $id = (int) Tools::getValue($this->identifier); // id_pending

        if ($id <= 0) {
            $this->errors[] = $this->l('ID no válido.');
            return false;
        }

        $ok = Db::getInstance()->delete(
            'manufacturer_alias_pending',
            'id_pending = ' . (int) $id
        );

        if (!$ok) {
            $this->errors[] = $this->l('Error al eliminar el registro.');
            return false;
        }

        $this->confirmations[] = $this->l('Registro pendiente eliminado correctamente.');
        return true;
    }



}
