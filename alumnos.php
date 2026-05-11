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
$BACKEND_URL = 'https://tu-url.railway.app';
$ID_MAESTRO      = $_SESSION['id_maestro']     ?? '';
$NOMBRE_MAESTRO  = $_SESSION['nombre_maestro'] ?? '';

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

$materias     = appsGet($APPS_SCRIPT_URL, ['tipo' => 'materias', 'id_maestro' => $ID_MAESTRO]) ?? [];
$alumnos_raw  = appsGet($APPS_SCRIPT_URL, ['tipo' => 'alumnos']) ?? [];
$ids_materias = !empty($materias) ? array_column($materias, 'id') : [];
$alumnos      = array_values(array_filter($alumnos_raw, fn($a) => in_array($a['id_materia'] ?? '', $ids_materias)));

$msg_exito     = '';
$msg_error     = '';
$alumno_editar = null;

$form_data = ['uid' => '', 'nombre' => '', 'id_materia' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $accion = $_POST['accion'];

    // ── Registrar ──────────────────────────────────────────
    if ($accion === 'registrar_alumno') {
        $uid        = strtoupper(trim($_POST['uid']        ?? ''));
        $nombre     = trim($_POST['nombre']                ?? '');
        $id_materia = trim($_POST['id_materia']            ?? '');

        if (!$uid || !$nombre || !$id_materia) {
            $msg_error = 'El UID, nombre y materia son obligatorios.';
            $form_data = ['uid' => $uid, 'nombre' => $nombre, 'id_materia' => $id_materia];
        } else {
            $resp = appsPost($APPS_SCRIPT_URL, [
                'accion'     => 'registrar_alumno',
                'uid'        => $uid,
                'nombre'     => $nombre,
                'id_materia' => $id_materia,
            ]);
            if (stripos($resp, 'exito') !== false || stripos($resp, 'ok') !== false) {
                $msg_exito = "Alumno \"$nombre\" ($uid) registrado correctamente.";
                // form_data queda vacío → campos limpios
            } else {
                $msg_error = 'Error al registrar: ' . $resp;
                $form_data = ['uid' => $uid, 'nombre' => $nombre, 'id_materia' => $id_materia];
            }
            $alumnos_raw  = appsGet($APPS_SCRIPT_URL, ['tipo' => 'alumnos']);
            $alumnos      = array_values(array_filter($alumnos_raw, fn($a) => in_array($a['id_materia'] ?? '', $ids_materias)));
        }
    }

    // ── Editar ─────────────────────────────────────────────
    if ($accion === 'editar_alumno') {
        $uid        = strtoupper(trim($_POST['uid']        ?? ''));
        $nombre     = trim($_POST['nombre']                ?? '');
        $id_materia = trim($_POST['id_materia']            ?? '');

        if (!$uid || !$nombre || !$id_materia) {
            $msg_error      = 'Todos los campos son obligatorios.';
            $alumno_editar  = ['uid' => $uid, 'nombre' => $nombre, 'id_materia' => $id_materia];
        } else {
            $resp = appsPost($APPS_SCRIPT_URL, [
                'accion'     => 'editar_alumno',
                'uid'        => $uid,
                'nombre'     => $nombre,
                'id_materia' => $id_materia,
            ]);
            if (stripos($resp, 'exito') !== false || stripos($resp, 'ok') !== false) {
                $msg_exito = "Alumno \"$nombre\" actualizado correctamente.";
            } else {
                $msg_error     = 'Error al editar: ' . $resp;
                $alumno_editar = ['uid' => $uid, 'nombre' => $nombre, 'id_materia' => $id_materia];
            }
            $alumnos_raw = appsGet($APPS_SCRIPT_URL, ['tipo' => 'alumnos']);
            $alumnos     = array_values(array_filter($alumnos_raw, fn($a) => in_array($a['id_materia'] ?? '', $ids_materias)));
        }
    }

    // ── Eliminar ───────────────────────────────────────────
    if ($accion === 'eliminar_alumno') {
        $uid        = strtoupper(trim($_POST['uid']        ?? ''));
        $id_materia = trim($_POST['id_materia']            ?? '');
        if (!$uid || !$id_materia) {
            $msg_error = 'Datos incompletos para eliminar.';
        } else {
            $resp      = appsPost($APPS_SCRIPT_URL, ['accion' => 'eliminar_alumno', 'uid' => $uid, 'id_materia' => $id_materia]);
            $msg_exito = stripos($resp, 'exito') !== false ? 'Alumno eliminado correctamente.' : '';
            $msg_error = $msg_exito ? '' : 'Error al eliminar: ' . $resp;
            $alumnos_raw = appsGet($APPS_SCRIPT_URL, ['tipo' => 'alumnos']);
            $alumnos     = array_values(array_filter($alumnos_raw, fn($a) => in_array($a['id_materia'] ?? '', $ids_materias)));
        }
    }

    // ── Cargar editar ──────────────────────────────────────
    if ($accion === 'cargar_editar') {
        $uid_b = strtoupper(trim($_POST['uid']        ?? ''));
        $mat_b = trim($_POST['id_materia']            ?? '');
        foreach ($alumnos as $a) {
            if (strtoupper($a['uid']) === $uid_b && $a['id_materia'] === $mat_b) {
                $alumno_editar = $a;
                break;
            }
        }
    }

    // ── Cancelar editar ────────────────────────────────────
    if ($accion === 'cancelar_editar') {
        $alumno_editar = null;
    }
}

