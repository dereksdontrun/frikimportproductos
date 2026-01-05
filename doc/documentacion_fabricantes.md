
# Sistema de gestión de fabricantes y alias  
_Módulo `frikimportproductos` – La Frikilería_

Este documento describe el flujo completo que se ha montado alrededor de los **fabricantes**:

- Alias de fabricantes y limpieza de duplicados.
- Pendientes de resolución manual.
- Integración con Amazon Catalog (SP-API) para completar fabricantes desconocidos.
- Herramientas de reintento y sincronización.

Está orientado a recordar **qué hace cada pieza**, **qué tablas intervienen** y **cómo se usa desde el BO y desde scripts**.

---

## 1. Objetivo general

1. **Normalizar fabricantes** aunque en catálogos vengan como:
   - `Noble Collection`, `The Noble Collection`, `Noble Collection, Inc.`
   - `Funko`, `Funko LLC`, `FUNKO (EU)`
2. **Evitar duplicados** en la tabla `lafrips_manufacturer`.
3. **Aprovechar Amazon** para descubrir fabricante (o marca) cuando en PrestaShop no lo tenemos claro.
4. Mantener todo **trazado** y revisable en el BO.

---

## 2. Tablas implicadas

### 2.1 `lafrips_manufacturer_alias`

Alias de fabricantes “normalizados”.

Campos relevantes (resumen):

- `id_manufacturer_alias` (PK)
- `id_manufacturer` – fabricante canónico de Presta.
- `alias` – nombre tal como viene del catálogo / Amazon.
- `normalized_alias` – alias normalizado (minúsculas, sin tildes, sin símbolos raros…).
- `source` – de dónde viene el alias (`HEO`, `AMAZON_MANUFACTURER`, `AMAZON_BRAND`, etc.).
- `auto_created` – `0` si ha sido revisado a mano; `1` si lo ha creado un proceso automático.
- `active` – para poder desactivar un alias sin borrarlo.
- `date_add`, `date_upd`.

> Uso: cuando se llama a `ManufacturerAliasHelper::resolveName()`, se busca aquí primero.

---

### 2.2 `lafrips_manufacturer_alias_pending`

Pendientes de resolución de fabricante.

Campos clave:

- `id_pending`
- `raw_name` – nombre original que no se ha podido resolver.
- `normalized_alias` – versión normalizada de `raw_name`.
- `source` – de dónde viene (`HEO`, `AMAZON_MANUFACTURER`, etc.).
- `times_seen` – cuántas veces se ha encontrado ese nombre y no se ha podido resolver.
- `id_manufacturer` – fabricante resuelto (cuando finalmente se casa).
- `resolved` – `0` pendiente, `1` ya resuelto (con alias o ignorado).
- `id_employee` – empleado que lo ha resuelto desde el BO (si aplica).
- `date_add`, `date_upd`.

Uso: cuando `ManufacturerAliasHelper::resolveName()` no encuentra match, registra / incrementa aquí.

---

### 2.3 `lafrips_manufacturer_amazon_lookup`

Lookup de Amazon por EAN para ayudar con fabricantes desconocidos.

Campos clave:

- Identificación:
  - `id_manufacturer_amazon_lookup` (PK)
  - `id_product`, `id_product_attribute`
  - `ean13`
  - `marketplace_id` (ej: `A1RKKUPIHCS9HS` España).
- Datos de producto:
  - `product_reference`, `product_name`
  - `id_manufacturer_current`, `manufacturer_current_name` (estado actual en PrestaShop).
- Datos de Amazon:
  - `asin`
  - `raw_manufacturer` (campo `manufacturer` en Amazon).
  - `raw_brand` (campo `brand` en Amazon).
  - `raw_response_json` (respuesta completa de Amazon).
- Resolución:
  - `id_manufacturer_resolved` – fabricante de Presta que hemos conseguido casar.
  - `resolved_from` – `manufacturer`, `brand`, `manual`, `none`.
  - `id_employee_resolved` – quién lo ha resuelto desde BO (para resoluciones manuales).
- Estado:
  - `status`:
    - `no_ean` – el producto no tenía EAN.
    - `not_found` – Amazon no devuelve resultado para ese EAN.
    - `resolved` – tenemos `id_manufacturer_resolved`.
    - `pending` – Amazon responde pero no hay match con Presta.
    - `error` – error técnico (HTTP, cURL, etc.).
    - `rate_limited` – Amazon devolvió 429 (demasiadas peticiones).
  - `error_message`.

Índice importante:

- `UNIQUE (id_product, id_product_attribute, marketplace_id)` – para hacer `INSERT ... ON DUPLICATE KEY` en el logger.

---

## 3. `ManufacturerAliasHelper`: el corazón de los alias

Ruta: `modules/frikimportproductos/classes/ManufacturerAliasHelper.php`

