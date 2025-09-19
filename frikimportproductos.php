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
        $this->description = $this->l('Permite importar, visualizar y crear productos desde catálogos externos de proveedores.');
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

        // pestaña Importar Productos
        $this->installTab('AdminImportExternalProducts', 'Importar Productos', (int) Tab::getIdFromClassName('AdminCatalog'));

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

    protected function installDb()
    {
        $sql = "CREATE TABLE `lafrips_productos_proveedores` (
        `id_productos_proveedores` INT(10) NOT NULL AUTO_INCREMENT,
        `es_atributo` TINYINT(1) NOT NULL DEFAULT 0,
        `atributo_id` INT(10) DEFAULT NULL,
        `ean` VARCHAR(32) NOT NULL,
        `ean_norm` varchar(13) GENERATED ALWAYS AS (LPAD(`ean`, 13, '0')) STORED,
        `id_supplier` INT(10) DEFAULT NULL,
        `id_manufacturer` INT(10) DEFAULT NULL,
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
        `procesando` TINYINT(1) DEFAULT 0,
        `date_procesando` DATETIME NULL,
        `mensaje_error` TEXT DEFAULT NULL, 
        `disponibilidad` TINYINT(1) NOT NULL DEFAULT 0,
        `last_update_info` DATETIME NOT NULL,
        `fuente` VARCHAR(64) DEFAULT NULL,  
        `date_add` DATETIME NOT NULL,
        `date_upd` DATETIME DEFAULT NULL,  
        PRIMARY KEY (`id_productos_proveedores`),
        UNIQUE KEY `uniq_proveedor_ref` (`id_supplier`,`referencia_proveedor`),
        KEY `idx_estado` (`estado`),
        KEY `idx_existe_ps` (`existe_prestashop`),
        KEY `idx_date_add` (`date_add`),
        KEY `idx_ean_norm_productos_proveedores` (`ean_norm`)
        ) ENGINE=InnoDB AUTO_INCREMENT=1 
        DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        return Db::getInstance()->execute($sql);
    }
}
