<?php
// The Novis — tus datos: ver, descargar y eliminar los datos personales (RGPD, arts. 15, 17 y 20).
// Funciona sin cuenta: la persona confirma que el email es suyo con un enlace de un solo uso.
require_once __DIR__ . '/../lib/cuentas.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');   // el enlace lleva un token: que no se filtre

$db = nv_db();
$vista = 'inicio';
$aviso = null;
$error = '';

/** Devuelve el email asociado a un token válido (sin consumirlo), o null. */
function token_email(string $t): ?string {
    if (!preg_match('/^[a-f0-9]{48}$/', $t)) return null;
    $st = nv_db()->prepare('SELECT email FROM datos_tokens WHERE hash = ? AND usado = 0 AND caduca >= ?');
    $st->execute([hash('sha256', $t), time()]);
    return $st->fetchColumn() ?: null;
}

function datos_de(string $email): array { return nv_datos_de($email); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');

    // 1) Pedir el enlace por email
    if ($accion === 'pedir') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Escribe un email válido.';
        } elseif (!novis_limite('datos', 3, 3600) || !novis_limite('datos-cta', 2, 3600, $email)) {
            $error = 'Ya has pedido varios enlaces. Espera un rato e inténtalo de nuevo.';
        } else {
            $d = datos_de($email);
            if ($d['solicitudes'] || $d['cuenta'] || $d['pagos']) {
                $t = bin2hex(random_bytes(24));
                $db->prepare('UPDATE datos_tokens SET usado = 1 WHERE email_idx = ? AND usado = 0')->execute([nv_indice($email)]);
                $db->prepare('INSERT INTO datos_tokens (email, email_idx, hash, caduca) VALUES (?, ?, ?, ?)')->execute([nv_cifrar($email), nv_indice($email), hash('sha256', $t), time() + 3600]);
                $db->prepare('DELETE FROM datos_tokens WHERE caduca < ?')->execute([time() - 86400]);
                $url = nv_url('datos/?t=' . $t);
                $html = novis_email_html('Tus datos en The Novis', [
                    'Has pedido ver, descargar o eliminar los datos personales que The Novis guarda asociados a este email.',
                    'Abre el enlace para continuar. Caduca en 1 hora y solo funciona una vez.',
                ], 'Ver mis datos', $url, 'Si no lo has pedido tú, ignora este mensaje: no se cambiará nada.');
                $texto = "Has pedido ver, descargar o eliminar tus datos en The Novis.\nAbre este enlace (caduca en 1 hora):\n$url\n\nSi no lo has pedido tú, ignora este mensaje.";
                novis_mail($email, 'Tus datos en The Novis', $texto, $html);
            }
            // Mismo mensaje haya datos o no, para no revelar quién ha contactado con The Novis
            $vista = 'enviado';
        }
    }

    // 2) Descargar los datos (derecho de acceso y portabilidad)
    if ($accion === 'descargar') {
        $email = token_email((string)($_POST['t'] ?? ''));
        if (!$email) { $vista = 'caducado'; }
        else {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="mis-datos-novis.json"');
            echo nv_datos_json($email);
            exit;
        }
    }

    // 3) Eliminar los datos (derecho de supresión)
    if ($accion === 'eliminar') {
        $t = (string)($_POST['t'] ?? '');
        $email = token_email($t);
        if (!$email) { $vista = 'caducado'; }
        elseif (empty($_POST['confirmo'])) { $error = 'Marca la casilla para confirmar que quieres eliminar tus datos.'; $_GET['t'] = $t; }
        else {
            $d = nv_borrar_datos($email, nv_url('datos/'));
            $vista = 'eliminado';
            $aviso = $d;
        }
    }
}

if ($vista === 'inicio' && isset($_GET['t'])) {
    $email = token_email((string)$_GET['t']);
    if ($email) { $vista = 'datos'; $d = datos_de($email); }
    else $vista = 'caducado';
}
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Tus datos — The Novis</title>
  <link rel="icon" href="/assets/favicon.svg?v=10" type="image/svg+xml">
  <link rel="preload" href="/assets/fonts/geist-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/css/styles.css?v=27">
  <link rel="stylesheet" href="/pagos/pagos.css?v=4">
</head>
<body class="acc-page">
  <a href="#contenido" class="skip">Saltar al contenido</a>
  <header class="nav is-scrolled">
    <div class="nav__inner">
      <a href="/" class="logo" aria-label="The Novis, volver a la web"><span class="logo__mark"></span>The Novis</a>
      <a href="/" class="link-back">Volver a la web</a>
    </div>
  </header>

  <main id="contenido" tabindex="-1" class="acc">
<?php if ($vista === 'inicio'): ?>
    <div class="acc-card acc-card--wide">
      <h1>Tus datos</h1>
      <p class="acc-lead">Aquí puedes ver, descargar o eliminar los datos personales que The Novis guarda sobre ti: los mensajes que nos enviaste por el formulario y, si la tienes, tu cuenta de pagos.</p>
      <?php if ($error): ?><p class="acc-flash acc-flash--err" role="alert"><?= e($error) ?></p><?php endif; ?>
      <form method="post" class="acc-form" novalidate>
        <div class="f"><label for="email">Tu email</label>
          <input id="email" name="email" type="email" autocomplete="email" required value="<?= e($_POST['email'] ?? '') ?>"></div>
        <button class="btn" name="accion" value="pedir">Enviarme un enlace</button>
      </form>
      <p class="acc-secure">Para proteger tus datos, te enviaremos un enlace a ese email. Así solo tú puedes verlos o borrarlos.</p>
    </div>

