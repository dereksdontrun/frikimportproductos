<?php
// modules/frikimportproductos/classes/ColaCreacion.php

// https://lafrikileria.com/modules/frikimportproductos/classes/ColaCreacion.php

require_once(dirname(__FILE__).'/../../../config/config.inc.php');
require_once(dirname(__FILE__).'/../../../init.php');
require_once _PS_ROOT_DIR_.'/modules/frikimportproductos/classes/CreaProducto.php';
require_once _PS_ROOT_DIR_.'/classes/utils/LoggerFrik.php';

class ColaCreacion
{
    private $inicio;
    private $max_execution_time;
    private $log_file = _PS_ROOT_DIR_.'/modules/frikimportproductos/logs/crear_cola.log';

    /** @var LoggerFrik */
    private $logger;

    /** @var CreadorProducto */
    private $creador;

    public function __construct()
    {
        $this->inicio = time();

        // Tiempo máximo: 90% del límite de PHP o 280s como máximo
        $max_time_ini = ini_get('max_execution_time');
        $this->max_execution_time = ($max_time_ini && $max_time_ini > 0) 
            ? min($max_time_ini * 0.9, 280) 
            : 280;

        $this->logger = new LoggerFrik($this->log_file);
        $this->creador = new CreaProducto($this->logger);

        $this->logger->log("-----     -----     -----     -----     -----", 'INFO', false);
        $this->logger->log("Iniciado proceso de creación de productos desde cola", 'INFO', false);

        // Reset de productos "atascados" en estado procesando más de X minutos
        $this->resetProcesandoAntiguos();

        $this->start();
    }

    private function start()
    {
        $contador = 0;

        do {
            $contador++;

            $producto = $this->obtenerProductoEnCola();            

            if ($producto === false) {
                $this->logger->log("No hay productos en cola", 'INFO', false);
                
                break;
            }           

            if ($producto['skip']) {
                $this->logger->log("Error marcando como 'procesando' a id_productos_proveedores= #{$producto['id_productos_proveedores']}", 'ERROR');
                continue;
            }

            $id_productos_proveedores = (int)$producto['id_productos_proveedores'];            

            try {
                $this->logger->log("Procesando id_productos_proveedores= #$id_productos_proveedores", 'INFO');

                $res = $this->creador->crearDesdeProveedor($producto, $id_productos_proveedores, 'cola', (int)$producto['id_employee_encolado']);

                if ($res['success']) {
                    $this->logger->log("Producto creado correctamente (id_product={$res['id_product']})", 'SUCCESS');
                    
                } else {
                    $this->logger->log("Error creando producto id_productos_proveedores=$id_productos_proveedores → ".$res['message'], 'ERROR');
                    
                    $this->guardarErrorProducto($id_productos_proveedores, $res['message']);
                }

            } catch (Exception $e) {                
                $this->logger->log("Excepción en producto id_proveedor=$id_productos_proveedores → ".$e->getMessage(), 'ERROR');

                $this->guardarErrorProducto($id_productos_proveedores, $e->getMessage());
            }

        } while ((time() - $this->inicio) < $this->max_execution_time);

        if ($contador > 1) {
            $duracion = time() - $this->inicio;            
            $this->logger->log("Finalizado ColaCreacion. Procesados: $contador productos, duración $duracion segundos", 'INFO');

        }
    }

    /**
     * Marca como "pendientes" los productos en estado 'procesando' durante más de X minutos.
     * Sumar 1 a reintentos cada vez que se detecta un producto atascado.
     * Si reintentos >= 3 (por ejemplo), se marca como error permanente.
     * Si no, se reencola para nuevo intento.
     * Se guarda el mensaje del motivo.
     * Si se marca como error, se envía email de aviso.
     */
    private function resetProcesandoAntiguos($minutos = 60, $maxReintentos = 3)
    {
        $limite = date('Y-m-d H:i:s', time() - $minutos * 60);

         // Solo productos en estado 'procesando' que han superado el tiempo límite
        $sql = "SELECT id_productos_proveedores, mensaje_error, reintentos, id_employee_encolado
                FROM lafrips_productos_proveedores
                WHERE estado = 'procesando'
                AND date_procesando < '".pSQL($limite)."'";

        $productos = Db::getInstance()->executeS($sql);

        if (!$productos || empty($productos)) {
            return; // Nada que resetear
        }

        $total = count($productos);
        $this->logger->log("---------- Reinicio de productos estancados ($minutos minutos) ----------", 'WARNING');
        $this->logger->log("Total de productos reiniciados por timeout: $total", 'WARNING');

        foreach ($productos as $producto) {
            $id = (int)$producto['id_productos_proveedores'];
            $reintentos = (int)$producto['reintentos'];
            $id_employee_encolado = $producto['id_employee_encolado'];
            $mensaje_anterior = $producto['mensaje_error'];
            $mensaje_base = date('[Y-m-d H:i:s] ')."Timeout tras {$minutos} min. Reintento #".($reintentos + 1);            

            if ($reintentos + 1 > $maxReintentos) {
                // Límite alcanzado → marcar como error permanente
                $nuevoEstado = 'error';
                $mensaje_final = trim(($mensaje_anterior ? $mensaje_anterior.' | ' : '').$mensaje_base." — Límite alcanzado, marcado como ERROR permanente.");

                Db::getInstance()->update('productos_proveedores', [
                    'estado'         => pSQL($nuevoEstado),
                    'mensaje_error'  => pSQL($mensaje_final),
                    'date_upd'       => date('Y-m-d H:i:s'),
                ], "id_productos_proveedores = {$id}");

                $this->logger->log("Producto #{$id} marcado como ERROR (reintentos: {$reintentos})", 'ERROR');

                // Notificar por email
                $this->enviarAvisoError($id, $mensaje_final, $id_employee_encolado);

            } else {
                // Reencolar para nuevo intento
                $mensaje_nuevo = trim(($mensaje_anterior ? $mensaje_anterior.' | ' : '').$mensaje_base);

                Db::getInstance()->update('productos_proveedores', [
                    'estado'         => 'encolado',
                    'date_procesando' => '0000-00-00 00:00:00',
                    'mensaje_error'  => pSQL($mensaje_nuevo),
                    'reintentos'     => $reintentos + 1,
                    'date_upd'       => date('Y-m-d H:i:s'),
                ], "id_productos_proveedores = {$id}");

                $this->logger->log("Reiniciado producto #{$id} (reintento #".($reintentos + 1).")", 'WARNING');
            }
        }

        return;        
    }

