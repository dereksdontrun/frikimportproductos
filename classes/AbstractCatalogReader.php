<?php

abstract class AbstractCatalogReader
{
    /** @var LoggerFrik */
    protected $logger;

    /** @var array Configuración del proveedor (fila de la tabla lafrips_import_proveedores) */
    protected $config;

    /** @var string Ruta local de descarga */
    protected $pathDescarga;

    /**
     * Constructor base
     */
    public function __construct($config = [])
    {
        $this->config = $config;
        $this->pathDescarga = _PS_MODULE_DIR_.'frikimportproductos/import/';

        // Cada Reader puede sobreescribir el logger
        $this->logger = new LoggerFrik(_PS_MODULE_DIR_.'frikimportproductos/logs/general.log');
    }

    /**
     * Descarga el catálogo y devuelve la ruta del archivo local
     */
    abstract public function fetch();

    /**
     * Verifica el formato del catálogo (cabecera, nº columnas, etc.)
     */
    abstract public function checkCatalogo($filename);

    /**
     * Parsea el archivo y devuelve un array de productos normalizados
     */
    abstract public function parse($filename);

    /**
     * Utilidad: leer CSV genérico
     */
    protected function readCsv($filename, $delimiter = ";")
    {
        $data = [];
        if (($handle = fopen($filename, "r")) !== false) {
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $data[] = $row;
            }
            fclose($handle);
        }
        return $data;
    }

    /**
     * Utilidad: leer JSON
     */
    protected function readJson($filename)
    {
        $content = file_get_contents($filename);
        return json_decode($content, true);
    }

    /**
     * Utilidad: leer XML
     */
    protected function readXml($filename)
    {
        return simplexml_load_file($filename);
    }
}