<?php elseif ($vista === 'enviado'): ?>
    <div class="acc-card">
      <div class="acc-icon acc-icon--mail" aria-hidden="true"></div>
      <h1>Revisa tu email</h1>
      <p class="acc-lead">Si tenemos datos asociados a <strong><?= e($_POST['email'] ?? '') ?></strong>, te hemos enviado un enlace para verlos. Caduca en 1 hora. Si no lo ves, mira en la carpeta de spam.</p>
      <p class="acc-alt"><a href="/">Volver a la web</a></p>
    </div>

<?php elseif ($vista === 'caducado'): ?>
    <div class="acc-card">
      <h1>Enlace caducado</h1>
      <p class="acc-lead">El enlace no es válido, ha caducado o ya se ha usado. Pide uno nuevo.</p>
      <p><a class="btn" href="./">Pedir un enlace nuevo</a></p>
    </div>

<?php elseif ($vista === 'datos'): ?>
    <div class="acc-card acc-card--wide">
      <h1>Tus datos</h1>
      <p class="acc-lead">Estos son los datos que The Novis guarda asociados a <strong><?= e($email) ?></strong>.</p>

      <h2 class="datos-h">Mensajes del formulario de contacto</h2>
      <?php if (!$d['solicitudes']): ?><p class="acc-desc">Ninguno.</p><?php else: ?>
        <ul class="datos-list">
          <?php foreach ($d['solicitudes'] as $s): ?>
            <li><strong><?= e(date('d/m/Y', strtotime($s['creado']))) ?></strong> · <?= e($s['nombre']) ?><?= $s['empresa'] ? ' (' . e($s['empresa']) . ')' : '' ?><span><?= e(mb_strimwidth($s['mensaje'], 0, 140, '…')) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <h2 class="datos-h">Cuenta de pagos</h2>
      <?php if (!$d['cuenta']): ?><p class="acc-desc">No tienes cuenta.</p><?php else: ?>
        <dl class="acc-sum">
          <div><dt>Usuario</dt><dd><?= e($d['cuenta']['usuario'] ?: '—') ?></dd></div>
          <div><dt>Teléfono</dt><dd><?= e($d['cuenta']['telefono'] ?: '—') ?></dd></div>
          <div><dt>Creada</dt><dd><?= e(date('d/m/Y', strtotime($d['cuenta']['creado']))) ?></dd></div>
        </dl>
      <?php endif; ?>

      <h2 class="datos-h">Pagos</h2>
      <?php if (!$d['pagos']): ?><p class="acc-desc">Ninguno.</p><?php else: ?>
        <ul class="datos-list">
          <?php foreach ($d['pagos'] as $p): ?>
            <li><strong><?= e(nv_importe_txt($p)) ?></strong> · <?= e($p['concepto']) ?><span><?= e($p['codigo']) ?> · <?= e(nv_estado($p)[1]) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <p class="acc-secure datos-nota">Los pagos realizados no se pueden borrar: la ley obliga a conservarlos 6 años como registro contable. Si eliminas tus datos, dejarán de estar vinculados a ti en esta web y tus suscripciones activas se cancelarán.</p>
      <?php endif; ?>

      <div class="datos-actions">
        <form method="post">
          <input type="hidden" name="t" value="<?= e($_GET['t']) ?>">
          <button class="btn btn--ghost" name="accion" value="descargar">Descargar mis datos</button>
        </form>
      </div>

      <form method="post" class="datos-borrar">
        <h2 class="datos-h">Eliminar mis datos</h2>
        <p class="acc-desc">Se borrarán tus mensajes del formulario<?= $d['cuenta'] ? ' y tu cuenta de pagos' : '' ?>. No se puede deshacer.</p>
        <?php if ($error): ?><p class="f-err" role="alert"><?= e($error) ?></p><?php endif; ?>
        <input type="hidden" name="t" value="<?= e($_GET['t']) ?>">
        <label class="f-check"><input type="checkbox" name="confirmo" value="1"><span>Entiendo que mis datos se eliminarán de forma permanente.</span></label>
        <button class="btn btn--danger" name="accion" value="eliminar">Eliminar mis datos</button>
      </form>
    </div>

<?php elseif ($vista === 'eliminado'): ?>
    <div class="acc-card">
      <div class="acc-icon acc-icon--ok" aria-hidden="true"></div>
      <h1>Datos eliminados</h1>
      <p class="acc-lead">Hemos borrado tus mensajes del formulario<?= $aviso['cuenta'] ? ' y tu cuenta de pagos' : '' ?>. Los emails que nos enviaste se eliminarán también de nuestro buzón en un plazo máximo de 30 días.</p>
      <?php if ($aviso['pagos']): ?><p class="acc-desc">Conservamos el registro de <?= count($aviso['pagos']) ?> pago<?= count($aviso['pagos']) > 1 ? 's' : '' ?> porque la ley obliga a guardarlo, sin vincularlo a ninguna cuenta.</p><?php endif; ?>
      <p class="acc-alt"><a href="/">Volver a la web</a></p>
    </div>
<?php endif; ?>
  </main>

  <footer class="footer">
    <div class="footer__inner">
      <div class="footer__brand"><a href="/" class="logo"><span class="logo__mark"></span>The Novis</a></div>
      <nav aria-label="Información legal">
        <a href="/aviso-legal.html">Aviso legal</a>
        <a href="/privacidad.html">Privacidad</a>
        <a href="/cookies.html">Cookies</a>
      </nav>
      <p class="footer__copy">¿Dudas? <a href="mailto:hola@thenovis.com">hola@thenovis.com</a></p>
    </div>
  </footer>
</body>
</html>
