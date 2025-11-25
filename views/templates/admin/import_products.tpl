<div id="filtros-panel" class="panel filtros-sticky">
  <h3><i class="icon-filter"></i> Filtros de búsqueda</h3>
  <form id="formFiltros" class="form-grid">

    <!-- Proveedor y fabricante -->
    <div class="form-group proveedor_fabricante">
      <label for="id_supplier">Proveedor:</label>
      <select name="id_supplier" id="id_supplier" class="form-control input-sm">
        <option value="">Todos</option>
        {foreach from=$proveedores item=prov}
          <option value="{$prov.id_supplier}">{$prov.name}</option>
        {/foreach}
      </select>

      <br><br>

      <label for="id_manufacturer">Fabricante:</label>
      <select name="id_manufacturer" id="id_manufacturer" class="form-control input-sm">
        <option value="">Todos</option>
        {foreach from=$fabricantes item=fab}
          <option value="{$fab.id_manufacturer}">{$fab.name}</option>
        {/foreach}
      </select>
    </div>

    <!-- Switches -->
    <div class="form-group switches">
      <label>Productos existentes:</label>
      <span class="switch prestashop-switch">
        <input type="radio" name="ocultar_existentes" id="exist_on" value="1">
        <label for="exist_on">Ocultar</label>
        <input type="radio" name="ocultar_existentes" id="exist_off" value="0" checked>
        <label for="exist_off">Mostrar</label>
        <a class="slide-button btn"></a>
      </span>

      <br><br>

      <label>Productos no disponibles:</label>
      <span class="switch prestashop-switch">
        <input type="radio" name="ocultar_no_disponibles" id="disp_on" value="1">
        <label for="disp_on">Ocultar</label>
        <input type="radio" name="ocultar_no_disponibles" id="disp_off" value="0" checked>
        <label for="disp_off">Mostrar</label>
        <a class="slide-button btn"></a>
      </span>
    </div>

    <!-- Ordenar por coste, y aplicar rango de coste -->
    <div class="form-group coste_rango">
      <label>Rango de coste (€)</label>
      <div style="display:flex; gap:5px;">
        <input type="number" name="coste_min" class="form-control input-sm" placeholder="mín" step="0.01" min="0">
        <input type="number" name="coste_max" class="form-control input-sm" placeholder="máx" step="0.01" min="0">
      </div>
    
      <br><br>

      <label>Ordenar por coste</label>
      <select name="orden_coste" class="form-control input-sm">
        <option value="">-- Sin orden --</option>
        <option value="asc">Menor a mayor</option>
        <option value="desc">Mayor a menor</option>
      </select>
    </div>


    <!-- Estado y select numero-->
    <div class="form-group estado_mostrar">
      <label for="estado">Estado productos:</label>
      <select name="estado" id="estado" class="form-control input-sm">
        <option value="">Todos</option>
        {foreach from=$estados item=est}
          <option value="{$est.estado}">{$est.estado|ucfirst}</option>
        {/foreach}
      </select>

      <br><br>

      <label for="limit">Mostrar:</label>
      <select name="limit" id="limit" class="form-control input-sm">
        <option value="30">30</option>
        <option value="50">50</option>
        <option value="100" selected>100</option>
        <option value="200">200</option>
        <option value="500">500</option>
        <option value="0">Todos</option>
      </select>
    </div>

    <!-- Botón buscar -->
    <div class="form-group busqueda_buscar">
      <label for="search">Buscar:</label>
      <input type="text" name="search" id="search" class="form-control input-sm"
        placeholder="Nombre, ref, EAN o descripción">

      <br><br>

      <button type="button" id="btnBuscar" class="btn btn-primary">
        <i class="icon-search"></i> Buscar
      </button>
    </div>

  </form>
</div>

<!-- Resultados -->
<div class="panel">
  <h3>
    <i class="icon-list"></i> Resultados
    <span id="totalResultados" class="badge">0</span>
  </h3>
  <div id="resultados"></div>
</div>

<!-- overlay para mostrar pantalla de carga mientras se trae la info al front -->
<div id="loading-overlay" class="loading-overlay">
  <div class="spinner"></div>
  <p>Procesando...</p>
</div>

{* Este script estable que en la carga inicial del módulo, al cargar el formulario de este tpl, se hará por defecto una llamada al controlador para obtener los últimos 100 productos en entrar en la tabla  *}
<script>
  window.importProductos = {
    token: '{$token|escape:'htmlall':'UTF-8'}',
    controllerUrl: 'index.php?controller=AdminImportExternalProducts&ajax=1&token={$token|escape:'htmlall':'UTF-8'}',
    initialData: {$productos|@json_encode nofilter}
  };
</script>