<?php

require_once(_PS_MODULE_DIR_ . 'frikimportproductos/classes/AbstractCatalogReader.php');
require_once _PS_ROOT_DIR_ . '/classes/utils/LoggerFrik.php';

class AbysseReader extends AbstractCatalogReader
{
    protected $config;
    protected $logger;

    // carpeta donde se dejan los CSV de Abysse manualmente
    protected $path_catalogos = _PS_MODULE_DIR_ . 'frikimportproductos/import/abysse/';

    // nombre del fichero CSV (por ejemplo "ABY_13_01_2025.csv")
    protected $nombre_catalogo;

    // mapa de posibles nombres de los campos (copiado/adaptado de tu ProcesoCatalogoAbysse)
    protected $mapa_campos = [
        "referencia" => [
            "obligatorio" => 1,
            "variantes" => ["reference"],
        ],
        "nombre" => [
            "obligatorio" => 1,
            "variantes" => ["designation", "description"],
        ],
        "fabricante" => [
            "obligatorio" => 0,
            "variantes" => ["manufacturer", "brand"],
        ],
        "ean" => [
            "obligatorio" => 1,
            "variantes" => ["ean", "ean code"],
        ],
        "stock" => [
            "obligatorio" => 1,
            "variantes" => ["stock"],
        ],
        "precio" => [
            "obligatorio" => 1,
            "variantes" => ["price"],
        ],
        "licencia" => [
            "obligatorio" => 0,
            "variantes" => ["license"],
        ],
        "categoria" => [
            "obligatorio" => 1, // no es opcional porque tienes descuentos según categoría
            "variantes" => [" category 1", "category 1", "category"],
        ],
        "atributo" => [
            "obligatorio" => 1,
            "variantes" => ["size"],
        ],
        "peso" => [
            "obligatorio" => 0,
            "variantes" => ["product weight in g", "product Weight [g]", "product weight"],
        ],
    ];

    // indices de columna detectados en la cabecera
    protected $indices = [
        "referencia" => -1,
        "nombre" => -1,
        "fabricante" => -1,
        "ean" => -1,
        "stock" => -1,
        "precio" => -1,
        "licencia" => -1,
        "categoria" => -1,
        "atributo" => -1,
        "peso" => -1,
    ];

    /**
     * @param int        $id_proveedor   id_supplier en tu tabla import_proveedores (Abysse)
     * @param LoggerFrik $logger
     * @param string     $nombre_catalogo Nombre del CSV, p.ej. "ABY_13_01_2025.csv"
     */
    public function __construct($id_proveedor, LoggerFrik $logger, $nombre_catalogo)
    {
        $this->config = Db::getInstance()->getRow('
            SELECT * 
            FROM ' . _DB_PREFIX_ . 'import_proveedores 
            WHERE id_supplier = ' . (int) $id_proveedor
        );

        if (!$this->config) {
            throw new Exception('No se encontró configuración para el proveedor con ID ' . $id_proveedor);
        }

        $this->logger = $logger;
        $this->nombre_catalogo = $nombre_catalogo;

        // si te interesa asegurarte de que lleva extensión .csv:
        if (substr($this->nombre_catalogo, -4) !== '.csv') {
            $this->nombre_catalogo .= '.csv';
        }
    }

    /**
     * FETCH
     * En Abysse no descargamos nada: simplemente usamos el archivo ya subido al servidor.
     * Devuelve la ruta completa al archivo CSV o false si no existe.
     */
    public function fetch()
    {
        $ruta_archivo = $this->path_catalogos . $this->nombre_catalogo;

        if (!file_exists($ruta_archivo)) {
            $this->logger->log(
                'No se encontró el catálogo Abysse en ' . $ruta_archivo,
                'ERROR'
            );
            return false;
        }

        $this->logger->log(
            'Usando catálogo Abysse existente en ' . $ruta_archivo,
            'INFO'
        );

        return $ruta_archivo;
    }

