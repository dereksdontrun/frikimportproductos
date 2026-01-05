<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminManufacturerGpsrController extends AdminController
{
    public function __construct()
    {
        // Tabla base
        $this->table = 'manufacturer_gpsr';
        $this->identifier = 'id_manufacturer_gpsr';
        $this->className = ''; // sin ObjectModel
        $this->lang = false;
        $this->bootstrap = true;

        parent::__construct();

        // SELECT extra: datos del fabricante Presta y del responsable (si lo hay)
        $this->_select = '
            m.name AS presta_manufacturer_name,
            m.active AS presta_manufacturer_active,
            r.name AS responsible_short_name
        ';

        $this->_join = '
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m
                ON (m.id_manufacturer = a.id_manufacturer)
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer_gpsr_responsible r
                ON (r.id_manufacturer_gpsr_responsible = a.id_responsible)
        ';

        // Opcional: solo fabricantes activos en Presta
        $this->_where = ' AND m.active = 1 ';

        $this->fields_list = array(
            'id_manufacturer_gpsr' => array(
                'title' => $this->l('ID GPSR'),
                'align' => 'center',
                'class' => 'fixed-width-xs',
            ),
            'id_manufacturer' => array(
                'title' => $this->l('ID Fabricante'),
                'align' => 'center',
                'class' => 'fixed-width-xs',
                'filter_key' => 'a!id_manufacturer',
            ),
            'presta_manufacturer_name' => array(
                'title' => $this->l('Fabricante Presta'),
                'filter_key' => 'm!name',
            ),
            'is_eu' => array(
                'title' => $this->l('UE'),
                'type' => 'bool',
                'align' => 'center',
                'class' => 'fixed-width-xs',
            ),
            'use_own_responsible' => array(
                'title' => $this->l('Usar responsable propio'),
                'type' => 'bool',
                'align' => 'center',
                'class' => 'fixed-width-xs',
            ),
            'responsible_short_name' => array(
                'title' => $this->l('Responsable asignado'),
                'filter_key' => 'r!name',
            ),
            'data_complete' => array(
                'title' => $this->l('Datos completos'),
                'type' => 'bool',
                'align' => 'center',
                'class' => 'fixed-width-xs',
            ),
            'date_upd' => array(
                'title' => $this->l('Última actualización'),
                'type' => 'datetime',
            ),
        );

        $this->addRowAction('edit');
        $this->addRowAction('delete');

        $this->bulk_actions = array(
            'delete' => array(
                'text' => $this->l('Eliminar seleccionados'),
                'confirm' => $this->l('¿Eliminar las fichas GPSR seleccionadas? (no borra el fabricante)'),
            ),
        );
    }

    public function renderForm()
    {
        // al tener $this->className = ''; // sin ObjectModel no podemos obtener así la info
        // if (!($obj = $this->loadObject(true))) {
        //     return;
        // }

        $idGpsr = (int) Tools::getValue($this->identifier); // id_manufacturer_gpsr
        $row = array();

        if ($idGpsr > 0) {
            // Editar registro existente
            $row = Db::getInstance()->getRow('
            SELECT *
            FROM ' . _DB_PREFIX_ . 'manufacturer_gpsr
            WHERE id_manufacturer_gpsr = ' . (int) $idGpsr
            );

            if (!$row) {
                $this->errors[] = $this->l('Registro GPSR no encontrado.');
                return '';
            }
        } else {
            // Alta nueva: necesitamos id_manufacturer como parámetro
            $idManufacturer = (int) Tools::getValue('id_manufacturer');
            if ($idManufacturer <= 0) {
                $this->errors[] = $this->l('Falta el ID de fabricante para crear la ficha GPSR.');
                return '';
            }

            $row = array(
                'id_manufacturer_gpsr' => 0,
                'id_manufacturer' => $idManufacturer,
                'is_eu' => 1,
                'use_own_responsible' => 0,
                'id_responsible' => 0,
                'commercial_name' => '',
                'legal_name' => '',
                'street' => '',
                'city' => '',
                'postcode' => '',
                'country_iso' => '',
                'email' => '',
                'phone' => '',
                'safety_info_text' => '',
                'notes' => '',
                'data_complete' => 0,
            );
        }

        // Nombre del fabricante Presta para mostrarlo
        $manufacturerName = Db::getInstance()->getValue('
            SELECT name
            FROM ' . _DB_PREFIX_ . 'manufacturer
            WHERE id_manufacturer = ' . (int) $row['id_manufacturer']
        );

        // Listado de responsables posibles
        $responsibles = Db::getInstance()->executeS('
            SELECT id_manufacturer_gpsr_responsible, name
            FROM ' . _DB_PREFIX_ . 'manufacturer_gpsr_responsible
            WHERE active = 1
            ORDER BY name ASC
        ');

        $optionsResp = array(
            array(
                'id' => 0,
                'name' => $this->l('-- Ninguno / usar lógica por defecto --'),
            ),
        );

        foreach ($responsibles as $r) {
            $optionsResp[] = array(
                'id' => (int) $r['id_manufacturer_gpsr_responsible'],
                'name' => $r['name'],
            );
        }

        $this->fields_form = array(
            'legend' => array(
                'title' => $this->l('Datos GPSR del fabricante'),
                'icon' => 'icon-shield',
            ),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => $this->l('ID fabricante Presta'),
                    'name' => 'id_manufacturer',
                    'readonly' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Nombre fabricante Presta'),
                    'name' => 'manufacturer_name_display',
                    'readonly' => true,
                ),

                array(
                    'type' => 'switch',
                    'label' => $this->l('Fabricante de la UE'),
                    'name' => 'is_eu',
                    'is_bool' => true,
                    'values' => array(
                        array('id' => 'is_eu_on', 'value' => 1, 'label' => $this->l('Sí')),
                        array('id' => 'is_eu_off', 'value' => 0, 'label' => $this->l('No / Desconocido')),
                    ),
                ),

                array(
                    'type' => 'switch',
                    'label' => $this->l('Usar nuestros datos como responsable'),
                    'name' => 'use_own_responsible',
                    'is_bool' => true,
                    'values' => array(
                        array('id' => 'uor_on', 'value' => 1, 'label' => $this->l('Sí')),
                        array('id' => 'uor_off', 'value' => 0, 'label' => $this->l('No')),
                    ),
                    'desc' => $this->l('Si se marca, se usará el responsable por defecto (La Frikilería) incluso si es fabricante UE.'),
                ),

                array(
                    'type' => 'select',
                    'label' => $this->l('Responsable específico'),
                    'name' => 'id_responsible',
                    'options' => array(
                        'query' => $optionsResp,
                        'id' => 'id',
                        'name' => 'name',
                    ),
                    'desc' => $this->l('Solo necesario si el responsable no es el fabricante ni el responsable por defecto.'),
                ),

                array(
                    'type' => 'text',
                    'label' => $this->l('Marca / nombre comercial'),
                    'name' => 'commercial_name',
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Denominación social'),
                    'name' => 'legal_name',
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Calle y nº'),
                    'name' => 'street',
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Ciudad'),
                    'name' => 'city',
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Código postal'),
                    'name' => 'postcode',
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('País (ISO2, ej. ES, FR)'),
                    'name' => 'country_iso',
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Email'),
                    'name' => 'email',
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Teléfono'),
                    'name' => 'phone',
                ),

                array(
                    'type' => 'textarea',
                    'label' => $this->l('Información de seguridad / materiales'),
                    'name' => 'safety_info_text',
                    'cols' => 60,
                    'rows' => 4,
                ),

                array(
                    'type' => 'textarea',
                    'label' => $this->l('Notas internas'),
                    'name' => 'notes',
                    'cols' => 60,
                    'rows' => 3,
                ),

                array(
                    'type' => 'switch',
                    'label' => $this->l('Datos GPSR completos'),
                    'name' => 'data_complete',
                    'is_bool' => true,
                    'values' => array(
                        array('id' => 'dc_on', 'value' => 1, 'label' => $this->l('Sí')),
                        array('id' => 'dc_off', 'value' => 0, 'label' => $this->l('No')),
                    ),
                ),
            ),
            'submit' => array(
                'title' => $this->l('Guardar'),
            ),
        );

        // Rellenar valores a partir de $row
        $this->fields_value['id_manufacturer'] = (int) $row['id_manufacturer'];
        $this->fields_value['manufacturer_name_display'] = $manufacturerName;
        $this->fields_value['is_eu'] = (int) $row['is_eu'];
        $this->fields_value['use_own_responsible'] = (int) $row['use_own_responsible'];
        $this->fields_value['id_responsible'] = (int) $row['id_responsible'];
        $this->fields_value['commercial_name'] = $row['commercial_name'];
        $this->fields_value['legal_name'] = $row['legal_name'];
        $this->fields_value['street'] = $row['street'];
        $this->fields_value['city'] = $row['city'];
        $this->fields_value['postcode'] = $row['postcode'];
        $this->fields_value['country_iso'] = $row['country_iso'];
        $this->fields_value['email'] = $row['email'];
        $this->fields_value['phone'] = $row['phone'];
        $this->fields_value['safety_info_text'] = $row['safety_info_text'];
        $this->fields_value['notes'] = $row['notes'];
        $this->fields_value['data_complete'] = (int) $row['data_complete'];

        return parent::renderForm();
    }

    public function processSave()
    {
        $idGpsr = (int) Tools::getValue($this->identifier);

        $data = array(
            'is_eu' => (int) Tools::getValue('is_eu', 1),
            'use_own_responsible' => (int) Tools::getValue('use_own_responsible', 0),
            'id_responsible' => (int) Tools::getValue('id_responsible', 0) ?: null,
            'commercial_name' => pSQL(Tools::getValue('commercial_name')),
            'legal_name' => pSQL(Tools::getValue('legal_name')),
            'street' => pSQL(Tools::getValue('street')),
            'city' => pSQL(Tools::getValue('city')),
            'postcode' => pSQL(Tools::getValue('postcode')),
            'country_iso' => pSQL(Tools::strtoupper(Tools::getValue('country_iso'))),
            'email' => pSQL(Tools::getValue('email')),
            'phone' => pSQL(Tools::getValue('phone')),
            'safety_info_text' => Tools::getValue('safety_info_text', null),
            'notes' => Tools::getValue('notes', null),
            'data_complete' => (int) Tools::getValue('data_complete', 0),
            'date_upd' => date('Y-m-d H:i:s'),
        );

        if ($idGpsr > 0) {
            Db::getInstance()->update(
                'manufacturer_gpsr',
                $data,
                'id_manufacturer_gpsr = ' . (int) $idGpsr
            );
        } else {
            // nuevo registro: necesitamos id_manufacturer
            $idManufacturer = (int) Tools::getValue('id_manufacturer');
            if ($idManufacturer <= 0) {
                $this->errors[] = $this->l('ID de fabricante no válido.');
                return false;
            }

            $data['id_manufacturer'] = $idManufacturer;
            $data['manufacturer_name'] = Db::getInstance()->getValue('
                SELECT name FROM ' . _DB_PREFIX_ . 'manufacturer
                WHERE id_manufacturer = ' . (int) $idManufacturer
            );
            $data['date_add'] = date('Y-m-d H:i:s');

            Db::getInstance()->insert('manufacturer_gpsr', $data);
        }

        $this->confirmations[] = $this->l('Datos GPSR guardados correctamente.');
        Tools::redirectAdmin(self::$currentIndex . '&token=' . $this->token);

        return true;
    }
}
