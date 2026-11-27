import { CapturaFormulario } from './camara.js';

let usuarioActual = null;
let eventSource = null;
let zoomNivel = 1;
let rotacionGrados = 0;

// ============================================================================
// INICIALIZACIÓN GLOBAL Y REGISTRO DE SERVICE WORKER (PWA)
// ============================================================================
document.addEventListener('DOMContentLoaded', async () => {
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js').catch((err) => console.warn('SW no registrado:', err));
  }

  configurarEventosPublicos();
  configurarNavegacion();
  configurarModuloEntrevista();
  configurarVisorSupervisor();

  await verificarSesionActiva();
});

async function apiFetch(url, opciones = {}) {
  const config = {
    credentials: 'same-origin',
    ...opciones
  };
  if (config.body && !(config.body instanceof FormData)) {
    config.headers = { 'Content-Type': 'application/json', ...(config.headers || {}) };
    config.body = JSON.stringify(config.body);
  }
  const resp = await fetch(url, config);
  const json = await resp.json();
  if (!resp.ok || !json.ok) {
    throw new Error(json.error || `Error HTTP ${resp.status}`);
  }
  return json.data;
}

function mostrarToast(mensaje) {
  const container = document.getElementById('toast-container');
  const el = document.createElement('div');
  el.className = 'toast';
  el.textContent = mensaje;
  container.appendChild(el);
  setTimeout(() => el.remove(), 7000);
}

// ============================================================================
// AUTENTICACIÓN Y GESTIÓN DE SESIÓN
// ============================================================================
async function verificarSesionActiva() {
  try {
    const data = await apiFetch('/api/auth/me');
    activarInterfazAutenticada(data.usuario);
  } catch {
    usuarioActual = null;
    document.getElementById('view-publico').classList.remove('hidden');
    document.getElementById('main-nav').classList.add('hidden');
  }
}

function activarInterfazAutenticada(usuario) {
  usuarioActual = usuario;
  document.getElementById('view-publico').classList.add('hidden');
  document.getElementById('main-nav').classList.remove('hidden');

  document.getElementById('user-controls').innerHTML = `
    <span style="font-size: 0.85rem; margin-right: 0.6rem;">${usuario.nombre_completo} (${usuario.rol})</span>
    <button type="button" id="btn-logout" class="btn btn-secondary btn-sm">Salir</button>
  `;
  document.getElementById('btn-logout').addEventListener('click', cerrarSesion);

  // Filtrar pestañas según rol RBAC
  document.querySelectorAll('#main-nav button[data-roles]').forEach((btn) => {
    const rolesPermitidos = btn.dataset.roles.split(',');
    btn.classList.toggle('hidden', !rolesPermitidos.includes(usuario.rol));
  });

  cambiarVista('view-stock');
  iniciarStreamTiempoReal();
}

async function cerrarSesion() {
  if (eventSource) {
    eventSource.close();
    eventSource = null;
  }
  await apiFetch('/api/auth/logout', { method: 'POST' });
  window.location.reload();
}

// ============================================================================
// PORTAL PÚBLICO (SOLICITUD DE FAMILIAR, SEGUIMIENTO Y LOGIN)
// ============================================================================
function configurarEventosPublicos() {
  const cardLogin = document.getElementById('card-login');
  document.getElementById('btn-mostrar-login')?.addEventListener('click', () => cardLogin.classList.remove('hidden'));
  document.getElementById('btn-cerrar-login')?.addEventListener('click', () => cardLogin.classList.add('hidden'));

  document.getElementById('form-login')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const data = await apiFetch('/api/auth/login', {
        method: 'POST',
        body: {
          email: document.getElementById('login-email').value,
          password: document.getElementById('login-pass').value
        }
      });
      activarInterfazAutenticada(data.usuario);
    } catch (err) {
      alert(err.message);
    }
  });

  document.getElementById('form-solicitud-publica')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    try {
      const data = await apiFetch('/api/solicitudes', {
        method: 'POST',
        body: formData
      });
      document.getElementById('resultado-solicitud-publica').innerHTML = `
        <div class="card" style="background: #f0fdf4; border-color: #86efac;">
          <strong>Solicitud Registrada:</strong> Código <code>${data.codigo_solicitud}</code><br/>
          Unidades compatibles en stock: <strong>${data.stock_compatible_disponible}</strong> de ${data.unidades_requeridas} requeridas.
        </div>
      `;
      e.target.reset();
    } catch (err) {
      alert(err.message);
    }
  });

  document.getElementById('form-buscar-codigo')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const codigo = document.getElementById('input-codigo-solicitud').value.trim();
    try {
      const data = await apiFetch(`/api/solicitudes/${encodeURIComponent(codigo)}`);
      const s = data.solicitud;
      document.getElementById('detalle-codigo-solicitud').innerHTML = `
        <div class="card" style="background: #f8fafc;">
          <span class="badge badge-${s.estado}">${s.estado}</span>
          <h4>${s.paciente_nombres} ${s.paciente_apellidos} (${s.paciente_grupo})</h4>
          <p><strong>Hospital:</strong> ${s.hospital_clinica}<br/>
          <strong>Progreso:</strong> ${s.unidades_cubiertas} / ${s.unidades_requeridas} unidades cubiertas<br/>
          <strong>Donantes confirmados en camino:</strong> ${data.donantes_confirmados}</p>
        </div>
      `;
    } catch (err) {
      alert(err.message);
    }
  });
}