### 3.1 `normalizeName($name)`

Devuelve una versión “plana” del nombre:

- Minúsculas.
- Sin acentos.
- Sin símbolos raros, espacios extremos, etc.

Ejemplo: `" The Noble Collection® "` → `"the noble collection"`

Se usa en todas partes para comparar nombres “en serio”.

---

### 3.2 `createAlias($idManufacturer, $alias, $norm = null, $source = null, $autoCreated = 0)`

- Crea una fila en `lafrips_manufacturer_alias` si no existe ya.
- Si `$norm` viene `null`, llama internamente a `normalizeName($alias)`.
- Evita duplicados por (`id_manufacturer`, `normalized_alias`, `alias`).

Devuelve:

- `'created'` – insertado nuevo alias.
- `'exists'` – ya existía.
- `'invalid'` – alias vacío o `id_manufacturer` inválido.
- `'error'` – fallo SQL u otra excepción.

---

### 3.3 `resolveName($rawName, $source = null)`

Intenta resolver un nombre al `id_manufacturer` de Presta.

1. Normaliza: `norm = normalizeName($rawName)`.
2. Busca en `lafrips_manufacturer_alias` (por `normalized_alias` y/o `alias`).
3. Si no encuentra, compara con nombres de `lafrips_manufacturer` normalizados.
4. Si sigue sin match:
   - Registra / incrementa en `lafrips_manufacturer_alias_pending` (por `normalized_alias` + `source`).
   - Devuelve `null`.

Resultado:

- `id_manufacturer` si se ha podido casar.
- `null` si se ha añadido/actualizado a pending.

---

### 3.4 `fuzzySuggestManufacturers($rawName, $minRatio = 0.5)`

Ayuda para sugerencias:

- Carga caché de fabricantes (`loadManufacturersCache()`).
- Compara por distancia de Levenshtein / ratio.
- Lógica especial para:
  - Matches por prefijo (`funkoXXXX` → `funko`).
  - Evitar matches ridículos tipo `"dc"` en `"dccomics"`.
  - Nombres cortos con ratio más bajo.

Devuelve:

```php
[
  [
    'id_manufacturer' => 123,
    'name'            => 'Funko',
    'ratio'           => 0.92,
  ],
  ...
]
```

Se usa en:

- Scripts de diagnóstico (`init_aliases_from_inactive.php`).
- Columna “Sugerencia” en `AdminManufacturerAliasPending`.

---

## 4. BO: `AdminManufacturerAliasPendingController`

Controla la tabla `lafrips_manufacturer_alias_pending`.

### 4.1 Listado principal

Campos:

- `ID` (`id_pending`)
- `Nombre original` (`raw_name`)
- `Alias normalizado` (`normalized_alias`)
- `Origen` (`source`)
- `Veces visto` (`times_seen`)
- `Fabricante resuelto` (join con `manufacturer` via `id_manufacturer`)
- `Sugerencia` (callback con fuzzy)
- `Resuelto` (bool)
- `Fecha alta`, `Última actualización`

Acciones por fila:

- `edit` – abre formulario para resolver/ignorar.
- `Usar sugerencia` – crea alias con el fabricante sugerido y marca resuelto.
- `Crear fabricante` – crea un fabricante nuevo a partir de `raw_name` y marca resuelto.
- `delete`.

### 4.2 Formulario de edición

Campos:

- `raw_name`, `normalized_alias`, `source`, `times_seen` (readonly).
- Selector de “Fabricante canónico”.
- Switch “Ignorar sin crear alias”.

Al guardar:

- Si se marca “Ignorar”:
  - `resolved = 1`, `id_manufacturer = NULL`.
- Si se elige fabricante:
  - `createAlias(...)` con `raw_name`.
  - `resolved = 1`, `id_manufacturer` = seleccionado.
  - Guarda `id_employee` del usuario.

---

## 5. Scripts / herramientas sin Amazon

### 5.1 `init_aliases_from_inactive.php`

One-shot de limpieza inicial:

1. Agrupa `manufacturer` por nombre normalizado.
2. Casos:
   - 1 activo + varios inactivos:
     - Crea alias para cada inactivo apuntando al activo.
   - Solo inactivos (ningún activo):
     - No toca, muestra para tratar a mano.
   - Varios activos + inactivos:
     - No toca, casos conflictivos a mano.

Pensado para ejecutarse **una sola vez** al inicio de la limpieza.

---

### 5.2 Reintento de pendientes (`retry_pending_aliases`)

Ahora integrado como método estático en una clase tipo `ManufacturerMaintenanceTools::retryPendingAliases($limit, $logger)` y lanzable desde un botón en el BO.

Qué hace:

