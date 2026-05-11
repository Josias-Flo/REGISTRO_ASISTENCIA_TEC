<?php
session_start();

// ── URL del Apps Script ──────────────────────────────────────
$APPS_SCRIPT_URL = 'https://script.google.com/macros/s/AKfycbxtXZmzA8QnG1xO4kjEzx9sats4uSQRDrXWksD-p90Kc4stx4DhL1uML6Fg6AHEjzE1lg/exec';

// ── Usuarios hardcodeados ────────────────────────────────────
$usuarios_fijos = [
    'MAESTRO TEC' => ['password' => 'REGISTROS2026TEC', 'rol' => 'admin'],
    'ALUMNO TEC'  => ['password' => 'TEC2026+',         'rol' => 'alumno'],
    'MAESTRO'     => ['password' => 'Itsc$Maestro#2026',       'rol' => 'maestro'],
];

$error = '';

// ── Función para validar maestro contra Excel ────────────────
function validarMaestroExcel($usuario, $password, $apps_script_url) {
    try {
        $url      = $apps_script_url . '?tipo=maestros';
        $contexto = stream_context_create([
            'http' => [
                'timeout'        => 10,
                'ignore_errors'  => true,
                'follow_location'=> true,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ]
        ]);

        $json = @file_get_contents($url, false, $contexto);
        if (!$json) return null;

        $maestros = json_decode($json, true);
        if (!is_array($maestros)) return null;

        foreach ($maestros as $m) {
            $usuarioExcel   = isset($m['usuario'])  ? trim($m['usuario'])   : '';
            $passwordExcel  = isset($m['password']) ? trim($m['password'])  : '';
            $nombreExcel    = isset($m['nombre'])   ? trim($m['nombre'])    : '';
            $idExcel        = isset($m['id'])       ? trim($m['id'])        : '';

            if ($usuarioExcel === $usuario && $passwordExcel === $password) {
                return [
                    'rol'     => 'maestro',
                    'nombre'  => $nombreExcel,
                    'id'      => $idExcel,
                    'usuario' => $usuarioExcel,
                ];
            }
        }

        return null;

    } catch (Exception $e) {
        return null;
    }
}