// ============================================================================
// NAVEGACIÓN DE VISTAS INTERNAS
// ============================================================================
function configurarNavegacion() {
  document.querySelectorAll('#main-nav button').forEach((btn) => {
    btn.addEventListener('click', () => cambiarVista(btn.dataset.view));
  });

  document.getElementById('btn-recargar-stock')?.addEventListener('click', cargarStock);
  document.getElementById('btn-buscar-donantes')?.addEventListener('click', cargarDonantes);
  document.getElementById('btn-recargar-pendientes')?.addEventListener('click', cargarEntrevistasPendientes);
  document.getElementById('btn-recargar-solicitudes')?.addEventListener('click', cargarSolicitudesActivas);

  document.getElementById('form-nuevo-donante')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const payload = Object.fromEntries(new FormData(e.target).entries());
    try {
      await apiFetch('/api/donantes', { method: 'POST', body: payload });
      alert('Donante registrado correctamente.');
      e.target.reset();
      cargarDonantes();
    } catch (err) {
      alert(err.message);
    }
  });
}

function cambiarVista(viewId) {
  document.querySelectorAll('.app-view').forEach((sec) => sec.classList.add('hidden'));
  document.querySelectorAll('#main-nav button').forEach((b) => b.classList.remove('active'));

  document.getElementById(viewId)?.classList.remove('hidden');
  document.querySelector(`#main-nav button[data-view="${viewId}"]`)?.classList.add('active');

  if (viewId === 'view-stock') cargarStock();
  if (viewId === 'view-donantes') cargarDonantes();
  if (viewId === 'view-supervisor') cargarEntrevistasPendientes();
  if (viewId === 'view-solicitudes') cargarSolicitudesActivas();
  if (viewId === 'view-laboratorio') cargarCuarentena();
}

// ============================================================================
// CARGA DE DATOS POR MÓDULO
// ============================================================================
async function cargarStock() {
  const data = await apiFetch('/api/inventario/stock');
  const tbody = document.querySelector('#tabla-stock tbody');
  tbody.innerHTML = data.matriz_stock.map((r) => `
    <tr>
      <td><strong>${r.grupo_sanguineo}</strong></td>
      <td>${r.tipo_hemocomponente}</td>
      <td><span class="badge badge-DISPONIBLE">${r.disponibles}</span></td>
      <td><span class="badge badge-CUARENTENA">${r.en_cuarentena}</span></td>
      <td><span class="badge badge-RESERVADA">${r.reservadas}</span></td>
    </tr>
  `).join('') || '<tr><td colspan="5">Sin unidades registradas en inventario.</td></tr>';

  const tbodyFefo = document.querySelector('#tabla-fefo tbody');
  tbodyFefo.innerHTML = data.proximas_a_caducar.map((u) => `
    <tr>
      <td><code>${u.codigo_unidad}</code></td>
      <td><strong>${u.grupo_sanguineo}</strong></td>
      <td>${u.tipo_hemocomponente}</td>
      <td>${u.ubicacion_equipo}</td>
      <td>${u.fecha_caducidad} (${u.horas_restantes}h)</td>
      <td><a href="/api/inventario/${u.id}/etiqueta" target="_blank" class="btn btn-secondary btn-sm">PDF</a></td>
    </tr>
  `).join('') || '<tr><td colspan="6">No hay unidades próximas a vencer en los próximos 5 días.</td></tr>';
}

async function cargarDonantes() {
  const q = document.getElementById('filtro-donante-q')?.value.trim() || '';
  const lista = await apiFetch(`/api/donantes?q=${encodeURIComponent(q)}`);
  const tbody = document.querySelector('#tabla-donantes tbody');
  tbody.innerHTML = lista.map((d) => `
    <tr>
      <td>${d.documento_identidad}</td>
      <td>${d.nombres} ${d.apellidos} (${d.edad}a)</td>
      <td><strong>${d.grupo_sanguineo || '-'}</strong></td>
      <td><span class="badge badge-${d.estado}">${d.estado}</span></td>
      <td><a href="/api/donantes/${d.id}/kardex-pdf" target="_blank" class="btn btn-secondary btn-sm">Kardex PDF</a></td>
    </tr>
  `).join('');
}

