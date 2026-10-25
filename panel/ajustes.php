<?php
// The Novis — panel privado: ajustes (tu información, tu correo, acceso al panel y base de datos).
require_once __DIR__ . '/../lib/cuentas.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

nv_panel_sesion();
if (empty($_SESSION['ok'])) { header('Location: ./'); exit; }
if (!novis_db_config()) { header('Location: instalar.php'); exit; }

function volver(string $q = ''): void { header('Location: ajustes.php' . ($q ? '?' . $q : '')); exit; }

$errores = [];
$seccion = '';
$viejo = [];
$cfg = novis_config();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!nv_csrf_ok()) volver();
    $accion = (string)($_POST['accion'] ?? '');
    $seccion = $accion;
    $viejo = $_POST;

    if ($accion === 'info') {
        $negocio = trim((string)($_POST['negocio'] ?? ''));
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $tel = trim((string)($_POST['telefono'] ?? ''));
        $titular = trim((string)($_POST['titular'] ?? ''));
        $nif = strtoupper(preg_replace('/[\s.-]/', '', (string)($_POST['nif'] ?? '')));
        $domicilio = trim(preg_replace('/\s+/', ' ', (string)($_POST['domicilio'] ?? '')));
        $localidad = trim((string)($_POST['localidad'] ?? ''));
        if ($negocio === '' || mb_strlen($negocio) > 80) $errores['negocio'] = 'Escribe el nombre de tu negocio (máximo 80 caracteres).';
        if (mb_strlen($titular) > 120) $errores['titular'] = 'Máximo 120 caracteres.';
        if ($nif !== '' && !preg_match('/^[A-Z0-9]{8,12}$/', $nif)) $errores['nif'] = 'NIF no válido (p. ej. 12345678Z o B12345678).';
        if (mb_strlen($domicilio) > 200) $errores['domicilio'] = 'Máximo 200 caracteres.';
        if (mb_strlen($localidad) > 80) $errores['localidad'] = 'Máximo 80 caracteres.';
        if (mb_strlen($nombre) > 80) $errores['nombre'] = 'Máximo 80 caracteres.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errores['email'] = 'Escribe un email válido.';
        if ($tel !== '' && !preg_match('/^[0-9 +()-]{6,20}$/', $tel)) $errores['telefono'] = 'Teléfono no válido.';
        if (!$errores) {
            nv_ajustes_guardar(['negocio' => $negocio, 'nombre' => $nombre, 'email' => $email, 'telefono' => $tel,
                'telefono_publico' => !empty($_POST['telefono_publico']) && $tel !== '' ? '1' : '0',
                'titular' => $titular, 'nif' => $nif, 'domicilio' => $domicilio, 'localidad' => $localidad]);
            volver('ok=info');
        }
    }

    if ($accion === 'correo' || $accion === 'probar') {
        $user = strtolower(trim((string)($_POST['smtp_user'] ?? '')));
        $pass = (string)($_POST['smtp_pass'] ?? '');
        $host = trim((string)($_POST['smtp_host'] ?? '')) ?: 'smtp.hostinger.com';
        $port = (int)($_POST['smtp_port'] ?? 465) ?: 465;
        if ($user !== '' && !filter_var($user, FILTER_VALIDATE_EMAIL)) $errores['smtp_user'] = 'Escribe el email completo del buzón.';
        if (!preg_match('/^[A-Za-z0-9.-]{3,190}$/', $host)) $errores['smtp_host'] = 'Servidor no válido (en Hostinger: smtp.hostinger.com).';
        if (!in_array($port, [25, 465, 587, 2525], true)) $errores['smtp_port'] = 'Usa 465 (recomendado) o 587.';
        if (!$errores) {
            $g = ['smtp_user' => $user ?: null, 'smtp_host' => $host, 'smtp_port' => (string)$port];
            if (!empty($_POST['borrar_pass'])) $g['smtp_pass'] = null;
            elseif ($pass !== '') $g['smtp_pass'] = $pass;
            nv_ajustes_guardar($g, ['smtp_pass']);
            if ($accion === 'probar') {
                if (!novis_limite('correo-prueba', 5, 900)) volver('ok=limite#correo');
                $para = nv_email_negocio();
                $ok = novis_mail($para, 'Correo de prueba · ' . nv_ajuste('negocio', 'The Novis'),
                    "Si lees esto, tu web ya puede enviar emails.\n\nEnviado desde el panel → Ajustes el " . date('d/m/Y H:i') . '.');
                if (!$ok) $_SESSION['correo_error'] = (string)($GLOBALS['novis_mail_error'] ?? '');
                volver(($ok ? 'ok=prueba' : 'ok=fallo') . '#correo');
            }
            volver('ok=correo#correo');
        }
    }

    if ($accion === 'acceso') {
        $usuario = trim((string)($_POST['usuario'] ?? ''));
        $actual = (string)($_POST['actual'] ?? '');
        $p1 = (string)($_POST['password'] ?? '');
        $p2 = (string)($_POST['password2'] ?? '');
        if (!novis_limite('panel-acceso', 6, 900)) $errores['actual'] = 'Demasiados intentos. Espera un rato.';
        elseif (!nv_panel_clave_ok($actual)) { sleep(1); $errores['actual'] = 'La contraseña actual no es correcta.'; }
        if (!preg_match('/^[\p{L}0-9._@-]{3,40}$/u', $usuario)) $errores['usuario'] = 'Entre 3 y 40 letras o números (sin espacios).';
        if ($p1 !== '' && mb_strlen($p1) < 10) $errores['password'] = 'Usa al menos 10 caracteres (mejor una frase).';
        elseif ($p1 !== $p2) $errores['password2'] = 'Las contraseñas no coinciden.';
        if (!$errores) {
            // Si no cambia la contraseña, se guarda la de siempre ya como hash (así el usuario vive en la base de datos)
            nv_ajustes_guardar(['panel_usuario' => $usuario, 'panel_hash' => password_hash($p1 !== '' ? $p1 : $actual, PASSWORD_DEFAULT), 'panel_cambio' => (string)time()]);
            // Esta sesión sigue abierta; las demás (otros dispositivos) se cierran
            session_regenerate_id(true);
            $_SESSION['desde'] = time() + 1;
            volver('ok=acceso#acceso');
        }
    }
}

