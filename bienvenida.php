<?php
session_start();

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'alumno') {
    header('Location: login.php');
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$BACKEND_URL = 'https://tu-url.railway.app';

// Carga inicial
$ultimo        = [];
$todos         = [];
$activa        = [];
try {
    $jsonUltimo = @file_get_contents($BACKEND_URL . '/api/ultimo');
    if ($jsonUltimo) $ultimo = json_decode($jsonUltimo, true) ?? [];

    $jsonTodos = @file_get_contents($BACKEND_URL . '/api/asistencia');
    if ($jsonTodos) $todos = json_decode($jsonTodos, true) ?? [];

    $jsonActiva = @file_get_contents($BACKEND_URL . '/api/materia-activa');
    if ($jsonActiva) $activa = json_decode($jsonActiva, true) ?? [];
} catch (Exception $e) {}

function calcularAsistencias($todos, $uid, $materia) {
    if (!$uid || !$todos) return 0;
    return count(array_filter($todos, function($r) use ($uid, $materia) {
        $mismoUid     = strtoupper($r['usuario'] ?? '') === strtoupper($uid);
        $mismaMateria = !$materia || $materia === '--' || ($r['materia'] ?? '') === $materia;
        return $mismoUid && $mismaMateria;
    }));
}

function formatearFecha($f) {
    if (!$f || $f === '--') return '--';
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $f)) {
        $meses = ['01'=>'ene','02'=>'feb','03'=>'mar','04'=>'abr',
                  '05'=>'may','06'=>'jun','07'=>'jul','08'=>'ago',
                  '09'=>'sep','10'=>'oct','11'=>'nov','12'=>'dic'];
        [$dia, $mes, $anio] = explode('/', $f);
        return $dia . ' ' . ($meses[$mes] ?? $mes) . '. ' . $anio;
    }
    return $f;
}

function formatearHora($h) {
    if (!$h || $h === '--') return '--';
    $partes = explode(':', $h);
    if (count($partes) >= 2) {
        return $partes[0] . ':' . $partes[1];
    }
    return $h;
}

$uid_ini     = $ultimo['usuario'] ?? '--';
$nombre_ini  = $ultimo['nombre']  ?? '--';
$materia_ini = $ultimo['materia'] ?? '--';
$carrera_ini = $ultimo['carrera'] ?? '--';
$grupo_ini   = $ultimo['grupo']   ?? '--';
$maestro_ini = $ultimo['maestro'] ?? '--';
$fecha_ini   = formatearFecha($ultimo['fecha'] ?? '--');
$hora_ini    = formatearHora($ultimo['hora']   ?? '--');

$total_clases_ini = intval($activa['total_clases'] ?? 0);
$asistencias_ini  = calcularAsistencias($todos, $uid_ini, $materia_ini);
$porcentaje_ini   = $total_clases_ini > 0
    ? min(round(($asistencias_ini / $total_clases_ini) * 100, 1), 100)
    : 0;

function getEstatus($pct) {
    if ($pct >= 95) return ['EXCELENTE', '#27ae60', 'rgba(39,174,96,0.12)',  'Tu desempeño en asistencia es sobresaliente.'];
    if ($pct >= 85) return ['BUENO',     '#2980b9', 'rgba(41,128,185,0.12)', 'Tienes una buena asistencia.'];
    if ($pct >= 70) return ['REGULAR',   '#f39c12', 'rgba(243,156,18,0.12)', 'Eres regular en la materia. Procura no faltar más.'];
    return             ['EN RIESGO', '#e74c3c', 'rgba(231,76,60,0.12)',  'Estás en riesgo de reprobar.'];
}