// ── Procesar login ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario  = trim($_POST['usuario']  ?? '');
    $password = trim($_POST['password'] ?? '');

    // 1. Verificar usuarios fijos (admin y alumno)
    if (isset($usuarios_fijos[$usuario]) && $usuarios_fijos[$usuario]['password'] === $password) {
        $_SESSION['usuario'] = $usuario;
        $_SESSION['rol']     = $usuarios_fijos[$usuario]['rol'];

       if ($usuarios_fijos[$usuario]['rol'] === 'admin') {
    header('Location: index.php');
} elseif ($usuarios_fijos[$usuario]['rol'] === 'maestro') {
    $_SESSION['nombre_maestro'] = $usuario;
    $_SESSION['id_maestro']     = 'HARDCODED';
    header('Location: maestro.php');
} else {
    header('Location: bienvenida.php');
}
        exit;
    }

    // 2. Verificar maestro contra Excel
    $maestro = validarMaestroExcel($usuario, $password, $APPS_SCRIPT_URL);
    if ($maestro) {
        $_SESSION['usuario']        = $maestro['usuario'];
        $_SESSION['rol']            = 'maestro';
        $_SESSION['nombre_maestro'] = $maestro['nombre'];
        $_SESSION['id_maestro']     = $maestro['id'];
        header('Location: maestro.php');
        exit;
    }

    // 3. Credenciales incorrectas
    $error = 'Usuario o contraseña incorrectos.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login – ESP32 Asistencia</title>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --azul-oscuro:  #0b1f45;
      --azul-medio:   #1e3c72;
      --azul-claro:   #2a5298;
      --acento:       #4a90d9;
      --blanco:       #ffffff;
      --gris-claro:   #f0f4f8;
      --gris-texto:   #8899aa;
      --error:        #e74c3c;
      --radius:       14px;
    }

    html, body {
      height: 100%;
      font-family: 'Outfit', sans-serif;
      background: var(--azul-oscuro);
      overflow: hidden;
    }

    .login-wrapper {
      display: flex;
      height: 100vh;
      width: 100vw;
    }

    /* PANEL IZQUIERDO */
    .panel-left {
      flex: 1;
      position: relative;
      display: flex;
      flex-direction: column;
      justify-content: flex-end;
      padding: 3rem;
      overflow: hidden;
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
      background: linear-gradient(
        to bottom,
        rgba(11,31,69,0.35) 0%,
        rgba(11,31,69,0.72) 60%,
        rgba(11,31,69,0.92) 100%
      );
      backdrop-filter: blur(3px);
      -webkit-backdrop-filter: blur(3px);
    }

    .panel-left .logo-area {
      position: absolute;
      top: 2.5rem;
      left: 3rem;
      z-index: 2;
    }

    .panel-left .logo-box {
      width: 72px;
      height: 72px;
      border-radius: 16px;
      background: rgba(255,255,255,0.15);
      border: 1px solid rgba(255,255,255,0.25);
      backdrop-filter: blur(8px);
      display: flex;
      align-items: center;
      justify-content: center;
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

    .panel-left .features {
      list-style: none;
      display: flex;
      flex-direction: column;
      gap: 0.6rem;
    }

    .panel-left .features li {
      display: flex;
      align-items: center;
      gap: 0.6rem;
      font-size: 0.9rem;
      color: rgba(255,255,255,0.85);
    }

    .panel-left .features li::before {
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

    .panel-left .credit {
      margin-top: 2rem;
      font-size: 0.78rem;
      color: rgba(255,255,255,0.45);
      font-style: italic;
    }

    /* PANEL DERECHO */
    .panel-right {
      width: 460px;
      background: var(--blanco);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 3rem 3.5rem;
      position: relative;
      box-shadow: -20px 0 60px rgba(0,0,0,0.35);
      animation: slideIn 0.5s ease forwards;
    }

    .panel-right h2 {
      font-family: 'Bebas Neue', sans-serif;
      font-size: 2.2rem;
      letter-spacing: 2px;
      color: var(--azul-oscuro);
      margin-bottom: 0.4rem;
      text-align: center;
    }

    .panel-right .tagline {
      font-size: 0.875rem;
      color: var(--gris-texto);
      text-align: center;
      margin-bottom: 2.5rem;
    }

    .field { width: 100%; margin-bottom: 1.25rem; }

    .field label {
      display: flex;
      align-items: center;
      gap: 0.4rem;
      font-size: 0.85rem;
      font-weight: 500;
      color: #445;
      margin-bottom: 0.5rem;
    }

    .field label svg { width: 16px; height: 16px; opacity: 0.6; }

    .field .input-wrap { position: relative; }

    .field input {
      width: 100%;
      padding: 0.85rem 1rem;
      border: 1.5px solid #dde3ee;
      border-radius: var(--radius);
      font-family: 'Outfit', sans-serif;
      font-size: 0.95rem;
      color: var(--azul-oscuro);
      background: var(--gris-claro);
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
    }

    .field input:focus {
      border-color: var(--acento);
      box-shadow: 0 0 0 3px rgba(74,144,217,0.15);
      background: #fff;
    }

    .toggle-pass {
      position: absolute;
      right: 0.9rem;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      cursor: pointer;
      color: var(--gris-texto);
      padding: 0;
      display: flex;
      align-items: center;
    }

    /* Loader mientras valida contra Excel */
    .btn-login {
      width: 100%;
      padding: 0.95rem;
      background: linear-gradient(135deg, var(--azul-medio), var(--azul-claro));
      color: var(--blanco);
      border: none;
      border-radius: var(--radius);
      font-family: 'Outfit', sans-serif;
      font-size: 1rem;
      font-weight: 600;
      letter-spacing: 0.5px;
      cursor: pointer;
      transition: transform 0.15s, box-shadow 0.15s, filter 0.15s;
      box-shadow: 0 4px 18px rgba(30,60,114,0.35);
      margin-top: 0.5rem;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
    }

    .btn-login:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(30,60,114,0.45);
      filter: brightness(1.08);
    }

    .btn-login:active { transform: translateY(0); }

    .btn-login.loading {
      opacity: 0.8;
      cursor: not-allowed;
      pointer-events: none;
    }

    .spinner {
      width: 18px;
      height: 18px;
      border: 2px solid rgba(255,255,255,0.4);
      border-top-color: #fff;
      border-radius: 50%;
      animation: spin 0.7s linear infinite;
      display: none;
    }

    .btn-login.loading .spinner { display: block; }
    .btn-login.loading .btn-text { display: none; }

    .error-msg {
      width: 100%;
      background: #fdecea;
      border: 1px solid #f5c6cb;
      color: var(--error);
      border-radius: 10px;
      padding: 0.75rem 1rem;
      font-size: 0.85rem;
      margin-bottom: 1.25rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .panel-right .footer {
      position: absolute;
      bottom: 1.5rem;
      font-size: 0.75rem;
      color: #bbc;
      text-align: center;
    }

    @keyframes slideIn {
      from { opacity: 0; transform: translateX(30px); }
      to   { opacity: 1; transform: translateX(0); }
    }

    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(20px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }

    .panel-left .logo-area { animation: fadeUp 0.5s ease both; }

    @media (max-width: 768px) {
      .panel-left { display: none; }
      .panel-right { width: 100%; padding: 2rem 1.5rem; }
    }
  </style>
</head>
<body>

<div class="login-wrapper">

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
    <img src="logo_tec.png" alt="Logo" style="width:180px;height:180px;object-fit:contain;border-radius:16px;margin-bottom:1rem;">
    <h2>BIENVENIDO</h2>
    <p class="tagline">Ingresa tus credenciales para continuar</p>

    <?php if ($error): ?>
    <div class="error-msg">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <form method="POST" style="width:100%" id="loginForm">

      <div class="field">
        <label>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
          </svg>
          Usuario
        </label>
        <div class="input-wrap">
          <input type="text" name="usuario" placeholder="Ingresa tu usuario"
                 autocomplete="off"
                 value="<?php echo htmlspecialchars($_POST['usuario'] ?? ''); ?>" required>
        </div>
      </div>

      <div class="field">
        <label>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="11" width="18" height="11" rx="2"/>
            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
          </svg>
          Contraseña
        </label>
        <div class="input-wrap">
          <input type="password" name="password" id="passInput"
                 placeholder="Ingresa tu contraseña" required>
          <button type="button" class="toggle-pass" onclick="togglePass()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
      </div>

      <button type="submit" class="btn-login" id="btnLogin">
        <div class="spinner"></div>
        <span class="btn-text">Iniciar Sesión →</span>
      </button>

    </form>

    <div class="footer">© 2026 ITSC – Todos los derechos reservados para VBCR</div>
  </div>

</div>

<script>
  function togglePass() {
    const input = document.getElementById('passInput');
    input.type = input.type === 'password' ? 'text' : 'password';
  }

  // Mostrar spinner al enviar (por si tarda en consultar el Excel)
  document.getElementById('loginForm').addEventListener('submit', function () {
    const btn = document.getElementById('btnLogin');
    btn.classList.add('loading');
  });

  // Enter para enviar
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      document.getElementById('loginForm').submit();
    }
  });
</script>

</body>
</html>