function configurarModuloEntrevista() {
  const captura = new CapturaFormulario();
  captura.inicializar({
    form: document.getElementById('form-captura-entrevista'),
    inputAnverso: document.getElementById('input-foto-anverso'),
    previewAnverso: document.getElementById('preview-foto-anverso'),
    inputReverso: document.getElementById('input-foto-reverso'),
    previewReverso: document.getElementById('preview-foto-reverso'),
    btnEnviar: document.getElementById('btn-enviar-entrevista')
  });

  document.getElementById('btn-entrevista-buscar-ci')?.addEventListener('click', async () => {
    const ci = document.getElementById('entrevista-buscar-ci').value.trim();
    if (!ci) return;
    try {
      const d = await apiFetch(`/api/donantes/ci/${encodeURIComponent(ci)}`);
      document.getElementById('entrevista-donante-id').value = d.id;
      document.getElementById('info-donante-entrevista').innerHTML =
        `Donante: ${d.nombres} ${d.apellidos} | Estado: <span class="badge badge-${d.estado}">${d.estado}</span>`;
    } catch (err) {
      alert(err.message);
    }
  });
}

async function cargarEntrevistasPendientes() {
  const lista = await apiFetch('/api/entrevistas/pendientes');
  const tbody = document.querySelector('#tabla-pendientes tbody');
  tbody.innerHTML = lista.map((e) => `
    <tr>
      <td>${e.fecha_entrevista}</td>
      <td>${e.nombres} ${e.apellidos} (CI: ${e.documento_identidad})</td>
      <td>${e.peso_registrado_kg}kg | PA: ${e.presion_arterial} | Hb: ${e.hemoglobina_g_dl}</td>
      <td>${e.tipo_vinculo}</td>
      <td><button type="button" class="btn btn-primary btn-sm btn-revisar-ent" data-id="${e.id}" data-nombre="${e.nombres} ${e.apellidos}" data-reverso="${e.tiene_reverso}">Revisar</button></td>
    </tr>
  `).join('') || '<tr><td colspan="5">No hay entrevistas pendientes de revisión.</td></tr>';

  tbody.querySelectorAll('.btn-revisar-ent').forEach((btn) => {
    btn.addEventListener('click', () => abrirVisorSupervisor(btn.dataset));
  });
}

function configurarVisorSupervisor() {
  const aplicarTransformacion = () => {
    const t = `scale(${zoomNivel}) rotate(${rotacionGrados}deg)`;
    document.getElementById('visor-img-anverso').style.transform = t;
    document.getElementById('visor-img-reverso').style.transform = t;
  };

  document.getElementById('btn-zoom-in')?.addEventListener('click', () => { zoomNivel += 0.25; aplicarTransformacion(); });
  document.getElementById('btn-zoom-out')?.addEventListener('click', () => { zoomNivel = Math.max(0.5, zoomNivel - 0.25); aplicarTransformacion(); });
  document.getElementById('btn-rotar-img')?.addEventListener('click', () => { rotacionGrados = (rotacionGrados + 90) % 360; aplicarTransformacion(); });
  document.getElementById('btn-cerrar-visor')?.addEventListener('click', () => document.getElementById('panel-visor-supervisor').classList.add('hidden'));

  document.getElementById('form-dictamen-supervisor')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = document.getElementById('eval-entrevista-id').value;
    const aprobada = document.getElementById('eval-aprobada').value === 'true';

    try {
      await apiFetch(`/api/entrevistas/${id}/evaluar`, {
        method: 'PUT',
        body: {
          aprobada,
          nuevo_estado_donante: document.getElementById('eval-estado-rechazo').value,
          fecha_fin_estimada_pausa: document.getElementById('eval-fecha-pausa').value || null,
          motivo_rechazo: document.getElementById('eval-motivo').value
        }
      });
      alert('Dictamen médico registrado en el Kardex.');
      document.getElementById('panel-visor-supervisor').classList.add('hidden');
      cargarEntrevistasPendientes();
    } catch (err) {
      alert(err.message);
    }
  });
}

function abrirVisorSupervisor(meta) {
  zoomNivel = 1;
  rotacionGrados = 0;
  document.getElementById('eval-entrevista-id').value = meta.id;
  document.getElementById('visor-datos-cabecera').textContent = `Revisando formulario de: ${meta.nombre}`;
  document.getElementById('visor-img-anverso').src = `/api/entrevistas/${meta.id}/foto/anverso`;

  const imgRev = document.getElementById('visor-img-reverso');
  if (meta.reverso === '1') {
    imgRev.src = `/api/entrevistas/${meta.id}/foto/reverso`;
    imgRev.classList.remove('hidden');
  } else {
    imgRev.src = '';
    imgRev.classList.add('hidden');
  }

  document.getElementById('panel-visor-supervisor').classList.remove('hidden');
}