[$estatus_ini, $color_ini, $bg_ini, $msg_ini] = getEstatus($porcentaje_ini);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Bienvenido – ESP32 Asistencia</title>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --azul-oscuro: #0b1f45;
      --azul-medio:  #1e3c72;
      --azul-claro:  #2a5298;
      --acento:      #4a90d9;
      --blanco:      #ffffff;
      --gris-claro:  #f0f4f8;
      --gris-texto:  #8899aa;
      --radius:      14px;
    }

    html, body {
      height: 100%;
      font-family: 'Outfit', sans-serif;
      background: var(--azul-oscuro);
    }

    /* ── LAYOUT PRINCIPAL ── */
    .wrapper {
      display: flex;
      min-height: 100vh;
      width: 100%;
    }

    /* ── PANEL IZQUIERDO ── */
    .panel-left {
      flex: 1;
      position: relative;
      display: flex;
      flex-direction: column;
      justify-content: flex-end;
      padding: 3rem;
      overflow: hidden;
      min-height: 100vh;
    }
    .panel-left .bg-img {
      position: absolute;
      inset: 0;
      background-image: url('fondo.webp');
      background-size: cover;
      background-position: center;
      background-color: #1a3060;
    }
    .panel-left .overlay {
      position: absolute;
      inset: 0;
      background: linear-gradient(to bottom, rgba(11,31,69,0.35) 0%, rgba(11,31,69,0.72) 60%, rgba(11,31,69,0.92) 100%);
      backdrop-filter: blur(3px);
      -webkit-backdrop-filter: blur(3px);
    }
    .logo-area {
      position: absolute;
      top: 1.5rem;
      left: 2rem;
      z-index: 2;
    }
    .logo-box {
      width: 72px;
      height: 72px;
      border-radius: 16px;
      background: rgba(255,255,255,0.15);
      border: 1px solid rgba(255,255,255,0.25);
      backdrop-filter: blur(8px);
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
    }
    .panel-left .content {
      position: relative;
      z-index: 2;
      color: var(--blanco);
      animation: fadeUp 0.7s ease 0.2s both;
    }
    .panel-left h1 {
      font-family: 'Bebas Neue', sans-serif;
      font-size: 3rem;
      letter-spacing: 2px;
      line-height: 1.1;
      margin-bottom: 0.5rem;
      text-shadow: 0 2px 12px rgba(0,0,0,0.5);
    }
    .panel-left .subtitle {
      font-size: 1rem;
      font-weight: 300;
      color: rgba(255,255,255,0.75);
      margin-bottom: 2rem;
    }
    .features { list-style: none; display: flex; flex-direction: column; gap: 0.6rem; }
    .features li {
      display: flex;
      align-items: center;
      gap: 0.6rem;
      font-size: 0.9rem;
      color: rgba(255,255,255,0.85);
    }
    .features li::before {
      content: '✓';
      width: 22px;
      height: 22px;
      border-radius: 50%;
      background: var(--acento);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.7rem;
      font-weight: 700;
      flex-shrink: 0;
    }
    .credit { margin-top: 2rem; font-size: 0.78rem; color: rgba(255,255,255,0.45); font-style: italic; }

    /* ── PANEL DERECHO ── */
    .panel-right {
      width: 480px;
      flex-shrink: 0;
      background: var(--blanco);
      display: flex;
      flex-direction: column;
      padding: 2rem 2.5rem;
      position: relative;
      box-shadow: -20px 0 60px rgba(0,0,0,0.35);
      overflow-y: auto;
      animation: slideIn 0.5s ease forwards;
      min-height: 100vh;
    }

    /* ── HEADER BIENVENIDA ── */
    .welcome-header { text-align: center; margin-bottom: 1.2rem; }
    .welcome-header h2 {
      font-family: 'Bebas Neue', sans-serif;
      font-size: 1.6rem;
      letter-spacing: 2px;
      color: var(--azul-oscuro);
      line-height: 1.1;
    }
    .welcome-header .nombre-alumno {
      font-family: 'Bebas Neue', sans-serif;
      font-size: 2rem;
      letter-spacing: 1px;
      color: var(--acento);
      margin-top: 0.2rem;
      transition: all 0.4s;
    }
    .welcome-header p { font-size: 0.82rem; color: var(--gris-texto); margin-top: 0.25rem; }

    /* ── INFO CARDS ── */
    .info-card {
      background: var(--gris-claro);
      border-radius: var(--radius);
      padding: 1rem 1.2rem;
      margin-bottom: 0.8rem;
    }
    .info-card .label {
      font-size: 0.68rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      color: var(--gris-texto);
      margin-bottom: 0.2rem;
    }
    .info-card .value {
      font-size: 0.9rem;
      font-weight: 600;
      color: var(--azul-oscuro);
      transition: all 0.3s;
    }

    .info-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0.8rem;
      margin-bottom: 0.8rem;
    }
    .info-grid .info-card { margin-bottom: 0; }

    /* ── ESTATUS ── */
    .estatus-card {
      border-radius: var(--radius);
      padding: 1rem 1.2rem;
      margin-bottom: 0.8rem;
      display: flex;
      align-items: center;
      gap: 0.8rem;
      transition: background 0.5s, border-color 0.5s;
    }
    .estatus-icon { font-size: 1.8rem; }
    .estatus-info .badge-txt {
      font-family: 'Bebas Neue', sans-serif;
      font-size: 1.2rem;
      letter-spacing: 1.5px;
    }
    .estatus-info .msg { font-size: 0.78rem; color: #556; margin-top: 0.15rem; }

    /* ── STATS ── */
    .stats-row {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 0.7rem;
      margin-bottom: 0.8rem;
    }
    .stat-box {
      background: var(--gris-claro);
      border-radius: var(--radius);
      padding: 0.85rem;
      text-align: center;
    }
    .stat-box .num {
      font-family: 'Bebas Neue', sans-serif;
      font-size: 1.8rem;
      color: var(--azul-oscuro);
      line-height: 1;
      transition: all 0.3s;
    }
    .stat-box .num.accent { color: var(--acento); }
    .stat-box .desc { font-size: 0.68rem; color: var(--gris-texto); margin-top: 0.2rem; }

    /* ── BARRA PROGRESO ── */
    .progress-wrap { margin-bottom: 0.8rem; }
    .progress-label {
      display: flex;
      justify-content: space-between;
      font-size: 0.78rem;
      color: #667;
      margin-bottom: 0.4rem;
    }
    .progress-bar { height: 9px; background: #e0e7f0; border-radius: 99px; overflow: hidden; }
    .progress-fill {
      height: 100%;
      border-radius: 99px;
      background: linear-gradient(90deg, var(--azul-medio), var(--acento));
      transition: width 0.8s ease;
    }

    /* ── ÚLTIMA ASISTENCIA ── */
    .ultima {
      background: var(--gris-claro);
      border-radius: var(--radius);
      padding: 0.85rem 1.2rem;
      margin-bottom: 1rem;
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }
    .ultima .dot {
      width: 9px;
      height: 9px;
      border-radius: 50%;
      background: #27ae60;
      flex-shrink: 0;
      animation: pulse 2s infinite;
    }
    .ultima .txt { font-size: 0.8rem; color: #556; }
    .ultima .txt strong { color: var(--azul-oscuro); }

    /* ── BOTÓN ── */
    .btn-logout {
      width: 100%;
      padding: 0.8rem;
      background: transparent;
      border: 1.5px solid #dde3ee;
      border-radius: var(--radius);
      font-family: 'Outfit', sans-serif;
      font-size: 0.88rem;
      font-weight: 500;
      color: #667;
      cursor: pointer;
      transition: all 0.2s;
    }
    .btn-logout:hover { background: #fdecea; border-color: #e74c3c; color: #e74c3c; }

    .panel-right .footer {
      font-size: 0.7rem;
      color: #bbc;
      text-align: center;
      margin-top: 0.8rem;
    }

    /* ── TOAST ── */
    .toast-registrada {
      position: fixed;
      top: 1.5rem;
      left: 50%;
      transform: translateX(-50%) translateY(-160px);
      background: linear-gradient(135deg, #27ae60, #2ecc71);
      color: #fff;
      border-radius: 16px;
      padding: 0.85rem 1.5rem;
      display: flex;
      align-items: center;
      gap: 0.75rem;
      box-shadow: 0 8px 32px rgba(39,174,96,0.45);
      z-index: 999;
      transition: transform 0.4s cubic-bezier(0.34,1.56,0.64,1);
      font-family: 'Outfit', sans-serif;
      font-weight: 600;
      font-size: 0.95rem;
      max-width: 90vw;
      white-space: nowrap;
    }
    .toast-registrada.show { transform: translateX(-50%) translateY(0); }
    .toast-icon { font-size: 1.4rem; }

    /* ── ANIMACIONES ── */
    @keyframes slideIn { from { opacity: 0; transform: translateX(30px); } to { opacity: 1; transform: translateX(0); } }
    @keyframes fadeUp  { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes pulse   { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
    @keyframes pop     { 0% { transform: scale(1); } 50% { transform: scale(1.18); } 100% { transform: scale(1); } }
    .pop { animation: pop 0.4s ease; }

    /* ════════════════════════════════════════════
       RESPONSIVE — TABLET  (≤ 900px)
    ════════════════════════════════════════════ */
    @media (max-width: 900px) {
      .wrapper {
        flex-direction: column;
        min-height: 100vh;
      }

      /* Panel izquierdo: se convierte en banner compacto horizontal */
      .panel-left {
        min-height: 0;
        height: auto;
        flex-direction: row;
        align-items: center;
        justify-content: flex-start;
        padding: 1rem 1.5rem;
        gap: 1rem;
      }

      .logo-area {
        position: relative;
        top: auto;
        left: auto;
      }

      .logo-box {
        width: 52px;
        height: 52px;
        border-radius: 12px;
        flex-shrink: 0;
      }

      .panel-left .content {
        display: flex;
        flex-direction: row;
        align-items: center;
        gap: 1.5rem;
        flex-wrap: wrap;
        animation: none;
      }

      .panel-left h1 {
        font-size: 1.8rem;
        margin-bottom: 0;
        white-space: nowrap;
      }

      .panel-left .subtitle {
        font-size: 0.82rem;
        margin-bottom: 0;
        white-space: nowrap;
      }

      /* Ocultar features y credit en el banner compacto */
      .features,
      .credit { display: none; }

      /* Panel derecho: ocupa todo el ancho restante */
      .panel-right {
        width: 100%;
        flex-shrink: 1;
        box-shadow: none;
        border-top: 1px solid rgba(255,255,255,0.1);
        padding: 1.5rem 2rem;
        min-height: 0;
        animation: none;
      }

      .welcome-header { margin-bottom: 1rem; }
      .welcome-header h2  { font-size: 1.4rem; }
      .welcome-header .nombre-alumno { font-size: 1.7rem; }
    }

    /* ════════════════════════════════════════════
       RESPONSIVE — MÓVIL  (≤ 560px)
    ════════════════════════════════════════════ */
    @media (max-width: 560px) {
      /* Banner superior más compacto */
      .panel-left {
        padding: 0.75rem 1rem;
        gap: 0.75rem;
      }

      .logo-box {
        width: 42px;
        height: 42px;
        border-radius: 10px;
      }

      .panel-left h1 { font-size: 1.4rem; }
      .panel-left .subtitle { display: none; }

      /* Panel derecho full width, padding ajustado */
      .panel-right {
        padding: 1rem;
      }

      .welcome-header h2 { font-size: 1.2rem; }
      .welcome-header .nombre-alumno { font-size: 1.5rem; }
      .welcome-header p { font-size: 0.75rem; }

      /* Info cards */
      .info-card { padding: 0.75rem 1rem; }
      .info-card .label { font-size: 0.65rem; }
      .info-card .value { font-size: 0.85rem; }

      /* Grid de carrera/grupo: mantener 2 columnas */
      .info-grid { gap: 0.6rem; }

      /* Estatus */
      .estatus-card { padding: 0.75rem 1rem; gap: 0.6rem; }
      .estatus-icon { font-size: 1.5rem; }
      .estatus-info .badge-txt { font-size: 1.05rem; }
      .estatus-info .msg { font-size: 0.72rem; }

      /* Stats: 3 columnas, tamaño reducido */
      .stats-row { gap: 0.5rem; }
      .stat-box { padding: 0.65rem 0.4rem; }
      .stat-box .num { font-size: 1.5rem; }
      .stat-box .desc { font-size: 0.6rem; }

      /* Progreso */
      .progress-label { font-size: 0.72rem; }

      /* Última asistencia */
      .ultima { padding: 0.7rem 1rem; }
      .ultima .txt { font-size: 0.75rem; }

      /* Toast más compacto */
      .toast-registrada {
        font-size: 0.82rem;
        padding: 0.7rem 1.1rem;
        border-radius: 12px;
        gap: 0.5rem;
      }
      .toast-icon { font-size: 1.1rem; }

      /* Botón logout */
      .btn-logout { font-size: 0.82rem; padding: 0.7rem; }
    }

    /* ════════════════════════════════════════════
       PANTALLAS GRANDES (> 900px): layout original
    ════════════════════════════════════════════ */
    @media (min-width: 901px) {
      html, body { overflow: hidden; height: 100%; }
      .wrapper { height: 100vh; }
      .panel-left { height: 100%; }
    }
  </style>
</head>
<body>

<!-- Toast -->
<div class="toast-registrada" id="toastRegistrada">
  <span class="toast-icon">&#10003;</span>
  <span id="toastMsg">Asistencia Registrada</span>
</div>

<div class="wrapper">

  <!-- PANEL IZQUIERDO -->
  <div class="panel-left">
    <div class="bg-img"></div>
    <div class="overlay"></div>
    <div class="logo-area">
      <div class="logo-box">
        <img src="logo_tec.png" alt="Logo" style="width:100%;height:100%;object-fit:contain;border-radius:12px;">
      </div>
    </div>
    <div class="content">
      <h1>ITSC<br>Asistencia</h1>
      <p class="subtitle">Sistema de Control de Asistencia con ESP32</p>
      <ul class="features">
        <li>Registro automático por tarjeta RFID</li>
        <li>Visualización en tiempo real</li>
        <li>Control de asistencia por materia</li>
        <li>Acceso para maestros y alumnos</li>
      </ul>
      <p class="credit">© 2026 ITSC – Tecnologías Emergentes</p>
    </div>
  </div>

  <!-- PANEL DERECHO -->
  <div class="panel-right">

    <div class="welcome-header">
      <h2>BIENVENIDO AL ITSC</h2>
      <div class="nombre-alumno" id="nombreAlumno"><?php echo htmlspecialchars($nombre_ini); ?></div>
      <p id="uidAlumno">UID: <?php echo htmlspecialchars($uid_ini); ?></p>
    </div>

    <div class="info-card">
      <div class="label">Materia</div>
      <div class="value" id="infoMateria"><?php echo htmlspecialchars($materia_ini); ?></div>
    </div>

    <div class="info-grid">
      <div class="info-card">
        <div class="label">Carrera</div>
        <div class="value" id="infoCarrera" style="font-size:0.78rem;"><?php echo htmlspecialchars($carrera_ini); ?></div>
      </div>
      <div class="info-card">
        <div class="label">Grupo</div>
        <div class="value" id="infoGrupo"><?php echo htmlspecialchars($grupo_ini); ?></div>
      </div>
    </div>

    <div class="info-card">
      <div class="label">Maestro</div>
      <div class="value" id="infoMaestro"><?php echo htmlspecialchars($maestro_ini); ?></div>
    </div>

    <div class="estatus-card" id="estatusCard"
         style="background:<?php echo $bg_ini; ?>;border:1.5px solid <?php echo $color_ini; ?>33;">
      <div class="estatus-icon" id="estatusIcon">&#128293;</div>
      <div class="estatus-info">
        <div class="badge-txt" id="estatusTxt" style="color:<?php echo $color_ini; ?>;"><?php echo $estatus_ini; ?></div>
        <div class="msg" id="estatusMsg"><?php echo $msg_ini; ?></div>
      </div>
    </div>

    <div class="stats-row">
      <div class="stat-box">
        <div class="num accent" id="numAsistencias"><?php echo $asistencias_ini; ?></div>
        <div class="desc">Asistencias</div>
      </div>
      <div class="stat-box">
        <div class="num" id="numTotal"><?php echo $total_clases_ini ?: '--'; ?></div>
        <div class="desc">Total clases</div>
      </div>
      <div class="stat-box">
        <div class="num" id="numPorcentaje"><?php echo $total_clases_ini ? $porcentaje_ini . '%' : '--'; ?></div>
        <div class="desc">Porcentaje</div>
      </div>
    </div>

    <div class="progress-wrap">
      <div class="progress-label">
        <span>Progreso de asistencia</span>
        <span id="labelPct"><?php echo $total_clases_ini ? $porcentaje_ini . '%' : '--'; ?></span>
      </div>
      <div class="progress-bar">
        <div class="progress-fill" id="progressFill" style="width:<?php echo $porcentaje_ini; ?>%;"></div>
      </div>
    </div>

    <div class="ultima" id="ultimaBox" <?php if (!$ultimo) echo 'style="display:none"'; ?>>
      <div class="dot"></div>
      <div class="txt">
        Última asistencia:
        <strong id="ultimaFecha"><?php echo htmlspecialchars($fecha_ini); ?></strong>
        a las
        <strong id="ultimaHora"><?php echo htmlspecialchars($hora_ini); ?></strong>
      </div>
    </div>

    <form method="POST" action="logout.php">
      <button type="submit" class="btn-logout">Cerrar sesión</button>
    </form>

    <div class="footer">© 2026 ITSC – Todos los derechos reservados</div>
  </div>
</div>

<script>
  const BACKEND_URL = '<?php echo $BACKEND_URL; ?>';

  let uidActual      = '<?php echo addslashes($uid_ini); ?>';
  let materiaActual  = '<?php echo addslashes($materia_ini); ?>';
  let totalClases    = <?php echo $total_clases_ini ?: 0; ?>;
  let todosRegistros = [];
  let sseUltimo      = null;
  let sseTodos       = null;

  const estatusConfig = {
    'EXCELENTE': { color: '#27ae60', bg: 'rgba(39,174,96,0.12)',  icon: '&#127942;', msg: 'Tu desempeño en asistencia es sobresaliente.' },
    'BUENO':     { color: '#2980b9', bg: 'rgba(41,128,185,0.12)', icon: '&#128077;', msg: 'Tienes una buena asistencia.' },
    'REGULAR':   { color: '#f39c12', bg: 'rgba(243,156,18,0.12)', icon: '&#9888;',   msg: 'Eres regular en la materia. Procura no faltar más.' },
    'EN RIESGO': { color: '#e74c3c', bg: 'rgba(231,76,60,0.12)',  icon: '&#128680;', msg: 'Estás en riesgo de reprobar.' },
  };

  function getEstatus(pct) {
    if (pct >= 95) return 'EXCELENTE';
    if (pct >= 85) return 'BUENO';
    if (pct >= 70) return 'REGULAR';
    return 'EN RIESGO';
  }

  function formatearFecha(f) {
    if (!f || f === '--') return '--';
    const meses = ['ene.','feb.','mar.','abr.','may.','jun.',
                   'jul.','ago.','sep.','oct.','nov.','dic.'];
    const partes = f.split('/');
    if (partes.length === 3) {
      const dia  = partes[0];
      const mes  = meses[parseInt(partes[1], 10) - 1] || partes[1];
      const anio = partes[2];
      return `${dia} ${mes} ${anio}`;
    }
    return f;
  }

  function formatearHora(h) {
    if (!h || h === '--') return '--';
    const partes = h.split(':');
    if (partes.length >= 2) return `${partes[0]}:${partes[1]}`;
    return h;
  }

  function mostrarToast(nombre) {
    const txt = nombre && nombre !== '--' ? `Asistencia Registrada – ${nombre}` : 'Asistencia Registrada';
    document.getElementById('toastMsg').textContent = txt;
    const toast = document.getElementById('toastRegistrada');
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 4000);
  }

  function animarPop(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('pop');
    void el.offsetWidth;
    el.classList.add('pop');
  }

  function calcularAsistencias(uid, materia) {
    if (!uid || uid === '--') return 0;
    return todosRegistros.filter(r => {
      const mismoUid     = (r.usuario || '').toUpperCase() === uid.toUpperCase();
      const mismaMateria = !materia || materia === '--' || (r.materia || '') === materia;
      return mismoUid && mismaMateria;
    }).length;
  }

  function actualizarEstadisticas(uid, materia) {
    const asistencias = calcularAsistencias(uid, materia);
    const pct = totalClases > 0
      ? Math.min(parseFloat((asistencias / totalClases * 100).toFixed(1)), 100)
      : 0;
    const estatus = getEstatus(pct);
    const cfg     = estatusConfig[estatus];

    document.getElementById('numAsistencias').textContent = asistencias;
    document.getElementById('numTotal').textContent       = totalClases || '--';
    document.getElementById('numPorcentaje').textContent  = totalClases ? pct + '%' : '--';
    document.getElementById('labelPct').textContent       = totalClases ? pct + '%' : '--';
    document.getElementById('progressFill').style.width   = pct + '%';

    animarPop('numAsistencias');
    animarPop('numPorcentaje');

    const card = document.getElementById('estatusCard');
    card.style.background  = cfg.bg;
    card.style.borderColor = cfg.color + '33';
    document.getElementById('estatusIcon').innerHTML      = cfg.icon;
    document.getElementById('estatusTxt').textContent     = estatus;
    document.getElementById('estatusTxt').style.color     = cfg.color;
    document.getElementById('estatusMsg').textContent     = cfg.msg;
  }

  function actualizarUltimo(r) {
    const nuevoUid = (r.usuario || '--').toUpperCase();

    uidActual     = nuevoUid;
    materiaActual = r.materia || '--';

    document.getElementById('nombreAlumno').textContent = r.nombre  || '--';
    document.getElementById('uidAlumno').textContent    = 'UID: ' + nuevoUid;
    document.getElementById('infoMateria').textContent  = r.materia || '--';
    document.getElementById('infoCarrera').textContent  = r.carrera || '--';
    document.getElementById('infoGrupo').textContent    = r.grupo   || '--';
    document.getElementById('infoMaestro').textContent  = r.maestro || '--';

    document.getElementById('ultimaFecha').textContent = formatearFecha(r.fecha || '--');
    document.getElementById('ultimaHora').textContent  = formatearHora(r.hora   || '--');
    document.getElementById('ultimaBox').style.display = 'flex';

    mostrarToast(r.nombre || '');
    actualizarEstadisticas(nuevoUid, materiaActual);
  }

  function cargarMateriaActiva() {
    fetch(`${BACKEND_URL}/api/materia-activa`)
      .then(r => r.json())
      .then(data => {
        if (data && data.total_clases) {
          totalClases = parseInt(data.total_clases, 10) || 0;
          actualizarEstadisticas(uidActual, materiaActual);
        }
      })
      .catch(() => {});
  }

  function conectarSSEUltimo() {
    if (sseUltimo) sseUltimo.close();
    sseUltimo = new EventSource(`${BACKEND_URL}/api/stream/ultimo`);
    sseUltimo.onmessage = e => {
      try { actualizarUltimo(JSON.parse(e.data)); }
      catch (err) { console.error('SSE ultimo:', err); }
    };
    sseUltimo.onerror = () => {
      sseUltimo.close();
      setTimeout(conectarSSEUltimo, 3000);
    };
  }

  function conectarSSETodos() {
    if (sseTodos) sseTodos.close();
    sseTodos = new EventSource(`${BACKEND_URL}/api/stream`);
    sseTodos.onmessage = e => {
      try {
        todosRegistros = JSON.parse(e.data);
        actualizarEstadisticas(uidActual, materiaActual);
      } catch (err) { console.error('SSE todos:', err); }
    };
    sseTodos.onerror = () => {
      sseTodos.close();
      setTimeout(conectarSSETodos, 3000);
    };
  }

  document.addEventListener('DOMContentLoaded', () => {
    cargarMateriaActiva();
    setInterval(cargarMateriaActiva, 60000);
    conectarSSEUltimo();
    conectarSSETodos();
  });

  window.addEventListener('beforeunload', () => {
    if (sseUltimo) sseUltimo.close();
    if (sseTodos)  sseTodos.close();
  });
</script>

</body>
</html>