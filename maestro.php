<?php
session_start();

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'maestro') {
    header('Location: login.php');
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$APPS_SCRIPT_URL = 'https://script.google.com/macros/s/AKfycbxtXZmzA8QnG1xO4kjEzx9sats4uSQRDrXWksD-p90Kc4stx4DhL1uML6Fg6AHEjzE1lg/exec';
$ID_MAESTRO     = $_SESSION['id_maestro']     ?? '';
$NOMBRE_MAESTRO = $_SESSION['nombre_maestro'] ?? '';
$BACKEND_URL    = 'http://localhost:5000';

function appsGet($url, $params = []) {
    $query = $params ? '?' . http_build_query($params) : '';
    $ctx   = stream_context_create([
        'http' => ['timeout' => 12, 'follow_location' => true],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $json = @file_get_contents($url . $query, false, $ctx);
    return json_decode($json, true) ?? [];
}

function appsPost($url, $data) {
    $ctx = stream_context_create([
        'http' => [
            'method'          => 'POST',
            'header'          => 'Content-Type: application/json',
            'content'         => json_encode($data, JSON_UNESCAPED_UNICODE),
            'timeout'         => 12,
            'follow_location' => true,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    return @file_get_contents($url, false, $ctx) ?: '';
}

function formatearHora($h) {
    if (!$h || $h === '--') return '--';
    // Formato objeto Date de Sheets: "Fri May 08 2026 22:00:08 GMT-0600 (...)"
    if (strpos($h, 'GMT') !== false) {
        $partes = explode(' ', $h);
        if (isset($partes[4])) {
            $hms = explode(':', $partes[4]);
            return $hms[0] . ':' . ($hms[1] ?? '00');
        }
    }
    // Ya viene HH:MM:SS limpio
    $partes = explode(':', $h);
    if (count($partes) >= 2) return $partes[0] . ':' . $partes[1];
    return $h;
}

// Cargar materias del maestro
$materias = appsGet($APPS_SCRIPT_URL, ['tipo' => 'materias', 'id_maestro' => $ID_MAESTRO]) ?? [];

// Cargar materia activa actual
$materia_activa = appsGet($APPS_SCRIPT_URL, ['tipo' => 'activa']) ?? [];

$msg_exito = '';
$msg_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {

    if ($_POST['accion'] === 'activar_materia') {
        $id_materia = trim($_POST['id_materia'] ?? '');
        $resp = appsPost($APPS_SCRIPT_URL, [
            'accion'     => 'activar_materia',
            'id_materia' => $id_materia,
            'id_maestro' => $ID_MAESTRO,
            'maestro'    => $NOMBRE_MAESTRO,
        ]);
        if (stripos($resp, 'exito') !== false || stripos($resp, 'ok') !== false) {
            $msg_exito = 'Materia activada. Los alumnos pueden registrar asistencia.';
        } else {
            $msg_error = 'Error al activar: ' . $resp;
        }
        $materia_activa = appsGet($APPS_SCRIPT_URL, ['tipo' => 'activa']);
    }

    if ($_POST['accion'] === 'desactivar_materia') {
        $resp = appsPost($APPS_SCRIPT_URL, [
            'accion'     => 'desactivar_materia',
            'id_maestro' => $ID_MAESTRO,
        ]);
        if (stripos($resp, 'exito') !== false || stripos($resp, 'ok') !== false) {
            $msg_exito = 'Materia desactivada correctamente.';
        } else {
            $msg_error = 'Error al desactivar: ' . $resp;
        }
        $materia_activa = appsGet($APPS_SCRIPT_URL, ['tipo' => 'activa']);
    }
}

$id_activa     = $materia_activa['id_materia'] ?? '';
$nombre_activa = '';

if ($id_activa) {
    foreach ($materias as $m) {
        if ($m['id'] === $id_activa) {
            $nombre_activa = $m['nombre'];
            break;
        }
    }
    if (!$nombre_activa) {
        $todas_materias = appsGet($APPS_SCRIPT_URL, ['tipo' => 'materias']);
        foreach ($todas_materias as $m) {
            if ($m['id'] === $id_activa) {
                $nombre_activa = $m['nombre'];
                break;
            }
        }
    }
    // Inyectar el nombre al array para que el banner lo pueda usar
    $materia_activa['nombre'] = $nombre_activa;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Panel Maestro – ESP32 Asistencia</title>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{
      --azul-oscuro:#0b1f45;--azul-medio:#1e3c72;--azul-claro:#2a5298;
      --acento:#4a90d9;--blanco:#ffffff;--gris-claro:#f0f4f8;
      --gris-texto:#8899aa;--verde:#27ae60;--rojo:#e74c3c;--radius:14px;
    }
    html,body{min-height:100vh;font-family:'Outfit',sans-serif;background:var(--gris-claro);color:#2c3e50}

    /* NAVBAR */
    .navbar{background:linear-gradient(135deg,var(--azul-medio),var(--azul-claro));padding:0 2rem;height:64px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 12px rgba(0,0,0,.2);position:sticky;top:0;z-index:100}
    .navbar-brand{display:flex;align-items:center;gap:.75rem;color:var(--blanco);text-decoration:none}
    .navbar-brand .logo-box{width:40px;height:40px;border-radius:10px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;overflow:hidden}
    .navbar-brand span{font-family:'Bebas Neue',sans-serif;font-size:1.4rem;letter-spacing:1.5px}
    .navbar-info{font-size:.82rem;color:rgba(255,255,255,.8);display:flex;align-items:center;gap:.4rem}
    .navbar-actions{display:flex;align-items:center;gap:1rem}
    .btn-nav{display:flex;align-items:center;gap:.4rem;padding:.45rem 1rem;border-radius:8px;font-family:'Outfit',sans-serif;font-size:.85rem;font-weight:500;cursor:pointer;text-decoration:none;transition:all .2s;border:none}
    .btn-nav-outline{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);color:var(--blanco)}
    .btn-nav-outline:hover{background:rgba(255,255,255,.22);color:var(--blanco)}
    .btn-nav-danger{background:rgba(231,76,60,.2);border:1px solid rgba(231,76,60,.4);color:#ff8a80}
    .btn-nav-danger:hover{background:rgba(231,76,60,.35)}

    /* LAYOUT */
    .page-container{max-width:1300px;margin:2rem auto;padding:0 1.5rem;display:grid;grid-template-columns:340px 1fr;gap:1.5rem;align-items:start}
    .full-width{grid-column:1/-1}

    /* ALERTAS */
    .alert{padding:.85rem 1.1rem;border-radius:var(--radius);font-size:.88rem;display:flex;align-items:center;gap:.6rem;margin-bottom:1rem;transition:opacity .5s ease}
    .alert-success{background:#d4edda;border:1px solid #c3e6cb;color:#155724}
    .alert-error{background:#fdecea;border:1px solid #f5c6cb;color:#721c24}
    .alert.fadeout{opacity:0}

    /* CARDS */
    .card{background:var(--blanco);border-radius:16px;box-shadow:0 2px 12px rgba(0,0,0,.07);overflow:hidden;animation:fadeIn .4s ease both}
    .card:nth-child(2){animation-delay:.06s}
    .card:nth-child(3){animation-delay:.1s}
    .card-header{padding:1.25rem 1.5rem;border-bottom:1px solid #eef0f5;display:flex;align-items:center;gap:.75rem}
    .card-header-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
    .icon-azul{background:rgba(74,144,217,.12);color:var(--acento)}
    .icon-verde{background:rgba(39,174,96,.12);color:var(--verde)}
    .icon-rojo{background:rgba(231,76,60,.12);color:var(--rojo)}
    .icon-gris{background:rgba(136,153,170,.12);color:var(--gris-texto)}
    .icon-naranja{background:rgba(243,156,18,.12);color:#f39c12}
    .card-header h3{font-size:1rem;font-weight:600;color:var(--azul-oscuro)}
    .card-header p{font-size:.78rem;color:var(--gris-texto);margin-top:.1rem}
    .card-body{padding:1.5rem}

    /* MATERIA ACTIVA BANNER */
    .banner-activa{background:linear-gradient(135deg,#27ae60,#2ecc71);color:#fff;border-radius:var(--radius);padding:1.2rem 1.5rem;margin-bottom:1rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;box-shadow:0 4px 14px rgba(39,174,96,.3)}
    .banner-activa .ba-info .ba-label{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.8px;opacity:.8}
    .banner-activa .ba-info .ba-nombre{font-family:'Bebas Neue',sans-serif;font-size:1.5rem;letter-spacing:1px;line-height:1.1}
    .banner-activa .ba-info .ba-sub{font-size:.82rem;opacity:.85;margin-top:.2rem}
    .banner-inactiva{background:var(--gris-claro);border:2px dashed #dde3ee;border-radius:var(--radius);padding:1.2rem 1.5rem;margin-bottom:1rem;text-align:center;color:var(--gris-texto);font-size:.88rem}
    .banner-inactiva i{font-size:1.5rem;display:block;margin-bottom:.5rem;opacity:.4}

    /* MATERIAS LIST */
    .materia-item{border:1.5px solid #eef0f5;border-radius:var(--radius);padding:1rem 1.2rem;margin-bottom:.75rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;transition:border-color .2s,box-shadow .2s}
    .materia-item:hover{border-color:var(--acento);box-shadow:0 2px 8px rgba(74,144,217,.1)}
    .materia-item.activa{border-color:var(--verde);background:rgba(39,174,96,.05)}
    .materia-item .mi-info .mi-nombre{font-weight:600;font-size:.92rem;color:var(--azul-oscuro)}
    .materia-item .mi-info .mi-sub{font-size:.78rem;color:var(--gris-texto);margin-top:.2rem}
    .badge-activa{background:#d4edda;color:#155724;font-size:.72rem;font-weight:600;padding:.25rem .6rem;border-radius:6px;border:1px solid #c3e6cb}
    .badge-inactiva{background:#f8f9fa;color:var(--gris-texto);font-size:.72rem;font-weight:600;padding:.25rem .6rem;border-radius:6px;border:1px solid #dee2e6}

    /* BOTONES */
    .btn-activar{padding:.5rem 1.1rem;background:linear-gradient(135deg,var(--verde),#2ecc71);color:#fff;border:none;border-radius:10px;font-family:'Outfit',sans-serif;font-size:.82rem;font-weight:600;cursor:pointer;transition:all .2s;box-shadow:0 3px 10px rgba(39,174,96,.3);white-space:nowrap}
    .btn-activar:hover{transform:translateY(-1px);filter:brightness(1.06)}
    .btn-desactivar{padding:.5rem 1.1rem;background:linear-gradient(135deg,var(--rojo),#e74c3c);color:#fff;border:none;border-radius:10px;font-family:'Outfit',sans-serif;font-size:.82rem;font-weight:600;cursor:pointer;transition:all .2s;box-shadow:0 3px 10px rgba(231,76,60,.3);white-space:nowrap}
    .btn-desactivar:hover{transform:translateY(-1px);filter:brightness(1.06)}

    /* TABLA */
    .filtros-bar{padding:1rem 1.5rem;border-bottom:1px solid #eef0f5;display:flex;align-items:center;flex-wrap:wrap;gap:.75rem;background:#fafbfc}
    .filtro-label{font-size:.78rem;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--gris-texto);white-space:nowrap}
    .filtro-select{padding:.4rem .8rem;border:1px solid #dde3ee;border-radius:8px;font-size:.85rem;color:#495057;background:#fff;cursor:pointer;outline:none;transition:border-color .2s;min-width:160px}
    .filtro-select:focus{border-color:var(--acento)}
    .search-box{position:relative;min-width:220px;flex:1}
    .search-box input{padding:.42rem .8rem .42rem 2.2rem;border:1px solid #dde3ee;border-radius:8px;width:100%;font-size:.85rem;outline:none}
    .search-box input:focus{border-color:var(--acento)}
    .search-box i{position:absolute;left:.7rem;top:50%;transform:translateY(-50%);color:var(--gris-texto);font-size:.85rem}
    .table-container{max-height:500px;overflow-y:auto;overflow-x:auto}
    table.registros{width:100%;border-collapse:collapse}
    table.registros thead th{background:var(--gris-claro);font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gris-texto);padding:.85rem 1rem;text-align:left;border-bottom:2px solid #eef0f5;white-space:nowrap;position:sticky;top:0;z-index:5}
    table.registros tbody td{padding:.85rem 1rem;border-bottom:1px solid #eef0f5;font-size:.88rem;vertical-align:middle}
    table.registros tbody tr:hover{background:#f8faff}
    table.registros tbody tr:last-child td{border-bottom:none}
    .uid-badge{font-family:'Courier New',monospace;font-size:.8rem;font-weight:700;background:rgba(74,144,217,.1);color:var(--acento);padding:.25rem .6rem;border-radius:5px}
    .materia-badge{background:#f0f4f8;color:#495057;padding:.22rem .55rem;border-radius:5px;font-size:.78rem}
    .grupo-badge{background:#e8f5e9;color:#2e7d32;padding:.22rem .55rem;border-radius:5px;font-size:.78rem;font-weight:600}
    .resultados-badge{font-size:.78rem;color:var(--gris-texto);white-space:nowrap}
    .table-container::-webkit-scrollbar{width:8px;height:8px}
    .table-container::-webkit-scrollbar-track{background:#f8f9fa;border-radius:4px}
    .table-container::-webkit-scrollbar-thumb{background:#cbd5e0;border-radius:4px}
    .empty-state{text-align:center;padding:2.5rem;color:var(--gris-texto)}
    .empty-state i{font-size:2rem;display:block;margin-bottom:.75rem;opacity:.4}
    .empty-state p{font-size:.88rem}

    /* CONEXIÓN */
    .connection-badge{display:inline-flex;align-items:center;gap:.5rem;padding:.4rem .9rem;border-radius:20px;font-size:.82rem;font-weight:500;background-color:#d1e7dd;color:#0f5132}
    .pulse-dot{width:7px;height:7px;border-radius:50%;background-color:currentColor;animation:pulse 2s infinite}

    @keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
    @keyframes fadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
    @media(max-width:900px){.page-container{grid-template-columns:1fr}.full-width{grid-column:1}}
  </style>
</head>
<body>

<nav class="navbar">
  <a href="maestro.php" class="navbar-brand">
    <div class="logo-box"><img src="logo_tec.png" alt="Logo" style="width:100%;height:100%;object-fit:contain;"></div>
    <span>ITSC ASISTENCIA</span>
  </a>
  <span class="navbar-info">
    <i class="bi bi-person-badge"></i>
    <?php echo htmlspecialchars($NOMBRE_MAESTRO); ?>
  </span>
  <div class="navbar-actions">
    <a href="alumnos.php" class="btn-nav btn-nav-outline position-relative" id="btnAlumnos">
      <i class="bi bi-people-fill"></i> Mis Alumnos
      <span id="badgeNuevos" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="display:none;font-size:0.7rem;"></span>
    </a>
    <a href="logout.php" class="btn-nav btn-nav-danger">
      <i class="bi bi-box-arrow-right"></i> Salir
    </a>
  </div>
</nav>

<div class="page-container">

  <?php if ($msg_exito): ?>
  <div class="full-width">
    <div class="alert alert-success" id="alertMsg">
      <i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($msg_exito); ?>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($msg_error): ?>
  <div class="full-width">
    <div class="alert alert-error" id="alertMsg">
      <i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($msg_error); ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- COLUMNA IZQUIERDA -->
  <div>

    <!-- MATERIA ACTIVA -->
    <div class="card" style="margin-bottom:1.5rem;">
      <div class="card-header">
        <div class="card-header-icon icon-verde"><i class="bi bi-broadcast"></i></div>
        <div><h3>Materia Activa</h3><p>Estado actual del registro de asistencia</p></div>
      </div>
      <div class="card-body">
        <?php if ($id_activa): ?>
        <div class="banner-activa">
          <div class="ba-info">
    <div class="ba-label">En curso</div>
    <div class="ba-nombre"><?php echo htmlspecialchars($materia_activa['nombre'] ?? ''); ?></div>
    <div class="ba-sub">
        <?php echo htmlspecialchars($materia_activa['maestro'] ?? ''); ?> &nbsp;·&nbsp;
        Desde las <?php echo htmlspecialchars(formatearHora($materia_activa['hora_inicio'] ?? '')); ?>
    </div>
</div>
          <?php if (($materia_activa['id_maestro'] ?? '') === $ID_MAESTRO): ?>
          <form method="POST">
            <input type="hidden" name="accion" value="desactivar_materia">
            <button type="submit" class="btn-desactivar"><i class="bi bi-stop-circle me-1"></i>Terminar clase</button>
          </form>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="banner-inactiva">
          <i class="bi bi-pause-circle"></i>
          Ninguna materia activa en este momento.<br>Activa una materia para que los alumnos puedan registrar asistencia.
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- MIS MATERIAS -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-icon icon-azul"><i class="bi bi-journal-bookmark-fill"></i></div>
        <div><h3>Mis Materias</h3><p><?php echo count($materias); ?> materia(s) asignadas</p></div>
      </div>
      <div class="card-body">
        <?php if (empty($materias)): ?>
        <div style="text-align:center;padding:1.5rem;color:var(--gris-texto);font-size:.88rem;">
          <i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.4;"></i>
          No tienes materias asignadas aún.
        </div>
        <?php else: ?>
        <?php foreach ($materias as $m): ?>
        <div class="materia-item <?php echo $m['id'] === $id_activa ? 'activa' : ''; ?>">
          <div class="mi-info">
            <div class="mi-nombre"><?php echo htmlspecialchars($m['nombre']); ?></div>
            <div class="mi-sub">
              <?php echo htmlspecialchars($m['carrera']); ?> &nbsp;|&nbsp;
              Grupo <?php echo htmlspecialchars($m['grupo']); ?> &nbsp;|&nbsp;
              <?php echo htmlspecialchars($m['total_clases']); ?> clases
            </div>
          </div>
          <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.5rem;">
            <?php if ($m['id'] === $id_activa): ?>
              <span class="badge-activa"><i class="bi bi-broadcast me-1"></i>ACTIVA</span>
            <?php else: ?>
              <span class="badge-inactiva">Inactiva</span>
              <?php if (!$id_activa): ?>
              <form method="POST">
                <input type="hidden" name="accion" value="activar_materia">
                <input type="hidden" name="id_materia" value="<?php echo htmlspecialchars($m['id']); ?>">
                <button type="submit" class="btn-activar"><i class="bi bi-play-circle me-1"></i>Iniciar clase</button>
              </form>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- COLUMNA DERECHA — REGISTROS -->
  <div class="card">
    <div class="card-header">
      <div class="card-header-icon icon-gris"><i class="bi bi-activity"></i></div>
      <div><h3>Registros de Asistencia</h3><p>Solo de tus materias, en tiempo real</p></div>
      <div style="margin-left:auto;">
        <div class="connection-badge">
          <span class="pulse-dot"></span>
          <span id="connection-text">En vivo</span>
        </div>
      </div>
    </div>

    <div class="filtros-bar">
      <span class="filtro-label"><i class="bi bi-funnel me-1"></i>Filtrar:</span>
      <select class="filtro-select" id="filtroMateria">
        <option value="">Todas las materias</option>
        <?php foreach ($materias as $m): ?>
        <option value="<?php echo htmlspecialchars($m['nombre']); ?>">
          <?php echo htmlspecialchars($m['nombre']); ?>
        </option>
        <?php endforeach; ?>
      </select>
      <select class="filtro-select" id="filtroGrupo">
        <option value="">Todos los grupos</option>
        <?php foreach ($materias as $m): ?>
        <option value="<?php echo htmlspecialchars($m['grupo']); ?>">
          <?php echo htmlspecialchars($m['grupo']); ?>
        </option>
        <?php endforeach; ?>
      </select>
      <div class="search-box">
        <i class="bi bi-search"></i>
        <input type="text" id="buscador" placeholder="Buscar por nombre, materia...">
      </div>
      <span class="resultados-badge" id="resultadosBadge"></span>
    </div>

    <div class="table-container">
      <table class="registros">
        <thead>
          <tr>
            <th style="width:55px;">#</th>
            <th style="width:105px;">Fecha</th>
            <th style="width:90px;">Hora</th>
            <th style="width:120px;">UID</th>
            <th>Nombre</th>
            <th>Materia</th>
            <th style="width:70px;">Grupo</th>
          </tr>
        </thead>
        <tbody id="tabla-body">
          <tr><td colspan="7" class="empty-state"><i class="bi bi-hourglass-split"></i><p>Cargando registros...</p></td></tr>
        </tbody>
      </table>
    </div>
  </div>

</div>

<script>
  const BACKEND_URL    = '<?php echo $BACKEND_URL; ?>';
  const MIS_MATERIAS   = <?php echo json_encode(array_column($materias, 'nombre')); ?>;
  const MATERIA_ACTIVA = '<?php echo addslashes($nombre_activa); ?>';
  let todosLosRegistros = [];
  let sse = null;

  // ── Auto-desvanece alertas en 3 segundos ─────────────────
  document.addEventListener('DOMContentLoaded', () => {
    const alerta = document.getElementById('alertMsg');
    if (alerta) {
      setTimeout(() => {
        alerta.classList.add('fadeout');
        setTimeout(() => alerta.closest('.full-width')?.remove(), 500);
      }, 3000);
    }
  });

  // ── Render tabla ─────────────────────────────────────────
  function renderTabla(registros) {
    const tbody = document.getElementById('tabla-body');
    if (!registros.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="empty-state"><i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.4;"></i><p>No se encontraron registros</p></td></tr>';
      return;
    }
    tbody.innerHTML = registros.map((r, i) => `
      <tr>
        <td style="color:var(--gris-texto);font-size:.8rem;">${String(i+1).padStart(3,'0')}</td>
        <td>${r.fecha||'--'}</td>
        <td style="font-family:'Courier New',monospace;">${r.hora||'--'}</td>
        <td><span class="uid-badge">${r.usuario||'--'}</span></td>
        <td style="font-weight:500;">${r.nombre||'--'}</td>
        <td><span class="materia-badge">${r.materia||'--'}</span></td>
        <td><span class="grupo-badge">${r.grupo||'--'}</span></td>
      </tr>`).join('');
  }

  // ── Aplicar filtros ───────────────────────────────────────
  function aplicarFiltros() {
    const materia = document.getElementById('filtroMateria').value.toLowerCase();
    const grupo   = document.getElementById('filtroGrupo').value.toLowerCase();
    const q       = document.getElementById('buscador').value.toLowerCase().trim();

    let filtrados = todosLosRegistros.filter(r => {
      const esMia     = MIS_MATERIAS.some(m => m.toLowerCase() === (r.materia||'').toLowerCase());
      if (!esMia) return false;
      const okMateria = !materia || (r.materia||'').toLowerCase() === materia;
      const okGrupo   = !grupo   || (r.grupo||'').toLowerCase()   === grupo;
      const okBusq    = !q || [r.nombre||'', r.materia||'', r.usuario||''].some(v => v.toLowerCase().includes(q));
      return okMateria && okGrupo && okBusq;
    });

    renderTabla(filtrados);
    document.getElementById('resultadosBadge').textContent = filtrados.length + ' registro(s)';

    // Badge UIDs sin nombre
    const sinNombre  = filtrados.filter(r => !r.nombre || r.nombre === '--');
    const uidsNuevos = [...new Set(sinNombre.map(r => r.usuario))];
    const badge      = document.getElementById('badgeNuevos');
    if (uidsNuevos.length > 0) {
      badge.textContent    = uidsNuevos.length;
      badge.style.display  = 'inline-block';
    } else {
      badge.style.display  = 'none';
    }
  }

  // ── Actualizar UI con nuevos datos ────────────────────────
  function actualizarUI(registros) {
    todosLosRegistros = registros;
    aplicarFiltros();
    document.getElementById('connection-text').textContent = 'En vivo · ' + new Date().toLocaleTimeString('es-MX');
  }

  // ── SSE ───────────────────────────────────────────────────
  function conectarSSE() {
    if (sse) sse.close();
    sse = new EventSource(`${BACKEND_URL}/api/stream`);
    sse.onmessage = e => {
      try { actualizarUI(JSON.parse(e.data)); }
      catch(err) { console.error('SSE error:', err); }
    };
    sse.onerror = () => {
      document.getElementById('connection-text').textContent = 'Sin conexión';
      sse.close();
      setTimeout(conectarSSE, 3000);
    };
  }

  // ── Carga inicial ─────────────────────────────────────────
async function cargaInicial() {
    try {
      const res = await fetch(`${BACKEND_URL}/api/asistencia`);
      if (res.ok) {
        todosLosRegistros = await res.json();
        aplicarFiltros();
        document.getElementById('connection-text').textContent = 'En vivo · ' + new Date().toLocaleTimeString('es-MX');
      }
    } catch(e) { console.warn('Error carga inicial:', e); }
}

document.addEventListener('DOMContentLoaded', () => {
    // Pre-seleccionar filtro si hay materia activa
    if (MATERIA_ACTIVA) {
      const sel = document.getElementById('filtroMateria');
      for (let opt of sel.options) {
        if (opt.value.toLowerCase() === MATERIA_ACTIVA.toLowerCase()) {
          sel.value = opt.value;
          break;
        }
      }
    }
    // Primero carga y conecta, luego aplica el filtro
    // ya con el select en el valor correcto
    cargaInicial().then(() => aplicarFiltros());
    conectarSSE();
});

  document.getElementById('filtroMateria').addEventListener('change', aplicarFiltros);
  document.getElementById('filtroGrupo').addEventListener('change', aplicarFiltros);
  document.getElementById('buscador').addEventListener('input', aplicarFiltros);

  window.addEventListener('beforeunload', () => { if (sse) sse.close(); });
</script>

</body>
</html>