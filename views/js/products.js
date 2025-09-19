
document.addEventListener('DOMContentLoaded', () => {
  //carga inicial al entrar en el módulo, se cargarán y mostrarán por defecto los últimos 100 productos en entrar en la tabla
  const data = window.importProductos.initialData || [];
  const cont = document.getElementById('resultados');
  const total = document.getElementById('totalResultados');
  const overlay = document.getElementById('loading-overlay');

  renderProductos(data);

  // evento buscar
  if (btnBuscar) {
    btnBuscar.addEventListener('click', () => {     

      const form = document.getElementById('formFiltros');

      //no queremos lanzar el formualrio como si fuera un submit porque estamos utilizando javascript y fetch. Cuando se pulsa el botón no hay problema, pero si se pulsa enter, se lanza por defecto el formulario, y se carga otro controlador etc, así que, una vez recogidos los valores del formulario , lo "bloqueamos"
      if (form) {
        form.addEventListener('submit', (e) => {
          e.preventDefault(); // bloquear submit normal
          btnBuscar.click();  // simular clic en el botón Buscar
        });
      }

      const formData = new FormData(form);
      formData.append('action', 'BuscarProductos'); //añadir action al body

      mostrarOverlay();

      //el contenido de window.importProductos se establece en el tpl, en el script del final, de ahí sacamos la url del controlador
      fetch(window.importProductos.controllerUrl, {
        method: 'POST',
        body: formData
      })
        .then(res => res.json())
        .then(data => {
          renderProductos(data);
        })
        .catch(err => {
          console.error('Error en búsqueda:', err);
          resultados.innerHTML = '<p>Error al cargar productos.</p>';
        })
        .finally(() => {
          ocultarOverlay();
        });
    });
  }

  // función para renderizar productos 
  function renderProductos(data) {
    if (!data || data.length === 0) {
      cont.innerHTML = '<p>No hay productos para mostrar.</p>';
      total.textContent = "0";
      return;
    }

    total.textContent = data.length;

    // <th class="col-checkbox"><input type="checkbox" id="checkAll"></th>
    let html = `
      <table class="productos-tabla">
        <thead>
          <tr>          
            <th class="col-checkbox">
              <button type="button" id="checkAll" class="btn btn-default btn-sm">
                Check
              </button>
              <button type="button" id="bulkEncolar" class="btn btn-default btn-sm">Encolar seleccionados</button>              
            </th>          
            <th class="col-carousel">Imagen</th>
            <th class="col-nombre">Nombre</th>
            <th class="col-proveedor">Proveedor</th>
            <th class="col-referencia">Referencia</th>
            <th class="col-ean">EAN</th>
            <th class="col-descripcion">Descripción</th>
            <th class="col-manufacturer">Fabricante</th>
            <th class="col-coste">Coste</th>
            <th class="col-disponibilidad">Disponibilidad</th>
            <th class="col-coincidencias">Coincidencias</th>
            <th class="col-otros_proveedores">Otros</th>
            <th class="col-estado">Estado</th>
            <th class="col-last_update">Última actualización</th>
            <th class="col-acciones">Acciones</th>
          </tr>
        </thead>
        <tbody>
    `;

    data.forEach((p, idx) => {
      let otras = [];
      try {
        otras = JSON.parse(p.otras_imagenes || "[]");
      } catch (e) {
        otras = [];
      }

      // Construir array de imágenes eliminando duplicados (ej. principal repetida en otras_imagenes)
      const imagenes = [p.imagen_principal, ...otras]
        .filter(url => url && url.trim() !== "")
        .filter((url, idx, arr) => arr.indexOf(url) === idx); // elimina duplicados exactos

      // carrusel
      let carrusel = '';
      if (imagenes.length > 0) {
        carrusel = `
          <div class="carousel" data-idx="${idx}">
            ${imagenes.map((url, i) => `
              <img src="${url}" class="carousel-img ${i === 0 ? 'active' : ''}">
            `).join('')}
            ${imagenes.length > 1 ? `
              <button class="prev">&lt;</button>
              <button class="next">&gt;</button>
            ` : ''}
          </div>
        `;
      }

      html += `
        <tr class="producto-row">
          <td class="col-checkbox"> 
            <input 
              type="checkbox" 
              class="check-producto" 
              data-id="${p.id_productos_proveedores}"
              ${['pendiente'].includes(p.estado.toLowerCase()) ? '' : 'disabled'}
            >
          </td>
          <td class="col-carousel">${carrusel}</td>
          <td class="col-nombre">${p.nombre}</td>
          <td class="col-proveedor"><a href="${p.url_proveedor}" target="_blank">${p.supplier || ''}</a></td>
          <td class="col-referencia">${p.referencia_proveedor}</td>
          <td class="col-ean">${p.ean}</td>
          <td class="col-descripcion"><div class="div-col-descripcion">${p.description_short || ''}</div></td>
          <td class="col-manufacturer">${p.manufacturer || ''}</td>
          <td class="col-coste">${isNaN(p.coste) ? '0.00' : Number(p.coste).toFixed(2)} €</td>
          <td class="col-disponibilidad">${Number(p.disponibilidad) === 1 ? 'Sí' : 'No'}</td>
          <td class="col-coincidencias">${p.coincidencias}</td>
          <td class="col-otros_proveedores">${p.otros_proveedores}</td>
          <td class="col-estado">${p.estado}</td>
          <td class="col-last_update">${p.last_update_info}</td>
          <td class="col-acciones">
            <div class="dropdown">
              <button class="dropdown-btn">Acciones</button>
              <div class="dropdown-content">
                ${p.estado.toLowerCase() === 'creado' ? '' : `
                  <button class="accion-crear" data-id="${p.id_productos_proveedores}">Crear</button>
                `}
                ${p.estado.toLowerCase() === 'ignorado' || p.estado.toLowerCase() === 'creado' ? '' : (
          p.estado.toLowerCase() === 'encolado'
            ? `<button class="accion-desencolar" data-id="${p.id_productos_proveedores}">Desencolar</button>`
            : `<button class="accion-encolar" data-id="${p.id_productos_proveedores}">Encolar</button>`
        )}
                ${p.estado.toLowerCase() === 'ignorado' ? '' : `
                  <button class="accion-ignorar" data-id="${p.id_productos_proveedores}">Ignorar</button>
                `}
              </div>
            </div>
          </td>
        </tr>
      `;
    });

    html += "</tbody></table>";
    cont.innerHTML = html;


    // === CARRUSEL ===
    document.querySelectorAll('.carousel').forEach(carousel => {
      const imgs = carousel.querySelectorAll('.carousel-img');
      let current = 0;

      const showImage = idx => {
        imgs.forEach((img, i) => img.classList.toggle('active', i === idx));
      };

      const prev = carousel.querySelector('.prev');
      const next = carousel.querySelector('.next');

      if (prev) {
        prev.addEventListener('click', () => {
          current = (current - 1 + imgs.length) % imgs.length;
          showImage(current);
        });
      }
      if (next) {
        next.addEventListener('click', () => {
          current = (current + 1) % imgs.length;
          showImage(current);
        });
      }
    });
  } //Fin renderizar productos

  function mostrarOverlay() {
    overlay.style.display = 'flex';
  }

  function ocultarOverlay() {
    overlay.style.display = 'none';
  }

  // Listener global para acciones
  document.addEventListener('click', e => {

    // Acciones botón de producto
    const acciones = {
      'accion-ignorar': {
        action: 'IgnorarProducto',
        confirm: '¿Seguro que quieres marcar este producto como IGNORADO?',
        success: 'Producto marcado como ignorado',
        error: 'Error al ignorar',
        nuevoEstado: 'Ignorado'
      },
      'accion-encolar': {
        action: 'EncolarProducto',
        confirm: '¿Quieres ENCOLAR este producto para creación?',
        success: 'Producto encolado para creación',
        error: 'Error al encolar',
        nuevoEstado: 'Encolado'
      },
      'accion-desencolar': {
        action: 'DesencolarProducto',
        confirm: '¿Quieres DESENCOLAR este producto?',
        success: 'Producto desencolado',
        error: 'Error al desencolar',
        nuevoEstado: 'Pendiente'
      },
      'accion-crear': {
        action: 'CrearProducto',
        confirm: '¿Quieres CREAR este producto ahora?',
        success: 'Producto creado correctamente',
        error: 'Error al crear',
        nuevoEstado: 'Creado'
      }
    };

    // comprobar si el clic coincide con alguna acción del botón de acciones
    const clase = Object.keys(acciones).find(c => e.target.classList.contains(c));

    if (clase) {
      e.preventDefault();
      const conf = acciones[clase];
      const id = e.target.dataset.id;

      if (!confirm(conf.confirm)) return;

      mostrarOverlay();

      fetch(window.importProductos.controllerUrl + '&action=' + conf.action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + encodeURIComponent(id)
      })
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            alert(conf.success);
            //actualizamos el estado del producto en el front y deshabilitamos el botón que se ha utilizado para no poder repetir
            //con esto podríamos desactivar todo el botónero
            // fila.querySelectorAll('.dropdown-content button').forEach(btn => btn.disabled = true);
            // localizar la fila del producto
            const fila = e.target.closest('tr');
            if (fila) {
              // actualizar estado según acción            
              fila.querySelector('.col-estado').textContent = conf.nuevoEstado;

              // alternar encolar <-> desencolar
              if (clase === 'accion-encolar') {
                e.target.textContent = 'Desencolar';
                e.target.classList.remove('accion-encolar');
                e.target.classList.add('accion-desencolar');
              } else if (clase === 'accion-desencolar') {
                e.target.textContent = 'Encolar';
                e.target.classList.remove('accion-desencolar');
                e.target.classList.add('accion-encolar');
              } else {
                // para ignorar/crear se bloquea el botón
                e.target.disabled = true;
              }

              // habilitar/deshabilitar checkbox según estado
              const estado = conf.nuevoEstado.toLowerCase();
              const chk = fila.querySelector('.check-producto');

              if (chk) {
                if (['ignorado', 'creado', 'encolado'].includes(estado)) {
                  // bloquear el check
                  chk.disabled = true;
                  chk.checked = false; // opcional: desmarcarlo si estaba marcado
                } else if (estado === 'pendiente') {
                  // volver a habilitar
                  chk.disabled = false;
                }
              }
            }
          } else {
            alert(conf.error + ': ' + res.message);
          }
        })
        .catch(err => console.error('Error en ' + conf.action + ':', err))
        .finally(() => {
          ocultarOverlay();
        });

      return;
    }

    // === CHECK ALL ===
    if (e.target && e.target.id === 'checkAll') {
      const checkboxes = document.querySelectorAll('.check-producto:not(:disabled)');
      const allChecked = Array.from(checkboxes).every(chk => chk.checked);

      checkboxes.forEach(chk => {
        chk.checked = !allChecked;
      });

      // Cambiar texto del botón
      e.target.textContent = allChecked ? 'Check' : 'Uncheck';
    }

    // === BULK ENCOLAR ===
    if (e.target && e.target.id === 'bulkEncolar') {
      bulkAction(
        'BulkEncolarProductos',
        '¿Quieres encolar %count% productos seleccionados?',
        'Se han encolado %count% productos',
        'Error al encolar'
      );
    }

  });



  // document.getElementById('bulkDesencolar')?.addEventListener('click', () => {
  //   bulkAction(
  //     'BulkDesencolarProductos',
  //     '¿Quieres desencolar %count% productos seleccionados?',
  //     'Se han desencolado %count% productos',
  //     'Error al desencolar'
  //   );
  // });

  function bulkAction(action, confirmMsg, successMsg, errorMsg) {
    const ids = Array.from(document.querySelectorAll('.check-producto:checked'))
      .map(chk => chk.dataset.id);

    if (ids.length === 0) {
      alert('No hay productos seleccionados.');
      return;
    }

    if (!confirm(confirmMsg.replace('%count%', ids.length))) return;

    mostrarOverlay();

    fetch(window.importProductos.controllerUrl + '&action=' + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'ids=' + encodeURIComponent(JSON.stringify(ids))
    })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          alert(successMsg.replace('%count%', res.count));
          document.getElementById('btnBuscar').click(); // refresca resultados
        } else {
          alert(errorMsg + ': ' + res.message);
        }
      })
      .catch(err => console.error('Error en ' + action + ':', err))
      .finally(() => {
        ocultarOverlay();
      });
  }

});