    /**
     * Obtiene un producto en estado encolado y lo marca como procesando.
     */
    private function obtenerProductoEnCola()
    {
        $producto = Db::getInstance()->getRow('
            SELECT * 
            FROM '._DB_PREFIX_.'productos_proveedores 
            WHERE estado = "encolado"
            ORDER BY date_add ASC            
        ');

        if (!$producto) {
            return false;
        }

        $ok = Db::getInstance()->update('productos_proveedores', [
            'estado' => 'procesando',
            'date_procesando' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s')
        ], 'id_productos_proveedores = '.(int)$producto['id_productos_proveedores']);

        if (!$ok) {
            return ['skip' => true, 'id_productos_proveedores' => $producto['id_productos_proveedores']];
        }

        return $producto;
    }

    /**
     * Guarda (concatenando) un mensaje de error en productos_proveedores.
     * Igual que en ColaCreacion, pero pensada para ejecuciones manuales.
     */
    protected function guardarErrorProducto($id_productos_proveedores, $nuevo_error)
    {
        $id_productos_proveedores = (int)$id_productos_proveedores;
        $nuevo_error = trim($nuevo_error);

        $mensaje_anterior = Db::getInstance()->getValue('
            SELECT mensaje_error 
            FROM lafrips_productos_proveedores 
            WHERE id_productos_proveedores = '.$id_productos_proveedores
        );

        $mensaje_concatenado = trim(
            ($mensaje_anterior ? $mensaje_anterior.' | ' : '') .
            date('[Y-m-d H:i:s] ') .
            $nuevo_error
        );

        Db::getInstance()->update('productos_proveedores', [
            'mensaje_error' => pSQL($mensaje_concatenado),
            'date_upd'      => date('Y-m-d H:i:s')
        ], 'id_productos_proveedores = '.$id_productos_proveedores);

        $this->logger->log("Guardado error en producto #$id_productos_proveedores → $nuevo_error", 'ERROR');
    }

    /**
     * Envía un email de aviso cuando un producto falla repetidamente
     */
    private function enviarAvisoError($id_productos_proveedores, $mensaje, $id_employee_encolado)
    {
        try {
            // Destinatarios
            // $cuentas = 'sergio@lafrikileria.com, soporte@lafrikileria.com';
            $cuentas = ['sergio@lafrikileria.com'];

            $employee = new Employee($id_employee_encolado);

            if (Validate::isLoadedObject($employee)) {
                // $employee_name = $employee->firstname.' '.$employee->lastname;                

                $cuentas[] = $employee->email;
            }

            $cuentas = implode(",", array_unique($cuentas));

            // Asunto
            $asunto = '⚠️ ERROR en creación de producto en cola #' . $id_productos_proveedores . ' (' . date("Y-m-d H:i:s") . ')';

            // Contenido del mensaje
            $detalles = [
                'ID producto proveedores' => $id_productos_proveedores,
                'Fecha' => date("Y-m-d H:i:s"),
                'Mensaje de error' => $mensaje
            ];

            // Montamos contenido HTML (tabla simple)
            $tabla = '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse: collapse; font-family: Arial; font-size: 13px;">';
            foreach ($detalles as $k => $v) {
                $tabla .= '<tr><th style="background:#f5f5f5; text-align:left;">' . pSQL($k) . '</th><td>' . nl2br(pSQL($v)) . '</td></tr>';
            }
            $tabla .= '</table>';

            // 🔧 Datos que usa la plantilla de email
            $info = [];
            $info['{employee_name}'] = 'Sistema Cola Creación';
            $info['{order_date}'] = date("Y-m-d H:i:s");
            $info['{seller}'] = "Módulo frikimportproductos";
            $info['{order_data}'] = '';
            $info['{messages}'] = $tabla;

            // Envío del email con la plantilla 'aviso_pedido_webservice'
            @Mail::Send(
                1, // id_lang
                'aviso_pedido_webservice', // nombre del template
                Mail::l($asunto, 1), // asunto traducible
                $info, // variables para el template
                $cuentas, // destinatarios (puede ser varios separados por coma)
                'Sistema Cola Creación', // nombre del remitente
                null, // from (usa por defecto)
                null, // reply-to
                null, // attachment
                null, // modo SMTP
                _PS_MAIL_DIR_, // carpeta de plantillas
                true, // modo HTML
                1 // id_shop
            );

            $this->logger->log("Email de aviso de error enviado correctamente (#$id_productos_proveedores)", 'INFO');
        } catch (Exception $e) {
            $this->logger->log("Error enviando email de aviso para #$id_productos_proveedores → " . $e->getMessage(), 'ERROR');
        }
    }
    
}

new ColaCreacion();
