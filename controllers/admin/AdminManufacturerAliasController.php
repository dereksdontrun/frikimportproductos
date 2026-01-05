<?php

class AdminManufacturerAliasController extends AdminController
{
    public function __construct()
    {
        $this->table = 'manufacturer_alias';
        $this->className = ''; // 'ManufacturerAlias' si no tenemos ObjectModel, podemos dejarlo vacío
        $this->identifier = 'id_manufacturer_alias';
        $this->lang = false;
        $this->bootstrap = true;

        parent::__construct();

        // Listado básico
        $this->fields_list = array(
            'id_manufacturer_alias' => array(
                'title' => $this->l('ID'),
                'align' => 'center',
                'class' => 'fixed-width-xs',
            ),
            'alias' => array(
                'title' => $this->l('Alias'),
            ),
            'normalized_alias' => array(
                'title' => $this->l('Alias normalizado'),
            ),
            'source' => array(
                'title' => $this->l('Origen'),
            ),
            // Id del fabricante en Prestashop
            'id_manufacturer' => array(
                'title' => $this->l('ID Fab.'),
                'align' => 'center',
                'class' => 'fixed-width-xs',
                'type' => 'int',
                'filter_key' => 'a!id_manufacturer', // filtra por el campo de la tabla principal
            ),
            //Nombre canónico del fabricante (de la tabla manufacturer)
            'manufacturer_name' => array(
                'title' => $this->l('Fabricante'),
                'filter_key' => 'm!name',           // filtra por m.name
            ),
            'auto_created' => array(
                'title' => $this->l('Auto'),
                'type' => 'bool',
            ),
            'active' => array(
                'title' => $this->l('Activo'),
                'type' => 'bool',
            ),
        );

        $this->addRowAction('edit');
        $this->addRowAction('delete');

        $this->_select = '
            m.name AS manufacturer_name
        ';
        $this->_join = '
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m
            ON (m.id_manufacturer = a.id_manufacturer)
        ';
    }