async function cargarSolicitudesActivas() {
  const lista = await apiFetch('/api/solicitudes');
  const tbody = document.querySelector('#tabla-solicitudes tbody');
  tbody.innerHTML = lista.map((s) => `
    <tr>
      <td><code>${s.codigo_solicitud}</code></td>
      <td><span class="badge badge-${s.nivel_urgencia}">${s.nivel_urgencia}</span></td>
      <td>${s.paciente_nombres} ${s.paciente_apellidos} (<strong>${s.paciente_grupo}</strong>)</td>
      <td>${s.unidades_cubiertas}/${s.unidades_requeridas} ${s.tipo_hemocomponente}</td>
      <td>${s.hospital_clinica}</td>
      <td>
        <button type="button" class="btn btn-primary btn-sm btn-alertar" data-id="${s.id}">Alertar Donantes</button>
        <button type="button" class="btn btn-success btn-sm btn-reservar" data-id="${s.id}">Reservar FEFO</button>
      </td>
    </tr>
  `).join('') || '<tr><td colspan="6">No hay solicitudes activas.</td></tr>';

  tbody.querySelectorAll('.btn-alertar').forEach((btn) => {
    btn.addEventListener('click', async () => {
      try {
        const res = await apiFetch(`/api/alertas/disparar/${btn.dataset.id}`, { method: 'POST', body: {} });
        alert(res.mensaje);
      } catch (err) {
        alert(err.message);
      }
    });
  });

  tbody.querySelectorAll('.btn-reservar').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (!confirm('¿Confirma prueba cruzada compatible para reservar 1 unidad bajo regla FEFO?')) return;
      try {
        const res = await apiFetch('/api/inventario/reservar', {
          method: 'POST',
          body: { solicitud_id: btn.dataset.id, cantidad: 1, prueba_cruzada_compatible: true }
        });
        alert(res.mensaje);
        cargarStock();
      } catch (err) {
        alert(err.message);
      }
    });
  });
}

async function cargarCuarentena() {
  const lista = await apiFetch('/api/laboratorio/cuarentena');
  const tbody = document.querySelector('#tabla-cuarentena tbody');
  tbody.innerHTML = lista.map((c) => `
    <tr>
      <td><strong>${c.codigo_bolsa_madre}</strong></td>
      <td>${c.fecha_extraccion}</td>
      <td>${c.detalle_unidades}</td>
      <td>${c.grupo_preliminar || 'Por confirmar'}</td>
      <td><button type="button" class="btn btn-success btn-sm btn-liberar-apto" data-id="${c.donacion_id}" data-grupo="${c.grupo_preliminar || 'O_POS'}">Validar Negativo (Apto)</button></td>
    </tr>
  `).join('') || '<tr><td colspan="5">No hay unidades en cuarentena pendientes de tamizaje.</td></tr>';

  tbody.querySelectorAll('.btn-liberar-apto').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (!confirm('¿Confirmar todos los marcadores infecciosos NO REACTIVOS y liberar unidades a DISPONIBLE?')) return;
      try {
        const res = await apiFetch('/api/laboratorio/tamizaje', {
          method: 'POST',
          body: {
            donacion_id: btn.dataset.id,
            grupo_confirmado: btn.dataset.grupo,
            anticuerpos_irregulares: false,
            vih_reactivo: false,
            hepatitis_b_reactivo: false,
            hepatitis_c_reactivo: false,
            chagas_reactivo: false,
            sifilis_reactivo: false
          }
        });
        alert(res.mensaje);
        cargarCuarentena();
      } catch (err) {
        alert(err.message);
      }
    });
  });
}

// ============================================================================
// STREAMING DE EVENTOS EN TIEMPO REAL (SSE)
// ============================================================================
function iniciarStreamTiempoReal() {
  if (eventSource) eventSource.close();
  eventSource = new EventSource('/api/stream');

  eventSource.addEventListener('alerta_aceptada', (ev) => {
    const items = JSON.parse(ev.data);
    items.forEach((a) => {
      mostrarToast(`Donante ${a.nombres} ${a.apellidos} (${a.grupo_sanguineo}) aceptó la solicitud ${a.codigo_solicitud}.`);
    });
  });

  eventSource.addEventListener('nueva_solicitud', (ev) => {
    const items = JSON.parse(ev.data);
    items.forEach((s) => {
      mostrarToast(`Nueva solicitud [${s.nivel_urgencia}]: ${s.unidades_requeridas}x ${s.tipo_hemocomponente} (${s.grupo_sanguineo}).`);
    });
  });
}