1. Selecciona `manufacturer_alias_pending` con `resolved = 0` (hasta `limit`).
2. Para cada fila:
   - Llama de nuevo a `ManufacturerAliasHelper::resolveName(raw_name, source)`.
   - Si ahora se resuelve:
     - Marca `resolved = 1`, `id_manufacturer` rellenado.
   - Si no:
     - Lo deja pendiente.

Uso típico:

- Tras crear nuevos alias o fabricantes, para que viejos pendientes se resuelvan solos.

---

## 6. Integración Amazon – `AmazonCatalogManufacturerResolver`

Ruta: `modules/frikimportproductos/classes/AmazonCatalogManufacturerResolver.php`

### 6.1 `resolveMissingManufacturers(...)`

Llamado desde un script tipo `resolve_missing_manufacturers_amazon.php`.

Parámetros:

- `$limit` – nº máximo de productos a procesar.
- `$dryRun` – si `true`, no escribe en BD.
- `$marketplaceId` – ej. `A1RKKUPIHCS9HS` (ES).
- `$idManufacturerFilter` – opcional para limitar a un fabricante concreto.
- `$logger`, `$csvLogger` – logging y CSV.

Flujo:

1. Selecciona productos (`product`) que:
   - Tienen EAN (`ean13 <> ""`).
   - No tienen registro aún en `lafrips_manufacturer_amazon_lookup` para ese marketplace.
   - Opcionalmente: pertenecen a `id_manufacturer` concreto.
2. Obtiene access token de Amazon una vez.
3. Para cada producto:
   - Llama a `fetchCatalogItemByEan(ean, marketplaceId, accessToken, logger)`.
   - Construye un `statusInfo` con:
     - `status` inicial (`not_found` si Amazon no devuelve nada).
     - `asin`, `raw_brand`, `raw_manufacturer`, `raw_json`.
   - Si hay `manufacturer`/`brand`:
     - Intenta resolver con `ManufacturerAliasHelper::resolveName`:
       - Primero `manufacturer` (`AMAZON_MANUFACTURER`).
       - Luego `brand` (`AMAZON_BRAND`).
     - Si resuelve: `status = 'resolved'`, `resolved_from = 'manufacturer'|'brand'`.
     - Si no: `status = 'pending'`.
   - Llama a `logLookup(...)` para insertar/actualizar fila en `lafrips_manufacturer_amazon_lookup` usando `INSERT ... ON DUPLICATE KEY`.

Nota: este proceso **no cambia el fabricante del producto**; solo llena la tabla de lookup.

---

## 7. BO Amazon – `AdminManufacturerAmazonLookupController`

Controla la tabla `lafrips_manufacturer_amazon_lookup`.

### 7.1 Listado

Columnas principales:

- `ID` (`id_manufacturer_amazon_lookup`)
- `ID Producto` (`id_product`) – con link a la ficha de producto.
- `Ref Presta` (`p.reference`)
- `Nombre Presta` (`pl.name`)
- `EAN`
- `Marketplace` – se muestra el `marketplace_id` y podría mapearse a ISO (`ES`, `FR`, etc.).
- `ASIN`
- `Brand (Amazon)`
- `Manufacturer (Amazon)`
- `Fabricante actual Presta`
- `Fabricante resuelto`
- `Estado` (`status`) – con etiquetas tipo `resolved`, `pending`, `not_found`, etc.
- `Origen resolución` (`resolved_from`)
- `Sincronizado` – columna calculada:

  - **No resuelto** – si no hay `id_manufacturer_resolved`.
  - **OK** – si `id_manufacturer_current == id_manufacturer_resolved`.
  - **Pendiente** – si hay `id_manufacturer_resolved` pero el producto tiene otro fabricante.

Acciones:

- `edit` – formulario para resolver manualmente y opcionalmente aplicar al producto.
- `delete`.
- Bulk actions:
  - `delete` – borrar filas seleccionadas.
  - `apply_resolved` – aplicar el fabricante resuelto a los productos seleccionados.

### 7.2 Bulk `apply_resolved`

`processBulkApply_resolved()`:

- Para cada fila seleccionada:
  - Requisitos:
    - `status = 'resolved'`
    - `id_product > 0`
    - `id_manufacturer_resolved > 0`
    - `id_manufacturer_current != id_manufacturer_resolved`
  - Actualiza:
    - `product.id_manufacturer = id_manufacturer_resolved`.
    - La propia fila lookup:
      - `id_manufacturer_current = id_manufacturer_resolved`
      - `manufacturer_current_name` actualizado.
      - `date_upd`.

Resultado: columna “Sincronizado” pasa a **OK**.

### 7.3 Formulario de edición manual

Readonly:

- `id_manufacturer_amazon_lookup`, `id_product`
- `product_reference`, `product_name`
- `ean13`, `marketplace_id`, `asin`
- `manufacturer_current_name`
- `raw_brand`, `raw_manufacturer`
- `employee_resolved_name` (si existe).

