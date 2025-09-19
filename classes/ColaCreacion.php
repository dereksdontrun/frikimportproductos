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
                    
                }

            } catch (Exception $e) {                
                $this->logger->log("Excepción en producto id_proveedor=$id_productos_proveedores → ".$e->getMessage(), 'ERROR');
            }

        } while ((time() - $this->inicio) < $this->max_execution_time);

        if ($contador > 1) {
            $duracion = time() - $this->inicio;            
            $this->logger->log("Finalizado ColaCreacion. Procesados: $contador productos, duración $duracion segundos", 'INFO');

        }
    }

    /**
     * Marca como "pendientes" los productos en estado 'procesando' durante más de X minutos.
     */
    private function resetProcesandoAntiguos($minutos = 60)
    {
        $limite = date('Y-m-d H:i:s', time() - $minutos * 60);

         // Solo productos en estado 'procesando' que han superado el tiempo límite
        $sql = "SELECT id_productos_proveedores, mensaje_error 
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
            $id_productos_proveedores = (int)$producto['id_productos_proveedores'];
            $mensaje_anterior = $producto['mensaje_error'];
            $mensaje_nuevo = pSQL(trim($mensaje_anterior . ' | Reiniciado ' . date('Y-m-d H:i:s')));

            Db::getInstance()->update('productos_proveedores', [
                'estado'         => 'encolado',
                'date_procesando' => '0000-00-00 00:00:00',
                'mensaje_error'  => $mensaje_nuevo,
                'date_upd'       => date('Y-m-d H:i:s'),
            ], "id_productos_proveedores = {$id_productos_proveedores}");

            $this->logger->log("Reiniciado producto #{$id_productos_proveedores} por timeout.", 'WARNING');
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
    
}

new ColaCreacion();
