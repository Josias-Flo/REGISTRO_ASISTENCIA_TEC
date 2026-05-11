<?php
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$BACKEND_URL     = 'http://localhost:5000';
$APPS_SCRIPT_URL = 'https://script.google.com/macros/s/AKfycbxtXZmzA8QnG1xO4kjEzx9sats4uSQRDrXWksD-p90Kc4stx4DhL1uML6Fg6AHEjzE1lg/exec';

$id_maestro_filtro = trim($_GET['id_maestro'] ?? '');

function backendGet($url) {
    $ctx = stream_context_create([
        'http' => ['timeout' => 12],
        'ssl'  => ['verify_peer' => false],
    ]);
    $json = @file_get_contents($url, false, $ctx);
    return json_decode($json, true) ?? [];
}

function appsGet($url, $params = []) {
    $query = $params ? '?' . http_build_query($params) : '';
    $ctx   = stream_context_create([
        'http' => ['timeout' => 12, 'follow_location' => true],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $json = @file_get_contents($url . $query, false, $ctx);
    return json_decode($json, true) ?? [];
}

// ── Cargar datos ──────────────────────────────────────────
$todos_registros = backendGet($BACKEND_URL . '/api/asistencia');
$materia_activa  = backendGet($BACKEND_URL . '/api/materia-activa');
$todas_materias  = appsGet($APPS_SCRIPT_URL, ['tipo' => 'materias']);

// Filtrar por maestro si viene en la URL
if ($id_maestro_filtro) {
    $materias = array_values(array_filter($todas_materias, fn($m) => ($m['id_maestro'] ?? '') === $id_maestro_filtro));
} else {
    $materias = $todas_materias;
}

$nombres_materias = array_column($materias, 'nombre');

// Filtrar registros a solo las materias del scope
$registros = array_values(array_filter($todos_registros, function($r) use ($nombres_materias) {
    return in_array($r['materia'] ?? '', $nombres_materias);
}));

// ── Calcular estadísticas por materia ─────────────────────
$stats_materias = [];
foreach ($materias as $m) {
    $nombre       = $m['nombre'];
    $total_clases = intval($m['total_clases'] ?? 0);

    // Registros de esta materia
    $regs_materia = array_filter($registros, fn($r) => ($r['materia'] ?? '') === $nombre);

    // UIDs únicos con nombre (alumnos registrados)
    $uids_con_nombre = [];
    $uids_sin_nombre = [];
    foreach ($regs_materia as $r) {
        $uid    = strtoupper($r['usuario'] ?? '');
        $nombre_alumno = trim($r['nombre'] ?? '');
        if ($uid) {
            if ($nombre_alumno && $nombre_alumno !== '--') {
                $uids_con_nombre[$uid] = $nombre_alumno;
            } else {
                if (!isset($uids_con_nombre[$uid])) {
                    $uids_sin_nombre[$uid] = true;
                }
            }
        }
    }

    // Calcular asistencias por alumno
    $alumnos_stats = [];
    foreach ($uids_con_nombre as $uid => $nombre_a) {
        $asistencias = count(array_filter($regs_materia, fn($r) => strtoupper($r['usuario'] ?? '') === $uid));
        $pct         = $total_clases > 0 ? min(round($asistencias / $total_clases * 100, 1), 100) : 0;
        $estatus     = $pct >= 95 ? 'EXCELENTE' : ($pct >= 85 ? 'BUENO' : ($pct >= 70 ? 'REGULAR' : 'EN RIESGO'));
        $alumnos_stats[] = [
            'uid'         => $uid,
            'nombre'      => $nombre_a,
            'asistencias' => $asistencias,
            'pct'         => $pct,
            'estatus'     => $estatus,
        ];
    }

    usort($alumnos_stats, fn($a, $b) => $b['pct'] <=> $a['pct']);

    $total_alumnos   = count($uids_con_nombre);
    $total_pendientes= count(array_diff_key($uids_sin_nombre, $uids_con_nombre));
    $excelente       = count(array_filter($alumnos_stats, fn($a) => $a['estatus'] === 'EXCELENTE'));
    $bueno           = count(array_filter($alumnos_stats, fn($a) => $a['estatus'] === 'BUENO'));
    $regular         = count(array_filter($alumnos_stats, fn($a) => $a['estatus'] === 'REGULAR'));
    $en_riesgo       = count(array_filter($alumnos_stats, fn($a) => $a['estatus'] === 'EN RIESGO'));

    $pct_promedio = $total_alumnos > 0
        ? round(array_sum(array_column($alumnos_stats, 'pct')) / $total_alumnos, 1)
        : 0;

    // Registros de hoy
    $hoy          = date('d/m/Y');
    $hoy_count    = count(array_filter($regs_materia, fn($r) => ($r['fecha'] ?? '') === $hoy));

    $stats_materias[] = [
        'materia'       => $nombre,
        'carrera'       => $m['carrera'] ?? '--',
        'grupo'         => $m['grupo']   ?? '--',
        'total_clases'  => $total_clases,
        'total_alumnos' => $total_alumnos,
        'pendientes'    => $total_pendientes,
        'excelente'     => $excelente,
        'bueno'         => $bueno,
        'regular'       => $regular,
        'en_riesgo'     => $en_riesgo,
        'pct_promedio'  => $pct_promedio,
        'hoy'           => $hoy_count,
        'alumnos'       => $alumnos_stats,
        'total_regs'    => count($regs_materia),
    ];
}

// ── Totales globales ──────────────────────────────────────
$total_registros_global = count($registros);
$hoy                    = date('d/m/Y');
$registros_hoy          = count(array_filter($registros, fn($r) => ($r['fecha'] ?? '') === $hoy));
$pendientes_global      = array_sum(array_column($stats_materias, 'pendientes'));
$en_riesgo_global       = array_sum(array_column($stats_materias, 'en_riesgo'));
$nombre_activa          = $materia_activa['nombre']      ?? '';
$maestro_activa         = $materia_activa['maestro']     ?? '';
$hora_activa            = $materia_activa['hora_inicio'] ?? '';

function fmtHora($h) {
    if (!$h || $h === '--') return '--';
    if (strpos($h, 'GMT') !== false) {
        $p = explode(' ', $h);
        if (isset($p[4])) { $hms = explode(':', $p[4]); return $hms[0].':'.($hms[1]??'00'); }
    }
    $p = explode(':', $h);
    return count($p) >= 2 ? $p[0].':'.$p[1] : $h;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Estadísticas – ESP32 Asistencia</title>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{
      --azul-oscuro:#0b1f45;--azul-medio:#1e3c72;--azul-claro:#2a5298;
      --acento:#4a90d9;--blanco:#ffffff;--gris-claro:#f0f4f8;
      --gris-texto:#8899aa;--verde:#27ae60;--rojo:#e74c3c;
      --amarillo:#f39c12;--azul:#2980b9;--radius:14px;
    }
    html,body{min-height:100vh;font-family:'Outfit',sans-serif;background:var(--gris-claro);color:#2c3e50}

    /* NAVBAR */
    .navbar{background:linear-gradient(135deg,var(--azul-medio),var(--azul-claro));padding:0 2rem;height:64px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 12px rgba(0,0,0,.2);position:sticky;top:0;z-index:100}
    .navbar-brand{display:flex;align-items:center;gap:.75rem;color:var(--blanco);text-decoration:none}
    .navbar-brand .logo-box{width:40px;height:40px;border-radius:10px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;overflow:hidden}
    .navbar-brand span{font-family:'Bebas Neue',sans-serif;font-size:1.4rem;letter-spacing:1.5px}
    .navbar-actions{display:flex;align-items:center;gap:1rem}
    .btn-nav{display:flex;align-items:center;gap:.4rem;padding:.45rem 1rem;border-radius:8px;font-family:'Outfit',sans-serif;font-size:.85rem;font-weight:500;cursor:pointer;text-decoration:none;transition:all .2s;border:none}
    .btn-nav-outline{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);color:var(--blanco)}
    .btn-nav-outline:hover{background:rgba(255,255,255,.22)}
    .btn-nav-danger{background:rgba(231,76,60,.2);border:1px solid rgba(231,76,60,.4);color:#ff8a80}
    .btn-nav-danger:hover{background:rgba(231,76,60,.35)}

    /* LAYOUT */
    .page-container{max-width:1400px;margin:2rem auto;padding:0 1.5rem}

    /* KPIS */
    .kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem}
    .kpi{background:var(--blanco);border-radius:16px;padding:1.4rem 1.5rem;box-shadow:0 2px 12px rgba(0,0,0,.07);display:flex;align-items:center;gap:1rem;animation:fadeIn .4s ease both}
    .kpi-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0}
    .kpi-icon.azul{background:rgba(74,144,217,.12);color:var(--acento)}
    .kpi-icon.verde{background:rgba(39,174,96,.12);color:var(--verde)}
    .kpi-icon.rojo{background:rgba(231,76,60,.12);color:var(--rojo)}
    .kpi-icon.naranja{background:rgba(243,156,18,.12);color:var(--amarillo)}
    .kpi-val{font-family:'Bebas Neue',sans-serif;font-size:2rem;line-height:1;color:var(--azul-oscuro)}
    .kpi-label{font-size:.75rem;color:var(--gris-texto);margin-top:.2rem;text-transform:uppercase;letter-spacing:.5px}

    /* BANNER ACTIVA */
    .banner-activa{background:linear-gradient(135deg,#27ae60,#2ecc71);border-radius:16px;padding:1.2rem 1.8rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:1rem;color:#fff;box-shadow:0 4px 14px rgba(39,174,96,.3);animation:fadeIn .4s ease .1s both}
    .banner-activa i{font-size:1.6rem;opacity:.9}
    .banner-activa .ba-nombre{font-family:'Bebas Neue',sans-serif;font-size:1.4rem;letter-spacing:1px}
    .banner-activa .ba-sub{font-size:.82rem;opacity:.85;margin-top:.1rem}
    .banner-inactiva{background:var(--blanco);border:2px dashed #dde3ee;border-radius:16px;padding:1rem 1.8rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:.75rem;color:var(--gris-texto);font-size:.88rem;animation:fadeIn .4s ease .1s both}

    /* CARDS */
    .card{background:var(--blanco);border-radius:16px;box-shadow:0 2px 12px rgba(0,0,0,.07);overflow:hidden;margin-bottom:1.5rem;animation:fadeIn .4s ease both}
    .card-header{padding:1.1rem 1.5rem;border-bottom:1px solid #eef0f5;display:flex;align-items:center;gap:.75rem}
    .card-header-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
    .icon-azul{background:rgba(74,144,217,.12);color:var(--acento)}
    .icon-gris{background:rgba(136,153,170,.12);color:var(--gris-texto)}
    .card-header h3{font-size:.95rem;font-weight:600;color:var(--azul-oscuro)}
    .card-header p{font-size:.75rem;color:var(--gris-texto);margin-top:.1rem}
    .card-body{padding:1.5rem}

    /* GRID MATERIAS */
    .grid-materias{display:grid;grid-template-columns:repeat(auto-fill,minmax(420px,1fr));gap:1.2rem;margin-bottom:1.5rem}
    .materia-card{background:var(--blanco);border-radius:16px;box-shadow:0 2px 12px rgba(0,0,0,.07);overflow:hidden;animation:fadeIn .4s ease both;border-top:4px solid var(--acento)}
    .materia-card.activa{border-top-color:var(--verde)}
    .mc-header{padding:1rem 1.2rem;border-bottom:1px solid #eef0f5}
    .mc-nombre{font-weight:700;font-size:.95rem;color:var(--azul-oscuro)}
    .mc-sub{font-size:.75rem;color:var(--gris-texto);margin-top:.2rem}
    .mc-body{padding:1rem 1.2rem}

    /* DONA CSS */
    .dona-wrap{display:flex;align-items:center;gap:1.5rem;margin-bottom:1rem}
    .dona{position:relative;width:90px;height:90px;flex-shrink:0}
    .dona svg{width:90px;height:90px;transform:rotate(-90deg)}
    .dona-track{fill:none;stroke:#eef0f5;stroke-width:10}
    .dona-fill{fill:none;stroke-width:10;stroke-linecap:round;transition:stroke-dasharray .8s ease}
    .dona-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center}
    .dona-pct{font-family:'Bebas Neue',sans-serif;font-size:1.3rem;line-height:1;color:var(--azul-oscuro)}
    .dona-lbl{font-size:.6rem;color:var(--gris-texto);text-transform:uppercase;letter-spacing:.5px}

    /* MINI STATS */
    .mini-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:.6rem;margin-bottom:1rem}
    .mini-stat{border-radius:10px;padding:.6rem .5rem;text-align:center}
    .mini-stat.excelente{background:rgba(39,174,96,.1)}
    .mini-stat.bueno{background:rgba(41,128,185,.1)}
    .mini-stat.regular{background:rgba(243,156,18,.1)}
    .mini-stat.riesgo{background:rgba(231,76,60,.1)}
    .mini-stat .ms-num{font-family:'Bebas Neue',sans-serif;font-size:1.4rem;line-height:1}
    .mini-stat.excelente .ms-num{color:var(--verde)}
    .mini-stat.bueno .ms-num{color:var(--azul)}
    .mini-stat.regular .ms-num{color:var(--amarillo)}
    .mini-stat.riesgo .ms-num{color:var(--rojo)}
    .mini-stat .ms-lbl{font-size:.62rem;color:var(--gris-texto);margin-top:.15rem;text-transform:uppercase;letter-spacing:.4px}

    /* BARRA HORIZONTAL */
    .barra-wrap{margin-bottom:.6rem}
    .barra-header{display:flex;justify-content:space-between;font-size:.78rem;color:#556;margin-bottom:.3rem}
    .barra-track{height:8px;background:#eef0f5;border-radius:99px;overflow:hidden}
    .barra-fill{height:100%;border-radius:99px;transition:width .8s ease}
    .barra-fill.excelente{background:var(--verde)}
    .barra-fill.bueno{background:var(--azul)}
    .barra-fill.regular{background:var(--amarillo)}
    .barra-fill.riesgo{background:var(--rojo)}

    /* TABLA ALUMNOS */
    .tabla-alumnos-wrap{max-height:220px;overflow-y:auto;margin-top:.8rem}
    .tabla-alumnos-wrap::-webkit-scrollbar{width:6px}
    .tabla-alumnos-wrap::-webkit-scrollbar-thumb{background:#cbd5e0;border-radius:3px}
    table.ta{width:100%;border-collapse:collapse;font-size:.8rem}
    table.ta thead th{background:var(--gris-claro);padding:.5rem .7rem;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gris-texto);position:sticky;top:0}
    table.ta tbody td{padding:.55rem .7rem;border-bottom:1px solid #f0f4f8;vertical-align:middle}
    table.ta tbody tr:last-child td{border-bottom:none}
    table.ta tbody tr:hover{background:#f8faff}
    .badge-est{font-size:.68rem;font-weight:600;padding:.2rem .55rem;border-radius:5px}
    .badge-est.EXCELENTE{background:rgba(39,174,96,.12);color:var(--verde)}
    .badge-est.BUENO{background:rgba(41,128,185,.12);color:var(--azul)}
    .badge-est.REGULAR{background:rgba(243,156,18,.12);color:var(--amarillo)}
    .badge-est.EN\ RIESGO{background:rgba(231,76,60,.12);color:var(--rojo)}
    .uid-sm{font-family:'Courier New',monospace;font-size:.72rem;color:var(--acento)}

    /* ÚLTIMOS REGISTROS */
    .ultimos-list{display:flex;flex-direction:column;gap:.6rem}
    .ultimo-item{display:flex;align-items:center;gap:.75rem;padding:.7rem 1rem;background:var(--gris-claro);border-radius:10px}
    .ultimo-dot{width:8px;height:8px;border-radius:50%;background:var(--verde);flex-shrink:0;animation:pulse 2s infinite}
    .ultimo-info{flex:1;font-size:.82rem}
    .ultimo-info strong{color:var(--azul-oscuro)}
    .ultimo-info span{color:var(--gris-texto);font-size:.75rem}
    .ultimo-hora{font-family:'Courier New',monospace;font-size:.78rem;color:var(--acento);white-space:nowrap}
    .empty-state{text-align:center;padding:2rem;color:var(--gris-texto);font-size:.85rem}
    .empty-state i{font-size:2rem;display:block;margin-bottom:.5rem;opacity:.35}

    /* CONEXIÓN */
    .live-badge{display:inline-flex;align-items:center;gap:.4rem;padding:.3rem .8rem;border-radius:20px;font-size:.78rem;font-weight:500;background:#d1e7dd;color:#0f5132;margin-left:auto}
    .pulse-dot{width:6px;height:6px;border-radius:50%;background:currentColor;animation:pulse 2s infinite}

    @keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
    @keyframes fadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
    @media(max-width:900px){.kpis{grid-template-columns:repeat(2,1fr)}.grid-materias{grid-template-columns:1fr}}
    @media(max-width:500px){.kpis{grid-template-columns:1fr}}
  </style>
</head>
<body>

<nav class="navbar">
  <a href="index.php" class="navbar-brand">
    <div class="logo-box"><img src="logo_tec.png" alt="Logo" style="width:100%;height:100%;object-fit:contain;"></div>
    <span>ITSC ASISTENCIA</span>
  </a>
  <div class="navbar-actions">
    <a href="javascript:history.back()" class="btn-nav btn-nav-outline">
      <i class="bi bi-arrow-left"></i> Volver
    </a>
  </div>
</nav>

<div class="page-container">

  <!-- KPIS GLOBALES -->
  <div class="kpis">
    <div class="kpi">
      <div class="kpi-icon azul"><i class="bi bi-journal-check"></i></div>
      <div>
        <div class="kpi-val"><?php echo $total_registros_global; ?></div>
        <div class="kpi-label">Registros totales</div>
      </div>
    </div>
    <div class="kpi">
      <div class="kpi-icon verde"><i class="bi bi-calendar-check"></i></div>
      <div>
        <div class="kpi-val"><?php echo $registros_hoy; ?></div>
        <div class="kpi-label">Registros hoy</div>
      </div>
    </div>
    <div class="kpi">
      <div class="kpi-icon rojo"><i class="bi bi-exclamation-triangle"></i></div>
      <div>
        <div class="kpi-val"><?php echo $en_riesgo_global; ?></div>
        <div class="kpi-label">En riesgo</div>
      </div>
    </div>
    <div class="kpi">
      <div class="kpi-icon naranja"><i class="bi bi-person-exclamation"></i></div>
      <div>
        <div class="kpi-val"><?php echo $pendientes_global; ?></div>
        <div class="kpi-label">Sin registrar</div>
      </div>
    </div>
  </div>

  <!-- BANNER MATERIA ACTIVA -->
  <?php if ($nombre_activa): ?>
  <div class="banner-activa">
    <i class="bi bi-broadcast"></i>
    <div>
      <div class="ba-nombre"><?php echo htmlspecialchars($nombre_activa); ?></div>
      <div class="ba-sub">
        <?php echo htmlspecialchars($maestro_activa); ?> &nbsp;·&nbsp;
        Iniciada a las <?php echo htmlspecialchars(fmtHora($hora_activa)); ?>
      </div>
    </div>
    <div class="live-badge" style="margin-left:auto;">
      <span class="pulse-dot"></span> En curso
    </div>
  </div>
  <?php else: ?>
  <div class="banner-inactiva">
    <i class="bi bi-pause-circle" style="font-size:1.2rem;"></i>
    Ninguna materia activa en este momento.
  </div>
  <?php endif; ?>

  <!-- GRID DE MATERIAS -->
  <div class="grid-materias">
    <?php foreach ($stats_materias as $s): ?>
    <?php
      $circonferencia = 2 * M_PI * 35; // radio 35
      $offset         = $circonferencia - ($s['pct_promedio'] / 100 * $circonferencia);
      $color_dona     = $s['pct_promedio'] >= 95 ? '#27ae60'
                      : ($s['pct_promedio'] >= 85 ? '#2980b9'
                      : ($s['pct_promedio'] >= 70 ? '#f39c12' : '#e74c3c'));
      $es_activa      = $nombre_activa && $nombre_activa === $s['materia'];
    ?>
    <div class="materia-card <?php echo $es_activa ? 'activa' : ''; ?>">
      <div class="mc-header">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;">
          <div>
            <div class="mc-nombre"><?php echo htmlspecialchars($s['materia']); ?></div>
            <div class="mc-sub">
              <?php echo htmlspecialchars($s['carrera']); ?> &nbsp;|&nbsp;
              Grupo <?php echo htmlspecialchars($s['grupo']); ?> &nbsp;|&nbsp;
              <?php echo $s['total_clases']; ?> clases
            </div>
          </div>
          <?php if ($es_activa): ?>
          <span style="background:rgba(39,174,96,.12);color:var(--verde);font-size:.7rem;font-weight:600;padding:.25rem .6rem;border-radius:6px;white-space:nowrap;">
            <i class="bi bi-broadcast me-1"></i>ACTIVA
          </span>
          <?php endif; ?>
        </div>
      </div>
      <div class="mc-body">

        <!-- Dona + resumen -->
        <div class="dona-wrap">
          <div class="dona">
            <svg viewBox="0 0 90 90">
              <circle class="dona-track" cx="45" cy="45" r="35"/>
              <circle class="dona-fill" cx="45" cy="45" r="35"
                stroke="<?php echo $color_dona; ?>"
                stroke-dasharray="<?php echo round($circonferencia - $offset, 2) . ' ' . round($circonferencia, 2); ?>"/>
            </svg>
            <div class="dona-center">
              <div class="dona-pct"><?php echo $s['pct_promedio']; ?>%</div>
              <div class="dona-lbl">Prom.</div>
            </div>
          </div>
          <div style="flex:1;">
            <div style="font-size:.82rem;color:#556;margin-bottom:.5rem;">
              <strong style="color:var(--azul-oscuro);"><?php echo $s['total_alumnos']; ?></strong> alumnos registrados
              <?php if ($s['pendientes'] > 0): ?>
              &nbsp;·&nbsp;
              <span style="color:var(--amarillo);font-weight:600;"><?php echo $s['pendientes']; ?> sin nombre</span>
              <?php endif; ?>
            </div>
            <div style="font-size:.82rem;color:#556;margin-bottom:.5rem;">
              <i class="bi bi-calendar-check" style="color:var(--verde);"></i>
              <strong><?php echo $s['hoy']; ?></strong> asistencias hoy &nbsp;·&nbsp;
              <strong><?php echo $s['total_regs']; ?></strong> totales
            </div>
          </div>
        </div>

        <!-- Mini stats estatus -->
        <div class="mini-stats">
          <div class="mini-stat excelente">
            <div class="ms-num"><?php echo $s['excelente']; ?></div>
            <div class="ms-lbl">Excelente</div>
          </div>
          <div class="mini-stat bueno">
            <div class="ms-num"><?php echo $s['bueno']; ?></div>
            <div class="ms-lbl">Bueno</div>
          </div>
          <div class="mini-stat regular">
            <div class="ms-num"><?php echo $s['regular']; ?></div>
            <div class="ms-lbl">Regular</div>
          </div>
          <div class="mini-stat riesgo">
            <div class="ms-num"><?php echo $s['en_riesgo']; ?></div>
            <div class="ms-lbl">En riesgo</div>
          </div>
        </div>

        <!-- Barras de distribución -->
        <?php if ($s['total_alumnos'] > 0):
          $pct_exc = round($s['excelente'] / $s['total_alumnos'] * 100);
          $pct_bue = round($s['bueno']     / $s['total_alumnos'] * 100);
          $pct_reg = round($s['regular']   / $s['total_alumnos'] * 100);
          $pct_rie = round($s['en_riesgo'] / $s['total_alumnos'] * 100);
        ?>
        <div class="barra-wrap">
          <div class="barra-header"><span>Excelente</span><span><?php echo $pct_exc; ?>%</span></div>
          <div class="barra-track"><div class="barra-fill excelente" style="width:<?php echo $pct_exc; ?>%"></div></div>
        </div>
        <div class="barra-wrap">
          <div class="barra-header"><span>Bueno</span><span><?php echo $pct_bue; ?>%</span></div>
          <div class="barra-track"><div class="barra-fill bueno" style="width:<?php echo $pct_bue; ?>%"></div></div>
        </div>
        <div class="barra-wrap">
          <div class="barra-header"><span>Regular</span><span><?php echo $pct_reg; ?>%</span></div>
          <div class="barra-track"><div class="barra-fill regular" style="width:<?php echo $pct_reg; ?>%"></div></div>
        </div>
        <div class="barra-wrap">
          <div class="barra-header"><span>En riesgo</span><span><?php echo $pct_rie; ?>%</span></div>
          <div class="barra-track"><div class="barra-fill riesgo" style="width:<?php echo $pct_rie; ?>%"></div></div>
        </div>
        <?php endif; ?>

        <!-- Tabla de alumnos -->
        <?php if (!empty($s['alumnos'])): ?>
        <div class="tabla-alumnos-wrap">
          <table class="ta">
            <thead>
              <tr>
                <th>Alumno</th>
                <th style="text-align:center;">Asist.</th>
                <th style="text-align:center;">%</th>
                <th style="text-align:center;">Estatus</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($s['alumnos'] as $a): ?>
              <tr>
                <td>
                  <div style="font-weight:500;"><?php echo htmlspecialchars($a['nombre']); ?></div>
                  <div class="uid-sm"><?php echo htmlspecialchars($a['uid']); ?></div>
                </td>
                <td style="text-align:center;"><?php echo $a['asistencias']; ?>/<?php echo $s['total_clases']; ?></td>
                <td style="text-align:center;font-weight:600;"><?php echo $a['pct']; ?>%</td>
                <td style="text-align:center;">
                  <span class="badge-est <?php echo htmlspecialchars($a['estatus']); ?>">
                    <?php echo htmlspecialchars($a['estatus']); ?>
                  </span>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state"><i class="bi bi-people"></i>Sin alumnos registrados aún.</div>
        <?php endif; ?>

      </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($stats_materias)): ?>
    <div style="grid-column:1/-1;">
      <div class="empty-state card" style="padding:3rem;">
        <i class="bi bi-inbox" style="font-size:3rem;display:block;margin-bottom:1rem;opacity:.3;"></i>
        No hay materias disponibles.
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ÚLTIMOS 10 REGISTROS EN VIVO -->
  <div class="card">
    <div class="card-header">
      <div class="card-header-icon icon-gris"><i class="bi bi-activity"></i></div>
      <div><h3>Últimos Registros</h3><p>Los 10 más recientes del sistema</p></div>
      <div class="live-badge">
        <span class="pulse-dot"></span>
        <span id="liveText">En vivo</span>
      </div>
    </div>
    <div class="card-body">
      <div class="ultimos-list" id="ultimosList">
        <?php
        $ultimos = array_slice($registros, 0, 10);
        if (empty($ultimos)):
        ?>
        <div class="empty-state"><i class="bi bi-inbox"></i>Sin registros aún.</div>
        <?php else: ?>
        <?php foreach ($ultimos as $r): ?>
        <div class="ultimo-item">
          <div class="ultimo-dot"></div>
          <div class="ultimo-info">
            <strong><?php echo htmlspecialchars($r['nombre'] && $r['nombre'] !== '--' ? $r['nombre'] : $r['usuario']); ?></strong>
            <span> · <?php echo htmlspecialchars($r['materia'] ?? '--'); ?> · Grupo <?php echo htmlspecialchars($r['grupo'] ?? '--'); ?></span>
          </div>
          <div class="ultimo-hora"><?php echo htmlspecialchars($r['fecha'] ?? '--'); ?> <?php echo htmlspecialchars($r['hora'] ?? '--'); ?></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<script>
  const BACKEND_URL  = '<?php echo $BACKEND_URL; ?>';
  const MIS_NOMBRES  = <?php echo json_encode($nombres_materias); ?>;
  let sse = null;

  function renderUltimos(registros) {
    const filtrados = MIS_NOMBRES.length
      ? registros.filter(r => MIS_NOMBRES.includes(r.materia || ''))
      : registros;

    const ultimos = filtrados.slice(0, 10);
    const lista   = document.getElementById('ultimosList');

    if (!ultimos.length) {
      lista.innerHTML = '<div class="empty-state"><i class="bi bi-inbox"></i>Sin registros aún.</div>';
      return;
    }

    lista.innerHTML = ultimos.map(r => `
      <div class="ultimo-item">
        <div class="ultimo-dot"></div>
        <div class="ultimo-info">
          <strong>${esc(r.nombre && r.nombre !== '--' ? r.nombre : r.usuario)}</strong>
          <span> · ${esc(r.materia||'--')} · Grupo ${esc(r.grupo||'--')}</span>
        </div>
        <div class="ultimo-hora">${esc(r.fecha||'--')} ${esc(r.hora||'--')}</div>
      </div>`).join('');

    document.getElementById('liveText').textContent =
      'En vivo · ' + new Date().toLocaleTimeString('es-MX');
  }

  function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  function conectarSSE() {
    if (sse) sse.close();
    sse = new EventSource(`${BACKEND_URL}/api/stream`);
    sse.onmessage = e => {
      try { renderUltimos(JSON.parse(e.data)); }
      catch(err) { console.error('SSE:', err); }
    };
    sse.onerror = () => {
      document.getElementById('liveText').textContent = 'Sin conexión';
      sse.close();
      setTimeout(conectarSSE, 3000);
    };
  }

  document.addEventListener('DOMContentLoaded', conectarSSE);
  window.addEventListener('beforeunload', () => { if (sse) sse.close(); });
</script>

</body>
</html>