    /**
     * Comprueba cabecera y rellena $indices en base a $mapa_campos
     */
    public function checkCatalogo($filename)
    {
        $handle = fopen($filename, 'r');
        if ($handle === false) {
            $this->logger->log(
                'Error abriendo archivo de catálogo Abysse: ' . $filename,
                'ERROR'
            );
            return false;
        }

        $cabecera = fgetcsv($handle, 0, ';');
        if (!$cabecera) {
            $this->logger->log(
                'No se pudo leer la cabecera del catálogo Abysse',
                'ERROR'
            );
            fclose($handle);
            return false;
        }

        // reiniciamos indices por si se reutiliza el objeto
        foreach ($this->indices as $campo => $_) {
            $this->indices[$campo] = -1;
        }

        foreach ($this->mapa_campos as $campo => $info) {
            $encontrado = false;

            foreach ($info['variantes'] as $nombre_posible) {
                foreach ($cabecera as $indice => $texto_cabecera) {
                    if ($this->normalizaTexto($texto_cabecera) === $this->normalizaTexto($nombre_posible)) {
                        $this->indices[$campo] = $indice;
                        $encontrado = true;
                        break 2; // salimos de los dos foreach
                    }
                }
            }

            if (!$encontrado && $info['obligatorio']) {
                $this->logger->log(
                    'Campo obligatorio no encontrado en catálogo Abysse: ' . $campo,
                    'ERROR'
                );
                fclose($handle);
                return false;
            }

            if (!$encontrado && !$info['obligatorio']) {
                $this->logger->log(
                    'Campo NO obligatorio no encontrado en catálogo Abysse: ' . $campo,
                    'INFO'
                );
            }
        }

        $this->logger->log(
            'Cabeceras catálogo Abysse OK: ' . json_encode($this->indices),
            'INFO'
        );

        fclose($handle);
        return true;
    }

    /**
     * PARSE
     * Recorre el CSV y genera el array normalizado de productos.
     */
    public function parse($filename)
    {
        // por si parse() se llama sin haber pasado por checkCatalogo()
        if ($this->indices['referencia'] < 0) {
            if (!$this->checkCatalogo($filename)) {
                return [];
            }
        }

        $handle = fopen($filename, 'r');
        if ($handle === false) {
            $this->logger->log(
                'No se pudo abrir el archivo Abysse para parsear: ' . $filename,
                'ERROR'
            );
            return [];
        }

        // saltamos cabecera
        fgetcsv($handle, 0, ';');

        $productos = [];

        while (($campos = fgetcsv($handle, 0, ';')) !== false) {
            // línea vacía
            if ($campos === [null] || $campos === false) {
                continue;
            }

            $producto = $this->normalizeRow($campos);
            if ($producto) {
                $productos[] = $producto;
            }
        }

        fclose($handle);

        $this->logger->log(
            'Parseados ' . count($productos) . ' productos de Abysse',
            'INFO'
        );

        return $productos;
    }