$a = nv_ajustes();
$val = fn($k, $def = '') => e(array_key_exists($k, $viejo) ? $viejo[$k] : ($a[$k] ?? $def));
$err = fn($k) => isset($errores[$k]) ? '<p class="f-err">' . e($errores[$k]) . '</p>' : '';
$avisos = ['info' => 'Tu información se ha guardado. Ya se ve en la web (aviso legal, privacidad, condiciones y contacto).', 'correo' => 'Ajustes del correo guardados.', 'prueba' => 'Correo de prueba enviado a ' . nv_email_negocio() . '. Mira tu bandeja de entrada (y la de spam).',
    'fallo' => 'No se pudo enviar el correo de prueba. Revisa el buzón y la contraseña.', 'acceso' => 'Acceso al panel actualizado. Las sesiones abiertas en otros dispositivos se han cerrado.',
    'limite' => 'Has enviado varias pruebas seguidas. Espera unos minutos.'];
$ok = $avisos[$_GET['ok'] ?? ''] ?? '';
$falloCorreo = ($_GET['ok'] ?? '') === 'fallo' ? (string)($_SESSION['correo_error'] ?? '') : '';
unset($_SESSION['correo_error']);
$db = novis_db_config();
$smtpPassGuardada = ($a['smtp_pass'] ?? '') !== '';
$smtpEnConfig = ($cfg['smtp_password'] ?? '') !== '';
$cred = nv_panel_credenciales();
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Ajustes · The Novis</title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="../assets/favicon.svg?v=10" type="image/svg+xml">
<link rel="apple-touch-icon" href="../assets/apple-touch-icon.png?v=10">
<meta name="theme-color" content="#f5f5f7">
<link rel="stylesheet" href="panel.css?v=21">
</head>
<body class="v-pagos">
  <header class="top">
    <div class="top__row">
      <h1>Ajustes</h1>
      <div class="top__actions"><form method="post" action="./"><input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>"><button class="link" name="accion" value="salir">Salir</button></form></div>
    </div>
    <?php $seccionActual = 'ajustes'; include __DIR__ . '/nav.php'; ?>
    <div class="pad"></div>
  </header>
  <main class="detail">
    <?php if ($ok): ?><p class="<?= in_array($_GET['ok'], ['fallo', 'limite'], true) ? 'warn' : 'toast' ?>" role="status"><?= e($ok) ?><?= $falloCorreo ? ' (' . e(mb_substr($falloCorreo, 0, 160)) . ')' : '' ?></p><?php endif; ?>

    <form method="post" class="card form-pago" id="info" novalidate>
      <h2>Tu negocio y contacto</h2>
      <p class="muted">Estos datos salen en el aviso legal, la política de privacidad, las condiciones de contratación, el contacto de la web y el pie de los emails.</p>
      <?php if (($a['titular'] ?? '') === '' || ($a['nif'] ?? '') === '' || ($a['domicilio'] ?? '') === ''): ?><p class="warn">Faltan el titular, el NIF o el domicilio: en tus páginas legales aparece «[PENDIENTE]». La ley (LSSI) obliga a mostrarlos.</p><?php endif; ?>
      <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
      <label>Nombre comercial <span class="muted">(también es el remitente de los emails)</span>
        <input name="negocio" required maxlength="80" value="<?= $val('negocio', 'The Novis') ?>"></label><?= $err('negocio') ?>
      <label>Titular <span class="muted">(tu nombre y apellidos si eres autónomo, o la razón social de tu empresa)</span>
        <input name="titular" maxlength="120" autocomplete="organization" value="<?= $val('titular') ?>"></label><?= $err('titular') ?>
      <label>NIF
        <input name="nif" maxlength="14" autocapitalize="characters" spellcheck="false" placeholder="12345678Z" value="<?= $val('nif') ?>"></label><?= $err('nif') ?>
      <label>Domicilio <span class="muted">(calle, número, código postal y ciudad)</span>
        <input name="domicilio" maxlength="200" autocomplete="street-address" value="<?= $val('domicilio') ?>"></label><?= $err('domicilio') ?>
      <label>Localidad que se ve en el contacto de la web
        <input name="localidad" maxlength="80" placeholder="Vigo, Galicia" value="<?= $val('localidad', 'Vigo, Galicia') ?>"></label><?= $err('localidad') ?>
      <label>Persona de contacto <span class="muted">(opcional)</span>
        <input name="nombre" maxlength="80" autocomplete="name" value="<?= $val('nombre') ?>"></label><?= $err('nombre') ?>
      <label>Tu correo <span class="muted">(aquí te llegan los avisos de mensajes y pagos, y es el que ven tus clientes en toda la web)</span>
        <input name="email" type="email" required autocomplete="email" value="<?= e(array_key_exists('email', $viejo) ? $viejo['email'] : nv_email_negocio()) ?>"></label><?= $err('email') ?>
      <label>Teléfono <span class="muted">(opcional)</span>
        <input name="telefono" type="tel" autocomplete="tel" value="<?= $val('telefono') ?>"></label><?= $err('telefono') ?>
      <label class="check"><input type="checkbox" name="telefono_publico" value="1"<?= (array_key_exists('telefono', $viejo) ? !empty($viejo['telefono_publico']) : ($a['telefono_publico'] ?? '') === '1') ? ' checked' : '' ?>> Mostrar el teléfono en la web (contacto, aviso legal y privacidad)</label>
      <button class="btn" name="accion" value="info">Guardar mis datos</button>
    </form>

    <form method="post" class="card form-pago" id="correo" novalidate autocomplete="off">
      <h2>Correo de envío</h2>
      <p class="muted">El buzón desde el que la web envía los emails (confirmaciones, avisos, respuestas). En Hostinger lo creas en <strong>hPanel → Emails</strong>.<?= $smtpEnConfig && !$smtpPassGuardada ? ' Ahora mismo se usa el que está en gemini-config.php.' : '' ?></p>
      <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
      <label>Email del buzón
        <input name="smtp_user" type="email" placeholder="hola@thenovis.com" value="<?= $val('smtp_user', (string)($cfg['smtp_user'] ?? '')) ?>"></label><?= $err('smtp_user') ?>
      <label>Contraseña del buzón <span class="muted"><?= $smtpPassGuardada ? '(guardada cifrada; déjala en blanco para no cambiarla)' : '(la del email en Hostinger)' ?></span>
        <input name="smtp_pass" type="password" autocomplete="new-password"></label>
      <?php if ($smtpPassGuardada): ?><label class="check"><input type="checkbox" name="borrar_pass" value="1"> Borrar la contraseña guardada</label><?php endif; ?>
      <div class="dos">
        <label>Servidor
          <input name="smtp_host" value="<?= $val('smtp_host', (string)($cfg['smtp_host'] ?? 'smtp.hostinger.com')) ?>" autocapitalize="none" spellcheck="false"></label>
        <label>Puerto
          <input name="smtp_port" inputmode="numeric" value="<?= $val('smtp_port', (string)($cfg['smtp_port'] ?? '465')) ?>"></label>
      </div><?= $err('smtp_host') ?><?= $err('smtp_port') ?>
      <div class="actions">
        <button class="btn" name="accion" value="correo">Guardar</button>
        <button class="btn btn--ghost" name="accion" value="probar">Guardar y enviar prueba</button>
      </div>
    </form>

    <form method="post" class="card form-pago" id="acceso" novalidate>
      <h2>Acceso al panel</h2>
      <?php if ($cred['origen'] === 'config'): ?><p class="muted">Ahora entras con el usuario de <code>gemini-config.php</code>. Al guardar aquí, el acceso pasa a la base de datos (con la contraseña cifrada) y ya no necesitas tocar ese archivo.</p><?php endif; ?>
      <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
      <label>Usuario
        <input name="usuario" required autocomplete="username" autocapitalize="none" spellcheck="false" value="<?= e($viejo['usuario'] ?? $cred['usuario']) ?>"></label><?= $err('usuario') ?>
      <label>Contraseña nueva <span class="muted">(déjala en blanco para no cambiarla; mínimo 10 caracteres)</span>
        <input name="password" type="password" autocomplete="new-password"></label><?= $err('password') ?>
      <label>Repite la contraseña nueva
        <input name="password2" type="password" autocomplete="new-password"></label><?= $err('password2') ?>
      <label>Contraseña actual <span class="muted">(para confirmar que eres tú)</span>
        <input name="actual" type="password" required autocomplete="current-password"></label><?= $err('actual') ?>
      <button class="btn" name="accion" value="acceso">Guardar acceso</button>
    </form>

    <section class="card" id="bd">
      <h2>Base de datos</h2>
      <dl>
        <dt>Tipo</dt><dd>MySQL de Hostinger</dd>
        <dt>Nombre</dt><dd><?= e($db['nombre']) ?></dd>
        <dt>Usuario</dt><dd><?= e($db['usuario']) ?></dd>
        <dt>Servidor</dt><dd><?= e($db['host']) ?>:<?= (int)$db['puerto'] ?></dd>
        <dt>Espacio</dt><dd><?= e(novis_tamano()) ?></dd>
      </dl>
      <p class="muted small">Los nombres, emails, teléfonos y mensajes se guardan cifrados: en phpMyAdmin se ven como <code>nv1:…</code> y en este panel, normales.</p>
      <div class="actions"><a class="btn btn--ghost" href="instalar.php?cambiar=1">Cambiar datos de conexión</a><a class="btn btn--ghost" href="seguridad.php">Seguridad</a></div>
    </section>
  </main>
  <script src="panel.js?v=4" defer></script>
</body>
</html>
