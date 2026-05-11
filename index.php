<?php
session_start();

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    header('Location: login.php');
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$BACKEND_URL = 'http://localhost:5000';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ESP32 CONTROL DE ASISTENCIA</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
  <style>
    body { background-color: #f8f9fa; font-family: 'Segoe UI', system-ui, sans-serif; }

    .navbar {
      background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%) !important;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .navbar-brand { font-weight: 600; font-size: 1.5rem; }

    .main-container { padding: 2rem 1.5rem; max-width: 1600px; margin: 0 auto; }

    .live-section {
      background: #ffffff;
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }

    /* Header principal */
    .section-header {
      padding: 1.25rem 1.5rem;
      border-bottom: 1px solid #dee2e6;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 1rem;
    }

    .section-title { font-size: 1.25rem; font-weight: 600; margin: 0; color: #212529; }

    /* Barra de filtros */
    .filtros-bar {
      padding: 1rem 1.5rem;
      border-bottom: 1px solid #dee2e6;
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 0.75rem;
      background: #fafbfc;
    }

    .filtro-label {
      font-size: 0.78rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: #6c757d;
      white-space: nowrap;
    }

    .filtro-select {
      padding: 0.45rem 0.85rem;
      border: 1px solid #dee2e6;
      border-radius: 8px;
      font-size: 0.85rem;
      color: #495057;
      background: #fff;
      cursor: pointer;
      outline: none;
      transition: border-color 0.2s;
      min-width: 160px;
    }

    .filtro-select:focus { border-color: #4a90d9; box-shadow: 0 0 0 2px rgba(74,144,217,0.15); }

    .search-box { position: relative; min-width: 260px; flex: 1; }
    .search-box input {
      padding-left: 2.5rem;
      border-radius: 8px;
      border: 1px solid #dee2e6;
      width: 100%;
      padding-top: 0.45rem;
      padding-bottom: 0.45rem;
      font-size: 0.85rem;
    }
    .search-box i {
      position: absolute;
      left: 0.75rem;
      top: 50%;
      transform: translateY(-50%);
      color: #6c757d;
      font-size: 0.85rem;
    }

    .btn-limpiar {
      padding: 0.45rem 0.9rem;
      border: 1px solid #dee2e6;
      border-radius: 8px;
      font-size: 0.82rem;
      color: #6c757d;
      background: #fff;
      cursor: pointer;
      transition: all 0.2s;
      white-space: nowrap;
    }
    .btn-limpiar:hover { background: #f0f4f8; border-color: #adb5bd; }

    /* Badge resultados */
    .resultados-badge {
      font-size: 0.78rem;
      color: #6c757d;
      white-space: nowrap;
    }

    /* Conexión */
    .connection-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.5rem 1rem;
      border-radius: 20px;
      font-size: 0.875rem;
      font-weight: 500;
      background-color: #d1e7dd;
      color: #0f5132;
    }

    .pulse-dot {
      width: 8px; height: 8px;
      border-radius: 50%;
      background-color: currentColor;
      animation: pulse 2s infinite;
    }

    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

    /* Tabla */
    .table-container { max-height: 560px; overflow-y: auto; overflow-x: auto; }
    .table { margin-bottom: 0; }

    .table thead th {
      background-color: #f8f9fa;
      font-weight: 600;
      text-transform: uppercase;
      font-size: 0.72rem;
      letter-spacing: 0.5px;
      color: #6c757d;
      border-bottom: 2px solid #dee2e6;
      white-space: nowrap;
      position: sticky;
      top: 0;
      z-index: 10;
    }

    .table tbody td {
      vertical-align: middle;
      padding: 0.85rem 1rem;
      border-bottom: 1px solid #dee2e6;
      font-size: 0.88rem;
    }

    .table tbody tr:hover { background-color: #f8faff; }

    .uid-badge {
      background-color: #e7f1ff;
      color: #0066cc;
      padding: 0.3rem 0.65rem;
      border-radius: 6px;
      font-family: 'Courier New', monospace;
      font-weight: 600;
      font-size: 0.82rem;
    }

    .nombre-badge {
      font-weight: 500;
      color: #212529;
    }

    .materia-badge {
      background: #f0f4f8;
      color: #495057;
      padding: 0.25rem 0.6rem;
      border-radius: 5px;
      font-size: 0.8rem;
    }

    .grupo-badge {
      background: #e8f5e9;
      color: #2e7d32;
      padding: 0.25rem 0.6rem;
      border-radius: 5px;
      font-size: 0.8rem;
      font-weight: 600;
    }

    /* Navbar botones */
    .btn-logout-nav {
      background: rgba(255,255,255,0.15);
      border: 1px solid rgba(255,255,255,0.3);
      color: #fff;
      border-radius: 8px;
      padding: 0.4rem 1rem;
      font-size: 0.85rem;
      cursor: pointer;
      transition: background 0.2s;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
    }
    .btn-logout-nav:hover { background: rgba(255,255,255,0.25); color: #fff; }

    .last-update {
      background-color: rgba(255,255,255,0.15);
      padding: 0.4rem 0.85rem;
      border-radius: 8px;
      font-size: 0.85rem;
      color: #fff;
    }

    /* Scrollbar */
    .table-container::-webkit-scrollbar { width: 8px; height: 8px; }
    .table-container::-webkit-scrollbar-track { background: #f8f9fa; border-radius: 4px; }
    .table-container::-webkit-scrollbar-thumb { background: #cbd5e0; border-radius: 4px; }
    .table-container::-webkit-scrollbar-thumb:hover { background: #a0aec0; }

    @media (max-width: 768px) {
      .search-box { min-width: 100%; }
      .filtro-select { min-width: 100%; }
      .table-container { max-height: 400px; }
    }
  </style>
</head>
<body>

  <!-- Navbar -->
  <nav class="navbar navbar-dark bg-body-tertiary">
    <div class="container-fluid">
      <a class="navbar-brand" href="#">
        <i class="bi bi-qr-code-scan me-2"></i>ESP32 ASISTENCIA
      </a>
      <div class="d-flex align-items-center gap-3">
        
        <a href="estadisticas.php" class="btn-logout-nav">
  <i class="bi bi-bar-chart-line"></i> Estadísticas
</a>
        <!-- Botón Registrar Materia con badge de alertas -->
        <a href="materias.php" class="btn-logout-nav position-relative" id="btnMaterias">
          <i class="bi bi-journal-plus"></i> Registrar Materia
        </a>

        <!-- Fecha y hora -->
        <div class="last-update">
          <i class="bi bi-clock me-1"></i>
          <span id="clock">--:--:--</span>
        </div>

        <a href="logout.php" class="btn-logout-nav">
          <i class="bi bi-box-arrow-right"></i> Salir
        </a>
      </div>
    </div>
  </nav>

  <!-- Contenido Principal -->
  <div class="main-container">
    <div class="live-section">

      <!-- Header -->
      <div class="section-header">
        <h5 class="section-title">
          <i class="bi bi-activity me-2 text-primary"></i>Actividad en Vivo
        </h5>
        <div class="d-flex align-items-center gap-3">
          <div class="last-update" style="background:#f8f9fa;color:#6c757d;">
            <i class="bi bi-calendar-event me-1"></i>
            <?php echo date('d/m/Y'); ?>
          </div>
          <div class="connection-badge">
            <span class="pulse-dot"></span>
            <span id="connection-text">En vivo</span>
          </div>
        </div>
      </div>

      <!-- Barra de filtros -->
      <div class="filtros-bar">
        <span class="filtro-label"><i class="bi bi-funnel me-1"></i>Filtros:</span>

        <select class="filtro-select" id="filtroMateria">
          <option value="">Todas las materias</option>
        </select>

        <select class="filtro-select" id="filtroCarrera">
          <option value="">Todas las carreras</option>
        </select>

        <select class="filtro-select" id="filtroMaestro">
          <option value="">Todos los maestros</option>
        </select>

        <div class="search-box">
          <i class="bi bi-search"></i>
          <input type="text" class="form-control" id="buscador"
                 placeholder="Buscar por nombre, materia, carrera, maestro...">
        </div>

        <button class="btn-limpiar" onclick="limpiarFiltros()">
          <i class="bi bi-x-circle me-1"></i>Limpiar
        </button>

        <span class="resultados-badge" id="resultadosBadge"></span>
      </div>

      <!-- Tabla -->
      <div class="table-container">
        <table class="table table-hover">
          <thead>
            <tr>
              <th style="width:60px;">#</th>
              <th style="width:110px;">Fecha</th>
              <th style="width:95px;">Hora</th>
              <th style="width:130px;">UID</th>
              <th>Nombre</th>
              <th>Materia</th>
              <th style="width:110px;">Carrera</th>
              <th style="width:70px;">Grupo</th>
              <th>Maestro</th>
            </tr>
          </thead>
          <tbody id="tabla-body">
            <tr>
              <td colspan="9" class="text-center py-5">
                <div class="spinner-border text-primary" role="status"></div>
                <p class="mt-3 text-muted">Cargando datos...</p>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const BACKEND_URL = '<?php echo $BACKEND_URL; ?>';
    let todosLosRegistros = [];
    let sse = null;

    // ── Reloj ──────────────────────────────────────────────────
    function updateClock() {
      document.getElementById('clock').textContent =
        new Date().toLocaleTimeString('es-MX');
    }
    setInterval(updateClock, 1000);
    updateClock();

    // ── Llenar combobox dinámicamente ──────────────────────────
    function llenarCombobox(registros) {
      const materias = [...new Set(registros.map(r => r.materia).filter(v => v && v !== '--'))].sort();
      const carreras = [...new Set(registros.map(r => r.carrera).filter(v => v && v !== '--'))].sort();
      const maestros = [...new Set(registros.map(r => r.maestro).filter(v => v && v !== '--'))].sort();

      const selMateria  = document.getElementById('filtroMateria');
      const selCarrera  = document.getElementById('filtroCarrera');
      const selMaestro  = document.getElementById('filtroMaestro');

      const valMateria  = selMateria.value;
      const valCarrera  = selCarrera.value;
      const valMaestro  = selMaestro.value;

      selMateria.innerHTML  = '<option value="">Todas las materias</option>'  + materias.map(v => `<option value="${v}">${v}</option>`).join('');
      selCarrera.innerHTML  = '<option value="">Todas las carreras</option>'  + carreras.map(v => `<option value="${v}">${v}</option>`).join('');
      selMaestro.innerHTML  = '<option value="">Todos los maestros</option>'  + maestros.map(v => `<option value="${v}">${v}</option>`).join('');

      selMateria.value = valMateria;
      selCarrera.value = valCarrera;
      selMaestro.value = valMaestro;
    }

    // ── Aplicar filtros ────────────────────────────────────────
    function aplicarFiltros() {
      const materia = document.getElementById('filtroMateria').value.toLowerCase();
      const carrera = document.getElementById('filtroCarrera').value.toLowerCase();
      const maestro = document.getElementById('filtroMaestro').value.toLowerCase();
      const q       = document.getElementById('buscador').value.toLowerCase().trim();

      const filtrados = todosLosRegistros.filter(r => {
        const okMateria = !materia || (r.materia || '').toLowerCase() === materia;
        const okCarrera = !carrera || (r.carrera || '').toLowerCase() === carrera;
        const okMaestro = !maestro || (r.maestro || '').toLowerCase() === maestro;
        const okBusqueda = !q || [
          r.nombre  || '',
          r.materia || '',
          r.carrera || '',
          r.maestro || '',
        ].some(campo => campo.toLowerCase().includes(q));

        return okMateria && okCarrera && okMaestro && okBusqueda;
      });

      renderTabla(filtrados);

      const badge = document.getElementById('resultadosBadge');
      badge.textContent = filtrados.length !== todosLosRegistros.length
        ? `${filtrados.length} de ${todosLosRegistros.length} registros`
        : `${todosLosRegistros.length} registros`;
    }

    function limpiarFiltros() {
      document.getElementById('filtroMateria').value = '';
      document.getElementById('filtroCarrera').value = '';
      document.getElementById('filtroMaestro').value = '';
      document.getElementById('buscador').value = '';
      aplicarFiltros();
    }

    // ── Render tabla ───────────────────────────────────────────
    function renderTabla(registros) {
      const tbody = document.getElementById('tabla-body');
      if (!registros.length) {
        tbody.innerHTML = `
          <tr>
            <td colspan="9" class="text-center py-5 text-muted">
              <i class="bi bi-inbox display-4 d-block mb-3"></i>
              <p>No se encontraron registros</p>
            </td>
          </tr>`;
        return;
      }
      tbody.innerHTML = registros.map((r, i) => `
        <tr>
          <td><span class="text-muted">${String(i + 1).padStart(3, '0')}</span></td>
          <td>${r.fecha || '--'}</td>
          <td class="text-monospace">${r.hora || '--'}</td>
          <td><span class="uid-badge"><i class="bi bi-fingerprint me-1"></i>${r.usuario || '--'}</span></td>
          <td><span class="nombre-badge">${r.nombre || '--'}</span></td>
          <td><span class="materia-badge">${r.materia || '--'}</span></td>
          <td style="font-size:0.82rem;">${r.carrera || '--'}</td>
          <td><span class="grupo-badge">${r.grupo || '--'}</span></td>
          <td style="font-size:0.85rem;">${r.maestro || '--'}</td>
        </tr>`).join('');
    }

    // ── Badge alertas alumnos sin nombre ──────────────────────
    function actualizarBadgeAlertas(registros) {
    }

    // ── Actualizar UI ──────────────────────────────────────────
    function actualizarUI(registros) {
      todosLosRegistros = registros;
      llenarCombobox(registros);
      aplicarFiltros();
      actualizarBadgeAlertas(registros);
      document.getElementById('connection-text').textContent =
        'En vivo · ' + new Date().toLocaleTimeString('es-MX');
    }

    // ── SSE ────────────────────────────────────────────────────
    function conectarSSE() {
      if (sse) sse.close();
      sse = new EventSource(`${BACKEND_URL}/api/stream`);
      sse.onmessage = e => {
        try { actualizarUI(JSON.parse(e.data)); }
        catch (err) { console.error('SSE error:', err); }
      };
      sse.onerror = () => {
        document.getElementById('connection-text').textContent = 'Sin conexión';
        document.querySelector('.connection-badge').style.backgroundColor = '#f8d7da';
        document.querySelector('.connection-badge').style.color = '#842029';
        sse.close();
        setTimeout(conectarSSE, 3000);
      };
    }

    // ── Eventos filtros ────────────────────────────────────────
    document.getElementById('filtroMateria').addEventListener('change', aplicarFiltros);
    document.getElementById('filtroCarrera').addEventListener('change', aplicarFiltros);
    document.getElementById('filtroMaestro').addEventListener('change', aplicarFiltros);
    document.getElementById('buscador').addEventListener('input', aplicarFiltros);

    // ── Carga inicial ──────────────────────────────────────────
    async function cargaInicial() {
      try {
        const res = await fetch(`${BACKEND_URL}/api/asistencia`);
        if (res.ok) actualizarUI(await res.json());
      } catch (e) { console.warn('Error carga inicial:', e); }
    }

    document.addEventListener('DOMContentLoaded', () => {
      cargaInicial();
      conectarSSE();
    });

    window.addEventListener('beforeunload', () => { if (sse) sse.close(); });
  </script>

</body>
</html>