$mapa_materias = !empty($materias) ? array_column($materias, 'nombre', 'id') : [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Registro de Alumnos</title>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{--azul-oscuro:#0b1f45;--azul-medio:#1e3c72;--azul-claro:#2a5298;--acento:#4a90d9;--blanco:#ffffff;--gris-claro:#f0f4f8;--gris-texto:#8899aa;--verde:#27ae60;--rojo:#e74c3c;--radius:14px}
    html,body{min-height:100vh;font-family:'Outfit',sans-serif;background:var(--gris-claro);color:#2c3e50}

    /* NAVBAR */
    .navbar{background:linear-gradient(135deg,var(--azul-medio),var(--azul-claro));padding:0 2rem;height:64px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 12px rgba(0,0,0,0.2);position:sticky;top:0;z-index:100}
    .navbar-brand{display:flex;align-items:center;gap:.75rem;color:var(--blanco);text-decoration:none}
    .navbar-brand .logo-box{width:40px;height:40px;border-radius:10px;background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.25);display:flex;align-items:center;justify-content:center;overflow:hidden}
    .navbar-brand span{font-family:'Bebas Neue',sans-serif;font-size:1.4rem;letter-spacing:1.5px}
    .navbar-info{font-size:.82rem;color:rgba(255,255,255,.75)}
    .navbar-actions{display:flex;align-items:center;gap:1rem}
    .btn-nav{display:flex;align-items:center;gap:.4rem;padding:.45rem 1rem;border-radius:8px;font-family:'Outfit',sans-serif;font-size:.85rem;font-weight:500;cursor:pointer;text-decoration:none;transition:all .2s;border:none}
    .btn-nav-outline{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);color:var(--blanco)}
    .btn-nav-outline:hover{background:rgba(255,255,255,.22);color:var(--blanco)}
    .btn-nav-danger{background:rgba(231,76,60,.2);border:1px solid rgba(231,76,60,.4);color:#ff8a80}
    .btn-nav-danger:hover{background:rgba(231,76,60,.35)}

    /* LAYOUT */
    .page-container{max-width:1500px;margin:2rem auto;padding:0 1.5rem;display:grid;grid-template-columns:360px 1fr;gap:1.5rem;align-items:start}
    .full-width{grid-column:1/-1}

    /* CARDS */
    .card{background:var(--blanco);border-radius:16px;box-shadow:0 2px 12px rgba(0,0,0,.07);overflow:hidden;animation:fadeIn .4s ease both}
    .card-header{padding:1.25rem 1.5rem;border-bottom:1px solid #eef0f5;display:flex;align-items:center;gap:.75rem}
    .card-header-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
    .icon-azul{background:rgba(74,144,217,.12);color:var(--acento)}
    .icon-verde{background:rgba(39,174,96,.12);color:var(--verde)}
    .icon-naranja{background:rgba(243,156,18,.12);color:#f39c12}
    .icon-gris{background:rgba(136,153,170,.12);color:var(--gris-texto)}
    .icon-rojo{background:rgba(231,76,60,.12);color:var(--rojo)}
    .card-header h3{font-size:1rem;font-weight:600;color:var(--azul-oscuro)}
    .card-header p{font-size:.78rem;color:var(--gris-texto);margin-top:.1rem}
    .card-body{padding:1.5rem}

    /* FORMULARIO */
    .field{margin-bottom:1.1rem}
    .field label{display:flex;align-items:center;gap:.4rem;font-size:.8rem;font-weight:600;color:#445;margin-bottom:.45rem;text-transform:uppercase;letter-spacing:.5px}
    .field label i{font-size:.9rem;color:var(--acento)}
    .field input,.field select{width:100%;padding:.8rem 1rem;border:1.5px solid #dde3ee;border-radius:var(--radius);font-family:'Outfit',sans-serif;font-size:.92rem;color:var(--azul-oscuro);background:var(--gris-claro);outline:none;transition:border-color .2s,box-shadow .2s}
    .field input:focus,.field select:focus{border-color:var(--acento);box-shadow:0 0 0 3px rgba(74,144,217,.15);background:#fff}
    .btn-primary{width:100%;padding:.88rem;background:linear-gradient(135deg,var(--azul-medio),var(--azul-claro));color:var(--blanco);border:none;border-radius:var(--radius);font-family:'Outfit',sans-serif;font-size:.95rem;font-weight:600;cursor:pointer;transition:all .2s;box-shadow:0 4px 14px rgba(30,60,114,.3);display:flex;align-items:center;justify-content:center;gap:.5rem;margin-top:.5rem}
    .btn-primary:hover{transform:translateY(-2px);filter:brightness(1.06)}
    .btn-success{background:linear-gradient(135deg,#27ae60,#2ecc71);box-shadow:0 4px 14px rgba(39,174,96,.3)}
    .btn-warning{background:linear-gradient(135deg,#f39c12,#f1c40f);box-shadow:0 4px 14px rgba(243,156,18,.3)}
    .btn-cancelar{width:100%;padding:.75rem;background:transparent;border:1.5px solid #dde3ee;border-radius:var(--radius);font-family:'Outfit',sans-serif;font-size:.88rem;color:#667;cursor:pointer;margin-top:.5rem;transition:all .2s}
    .btn-cancelar:hover{background:#fdecea;border-color:var(--rojo);color:var(--rojo)}

    /* ALERTAS */
    .alert{padding:.85rem 1.1rem;border-radius:var(--radius);font-size:.88rem;display:flex;align-items:center;gap:.6rem;margin-bottom:1.2rem;transition:opacity .5s ease}
    .alert-success{background:#d4edda;border:1px solid #c3e6cb;color:#155724}
    .alert-error{background:#fdecea;border:1px solid #f5c6cb;color:#721c24}
    .alert.fadeout{opacity:0}

    /* TABLA ALUMNOS */
    .tabla-wrapper{overflow-x:auto}
    .tabla-alumnos{width:100%;border-collapse:collapse;table-layout:auto}
    .tabla-alumnos thead th{background:var(--gris-claro);font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--gris-texto);padding:.85rem 1rem;text-align:left;border-bottom:2px solid #eef0f5;white-space:nowrap}
    .tabla-alumnos tbody td{padding:.9rem 1rem;border-bottom:1px solid #eef0f5;font-size:.88rem;vertical-align:middle;white-space:nowrap}
    .tabla-alumnos tbody tr:hover{background:#f8faff}
    .tabla-alumnos tbody tr:last-child td{border-bottom:none}
    .tabla-alumnos tbody tr.sin-nombre{background:#fffbf0}
    .uid-chip{font-family:'Courier New',monospace;font-size:.82rem;font-weight:700;background:rgba(74,144,217,.1);color:var(--acento);padding:.3rem .7rem;border-radius:6px}
    .materia-chip{background:#f0f4f8;color:#495057;padding:.25rem .6rem;border-radius:5px;font-size:.78rem}
    .pendiente-chip{background:#fff3cd;color:#856404;padding:.25rem .6rem;border-radius:5px;font-size:.78rem;font-weight:600;border:1px solid #ffc107}
    .acciones{display:flex;gap:.4rem;justify-content:center}
    .btn-icono{background:none;border:1.5px solid #eee;border-radius:8px;width:32px;height:32px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--gris-texto);transition:all .2s;font-size:.9rem}
    .btn-icono.validar:hover{background:#d4edda;border-color:var(--verde);color:var(--verde)}
    .btn-icono.editar:hover{background:#e8f4fd;border-color:var(--acento);color:var(--acento)}
    .btn-icono.eliminar:hover{background:#fdecea;border-color:var(--rojo);color:var(--rojo)}

    /* TABLA FILTRO */
    .tabla-filtro{padding:1rem 1.5rem;border-bottom:1px solid #eef0f5;display:flex;gap:.75rem;align-items:center;background:#fafbfc;flex-wrap:wrap}
    .tabla-filtro select{padding:.4rem .8rem;border:1px solid #dde3ee;border-radius:8px;font-size:.85rem;color:#495057;background:#fff;outline:none}
    .tabla-filtro label{font-size:.78rem;font-weight:600;color:var(--gris-texto);text-transform:uppercase;letter-spacing:.5px;white-space:nowrap}
    .resultados-count{font-size:.78rem;color:var(--gris-texto);margin-left:auto}

    /* PENDIENTES BANNER */
    .pendientes-banner{background:#fffbf0;border:1.5px solid #ffc107;border-radius:var(--radius);padding:.85rem 1.2rem;margin-bottom:1rem;display:flex;align-items:center;gap:.6rem;font-size:.85rem;color:#856404}
    .pendientes-banner i{font-size:1.1rem}

    .empty-state{text-align:center;padding:2.5rem;color:var(--gris-texto)}
    .empty-state i{font-size:2.5rem;display:block;margin-bottom:.75rem;opacity:.4}
    .empty-state p{font-size:.88rem}

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
  <span class="navbar-info"><i class="bi bi-person-badge me-1"></i><?php echo htmlspecialchars($NOMBRE_MAESTRO); ?></span>
  <div class="navbar-actions">
    <a href="maestro.php" class="btn-nav btn-nav-outline"><i class="bi bi-arrow-left"></i> Volver</a>
    <a href="logout.php" class="btn-nav btn-nav-danger"><i class="bi bi-box-arrow-right"></i> Salir</a>
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

  <!-- COLUMNA IZQUIERDA — FORMULARIO -->
  <div>

    <?php if (!$alumno_editar): ?>
    <div class="card">
      <div class="card-header">
        <div class="card-header-icon icon-verde"><i class="bi bi-person-plus-fill"></i></div>
        <div><h3>Registrar Alumno</h3><p>Asocia una tarjeta RFID a un alumno</p></div>
      </div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="accion" value="registrar_alumno">
          <div class="field">
            <label><i class="bi bi-credit-card-2-front"></i> UID de la tarjeta</label>
            <input type="text" name="uid" id="inputUid" placeholder="Ej: E37DA036" autocomplete="off"
                   style="font-family:'Courier New',monospace;font-weight:700;letter-spacing:1px;"
                   value="<?php echo htmlspecialchars($form_data['uid']); ?>">
          </div>
          <div class="field">
            <label><i class="bi bi-person"></i> Nombre completo</label>
            <input type="text" name="nombre" id="inputNombre" placeholder="Nombre del alumno"
                   value="<?php echo htmlspecialchars($form_data['nombre']); ?>">
          </div>
          <div class="field">
            <label><i class="bi bi-book"></i> Materia</label>
            <select name="id_materia" id="inputMateria">
              <option value="">Selecciona una materia</option>
              <?php foreach ($materias as $m): ?>
              <option value="<?php echo htmlspecialchars($m['id']); ?>"
                <?php echo $m['id'] === $form_data['id_materia'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($m['nombre'] . ' – ' . $m['grupo']); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn-primary btn-success">
            <i class="bi bi-person-check-fill"></i> Guardar Alumno
          </button>
        </form>
      </div>
    </div>

    <?php else: ?>
    <div class="card">
      <div class="card-header">
        <div class="card-header-icon icon-naranja"><i class="bi bi-pencil-square"></i></div>
        <div><h3>Editar Alumno</h3><p>Modifica los datos del alumno seleccionado</p></div>
      </div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="accion" value="editar_alumno">
          <input type="hidden" name="uid" value="<?php echo htmlspecialchars($alumno_editar['uid']); ?>">
          <div class="field">
            <label><i class="bi bi-credit-card-2-front"></i> UID de la tarjeta</label>
            <input type="text" value="<?php echo htmlspecialchars($alumno_editar['uid']); ?>"
                   style="font-family:'Courier New',monospace;font-weight:700;letter-spacing:1px;opacity:0.6;" disabled>
          </div>
          <div class="field">
            <label><i class="bi bi-person"></i> Nombre completo</label>
            <input type="text" name="nombre"
                   value="<?php echo htmlspecialchars($alumno_editar['nombre']); ?>" required>
          </div>
          <div class="field">
            <label><i class="bi bi-book"></i> Materia</label>
            <select name="id_materia">
              <?php foreach ($materias as $m): ?>
              <option value="<?php echo htmlspecialchars($m['id']); ?>"
                <?php echo $m['id'] === $alumno_editar['id_materia'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($m['nombre'] . ' – ' . $m['grupo']); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn-primary btn-warning">
            <i class="bi bi-save"></i> Actualizar Alumno
          </button>
        </form>
        <form method="POST">
          <input type="hidden" name="accion" value="cancelar_editar">
          <button type="submit" class="btn-cancelar">
            <i class="bi bi-x-circle me-1"></i> Cancelar
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <!-- COLUMNA DERECHA — TABLA -->
  <div class="card">
    <div class="card-header">
      <div class="card-header-icon icon-gris"><i class="bi bi-people-fill"></i></div>
      <div>
        <h3>Alumnos Registrados</h3>
        <p id="contadorAlumnos"><?php echo count($alumnos); ?> alumno(s) en tus materias</p>
      </div>
      <div style="margin-left:auto;display:flex;align-items:center;gap:.5rem;">
        <span id="badgePendientes" style="display:none;background:#fff3cd;color:#856404;font-size:.75rem;font-weight:600;padding:.3rem .7rem;border-radius:20px;border:1px solid #ffc107;">
          <i class="bi bi-exclamation-circle me-1"></i>
          <span id="numPendientes">0</span> sin nombre
        </span>
      </div>
    </div>

    <div class="tabla-filtro">
      <label><i class="bi bi-funnel me-1"></i>Materia:</label>
      <select id="filtroMateria" onchange="filtrarTabla()">
        <option value="">Todas</option>
        <?php foreach ($materias as $m): ?>
        <option value="<?php echo htmlspecialchars($m['id']); ?>">
          <?php echo htmlspecialchars($m['nombre'] . ' – ' . $m['grupo']); ?>
        </option>
        <?php endforeach; ?>
      </select>
      <select id="filtroEstado" onchange="filtrarTabla()">
        <option value="">Todos</option>
        <option value="pendiente">Sin nombre (pendientes)</option>
        <option value="registrado">Registrados</option>
      </select>
      <span class="resultados-count" id="resultadosCount"></span>
    </div>

    <div class="tabla-wrapper">
      <table class="tabla-alumnos" id="tablaAlumnos">
        <thead>
          <tr>
            <th style="width:50px;">#</th>
            <th style="width:120px;">UID</th>
            <th>Nombre</th>
            <th>Materia</th>
            <th style="text-align:center;width:110px;">Acciones</th>
          </tr>
        </thead>
        <tbody id="tablaBody">
          <!-- Se llena por JS con SSE + alumnos PHP -->
        </tbody>
      </table>
    </div>
  </div>

</div>

<script>
  const BACKEND_URL   = '<?php echo $BACKEND_URL; ?>';
  const MIS_MATERIAS  = <?php echo json_encode($materias); ?>;
  const IDS_MATERIAS  = <?php echo json_encode($ids_materias); ?>;
  const MAPA_MATERIAS = <?php echo json_encode($mapa_materias); ?>;

  // Alumnos registrados desde PHP
  let alumnosRegistrados = <?php echo json_encode(array_values($alumnos)); ?>;

  // Registros sin nombre del backend SSE
  let pendientesSSE = [];
  let sse = null;

  // ── Auto-desvanece alertas en 4 segundos ─────────────────
  document.addEventListener('DOMContentLoaded', () => {
    const alerta = document.getElementById('alertMsg');
    if (alerta) {
      setTimeout(() => {
        alerta.classList.add('fadeout');
        setTimeout(() => alerta.closest('.full-width')?.remove(), 500);
      }, 4000);
    }
  });

  // ── Validar: llena el formulario con el UID del pendiente ──
  function validarPendiente(uid, idMateria) {
    // Solo aplica en formulario de registro (no edición)
    const inputUid     = document.getElementById('inputUid');
    const inputMateria = document.getElementById('inputMateria');
    if (!inputUid) return; // estamos en modo editar, ignorar

    inputUid.value = uid;

    // Pre-seleccionar la materia si coincide
    if (idMateria && inputMateria) {
      for (let opt of inputMateria.options) {
        if (opt.value === idMateria) {
          inputMateria.value = opt.value;
          break;
        }
      }
    }

    // Hacer scroll al formulario y enfocar nombre
    inputUid.closest('.card').scrollIntoView({ behavior: 'smooth', block: 'start' });
    setTimeout(() => document.getElementById('inputNombre')?.focus(), 400);
  }

  // ── Construir filas de la tabla ───────────────────────────
  function construirFilas() {
    // Combinar alumnos registrados + pendientes SSE sin duplicar UIDs
    const uidsRegistrados = new Set(alumnosRegistrados.map(a => a.uid.toUpperCase()));

    // Pendientes: registros SSE sin nombre que NO estén ya registrados
    const pendientesFiltrados = pendientesSSE.filter(p =>
      !uidsRegistrados.has((p.usuario || '').toUpperCase())
    );

    // Fila de pendiente
    const filasPendientes = pendientesFiltrados.map((p, i) => {
      const uid       = (p.usuario || '').toUpperCase();
      const materia   = p.materia || '--';
      // Buscar id_materia por nombre
      const entradaMat = MIS_MATERIAS.find(m => m.nombre.toLowerCase() === materia.toLowerCase());
      const idMateria  = entradaMat ? entradaMat.id : '';
      const nombreMat  = entradaMat ? (entradaMat.nombre + ' – ' + entradaMat.grupo) : materia;

      return {
        tipo      : 'pendiente',
        uid,
        nombre    : '',
        idMateria,
        nombreMat,
        materia,
      };
    });

    // Fila de alumno registrado
    const filasRegistrados = alumnosRegistrados.map(a => ({
      tipo      : 'registrado',
      uid       : (a.uid || '').toUpperCase(),
      nombre    : a.nombre || '',
      idMateria : a.id_materia || '',
      nombreMat : MAPA_MATERIAS[a.id_materia] || '--',
      materia   : MAPA_MATERIAS[a.id_materia] || '--',
    }));

    return [...filasPendientes, ...filasRegistrados];
  }

  // ── Render tabla ──────────────────────────────────────────
  function renderTabla() {
    const filtroMat    = document.getElementById('filtroMateria').value;
    const filtroEstado = document.getElementById('filtroEstado').value;
    const filas        = construirFilas();

    let filtradas = filas.filter(f => {
      const okMat    = !filtroMat    || f.idMateria === filtroMat;
      const okEstado = !filtroEstado
        || (filtroEstado === 'pendiente'  && f.tipo === 'pendiente')
        || (filtroEstado === 'registrado' && f.tipo === 'registrado');
      return okMat && okEstado;
    });

    const tbody = document.getElementById('tablaBody');

    if (!filtradas.length) {
      tbody.innerHTML = `<tr><td colspan="5" class="empty-state">
        <i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.4;"></i>
        <p>No se encontraron alumnos</p></td></tr>`;
      document.getElementById('resultadosCount').textContent = '0 resultado(s)';
      return;
    }

    tbody.innerHTML = filtradas.map((f, i) => {
      const esPendiente = f.tipo === 'pendiente';
      const trClass     = esPendiente ? 'sin-nombre' : '';
      const nombreCell  = esPendiente
        ? `<span class="pendiente-chip"><i class="bi bi-exclamation-circle me-1"></i>Sin nombre</span>`
        : `<span style="font-weight:500;">${escHtml(f.nombre)}</span>`;

      // Botón validar — solo en pendientes
      const btnValidar = esPendiente
        ? `<button type="button" class="btn-icono validar" title="Registrar alumno"
               onclick="validarPendiente('${escHtml(f.uid)}','${escHtml(f.idMateria)}')">
             <i class="bi bi-person-check"></i>
           </button>`
        : `<button type="button" class="btn-icono validar" title="Ya registrado" disabled style="opacity:.25;cursor:default;">
             <i class="bi bi-person-check"></i>
           </button>`;

      // Botones editar y eliminar — solo en registrados
      const btnEditar = !esPendiente
        ? `<form method="POST" style="display:inline;">
             <input type="hidden" name="accion" value="cargar_editar">
             <input type="hidden" name="uid" value="${escHtml(f.uid)}">
             <input type="hidden" name="id_materia" value="${escHtml(f.idMateria)}">
             <button type="submit" class="btn-icono editar" title="Editar">
               <i class="bi bi-pencil"></i>
             </button>
           </form>`
        : `<button type="button" class="btn-icono editar" disabled style="opacity:.25;cursor:default;">
             <i class="bi bi-pencil"></i>
           </button>`;

      const btnEliminar = !esPendiente
        ? `<form method="POST" style="display:inline;"
               onsubmit="return confirm('¿Eliminar a ${escHtml(f.nombre)}?');">
             <input type="hidden" name="accion" value="eliminar_alumno">
             <input type="hidden" name="uid" value="${escHtml(f.uid)}">
             <input type="hidden" name="id_materia" value="${escHtml(f.idMateria)}">
             <button type="submit" class="btn-icono eliminar" title="Eliminar">
               <i class="bi bi-trash3"></i>
             </button>
           </form>`
        : `<button type="button" class="btn-icono eliminar" disabled style="opacity:.25;cursor:default;">
             <i class="bi bi-trash3"></i>
           </button>`;

      return `<tr class="${trClass}" data-materia="${escHtml(f.idMateria)}" data-tipo="${f.tipo}">
        <td style="color:var(--gris-texto);font-size:.8rem;">${String(i+1).padStart(3,'0')}</td>
        <td><span class="uid-chip">${escHtml(f.uid)}</span></td>
        <td>${nombreCell}</td>
        <td><span class="materia-chip">${escHtml(f.nombreMat)}</span></td>
        <td>
          <div class="acciones">
            ${btnValidar}
            ${btnEditar}
            ${btnEliminar}
          </div>
        </td>
      </tr>`;
    }).join('');

    document.getElementById('resultadosCount').textContent = filtradas.length + ' resultado(s)';

    // Badge pendientes
    const totalPendientes = construirFilas().filter(f => f.tipo === 'pendiente').length;
    const badge = document.getElementById('badgePendientes');
    document.getElementById('numPendientes').textContent = totalPendientes;
    badge.style.display = totalPendientes > 0 ? 'inline-flex' : 'none';
  }

  function filtrarTabla() { renderTabla(); }

  // ── Escape HTML para JS ───────────────────────────────────
  function escHtml(str) {
    return String(str || '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;')
      .replace(/'/g,'&#39;');
  }

  // ── SSE — detectar pendientes en tiempo real ──────────────
  function conectarSSE() {
    if (sse) sse.close();
    sse = new EventSource(`${BACKEND_URL}/api/stream`);
    sse.onmessage = e => {
      try {
        const todos = JSON.parse(e.data);
        // Pendientes: sin nombre, de mis materias
        pendientesSSE = todos.filter(r => {
          const sinNombre = !r.nombre || r.nombre === '--';
          const esMia     = IDS_MATERIAS.length === 0 ||
            MIS_MATERIAS.some(m => m.nombre.toLowerCase() === (r.materia||'').toLowerCase());
          return sinNombre && esMia;
        });
        renderTabla();
      } catch(err) { console.error('SSE error:', err); }
    };
    sse.onerror = () => { sse.close(); setTimeout(conectarSSE, 3000); };
  }

  document.addEventListener('DOMContentLoaded', () => {
    renderTabla();
    conectarSSE();
  });

  document.getElementById('filtroMateria').addEventListener('change', filtrarTabla);
  document.getElementById('filtroEstado').addEventListener('change', filtrarTabla);
  window.addEventListener('beforeunload', () => { if (sse) sse.close(); });
</script>

</body>
</html>