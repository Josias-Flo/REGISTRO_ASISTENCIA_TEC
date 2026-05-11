<?php
session_start();

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    header('Location: login.php');
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$APPS_SCRIPT_URL = 'https://script.google.com/macros/s/AKfycbxtXZmzA8QnG1xO4kjEzx9sats4uSQRDrXWksD-p90Kc4stx4DhL1uML6Fg6AHEjzE1lg/exec';

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

$maestros = appsGet($APPS_SCRIPT_URL, ['tipo' => 'maestros']) ?? [];

$msg_exito      = '';
$msg_error      = '';
$maestro_editar = null;

$form_data = ['nombre' => '', 'usuario' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $accion = $_POST['accion'];

    // ── Registrar ──────────────────────────────────────────
    if ($accion === 'registrar_maestro') {
        $nombre   = trim($_POST['nombre']   ?? '');
        $usuario  = trim($_POST['usuario']  ?? '');
        $password = trim($_POST['password'] ?? '');

        if (!$nombre || !$usuario || !$password) {
            $msg_error = 'Todos los campos son obligatorios.';
            $form_data = ['nombre' => $nombre, 'usuario' => $usuario];
        } else {
            $existe = array_filter($maestros, fn($m) => strtolower($m['usuario']) === strtolower($usuario));
            if ($existe) {
                $msg_error = "El usuario \"$usuario\" ya existe.";
                $form_data = ['nombre' => $nombre, 'usuario' => $usuario];
            } else {
                $resp = appsPost($APPS_SCRIPT_URL, [
                    'accion'   => 'registrar_maestro',
                    'nombre'   => $nombre,
                    'usuario'  => $usuario,
                    'password' => $password,
                ]);
                if (stripos($resp, 'exito') !== false || stripos($resp, 'ok') !== false) {
                    $msg_exito = "Maestro \"$nombre\" registrado correctamente.";
                    // form_data queda vacío → campos limpios
                } else {
                    $msg_error = 'Error al registrar: ' . $resp;
                    $form_data = ['nombre' => $nombre, 'usuario' => $usuario];
                }
                $maestros = appsGet($APPS_SCRIPT_URL, ['tipo' => 'maestros']);
            }
        }
    }

    // ── Editar ─────────────────────────────────────────────
    if ($accion === 'editar_maestro') {
        $id       = trim($_POST['id']       ?? '');
        $nombre   = trim($_POST['nombre']   ?? '');
        $usuario  = trim($_POST['usuario']  ?? '');
        $password = trim($_POST['password'] ?? '');

        if (!$id || !$nombre || !$usuario) {
            $msg_error      = 'Nombre y usuario son obligatorios.';
            $maestro_editar = [
                'id'       => $id,
                'nombre'   => $nombre,
                'usuario'  => $usuario,
                'password' => $password,
            ];
        } else {
            $resp = appsPost($APPS_SCRIPT_URL, [
                'accion'   => 'editar_maestro',
                'id'       => $id,
                'nombre'   => $nombre,
                'usuario'  => $usuario,
                'password' => $password,
            ]);
            if (stripos($resp, 'exito') !== false || stripos($resp, 'ok') !== false) {
                $msg_exito = "Maestro \"$nombre\" actualizado correctamente.";
            } else {
                $msg_error      = 'Error al editar: ' . $resp;
                $maestro_editar = [
                    'id'       => $id,
                    'nombre'   => $nombre,
                    'usuario'  => $usuario,
                    'password' => $password,
                ];
            }
            $maestros = appsGet($APPS_SCRIPT_URL, ['tipo' => 'maestros']);
        }
    }

    // ── Eliminar ───────────────────────────────────────────
    if ($accion === 'eliminar_maestro') {
        $id = trim($_POST['id'] ?? '');
        if (!$id) {
            $msg_error = 'ID de maestro no válido.';
        } else {
            $resp      = appsPost($APPS_SCRIPT_URL, ['accion' => 'eliminar_maestro', 'id' => $id]);
            $msg_exito = stripos($resp, 'exito') !== false ? 'Maestro eliminado correctamente.' : '';
            $msg_error = $msg_exito ? '' : 'Error al eliminar: ' . $resp;
            $maestros  = appsGet($APPS_SCRIPT_URL, ['tipo' => 'maestros']);
        }
    }

    // ── Cargar editar ──────────────────────────────────────
    if ($accion === 'cargar_editar') {
        $id_buscar = trim($_POST['id'] ?? '');
        foreach ($maestros as $m) {
            if ($m['id'] === $id_buscar) {
                $maestro_editar = $m;
                break;
            }
        }
    }

    // ── Cancelar editar ────────────────────────────────────
    if ($accion === 'cancelar_editar') {
        $maestro_editar = null;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Registro de Maestros – ESP32 Asistencia</title>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{--azul-oscuro:#0b1f45;--azul-medio:#1e3c72;--azul-claro:#2a5298;--acento:#4a90d9;--blanco:#ffffff;--gris-claro:#f0f4f8;--gris-texto:#8899aa;--verde:#27ae60;--rojo:#e74c3c;--radius:14px}
    html,body{min-height:100vh;font-family:'Outfit',sans-serif;background:var(--gris-claro);color:#2c3e50}
    .navbar{background:linear-gradient(135deg,var(--azul-medio),var(--azul-claro));padding:0 2rem;height:64px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 12px rgba(0,0,0,.2);position:sticky;top:0;z-index:100}
    .navbar-brand{display:flex;align-items:center;gap:.75rem;color:var(--blanco);text-decoration:none}
    .navbar-brand .logo-box{width:40px;height:40px;border-radius:10px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;overflow:hidden}
    .navbar-brand span{font-family:'Bebas Neue',sans-serif;font-size:1.4rem;letter-spacing:1.5px}
    .navbar-actions{display:flex;align-items:center;gap:1rem}
    .btn-nav{display:flex;align-items:center;gap:.4rem;padding:.45rem 1rem;border-radius:8px;font-family:'Outfit',sans-serif;font-size:.85rem;font-weight:500;cursor:pointer;text-decoration:none;transition:all .2s;border:none}
    .btn-nav-outline{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);color:var(--blanco)}
    .btn-nav-outline:hover{background:rgba(255,255,255,.22);color:var(--blanco)}
    .btn-nav-danger{background:rgba(231,76,60,.2);border:1px solid rgba(231,76,60,.4);color:#ff8a80}
    .btn-nav-danger:hover{background:rgba(231,76,60,.35)}
    .page-container{max-width:1100px;margin:2rem auto;padding:0 1.5rem;display:grid;grid-template-columns:380px 1fr;gap:1.5rem;align-items:start}
    .full-width{grid-column:1/-1}
    .card{background:var(--blanco);border-radius:16px;box-shadow:0 2px 12px rgba(0,0,0,.07);overflow:hidden;animation:fadeIn .4s ease both}
    .card:nth-child(2){animation-delay:.06s}
    .card-header{padding:1.25rem 1.5rem;border-bottom:1px solid #eef0f5;display:flex;align-items:center;gap:.75rem}
    .card-header-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
    .icon-azul{background:rgba(74,144,217,.12);color:var(--acento)}
    .icon-verde{background:rgba(39,174,96,.12);color:var(--verde)}
    .icon-naranja{background:rgba(243,156,18,.12);color:#f39c12}
    .icon-gris{background:rgba(136,153,170,.12);color:var(--gris-texto)}
    .card-header h3{font-size:1rem;font-weight:600;color:var(--azul-oscuro)}
    .card-header p{font-size:.78rem;color:var(--gris-texto);margin-top:.1rem}
    .card-body{padding:1.5rem}
    .field{margin-bottom:1.1rem}
    .field label{display:flex;align-items:center;gap:.4rem;font-size:.8rem;font-weight:600;color:#445;margin-bottom:.45rem;text-transform:uppercase;letter-spacing:.5px}
    .field label i{font-size:.9rem;color:var(--acento)}
    .field input{width:100%;padding:.8rem 1rem;border:1.5px solid #dde3ee;border-radius:var(--radius);font-family:'Outfit',sans-serif;font-size:.92rem;color:var(--azul-oscuro);background:var(--gris-claro);outline:none;transition:border-color .2s,box-shadow .2s}
    .field input:focus{border-color:var(--acento);box-shadow:0 0 0 3px rgba(74,144,217,.15);background:#fff}
    .field .input-wrap{position:relative}
    .field .input-wrap input{padding-right:2.8rem}
    .toggle-pass{position:absolute;right:.9rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--gris-texto);display:flex;align-items:center;font-size:1rem}
    .toggle-pass:hover{color:var(--acento)}
    .btn-primary{width:100%;padding:.88rem;background:linear-gradient(135deg,var(--azul-medio),var(--azul-claro));color:var(--blanco);border:none;border-radius:var(--radius);font-family:'Outfit',sans-serif;font-size:.95rem;font-weight:600;cursor:pointer;transition:all .2s;box-shadow:0 4px 14px rgba(30,60,114,.3);display:flex;align-items:center;justify-content:center;gap:.5rem;margin-top:.5rem}
    .btn-primary:hover{transform:translateY(-2px);filter:brightness(1.06)}
    .btn-success{background:linear-gradient(135deg,#27ae60,#2ecc71);box-shadow:0 4px 14px rgba(39,174,96,.3)}
    .btn-warning{background:linear-gradient(135deg,#f39c12,#f1c40f);box-shadow:0 4px 14px rgba(243,156,18,.3)}
    .btn-cancelar{width:100%;padding:.75rem;background:transparent;border:1.5px solid #dde3ee;border-radius:var(--radius);font-family:'Outfit',sans-serif;font-size:.88rem;color:#667;cursor:pointer;margin-top:.5rem;transition:all .2s}
    .btn-cancelar:hover{background:#fdecea;border-color:var(--rojo);color:var(--rojo)}
    .alert{padding:.85rem 1.1rem;border-radius:var(--radius);font-size:.88rem;display:flex;align-items:center;gap:.6rem;margin-bottom:1.2rem;transition:opacity .5s ease}
    .alert-success{background:#d4edda;border:1px solid #c3e6cb;color:#155724}
    .alert-error{background:#fdecea;border:1px solid #f5c6cb;color:#721c24}
    .alert.fadeout{opacity:0}
    .hint{font-size:.75rem;color:var(--gris-texto);margin-top:.35rem}
    .tabla-maestros{width:100%;border-collapse:collapse}
    .tabla-maestros thead th{background:var(--gris-claro);font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--gris-texto);padding:.85rem 1rem;text-align:left;border-bottom:2px solid #eef0f5;white-space:nowrap}
    .tabla-maestros tbody td{padding:.9rem 1rem;border-bottom:1px solid #eef0f5;font-size:.88rem;vertical-align:middle}
    .tabla-maestros tbody tr:hover{background:#f8faff}
    .tabla-maestros tbody tr:last-child td{border-bottom:none}
    .usuario-chip{font-family:'Courier New',monospace;font-size:.82rem;font-weight:700;background:rgba(74,144,217,.1);color:var(--acento);padding:.3rem .7rem;border-radius:6px}
    .pass-oculta{letter-spacing:2px;color:var(--gris-texto);font-size:.9rem}
    .acciones{display:flex;gap:.4rem;justify-content:center}
    .btn-icono{background:none;border:1.5px solid #eee;border-radius:8px;width:32px;height:32px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--gris-texto);transition:all .2s;font-size:.9rem}
    .btn-icono.editar:hover{background:#e8f4fd;border-color:var(--acento);color:var(--acento)}
    .btn-icono.eliminar:hover{background:#fdecea;border-color:var(--rojo);color:var(--rojo)}
    .btn-icono.ver:hover{background:#f0f4f8;border-color:#adb5bd;color:#495057}
    .empty-state{text-align:center;padding:2.5rem;color:var(--gris-texto)}
    .empty-state i{font-size:2.5rem;display:block;margin-bottom:.75rem;opacity:.4}
    .empty-state p{font-size:.88rem}
    @keyframes fadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
    @media(max-width:900px){.page-container{grid-template-columns:1fr}.full-width{grid-column:1}}
  </style>
</head>
<body>

<nav class="navbar">
  <a href="index.php" class="navbar-brand">
    <div class="logo-box"><img src="logo_tec.png" alt="Logo" style="width:100%;height:100%;object-fit:contain;"></div>
    <span>ITSC ASISTENCIA</span>
  </a>
  <div class="navbar-actions">
    <a href="materias.php" class="btn-nav btn-nav-outline"><i class="bi bi-journal-plus"></i> Materias</a>
    <a href="index.php" class="btn-nav btn-nav-outline"><i class="bi bi-arrow-left"></i> Volver</a>
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

  <!-- FORMULARIO -->
  <div>
    <?php if (!$maestro_editar): ?>
    <div class="card">
      <div class="card-header">
        <div class="card-header-icon icon-verde"><i class="bi bi-person-plus-fill"></i></div>
        <div><h3>Registrar Maestro</h3><p>Crea un nuevo usuario para un maestro</p></div>
      </div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="accion" value="registrar_maestro">
          <div class="field">
            <label><i class="bi bi-person"></i> Nombre completo</label>
            <input type="text" name="nombre" placeholder="Ej: Prof. Juan García"
                   value="<?php echo htmlspecialchars($form_data['nombre']); ?>">
          </div>
          <div class="field">
            <label><i class="bi bi-person-badge"></i> Usuario</label>
            <input type="text" name="usuario" placeholder="Ej: JUAN GARCIA" autocomplete="off"
                   value="<?php echo htmlspecialchars($form_data['usuario']); ?>">
            <p class="hint">Este será el nombre de usuario para iniciar sesión.</p>
          </div>
          <div class="field">
            <label><i class="bi bi-lock"></i> Contraseña</label>
            <div class="input-wrap">
              <input type="password" name="password" id="passNuevo"
                     placeholder="Contraseña del maestro" autocomplete="new-password">
              <button type="button" class="toggle-pass" onclick="togglePass('passNuevo')">
                <i class="bi bi-eye"></i>
              </button>
            </div>
          </div>
          <button type="submit" class="btn-primary btn-success">
            <i class="bi bi-person-check-fill"></i> Guardar Maestro
          </button>
        </form>
      </div>
    </div>

    <?php else: ?>
    <div class="card">
      <div class="card-header">
        <div class="card-header-icon icon-naranja"><i class="bi bi-pencil-square"></i></div>
        <div><h3>Editar Maestro</h3><p>Modifica los datos del maestro</p></div>
      </div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="accion" value="editar_maestro">
          <input type="hidden" name="id" value="<?php echo htmlspecialchars($maestro_editar['id']); ?>">
          <div class="field">
            <label><i class="bi bi-person"></i> Nombre completo</label>
            <input type="text" name="nombre"
                   value="<?php echo htmlspecialchars($maestro_editar['nombre']); ?>" required>
          </div>
          <div class="field">
            <label><i class="bi bi-person-badge"></i> Usuario</label>
            <input type="text" name="usuario"
                   value="<?php echo htmlspecialchars($maestro_editar['usuario']); ?>" required>
          </div>
          <div class="field">
            <label><i class="bi bi-lock"></i> Nueva contraseña</label>
            <div class="input-wrap">
              <input type="password" name="password" id="passEditar"
                     placeholder="Dejar vacío para no cambiar">
              <button type="button" class="toggle-pass" onclick="togglePass('passEditar')">
                <i class="bi bi-eye"></i>
              </button>
            </div>
            <p class="hint">Si dejas este campo vacío la contraseña no cambia.</p>
          </div>
          <button type="submit" class="btn-primary btn-warning">
            <i class="bi bi-save"></i> Actualizar Maestro
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

  <!-- TABLA -->
  <div class="card">
    <div class="card-header">
      <div class="card-header-icon icon-gris"><i class="bi bi-people-fill"></i></div>
      <div><h3>Maestros Registrados</h3><p><?php echo count($maestros); ?> maestro(s) en el sistema</p></div>
    </div>
    <div style="overflow-x:auto;">
      <?php if (empty($maestros)): ?>
      <div class="empty-state"><i class="bi bi-inbox"></i><p>No hay maestros registrados aún.</p></div>
      <?php else: ?>
      <table class="tabla-maestros">
        <thead>
          <tr>
            <th>#</th>
            <th>Nombre</th>
            <th>Usuario</th>
            <th>Contraseña</th>
            <th style="text-align:center;">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($maestros as $i => $m): ?>
          <tr>
            <td style="color:var(--gris-texto);font-size:.8rem;"><?php echo str_pad($i+1,3,'0',STR_PAD_LEFT); ?></td>
            <td style="font-weight:600;"><?php echo htmlspecialchars($m['nombre']); ?></td>
            <td><span class="usuario-chip"><?php echo htmlspecialchars($m['usuario']); ?></span></td>
            <td>
              <span class="pass-oculta" id="pass-<?php echo $i; ?>">••••••••</span>
              <button type="button" class="btn-icono ver"
                      style="display:inline-flex;width:24px;height:24px;margin-left:.4rem;"
                      onclick="toggleVerPass(<?php echo $i; ?>, '<?php echo htmlspecialchars(addslashes($m['password'] ?? ''), ENT_QUOTES); ?>')">
                <i class="bi bi-eye" style="font-size:.75rem;"></i>
              </button>
            </td>
            <td>
              <div class="acciones">
                <form method="POST">
                  <input type="hidden" name="accion" value="cargar_editar">
                  <input type="hidden" name="id" value="<?php echo htmlspecialchars($m['id']); ?>">
                  <button type="submit" class="btn-icono editar" title="Editar">
                    <i class="bi bi-pencil"></i>
                  </button>
                </form>
                <form method="POST"
                      onsubmit="return confirm('¿Eliminar maestro <?php echo htmlspecialchars(addslashes($m['nombre'])); ?>?');">
                  <input type="hidden" name="accion" value="eliminar_maestro">
                  <input type="hidden" name="id" value="<?php echo htmlspecialchars($m['id']); ?>">
                  <button type="submit" class="btn-icono eliminar" title="Eliminar">
                    <i class="bi bi-trash3"></i>
                  </button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
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

  function togglePass(id) {
    const input = document.getElementById(id);
    input.type = input.type === 'password' ? 'text' : 'password';
  }

  function toggleVerPass(idx, pass) {
    const el = document.getElementById('pass-' + idx);
    if (el.textContent === '••••••••') {
      el.textContent = pass;
      el.style.letterSpacing = 'normal';
      el.style.color = '#2c3e50';
    } else {
      el.textContent = '••••••••';
      el.style.letterSpacing = '2px';
      el.style.color = 'var(--gris-texto)';
    }
  }
</script>

</body>
</html>