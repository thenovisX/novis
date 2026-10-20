<?php
// Novis — panel: seguridad de la contraseña de administrador.
// Genera la versión cifrada (hash bcrypt) para guardarla en gemini-config.php en lugar de la contraseña.
require_once __DIR__ . '/../lib/novis.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,
    'path' => '/panel/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_name('novis_panel');
session_start();
if (empty($_SESSION['ok'])) { header('Location: ./'); exit; }

function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

$config = novis_config();
$yaHash = (string)($config['panel_password_hash'] ?? '') !== '';
$linea = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
        $error = 'La página ha caducado. Inténtalo de nuevo.';
    } else {
        $p1 = (string)($_POST['password'] ?? '');
        if (mb_strlen($p1) < 12) $error = 'Usa al menos 12 caracteres (mejor una frase larga).';
        elseif ($p1 !== (string)($_POST['password2'] ?? '')) $error = 'Las contraseñas no coinciden.';
        else $linea = "'panel_password_hash' => '" . password_hash($p1, PASSWORD_DEFAULT) . "',";
    }
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Seguridad · Novis</title>
<link rel="icon" href="../assets/favicon.svg?v=10" type="image/svg+xml">
<meta name="theme-color" content="#f5f5f7">
<link rel="stylesheet" href="panel.css?v=15">
</head>
<body class="v-pagos">
  <header class="top top--detail"><a href="./" class="back">‹ Solicitudes</a></header>
  <main class="detail">
    <section class="card">
      <h1>Seguridad</h1>
      <?php if ($yaHash): ?>
        <p class="toast" role="status">La contraseña del panel ya está guardada cifrada ✓</p>
      <?php else: ?>
        <p class="muted">Ahora mismo la contraseña del panel está escrita tal cual en <code>gemini-config.php</code>. Con este paso se guarda solo su versión cifrada: aunque alguien viera el archivo, no podría entrar.</p>
      <?php endif; ?>
    </section>

    <?php if ($linea): ?>
    <section class="card">
      <h2>Tu línea cifrada</h2>
      <ol class="pasos">
        <li>Copia esta línea:</li>
      </ol>
      <textarea class="msgbox" rows="4" readonly id="linea"><?= e($linea) ?></textarea>
      <div class="actions"><button class="btn" type="button" data-copy-from="linea" data-copied="Copiado ✓">Copiar línea</button></div>
      <ol class="pasos" start="2">
        <li>En hPanel abre el <strong>Administrador de archivos</strong>, entra en la carpeta que contiene <code>public_html</code> y edita <code>gemini-config.php</code>.</li>
        <li><strong>Borra</strong> la línea <code>'panel_password' =&gt; '…'</code> y pega en su lugar la línea de arriba. Guarda.</li>
        <li>Cierra sesión y entra de nuevo con tu contraseña de siempre para comprobar que funciona.</li>
      </ol>
      <p class="muted small">Esta línea no se guarda en ningún sitio: si cierras esta página, genera otra. Si la copias a <code>gemini-config.php</code> sin borrar <code>panel_password</code>, el panel usará el cifrado y ignorará la otra, pero conviene borrarla.</p>
    </section>
    <?php else: ?>
    <section class="card">
      <h2><?= $yaHash ? 'Cambiar la contraseña' : 'Cifrar la contraseña' ?></h2>
      <form method="post" class="form-pago" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <label>Contraseña del panel <span class="muted">(la actual, o una nueva; mínimo 12 caracteres)</span>
          <input name="password" type="password" autocomplete="new-password" required></label>
        <label>Repítela
          <input name="password2" type="password" autocomplete="new-password" required></label>
        <?php if ($error): ?><p class="f-err"><?= e($error) ?></p><?php endif; ?>
        <button class="btn">Generar línea cifrada</button>
      </form>
    </section>
    <?php endif; ?>
  </main>
  <script src="panel.js?v=3" defer></script>
</body>
</html>