Editables:

- `status`
- `id_manufacturer_resolved`
- `resolved_from`
- `error_message`
- `apply_to_product` (Sí/No)

Al guardar:

1. Valida: si `apply_to_product = 1`, debe ser `status = 'resolved'` y `id_manufacturer_resolved > 0`.
2. Si `status = 'resolved'` y hay `id_manufacturer_resolved`:
   - Crea alias con `raw_manufacturer` (`source = AMAZON_MANUFACTURER`, `auto_created = 0`).  
   - Si `resolved_from` está vacío o es `none`, lo pone a `manual`.
3. Si `apply_to_product = 1`:
   - Actualiza `product.id_manufacturer`.
4. Actualiza fila lookup (incluyendo `id_employee_resolved` y `date_upd`).

---

## 8. Herramientas de reintento Amazon

Integradas en una clase de mantenimiento (p.ej. `ManufacturerMaintenanceTools`) y llamadas desde botones en el BO.

### 8.1 `retryAmazonLookupResolves()`

Objetivo: volver a intentar casar registros usando **sólo los datos de Amazon ya guardados**, sin llamar otra vez a la API.

Filtro típico:

- `status = 'pending'`
- `id_manufacturer_resolved IS NULL`
- `raw_manufacturer <> "" OR raw_brand <> ""`

Para cada fila:

1. Intenta `resolveName(raw_manufacturer, 'AMAZON_MANUFACTURER')`.
2. Si no, `resolveName(raw_brand, 'AMAZON_BRAND')`.
3. Si resuelve:
   - `status = 'resolved'`
   - `id_manufacturer_resolved`, `resolved_from`
   - Limpia `error_message`.

Uso típico:

- Después de añadir alias de Amazon manualmente.

### 8.2 `retryAmazonLookupApi()`

Objetivo: reconsultar la **API de Amazon** sólo para filas problemáticas.

Filtro típico:

- `status IN ('not_found', 'error', 'rate_limited')`
- `id_manufacturer_resolved IS NULL`

Flujo:

1. Obtiene access token.
2. Vuelve a consultar Amazon por EAN.
3. Si HTTP 429:
   - Marca `status = 'rate_limited'`, guarda mensaje.
   - Sale del bucle para dejar el resto para otra ejecución.
4. Si sin resultados:
   - `status = 'not_found'`.
5. Si con resultados:
   - Actualiza `asin`, `raw_brand`, `raw_manufacturer`, `raw_response_json`.
   - Vuelve a intentar resolver con alias helper.
   - Marca `status = 'resolved'` o `pending` según resultado.

---

## 9. Flujos de trabajo recomendados

### 9.1 Limpieza inicial de fabricantes

1. Ejecutar una vez `init_aliases_from_inactive.php`.
2. Revisar:
   - Fabricantes (limpiar duplicados).
   - Alias creados automáticamente.
3. Resolver manualmente casos dudosos en `Alias pending`.

### 9.2 Mantenimiento periódico de alias

Tras importar catálogos o crear alias:

1. Revisar `Alias pending` en BO.
2. Resolver a mano lo evidente.
3. Pulsar botón “Reintentar pendientes” (usa `retryPendingAliases`).

### 9.3 Uso de Amazon para completar fabricantes

1. Ejecutar `resolve_missing_manufacturers_amazon` con `dry_run=1` para ver el efecto.
2. Ejecutar de verdad con un `limit` prudente.
3. Ir a `Fabricantes > Amazon lookup`:
   - Revisar `status = 'resolved'` y qué fabricante propone.
4. Opciones:
   - Ajustar manualmente los casos especiales.
   - Hacer bulk “Aplicar resueltos” para sincronizar muchos productos.

### 9.4 Después de añadir alias o fabricantes nuevos

1. Botón en `Alias pending`: reintentar pendientes.
2. Botón en `Amazon lookup`: reintentar resolves (sin API).

### 9.5 Recuperar errores de Amazon / rate limit

1. Botón en `Amazon lookup`: reintentar con API (retry API) con un `limit` razonable.
2. Mirar el log para ver cuántos se han arreglado.

---

## 10. Cómo seguir el rastro de un producto concreto

1. Ver `product.id_manufacturer` y el nombre en `manufacturer`.
2. Buscar su EAN en `lafrips_manufacturer_amazon_lookup`:
   - Ver `status`, `id_manufacturer_resolved`, `resolved_from`, `raw_manufacturer`, `raw_brand`.
3. Mirar en `lafrips_manufacturer_alias` si hay alias asociados a ese fabricante.
4. Ver si su `raw_name` apareció alguna vez en `lafrips_manufacturer_alias_pending`.

Con todo esto puedes reconstruir **por qué** un producto tiene el fabricante que tiene, y qué decisiones (automáticas o manuales) lo llevaron hasta ahí.
