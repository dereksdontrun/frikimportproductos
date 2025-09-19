<?php

require_once _PS_ROOT_DIR_.'/classes/utils/LoggerFrik.php';

class CatalogImporter
{
    /** @var LoggerFrik */
    protected $logger;

    /** @var int */
    protected $idSupplier;

    public function __construct($idSupplier, LoggerFrik $logger)
    {
        $this->idSupplier = (int)$idSupplier;
        $this->logger = $logger;
    }

    /**
     * Inserta o actualiza productos en la tabla lafrips_productos_proveedores
     *
     * @param array $productos -> cada elemento es un producto normalizado
     */
    public function saveProducts(array $productos)
    {
        $contadorInsert = 0;
        $contadorUpdate = 0;
        $contadorError  = 0;

        foreach ($productos as $p) {
            if (empty($p['referencia_proveedor']) || empty($p['nombre'])) {
                $this->logger->log("Saltado producto sin referencia o nombre", 'WARNING');
                continue;
            }

            try {
                $idExistente = Db::getInstance()->getValue('
                    SELECT id_productos_proveedores 
                    FROM '._DB_PREFIX_.'productos_proveedores 
                    WHERE id_supplier = '.(int)$this->idSupplier.'
                    AND referencia_proveedor = "'.pSQL($p['referencia_proveedor']).'"
                ');

                // Mapeo de campos de normalizados a columnas reales
                $data = [
                    'id_supplier'         => $this->idSupplier,
                    'referencia_proveedor'=> pSQL($p['referencia_proveedor']),
                    'url_proveedor'       => $p['url_proveedor'] ?? null,
                    'nombre'              => pSQL($p['nombre']),
                    'ean'                 => pSQL($p['ean'] ?? ''),
                    'coste'               => (float)($p['coste'] ?? 0),
                    'pvp_sin_iva'         => (float)($p['pvp_sin_iva'] ?? 0),
                    'peso'                => (float)($p['peso'] ?? 0),
                    'id_manufacturer'     => (int)($p['id_manufacturer'] ?? 0),
                    'description_short'   => $p['description_short'] ?? null,
                    'descripcion_larga'   => $p['descripcion_larga'] ?? null,
                    //quitamos la primera del array antes de guardar otras_imagenes
                    'otras_imagenes'      => isset($p['imagenes']) ? json_encode(array_slice($p['imagenes'], 1)) : null,
                    'video'               => $p['video'][0] ?? null,
                    'url_auxiliar'        => $p['url_auxiliar'][0] ?? null,
                    'disponibilidad'      => (int)($p['disponibilidad'] ?? 0),
                    'estado'              => 'pendiente',
                    'fuente'              => $p['fuente'] ?? 'desconocido',
                    'last_update_info'    => date('Y-m-d H:i:s'),
                    'date_upd'            => date('Y-m-d H:i:s'),
                ];

                if ($idExistente) {
                    // UPDATE
                    if (Db::getInstance()->update('productos_proveedores', $data, 'id_productos_proveedores='.(int)$idExistente)) {
                        $contadorUpdate++;
                    } else {
                        $contadorError++;
                        $this->logger->log("Error actualizando ".$p['referencia_proveedor'], 'ERROR');
                    }
                } else {
                    // INSERT
                    $data['date_add'] = date('Y-m-d H:i:s');
                    $data['date_importado'] = date('Y-m-d H:i:s');

                    if (Db::getInstance()->insert('productos_proveedores', $data)) {
                        $contadorInsert++;
                    } else {
                        $contadorError++;
                        $this->logger->log("Error insertando ".$p['referencia_proveedor'], 'ERROR');
                    }
                }
            } catch (Exception $e) {
                $contadorError++;
                $this->logger->log("Excepción con ".$p['referencia_proveedor']." → ".$e->getMessage(), 'ERROR');
            }
        }

        $this->logger->log("Importación finalizada: $contadorInsert insertados, $contadorUpdate actualizados, $contadorError errores", 'INFO');

        return [
            'insertados'   => $contadorInsert,
            'actualizados' => $contadorUpdate,
            'errores'      => $contadorError,
        ];
    }
}