    public function renderForm()
    {
        // ID si venimos de "Editar", 0 si es "Añadir nuevo"
        $id_alias = (int) Tools::getValue($this->identifier);

        $row = null;
        if ($id_alias > 0) {
            $row = Db::getInstance()->getRow('
            SELECT *
            FROM ' . _DB_PREFIX_ . 'manufacturer_alias
            WHERE id_manufacturer_alias = ' . (int) $id_alias . '
        ');

            if (!$row) {
                $this->errors[] = $this->l('Alias no encontrado.');
                // mostramos errores y no intentamos pintar el formulario
                return parent::renderForm();
            }
        }

        // Opciones de fabricantes para el select
        $manufacturers = Manufacturer::getManufacturers(false, $this->context->language->id, true);
        $options = array();
        foreach ($manufacturers as $m) {
            $options[] = array(
                'id_manufacturer' => (int) $m['id_manufacturer'],
                'name' => $m['name'],
            );
        }

        $this->fields_form = array(
            'legend' => array(
                'title' => $this->l('Alias de fabricante'),
                'icon' => 'icon-cogs',
            ),
            'input' => array(
                //oculto para tener el id de la tabla
                array(
                    'type' => 'hidden',
                    'name' => $this->identifier, // 'id_manufacturer_alias'
                ),
                array(
                    'type' => 'select',
                    'label' => $this->l('Fabricante'),
                    'name' => 'id_manufacturer',
                    'required' => true,
                    'options' => array(
                        'query' => $options,
                        'id' => 'id_manufacturer',
                        'name' => 'name',
                    ),
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Alias (como viene en catálogo)'),
                    'name' => 'alias',
                    'required' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Alias normalizado'),
                    'name' => 'normalized_alias',
                    'desc' => $this->l('Si lo dejas vacío se generará automáticamente al guardar.'),
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Origen (proveedor)'),
                    'name' => 'source',
                ),
                array(
                    'type' => 'switch',
                    'label' => $this->l('Activo'),
                    'name' => 'active',
                    'is_bool' => true,
                    'values' => array(
                        array(
                            'id' => 'active_on',
                            'value' => 1,
                            'label' => $this->l('Sí'),
                        ),
                        array(
                            'id' => 'active_off',
                            'value' => 0,
                            'label' => $this->l('No'),
                        ),
                    ),
                    'default_value' => 1,
                ),
            ),
            'submit' => array(
                'title' => $this->l('Guardar'),
            ),
        );

        // Rellenamos los valores del formulario para edición
        // Si hay fila (editar), usamos sus valores; si no, defaults
        $this->fields_value[$this->identifier] = $id_alias;
        $this->fields_value['id_manufacturer'] = $row ? (int) $row['id_manufacturer'] : 0;
        $this->fields_value['alias'] = $row ? $row['alias'] : '';
        $this->fields_value['normalized_alias'] = $row ? $row['normalized_alias'] : '';
        $this->fields_value['source'] = $row ? (string) $row['source'] : '';
        $this->fields_value['active'] = $row ? (int) $row['active'] : 1;

        return parent::renderForm();
    }

    /**
     * Antes de guardar, si normalized_alias está vacío, lo rellenamos
     * usando ManufacturerAliasHelper::normalizeName().
     */
    public function processSave()
    {
        $id_alias = (int) Tools::getValue($this->identifier); // id_manufacturer_alias si existe (si estamos editando)

        $id_manufacturer = (int) Tools::getValue('id_manufacturer');
        $alias = trim((string) Tools::getValue('alias'));
        $norm = trim((string) Tools::getValue('normalized_alias'));
        $source = trim((string) Tools::getValue('source'));
        $active = (int) Tools::getValue('active', 1);

        // Validaciones básicas
        if ($id_manufacturer <= 0) {
            $this->errors[] = $this->l('Debes seleccionar un fabricante.');
        }
        if ($alias === '') {
            $this->errors[] = $this->l('El alias no puede estar vacío.');
        }

        if (!empty($this->errors)) {
            return false;
        }

        require_once _PS_MODULE_DIR_ . 'frikimportproductos/classes/ManufacturerAliasHelper.php';

        // Si no se ha introducido nombre normalizado, lo generamos
        if ($norm === '' && $alias !== '') {
            $norm = ManufacturerAliasHelper::normalizeName($alias);
        }

        // EDITAR alias existente
        if ($id_alias) {
            $ok = Db::getInstance()->update(
                'manufacturer_alias',
                array(
                    'id_manufacturer' => (int) $id_manufacturer,
                    'alias' => pSQL($alias),
                    'normalized_alias' => pSQL($norm),
                    'source' => ($source !== '' ? pSQL($source) : null),
                    'active' => (int) $active,
                    'date_upd' => date('Y-m-d H:i:s'),
                ),
                'id_manufacturer_alias = ' . (int) $id_alias
            );

            if ($ok) {
                $this->confirmations[] = $this->l('Alias actualizado correctamente.');
                return true;
            } else {
                $this->errors[] = $this->l('Error al actualizar el alias.');
                return false;
            }
        }

        // AÑADIR alias nuevo — usamos el helper para respetar su lógica (duplicados, etc.)
        $result = ManufacturerAliasHelper::createAlias(
            $id_manufacturer,
            $alias,
            $norm,
            ($source !== '' ? $source : null),
            0 // auto_created = 0 porque es manual
        );

        if ($result === 'created') {
            // Si desde el formulario lo quieres crear ya inactivo, lo ajustamos aquí
            // if (!$active) {
            //     $newId = (int) Db::getInstance()->Insert_ID();
            //     if ($newId) {
            //         Db::getInstance()->update(
            //             'manufacturer_alias',
            //             array('active' => 0),
            //             'id_manufacturer_alias = ' . $newId
            //         );
            //     }
            // }

            $this->confirmations[] = $this->l('Alias creado correctamente.');
            return true;

        } elseif ($result === 'exists') {
            $this->errors[] = $this->l('Ya existe un alias idéntico para este fabricante.');
            return false;

        } elseif ($result === 'invalid') {
            $this->errors[] = $this->l('Datos inválidos. Revisa el alias y el fabricante.');
            return false;

        } else { // 'error'
            $this->errors[] = $this->l('Error al crear el alias.');
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
        // Ej.: manufacturer_aliasBox[]
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

        // $this->table = 'manufacturer_alias'
        // $this->identifier = 'id_manufacturer_alias'
        $ok = Db::getInstance()->delete(
            $this->table,
            $this->identifier . ' IN (' . $idList . ')'
        );

        if (!$ok) {
            $this->errors[] = $this->l('Error al eliminar los registros seleccionados.');
            return false;
        }

        $this->confirmations[] = $this->l('Registros eliminados correctamente.');
        return true;
    }

    //lo mismo, al no usar objectmodel también hay que implementar función de borrado individual
    public function processDelete()
    {
        $id = (int) Tools::getValue($this->identifier); // id_manufacturer_alias

        if ($id <= 0) {
            $this->errors[] = $this->l('ID no válido.');
            return false;
        }

        $ok = Db::getInstance()->delete(
            $this->table,
            $this->identifier . ' = ' . (int) $id
        );

        if (!$ok) {
            $this->errors[] = $this->l('Error al eliminar el registro.');
            return false;
        }

        $this->confirmations[] = $this->l('Registro eliminado correctamente.');
        return true;
    }

}
