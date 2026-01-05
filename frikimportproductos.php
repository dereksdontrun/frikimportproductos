<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class FrikImportProductos extends Module
{
    public function __construct()
    {
        $this->name = 'frikimportproductos';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Sergio';
        $this->need_instance = 0;

        parent::__construct();

        $this->displayName = $this->l('Importar productos de proveedores');
        $this->description = $this->l('Permite importar, visualizar y crear productos desde catálogos externos de proveedores, así como gestionar alias de fabricantes.');
    }


    private function installTab($className, $tabName, $idParent = 0)
    {
        $tab = new Tab();
        $tab->class_name = $className;
        $tab->id_parent = $idParent;
        $tab->module = $this->name;

        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = $tabName;
        }

        return $tab->add();
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        // Tab padre (AdminCatalog)
        $idParent = (int) Tab::getIdFromClassName('AdminCatalog');

        // Pestaña "Importar Productos" 
        if (!$this->installTab('AdminImportExternalProducts', 'Importar Productos', $idParent)) {
            return false;
        }

        // Pestaña para gestionar alias fabricantes
        if (!$this->installTab('AdminManufacturerAlias', 'Alias Fabricantes', $idParent)) {
            return false;
        }

        // Pestaña para gestionar alias fabricantes pending
        if (!$this->installTab('AdminManufacturerAliasPending', 'Alias Pendientes Fabricantes', $idParent)) {
            return false;
        }

        // Pestaña para gestionar fabricantes buscados en Amazon
        if (!$this->installTab('AdminManufacturerAmazonLookup', 'Búsqueda Fabricantes Amazon', $idParent)) {
            return false;
        }

        // BBDD (tablas de alias + pending)
        // if (!$this->installDb()) {
        //     return false;
        // }

        // Hooks de manufacturer
        if (
            !$this->registerHook('actionObjectManufacturerAddAfter')
            || !$this->registerHook('actionObjectManufacturerUpdateAfter')
        ) {
            return false;
        }

        return true;
    }

    private function uninstallTab($className)
    {
        $idTab = (int) Tab::getIdFromClassName($className);
        if ($idTab) {
            $tab = new Tab($idTab);
            return $tab->delete();
        }
        return true;
    }

    public function uninstall()
    {
        $this->uninstallTab('AdminImportExternalProducts');
        return parent::uninstall();
    }

    /**
     * Cuando se crea un fabricante nuevo, añadimos alias base con su nombre.
     */
    public function hookActionObjectManufacturerAddAfter($params)
    {
        if (!isset($params['object']) || !$params['object'] instanceof Manufacturer) {
            return;
        }

        /** @var Manufacturer $manufacturer */
        $manufacturer = $params['object'];

        if (!Validate::isLoadedObject($manufacturer)) {
            return;
        }

        // no creamos alias para fabricantes inactivos
        if (!(int) $manufacturer->active) {
            return;
        }

        $id = (int) $manufacturer->id;
        $name = $manufacturer->name;

        if (!class_exists('ManufacturerAliasHelper')) {
            require_once _PS_MODULE_DIR_ . $this->name . '/classes/ManufacturerAliasHelper.php';
        }

        $norm = ManufacturerAliasHelper::normalizeName($name);

        // mirar si ya hay un alias con este alias+norm, pero de OTRO fabricante
        $row = Db::getInstance()->getRow('
            SELECT id_manufacturer
            FROM ' . _DB_PREFIX_ . 'manufacturer_alias
            WHERE normalized_alias = "' . pSQL($norm) . '"
            AND alias = "' . pSQL($name) . '"
            ORDER BY id_manufacturer_alias ASC
        ');

        if ($row && (int) $row['id_manufacturer'] !== $id) {
            // Ya hay un alias con ese nombre apuntando a otro fabricante (ej. Bandai).
            // No creamos otro alias contradictorio.
            return;
        }

        ManufacturerAliasHelper::createAlias($id, $name, $norm, 'PRESTA_MANUFACTURERS', 0);
    }

    /**
     * Cuando se actualiza un fabricante (por ejemplo, cambia el name),
     * añadimos un alias nuevo con el nombre actualizado.
     */
    public function hookActionObjectManufacturerUpdateAfter($params)
    {
        if (!isset($params['object']) || !$params['object'] instanceof Manufacturer) {
            return;
        }

        /** @var Manufacturer $manufacturer */
        $manufacturer = $params['object'];

        if (!Validate::isLoadedObject($manufacturer)) {
            return;
        }

        // no creamos alias para fabricantes inactivos
        if (!(int) $manufacturer->active) {
            return;
        }

        $id = (int) $manufacturer->id;
        $name = $manufacturer->name;

        if (!class_exists('ManufacturerAliasHelper')) {
            require_once _PS_MODULE_DIR_ . $this->name . '/classes/ManufacturerAliasHelper.php';
        }

        $norm = ManufacturerAliasHelper::normalizeName($name);

        // mirar si ya hay un alias con este alias+norm, pero de OTRO fabricante
        $row = Db::getInstance()->getRow('
            SELECT id_manufacturer
            FROM ' . _DB_PREFIX_ . 'manufacturer_alias
            WHERE normalized_alias = "' . pSQL($norm) . '"
            AND alias = "' . pSQL($name) . '"
            ORDER BY id_manufacturer_alias ASC
        ');

        if ($row && (int) $row['id_manufacturer'] !== $id) {
            // Ya hay un alias con ese nombre apuntando a otro fabricante (ej. Bandai).
            // No creamos otro alias contradictorio.
            return;
        }
        
        ManufacturerAliasHelper::createAlias($id, $name, $norm, 'PRESTA_MANUFACTURERS', 0);
    }


    protected function installDb()
    {
        $sql = array();

        $sql[] = "CREATE TABLE `lafrips_productos_proveedores` (
            `id_productos_proveedores` INT(10) NOT NULL AUTO_INCREMENT,
            `es_atributo` TINYINT(1) NOT NULL DEFAULT 0,
            `atributo_id` INT(10) DEFAULT NULL,
            `ean` VARCHAR(32) NOT NULL,
            `ean_norm` varchar(13) GENERATED ALWAYS AS (LPAD(RIGHT(ean, 13), 13, '0')) STORED ,
            `id_supplier` INT(10) DEFAULT NULL,
            `id_manufacturer` INT(10) DEFAULT NULL,
            `manufacturer_name` VARCHAR(64) DEFAULT NULL,
            `referencia_proveedor` VARCHAR(64) NOT NULL,
            `referencia_base_proveedor` VARCHAR(64) DEFAULT NULL,
            `url_proveedor` VARCHAR(255) NOT NULL,
            `nombre` VARCHAR(255) NOT NULL,
            `description_short` TEXT,
            `descripcion_larga` TEXT,
            `coste` DECIMAL(20,6) NOT NULL,
            `pvp_sin_iva` DECIMAL(20,6) NOT NULL,
            `iva` INT(10) DEFAULT NULL,
            `peso` DECIMAL(20,6) DEFAULT NULL,
            `imagen_principal` VARCHAR(255) DEFAULT NULL,
            `otras_imagenes` TEXT DEFAULT NULL, -- JSON serializado
            `video` VARCHAR(255) DEFAULT NULL,
            `url_auxiliar` VARCHAR(255) DEFAULT NULL,
            `referencia_base_prestashop` VARCHAR(64) DEFAULT NULL,
            `referencia_atributo_prestashop` VARCHAR(64) DEFAULT NULL,
            `existe_prestashop` TINYINT(1) NOT NULL DEFAULT 0,
            `date_creado` DATETIME DEFAULT NULL, -- sin uso ahora
            `date_importado` DATETIME DEFAULT NULL, -- fecha en la que fue importado de proveedor (creado)
            `id_product_prestashop` INT(10) DEFAULT NULL,
            `id_product_attribute_prestashop` INT(10) DEFAULT NULL,
            `estado` ENUM('pendiente','ignorado','encolado','procesando','creado','error','eliminado') 
                NOT NULL DEFAULT 'pendiente',
            `id_employee_encolado` INT(11) NOT NULL,
            `id_employee_creado` INT(11) NOT NULL,
            `procesando` TINYINT(1) DEFAULT 0,
            `date_procesando` DATETIME NULL,
            `reintentos` INT(11) NOT NULL DEFAULT 0,
            `mensaje_error` TEXT DEFAULT NULL, 
            `disponibilidad` TINYINT(1) NOT NULL DEFAULT 0,
            `last_update_info` DATETIME NOT NULL,
            `actualizando_catalogo` TINYINT(1) NOT NULL DEFAULT 0,
            `fuente` VARCHAR(64) DEFAULT NULL,  
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME DEFAULT NULL,  
            PRIMARY KEY (`id_productos_proveedores`),
            UNIQUE KEY `uniq_proveedor_ref_ean_norm` (`id_supplier`,`referencia_proveedor`,`ean_norm`),
            KEY `idx_estado` (`estado`),
            KEY `idx_existe_ps` (`existe_prestashop`),
            KEY `idx_date_add` (`date_add`),
            KEY `idx_ean_norm_productos_proveedores` (`ean_norm`)
            ) ENGINE=InnoDB AUTO_INCREMENT=1 
            DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        // Tabla de alias de fabricante
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'manufacturer_alias` (
            `id_manufacturer_alias` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_manufacturer`       INT(11) UNSIGNED NOT NULL,
            `alias`                 VARCHAR(255) NOT NULL,
            `normalized_alias`      VARCHAR(255) NOT NULL,
            `source`                VARCHAR(64) DEFAULT NULL,
            `auto_created`          TINYINT(1) NOT NULL DEFAULT 0,
            `active`                TINYINT(1) NOT NULL DEFAULT 1,
            `date_add`              DATETIME DEFAULT NULL,
            `date_upd`              DATETIME DEFAULT NULL,
            PRIMARY KEY (`id_manufacturer_alias`),
            KEY `idx_alias` (`alias`),
            KEY `idx_id_manufacturer` (`id_manufacturer`),
            KEY `idx_active` (`active`)
            -- OJO: si quieres UNIQUE global de normalized_alias, quita comentarios:
            -- ,UNIQUE KEY `uniq_norm_alias` (`normalized_alias`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        // Tabla de pendientes
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'manufacturer_alias_pending` (
            `id_pending`        INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `raw_name`          VARCHAR(255) NOT NULL,
            `normalized_alias`  VARCHAR(255) NOT NULL,
            `source`            VARCHAR(64)  DEFAULT NULL,
            `times_seen`        INT(11) UNSIGNED NOT NULL DEFAULT 1,
            `resolved`          TINYINT(1) NOT NULL DEFAULT 0,
            `id_manufacturer`   INT(11) UNSIGNED DEFAULT NULL,
            `date_add`          DATETIME DEFAULT NULL,
            `date_upd`          DATETIME DEFAULT NULL,
            PRIMARY KEY (`id_pending`),
            KEY `idx_norm_alias` (`normalized_alias`),
            KEY `idx_source` (`source`),
            KEY `idx_resolved` (`resolved`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        $ok = true;
        foreach ($sql as $query) {
            if (!Db::getInstance()->execute($query)) {
                $ok = false;
            }
        }

        return $ok;
    }
}