    /**
     * NORMALIZA UNA FILA DEL CSV AL FORMATO DEL IMPORTADOR
     */
    public function normalizeRow($campos)
    {
        $referencia = trim($this->getCampo($campos, 'referencia'));
        if (!$referencia) {
            return false;
        }

        $nombre = pSQL(trim($this->getCampo($campos, 'nombre')));
        if (!$nombre) {
            return false;
        }

        $ean = trim($this->getCampo($campos, 'ean'));

        // stock y disponibilidad
        // preg_replace('/[^\d-]/', '', $stock_raw) Elimina todo lo que no sea dígito o signo menos.
        $stock_raw = $this->getCampo($campos, 'stock');
        $stock_int = (int) preg_replace('/[^\d-]/', '', $stock_raw);
        // Si stock es menos de 10 consideramos que no tiene stock
        $disponibilidad = $stock_int > 9 ? 1 : 0;

        // precio: lo usamos como COSTE
        $precio_raw = $this->getCampo($campos, 'precio');
        $precio = (float) str_replace(',', '.', preg_replace('/[^\d,\.]/', '', $precio_raw));
        if (!$precio) {
            $precio = 0;
        }

        // pvp_sin_iva
        $pvp_sin_iva = 0;

        // peso: viene en gramos, lo pasamos a kg si existe; si no, fallback como en Heo
        $peso_raw = $this->getCampo($campos, 'peso');
        if ($peso_raw !== '') {
            $peso_g = (float) str_replace(',', '.', preg_replace('/[^\d,\.]/', '', $peso_raw));
            $peso = $peso_g > 0 ? round($peso_g / 1000, 3) : 0.444;
        } else {
            $peso = 0.444;
        }

        $categoria = trim($this->getCampo($campos, 'categoria'));

        //Abysse nos aplica un descuento del 4% a todos los productos, salvo a las alfombrillas de ratón que tienen 50%. Su categoria abysse es "Mousepad"     stripos es case insensitive
        //50% mousepads, 10% lo demás
        if (stripos($categoria, "mousepad") !== false) {
            $precio = $precio - ($precio * 0.5);
        } else {
            $precio = $precio - ($precio * 0.10);
        }

        //elcatálogo ya no lleva fabricante, pero aunque bajo diferentes nombres parece ser todo de Abystyle, id 32, lo forzamos pero no lo añadimos a la descripción
        // $fabricante = trim($this->getCampo($campos, 'fabricante'));
        $fabricante = 'Abystyle';
        $id_manufacturer = 32;

        $licencia = trim($this->getCampo($campos, 'licencia'));
        $atributo = trim($this->getCampo($campos, 'atributo'));

        // descripción sencilla + info para IA
        $descripcion = $nombre;

        $datos_extra = '';
        if ($licencia) {
            $datos_extra .= '<br>Licencia: ' . $licencia . '.';
        }
        if ($categoria) {
            $datos_extra .= '<br>Categoría: ' . $categoria . '.';
        }
        if ($atributo) {
            $datos_extra .= '<br>Atributo: ' . $atributo . '.';
        }
        // if ($fabricante) {
        //     $datos_extra .= '<br>Fabricante: ' . $fabricante . '.';
        // }

        $para_ia = '<br>Producto con licencia oficial.<br> Un artículo perfecto para un regalo original o para un capricho.';

        $descripcion = pSQL($descripcion . $datos_extra . $para_ia);

        // buscamos el fabricante por su nombre para ver si existe
        // $id_manufacturer = null;
        // if ($fabricante) {
        //     $id_manufacturer = $this->getManufacturerId($fabricante);
        // }

        // ===== URLS DE ABYSSE =====
        $url_imagen = 'http://emailing.abyssecorp.com/' . urlencode($referencia) . '.jpg';
        $url_producto = 'http://trade.abyssecorp.com/e/en/recherche'
            . '?controller=search&orderby=date_add&orderway=desc&search_query='
            . urlencode($referencia);

        // en Abysse de momento solo tenemos una imagen construida por referencia
        $imagenes = [$url_imagen];

        // de momento no usamos lógica especial de ignorar (como el packaging_quantity de Heo)
        $ignorar = 0;

        return [
            'referencia_proveedor' => $referencia,
            'url_proveedor' => $url_producto,
            'nombre' => $nombre,
            'ean' => $ean,
            'coste' => $precio,
            'pvp_sin_iva' => $pvp_sin_iva,
            'peso' => $peso,
            'disponibilidad' => $disponibilidad,
            'description_short' => $descripcion,
            'manufacturer_name' => $fabricante ?: null,
            'id_manufacturer' => $id_manufacturer,
            'imagenes' => $imagenes,
            'fuente' => $this->config['tipo'],
            'ignorar' => $ignorar,
        ];
    }

    /**
     * Devuelve el valor de un campo según los índices detectados
     */
    protected function getCampo($campos, $clave)
    {
        if (!isset($this->indices[$clave])) {
            return '';
        }

        $idx = $this->indices[$clave];

        if ($idx < 0 || !isset($campos[$idx])) {
            return '';
        }

        return $campos[$idx];
    }

    /**
     * Normaliza texto de cabeceras para comparar
     */
    protected function normalizaTexto($texto)
    {
        // elimina BOM si lo hay
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);

        // elimina espacios invisibles, tabulaciones, saltos
        $texto = preg_replace('/[\x00-\x1F\x7F]/u', '', $texto);

        // trim + minúsculas
        return mb_strtolower(trim($texto), 'UTF-8');
    }

    /**
     * Igual que en HeoReader: busca id_manufacturer por nombre
     */
    protected function getManufacturerId($nombre)
    {
        if (!$nombre) {
            return null;
        }

        $id = Db::getInstance()->getValue('
            SELECT id_manufacturer
            FROM ' . _DB_PREFIX_ . 'manufacturer
            WHERE LOWER(name) = "' . pSQL(strtolower($nombre)) . '"
        ');
        if ($id) {
            return (int) $id;
        }

        return null;
    }
}
