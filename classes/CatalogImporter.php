<?php

require_once _PS_ROOT_DIR_ . '/classes/utils/LoggerFrik.php';

class CatalogImporter
{
    /** @var LoggerFrik */
    protected $logger;

    /** @var int */
    protected $id_supplier;

    public function __construct($id_supplier, LoggerFrik $logger)
    {
        $this->id_supplier = (int) $id_supplier;
        $this->logger = $logger;
    }

    /**
     * Inserta o actualiza productos en la tabla lafrips_productos_proveedores
     *
     * @param array $productos -> cada elemento es un producto normalizado
     */
    public function saveProducts(array $productos)
    {
        // Marcar como 'actualizando_catalogo' los productos del proveedor que no están eliminados
        $sql = "
            UPDATE lafrips_productos_proveedores
            SET actualizando_catalogo = 1
            WHERE id_supplier = " . (int) $this->id_supplier . "
            AND estado != 'eliminado'
        ";
        Db::getInstance()->execute($sql);

        $this->logger->log("Marcados como 'actualizando_catalogo = 1' los productos para revisión", 'INFO');

        $contadorProcesado = 0;
        $contadorInsert = 0;
        $contadorUpdate = 0;
        $contadorError = 0;
        $contadorEliminado = 0;
        $contadorIgnorado = 0;

        foreach ($productos as $p) {
            $contadorProcesado++;

            try {
                if (empty($p['referencia_proveedor']) || empty($p['nombre'])) {
                    $this->logger->log("Saltado producto sin referencia o nombre", 'WARNING');
                    continue;
                }

                //para comprobar si existe el producto en la tabla, buscamos por proveedor, con la referencia y el ean que viene en el catálogo, normalizado, es decir, relleno a ceros a la izquierda hasta 13, para compararlo que la columna ean_norm que contiene también ean normalizado
                $ean_raw = isset($p['ean']) ? $p['ean'] : '';
                $ean_limpio = preg_replace('/\D/', '', $ean_raw); // solo dígitos                
                // Tomar los últimos 13 dígitos (o todos si hay menos de 13)
                $last13 = strlen($ean_limpio) > 13 
                    ? substr($ean_limpio, -13) 
                    : $ean_limpio;                
                // Rellenar con ceros a la izquierda hasta 13
                $ean_norm = str_pad($last13, 13, '0', STR_PAD_LEFT);

                $existe = Db::getInstance()->getRow('
                    SELECT id_productos_proveedores, estado
                    FROM lafrips_productos_proveedores 
                    WHERE id_supplier = ' . (int) $this->id_supplier . '
                    AND referencia_proveedor = "' . pSQL($p['referencia_proveedor']) . '"
                    AND ean_norm = "' . pSQL($ean_norm) . '"
                ');

                //en data viene un parámetro 'ignorar' para si queremos poner estado 'ignorado' por defecto. Se establece al leer el catálogo. Por ejemplo, los productos con varias unidades de packaging en Heo los queremos ignorados por defecto, o los productos sin ean o al menos ean vacío, 0 o todo ceros, la parte del ean quiero que sea común a todos los catálogos así que la añadi aquí en lugar de en cada lectura de catálogo.
                $ignorar = (int) ($p['ignorar'] ?? 0);

                //si $ignorar es 1 se ignorará de todas formas, pero si es 0 se comprueba que exista ean, también que no tenga más de 14 cifras (los de 14 suelen ser de caja o surtido, y el ean del producto son los 13 finales, aunque estos solemos ignorarlos por ser surtidos precisamente), si no se pasa a ignorar=1
                if (!$ignorar && (!$ean_limpio || $ean_norm == '0000000000000' || strlen($ean_limpio) > 14 || strlen($ean_limpio) < 7)) {
                    $ignorar = 1;
                }

                $nombre = $this->limpiarTextoProducto($p['nombre']);

                // Mapeo de campos de normalizados a columnas reales
                $data = [
                    'id_supplier' => $this->id_supplier,
                    'referencia_proveedor' => pSQL($p['referencia_proveedor']),
                    'url_proveedor' => $p['url_proveedor'] ?? null,
                    'nombre' => pSQL($nombre),
                    'ean' => pSQL($p['ean'] ?? ''),
                    'coste' => (float) ($p['coste'] ?? 0),
                    'pvp_sin_iva' => (float) ($p['pvp_sin_iva'] ?? 0),
                    'peso' => (float) ($p['peso'] ?? 0),
                    'manufacturer_name' => $p['manufacturer_name'] ?? null,
                    'id_manufacturer' => $p['id_manufacturer'] ?? null,
                    'description_short' => $p['description_short'] ?? null,
                    'descripcion_larga' => $p['descripcion_larga'] ?? null,
                    //En el array de imagenes tenemos todas, la primera es la principal. La sacamos del array y almacenamos en variable imagen_principal
                    // 'otras_imagenes'      => isset($p['imagenes']) ? json_encode(array_slice($p['imagenes'], 1)) : null,
                    'imagen_principal' => isset($p['imagenes']) ? array_shift($p['imagenes']) : null,
                    'otras_imagenes' => json_encode($p['imagenes']),
                    'video' => $p['video'][0] ?? null,
                    'url_auxiliar' => $p['url_auxiliar'][0] ?? null,
                    'disponibilidad' => (int) ($p['disponibilidad'] ?? 0),
                    'actualizando_catalogo' => 0,
                    // 'estado'              => 'pendiente', No lo actualizamos directamente, hay que mirar si el producto está en un estado no actualizable
                    'fuente' => $p['fuente'] ?? 'desconocido',
                    'last_update_info' => date('Y-m-d H:i:s'),
                    'date_upd' => date('Y-m-d H:i:s'),
                ];
               

                if ($existe) {
                    // UPDATE
                    $estadoActual = $existe['estado'];

                    // No tocar el estado si está encolado, procesando, creado, error o ignorado. Si no es el estado actual, ponemos "pendiente" o ignorado si viene así
                    if (!in_array($estadoActual, ['encolado', 'procesando', 'creado', 'error', 'ignorado'])) {
                        // Mantener el estado actual si no viene de la lectura de catálogo como ignorado, si no se pone ignorar
                        if ($ignorar) {
                            $data['estado'] = 'ignorado';
                            $contadorIgnorado++;
                        } else {
                            $data['estado'] = 'pendiente';
                        }
                    }

                    if (Db::getInstance()->update('productos_proveedores', $data, 'id_productos_proveedores=' . (int) $existe['id_productos_proveedores'])) {
                        $contadorUpdate++;
                    } else {
                        $contadorError++;
                        $this->logger->log("Error actualizando " . $p['referencia_proveedor'], 'ERROR');
                    }
                } else {
                    // INSERT
                    // Ponemos el estado pendiente si no viene de la lectura de catálogo como ignorado
                    if ($ignorar) {
                        $data['estado'] = 'ignorado';
                        $contadorIgnorado++;
                    } else {
                        $data['estado'] = 'pendiente';
                    }
                    $data['date_add'] = date('Y-m-d H:i:s');
                    $data['date_importado'] = date('Y-m-d H:i:s');

                    if (Db::getInstance()->insert('productos_proveedores', $data)) {
                        $contadorInsert++;
                    } else {
                        $contadorError++;
                        // Obtener el error SQL real
                        $sql_error = Db::getInstance()->getMsgError();
                        $this->logger->log("Error insertando " . $p['referencia_proveedor'], 'ERROR');
                        $this->logger->log('Error al insertar proveedor: ' . $sql_error , 'DEBUG');                        
                        $this->logger->log('data: '.json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'DEBUG');
                    }
                }
            } catch (Exception $e) {
                $contadorError++;
                $this->logger->log("Excepción con " . $p['referencia_proveedor'] . " → " . $e->getMessage(), 'ERROR');
            }
        }

        // Marcar como eliminados los productos no actualizados (siguen con actualizando_catalogo = 1)
        $sqlEliminar = "
            UPDATE lafrips_productos_proveedores
            SET disponibilidad = 0,
                actualizando_catalogo = 0,
                estado = 'eliminado',
                last_update_info = NOW(),
                date_upd = NOW()
            WHERE id_supplier = " . (int) $this->id_supplier . "
            AND actualizando_catalogo = 1
        ";

        Db::getInstance()->execute($sqlEliminar);
        // $contadorEliminado = mysqli_affected_rows(Db::getInstance()->getLink());
        $contadorEliminado = Db::getInstance()->Affected_Rows();

        $this->logger->log("Marcados {$contadorEliminado} productos como eliminados (no encontrados en catálogo).", 'WARNING');

        // Limpieza opcional del flag
        // Db::getInstance()->execute("
        //     UPDATE lafrips_productos_proveedores
        //     SET actualizando_catalogo = 0
        //     WHERE id_supplier = ".(int)$this->id_supplier
        // );

        $this->logger->log("Importación finalizada: $contadorProcesado procesados, $contadorInsert insertados, $contadorUpdate actualizados, $contadorError errores, $contadorEliminado marcados eliminados, $contadorIgnorado marcados ignorados", 'INFO');

        return [
            'procesados' => $contadorProcesado,
            'insertados' => $contadorInsert,
            'actualizados' => $contadorUpdate,
            'errores' => $contadorError,
            'eliminados' => $contadorEliminado,
            'ignorados' => $contadorIgnorado,
        ];
    }

    //limpiador para el nombre de producto, si se adapta a descripciones etc, habría que revisar maxLength y strip_tags, ya que el html en la descripción puede estar permitido. $texto = strip_tags($texto, '<p><br><strong><em>');
    public function limpiarTextoProducto($texto, $maxLength = 128)
    {
        // Elimina etiquetas HTML y entidades peligrosas
        $texto = strip_tags($texto);

        // Reemplaza caracteres problemáticos con espacio o guion
        $texto = str_replace(
            ['#', '/', ';', '<', '>', '=', '{', '}'],
            ' ',
            $texto
        );

        // Opcional: elimina espacios duplicados
        $texto = preg_replace('/\s+/', ' ', $texto);

        // Recorta al máximo permitido (por seguridad en PrestaShop)
        $texto = mb_substr(trim($texto), 0, $maxLength, 'UTF-8');

        // Asegura codificación válida UTF-8 (sin usar utf8_encode que está deprecado a partir de PHP 8.2)
        // if (!mb_check_encoding($texto, 'UTF-8')) {
        //     // Detecta la codificación original si se puede
        //     $encoding = mb_detect_encoding($texto, ['ISO-8859-1', 'Windows-1252', 'UTF-8'], true);
        //     if ($encoding && $encoding !== 'UTF-8') {
        //         $texto = mb_convert_encoding($texto, 'UTF-8', $encoding);
        //     } else {
        //         // Fallback a iconv si no se detecta bien
        //         $texto = iconv('ISO-8859-1', 'UTF-8//IGNORE', $texto);
        //     }
        // }

        return $texto;
    }
}
