<?php
// Novis — área de clientes: cuenta y pagos con Stripe.
require_once __DIR__ . '/../lib/cuentas.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');
nv_sesion();

$a = preg_replace('/[^a-z-]/', '', $_GET['a'] ?? '');
$db = nv_db();
$u = nv_usuario();
$errores = [];
$viejo = [];

function ir(string $q = ''): void { header('Location: ./' . ($q ? '?' . $q : '')); exit; }
function aviso(string $m, string $tipo = 'ok'): void { $_SESSION['flash'] = [$tipo, $m]; }
function campo(string $k): string { return trim((string)($_POST[$k] ?? '')); }

/* ---------- Enlaces de los emails (funcionan con o sin sesión) ---------- */
if ($a === 'verificar') {
    $id = nv_token_usar((string)($_GET['t'] ?? ''), 'verificar');
    if ($id) {
        $db->prepare('UPDATE usuarios SET email_verificado = ? WHERE id = ? AND email_verificado IS NULL')->execute([date('c'), $id]);
        nv_entrar($id);
        aviso('Email confirmado. ¡Tu cuenta ya está activa!');
    } else {
        aviso('El enlace no es válido o ha caducado. Inicia sesión para pedir uno nuevo.', 'err');
    }
    ir();
}

/* ---------- Acciones (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!nv_csrf_ok()) { aviso('La página ha caducado. Inténtalo de nuevo.', 'err'); ir($a ? 'a=' . $a : ''); }
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'salir') {
        $_SESSION = [];
        session_regenerate_id(true);
        aviso('Has cerrado sesión.');
        ir();
    }

    if ($accion === 'registro' && !$u) {
        $viejo = $_POST;
        $email = strtolower(campo('email'));
        $usuario = strtolower(campo('usuario'));
        $tel = preg_replace('/[^\d+]/', '', campo('telefono'));
        $pass = (string)($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errores['email'] = 'Escribe un email válido.';
        if (!preg_match('/^[a-z0-9._-]{3,30}$/', $usuario)) $errores['usuario'] = 'Entre 3 y 30 caracteres: letras, números, punto, guion o guion bajo.';
        if (!preg_match('/^\+?\d{9,15}$/', $tel)) $errores['telefono'] = 'Escribe un teléfono válido, por ejemplo 612 345 678.';
        if (mb_strlen($pass) < 10) $errores['password'] = 'La contraseña debe tener al menos 10 caracteres.';
        elseif ($pass !== (string)($_POST['password2'] ?? '')) $errores['password2'] = 'Las contraseñas no coinciden.';
        if (empty($_POST['acepto'])) $errores['acepto'] = 'Debes aceptar la política de privacidad.';
        if (!$errores) {
            $st = $db->prepare('SELECT email, usuario FROM usuarios WHERE email = ? OR usuario = ?');
            $st->execute([$email, $usuario]);
            foreach ($st->fetchAll() as $r) {
                if (strcasecmp($r['email'], $email) === 0) $errores['email'] = 'Ya existe una cuenta con este email. Inicia sesión o recupera tu contraseña.';
                if ($r['usuario'] && strcasecmp($r['usuario'], $usuario) === 0) $errores['usuario'] = 'Este nombre de usuario ya está en uso.';
            }
        }
        if (!$errores && !novis_limite('registro', 5, 3600)) $errores['general'] = 'Demasiados intentos. Prueba de nuevo dentro de un rato.';
        if (!$errores) {
            $db->prepare('INSERT INTO usuarios (email, usuario, telefono, password_hash, creado) VALUES (?, ?, ?, ?, ?)')
               ->execute([$email, $usuario, $tel, password_hash($pass, PASSWORD_DEFAULT), date('c')]);
            $id = (int)$db->lastInsertId();
            nv_entrar($id);
            nv_email_verificacion(['id' => $id, 'email' => $email, 'usuario' => $usuario]);
            ir();
        }
        $a = 'registro';
    }

    if ($accion === 'entrar' && !$u) {
        $viejo = $_POST;
        $id_ = strtolower(campo('identificador'));
        $pass = (string)($_POST['password'] ?? '');
        if (!novis_limite('login', 10, 900)) {
            $errores['general'] = 'Demasiados intentos. Espera 15 minutos.';
        } else {
            $st = $db->prepare('SELECT * FROM usuarios WHERE email = ? OR usuario = ?');
            $st->execute([$id_, $id_]);
            $f = $st->fetch();
            if ($f && !$f['password_hash'] && $f['google_sub']) {
                $errores['general'] = 'Esta cuenta se creó con Google. Usa el botón «Continuar con Google».';
            } elseif ($f && password_verify($pass, (string)$f['password_hash'])) {
                if (password_needs_rehash($f['password_hash'], PASSWORD_DEFAULT)) {
                    $db->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $f['id']]);
                }
                nv_entrar((int)$f['id']);
                ir();
            } else {
                usleep(400000);
                $errores['general'] = 'Usuario o contraseña incorrectos.';
            }
        }
        $a = 'entrar';
    }

    if ($accion === 'olvide' && !$u) {
        $email = strtolower(campo('email'));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && novis_limite('reset', 5, 3600)) {
            $st = $db->prepare('SELECT * FROM usuarios WHERE email = ?');
            $st->execute([$email]);
            if ($f = $st->fetch()) nv_email_restablecer($f);
        }
        // Mismo mensaje exista o no la cuenta, para no revelar qué emails están registrados
        aviso('Si hay una cuenta con ese email, te hemos enviado un enlace para crear una contraseña nueva.');
        ir();
    }

    if ($accion === 'restablecer') {
        $pass = (string)($_POST['password'] ?? '');
        $t = (string)($_POST['t'] ?? '');
        if (mb_strlen($pass) < 10) $errores['password'] = 'La contraseña debe tener al menos 10 caracteres.';
        elseif ($pass !== (string)($_POST['password2'] ?? '')) $errores['password2'] = 'Las contraseñas no coinciden.';
        if (!$errores) {
            $id = nv_token_usar($t, 'reset');
            if (!$id) { aviso('El enlace no es válido o ha caducado. Pide uno nuevo.', 'err'); ir('a=olvide'); }
            // Si ha llegado desde el email, el email queda verificado
            $db->prepare('UPDATE usuarios SET password_hash = ?, email_verificado = COALESCE(email_verificado, ?), pass_cambiada = ? WHERE id = ?')
               ->execute([password_hash($pass, PASSWORD_DEFAULT), date('c'), time() - 1, $id]);
            nv_entrar($id);
            aviso('Contraseña actualizada.');
            ir();
        }
        $a = 'restablecer';
        $_GET['t'] = $t;
    }

    if ($accion === 'reenviar' && $u && !$u['email_verificado']) {
        if (novis_limite('reenviar', 3, 3600)) {
            nv_email_verificacion($u);
            aviso('Te hemos enviado un email nuevo. Revisa también la carpeta de spam.');
        } else {
            aviso('Ya te hemos enviado varios emails. Espera un rato antes de pedir otro.', 'err');
        }
        ir();
    }

    if ($accion === 'perfil' && $u && $u['email_verificado']) {
        $viejo = $_POST;
        $usuario = strtolower(campo('usuario'));
        $tel = preg_replace('/[^\d+]/', '', campo('telefono'));
        if (!preg_match('/^[a-z0-9._-]{3,30}$/', $usuario)) $errores['usuario'] = 'Entre 3 y 30 caracteres: letras, números, punto, guion o guion bajo.';
        if (!preg_match('/^\+?\d{9,15}$/', $tel)) $errores['telefono'] = 'Escribe un teléfono válido, por ejemplo 612 345 678.';
        if (empty($_POST['acepto'])) $errores['acepto'] = 'Debes aceptar la política de privacidad.';
        if (!$errores) {
            $st = $db->prepare('SELECT 1 FROM usuarios WHERE usuario = ? AND id != ?');
            $st->execute([$usuario, $u['id']]);
            if ($st->fetch()) $errores['usuario'] = 'Este nombre de usuario ya está en uso.';
        }
        if (!$errores) {
            $db->prepare('UPDATE usuarios SET usuario = ?, telefono = ? WHERE id = ?')->execute([$usuario, $tel, $u['id']]);
            ir();
        }
    }

    if ($accion === 'codigo' && $u) {
        $codigo = nv_normalizar_codigo(campo('codigo'));
        $viejo = $_POST;
        if (!novis_limite('codigo', 10, 3600)) {
            $errores['codigo'] = 'Demasiados intentos. Prueba de nuevo dentro de un rato.';
        } else {
            $st = $db->prepare('SELECT * FROM pagos WHERE codigo = ?');
            $st->execute([$codigo]);
            $p = $st->fetch();
            $valido = $p
                && ($p['email_cliente'] === null || $p['email_cliente'] === '' || strcasecmp($p['email_cliente'], $u['email']) === 0)
                && ($p['usuario_id'] === null || (int)$p['usuario_id'] === (int)$u['id']);
            if (!$valido || $p['estado'] === 'cancelado') {
                $errores['codigo'] = 'Este código no es válido. Revisa que esté bien escrito o escríbenos a hola@thenovis.com.';
            } elseif ($p['estado'] === 'pendiente' && $p['caduca'] && $p['caduca'] < date('Y-m-d')) {
                $errores['codigo'] = 'Este código ha caducado. Escríbenos a hola@thenovis.com y te enviamos uno nuevo.';
            } else {
                // El código queda asociado a esta cuenta
                $db->prepare('UPDATE pagos SET usuario_id = ? WHERE id = ? AND usuario_id IS NULL')->execute([$u['id'], $p['id']]);
                ir('a=resumen&id=' . $p['id']);
            }
        }
    }

    if ($accion === 'pagar' && $u) {
        $st = $db->prepare("SELECT * FROM pagos WHERE id = ? AND usuario_id = ?");
        $st->execute([(int)($_POST['id'] ?? 0), $u['id']]);
        $p = $st->fetch();
        if (!$p) ir();
        if ($p['estado'] !== 'pendiente') ir('a=resumen&id=' . $p['id']);
        if (!nv_stripe_ok()) { aviso('Los pagos online aún no están activados. Escríbenos a hola@thenovis.com.', 'err'); ir('a=resumen&id=' . $p['id']); }
        try {
            // Si ya hay una sesión de pago abierta, se reutiliza (evita cobrar dos veces)
            if ($p['stripe_session']) {
                $s = nv_sincronizar($p);
                if ($s && ($s['payment_status'] ?? '') === 'paid') ir('a=exito&id=' . $p['id']);
                if ($s && ($s['status'] ?? '') === 'open' && !empty($s['url'])) { header('Location: ' . $s['url'], true, 303); exit; }
            }
            $datos = [
                'mode' => 'payment',
                'locale' => 'es',
                'customer_email' => $u['email'],
                'client_reference_id' => (string)$p['id'],
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => $p['moneda'],
                        'unit_amount' => (int)$p['importe'],
                        'product_data' => array_filter([
                            'name' => mb_substr($p['concepto'], 0, 250),
                            'description' => $p['descripcion'] ? mb_substr($p['descripcion'], 0, 500) : null,
                        ]),
                    ],
                ]],
                'metadata' => ['pago_id' => (string)$p['id'], 'codigo' => $p['codigo'], 'usuario_id' => (string)$u['id']],
                'payment_intent_data' => [
                    'description' => mb_substr($p['concepto'] . ' (' . $p['codigo'] . ')', 0, 500),
                    'metadata' => ['pago_id' => (string)$p['id'], 'codigo' => $p['codigo']],
                ],
                'success_url' => nv_url('pagos/?a=exito&id=' . $p['id']),
                'cancel_url' => nv_url('pagos/?a=resumen&id=' . $p['id'] . '&cancelado=1'),
            ];
            $s = nv_stripe('POST', '/v1/checkout/sessions', $datos);
            $db->prepare('UPDATE pagos SET stripe_session = ?, pagador_email = ? WHERE id = ?')->execute([$s['id'], $u['email'], $p['id']]);
            header('Location: ' . $s['url'], true, 303);
            exit;
        } catch (Throwable $e) {
            aviso('No hemos podido conectar con el sistema de pago. Inténtalo de nuevo en unos minutos.', 'err');
            ir('a=resumen&id=' . $p['id']);
        }
    }

    if ($accion === 'eliminar' && $u) {
        if (strcasecmp(campo('confirmar'), 'ELIMINAR') !== 0) {
            aviso('Para eliminar la cuenta escribe ELIMINAR en la casilla.', 'err');
            ir('a=cuenta');
        }
        // Los pagos se conservan (obligación legal), pero dejan de estar ligados a la cuenta
        $db->prepare('UPDATE pagos SET usuario_id = NULL WHERE usuario_id = ?')->execute([$u['id']]);
        $db->prepare('DELETE FROM tokens WHERE usuario_id = ?')->execute([$u['id']]);
        $db->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$u['id']]);
        $_SESSION = [];
        session_regenerate_id(true);
        aviso('Tu cuenta se ha eliminado.');
        ir();
    }

    if (!$errores) ir($a ? 'a=' . $a : '');
}

/* ---------- Qué pantalla toca ---------- */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if (!$u) {
    $vista = in_array($a, ['registro', 'olvide', 'restablecer'], true) ? $a : 'entrar';
} elseif (!$u['email_verificado']) {
    $vista = 'pendiente';
} elseif (!$u['usuario'] || !$u['telefono']) {
    $vista = 'perfil';
} else {
    $vista = in_array($a, ['resumen', 'exito', 'cuenta'], true) ? $a : 'inicio';
    if ($vista === 'resumen' || $vista === 'exito') {
        $st = $db->prepare('SELECT * FROM pagos WHERE id = ? AND usuario_id = ?');
        $st->execute([(int)($_GET['id'] ?? 0), $u['id']]);
        $p = $st->fetch();
        if (!$p) ir();
        if ($p['estado'] === 'pendiente' && $p['stripe_session']) { nv_sincronizar($p); $st->execute([$p['id'], $u['id']]); $p = $st->fetch(); }
        if ($vista === 'resumen' && $p['estado'] === 'pagado') $vista = 'exito';
    }
    if ($vista === 'inicio') {
        $st = $db->prepare('SELECT * FROM pagos WHERE usuario_id = ? ORDER BY id DESC');
        $st->execute([$u['id']]);
        $mis = $st->fetchAll();
    }
}

$titulos = [
    'entrar' => 'Iniciar sesión', 'registro' => 'Crear cuenta', 'olvide' => 'Recuperar contraseña', 'restablecer' => 'Contraseña nueva',
    'pendiente' => 'Confirma tu email', 'perfil' => 'Completa tu perfil', 'inicio' => 'Pagos', 'resumen' => 'Resumen del pago',
    'exito' => 'Pago', 'cuenta' => 'Tu cuenta',
];
$v = fn(string $k) => e($viejo[$k] ?? '');
function err(array $errores, string $k): string {
    return isset($errores[$k]) ? '<p class="f-err" id="err-' . $k . '">' . e($errores[$k]) . '</p>' : '';
}
function inv(array $errores, string $k): string {
    return isset($errores[$k]) ? ' aria-invalid="true" aria-describedby="err-' . $k . '"' : '';
}
$google = nv_google_ok();
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($titulos[$vista]) ?> — Novis</title>
  <link rel="icon" href="../assets/favicon.svg?v=10" type="image/svg+xml">
  <link rel="apple-touch-icon" href="../assets/apple-touch-icon.png?v=10">
  <link rel="preload" href="../assets/fonts/geist-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="../css/styles.css?v=19">
  <link rel="stylesheet" href="pagos.css?v=3">
</head>
<body class="acc-page">
  <a href="#contenido" class="skip">Saltar al contenido</a>
  <header class="nav is-scrolled">
    <div class="nav__inner">
      <a href="../" class="logo" aria-label="Novis, volver a la web"><span class="logo__mark"></span>novis</a>
      <?php if ($u): ?>
        <form method="post" class="acc-user">
          <span><?= e($u['usuario'] ?: $u['email']) ?></span>
          <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
          <button class="btn btn--ghost btn--small" name="accion" value="salir">Cerrar sesión</button>
        </form>
      <?php else: ?>
        <a href="../" class="link-back">Volver a la web</a>
      <?php endif; ?>
    </div>
  </header>

  <main id="contenido" tabindex="-1" class="acc acc--<?= e($vista) ?>">
    <?php if ($flash): ?><p class="acc-flash acc-flash--<?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></p><?php endif; ?>
    <?php if (!empty($errores['general'])): ?><p class="acc-flash acc-flash--err" role="alert"><?= e($errores['general']) ?></p><?php endif; ?>

<?php if ($vista === 'entrar'): ?>
    <div class="acc-card">
      <h1>Pagos</h1>
      <p class="acc-lead">Inicia sesión para pagar con el código que te hemos enviado.</p>
      <?php if ($google): ?>
        <a class="btn btn--ghost acc-google" href="google.php">Continuar con Google</a>
        <p class="acc-or"><span>o con tu usuario</span></p>
      <?php endif; ?>
      <form method="post" class="acc-form" novalidate>
        <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
        <div class="f"><label for="identificador">Email o nombre de usuario</label>
          <input id="identificador" name="identificador" autocomplete="username" autocapitalize="none" required value="<?= $v('identificador') ?>"></div>
        <div class="f"><label for="password">Contraseña</label>
          <input id="password" name="password" type="password" autocomplete="current-password" required></div>
        <button class="btn" name="accion" value="entrar">Iniciar sesión</button>
      </form>
      <p class="acc-links"><a href="?a=olvide">He olvidado mi contraseña</a></p>
      <p class="acc-alt">¿Aún no tienes cuenta? <a href="?a=registro">Crear cuenta</a></p>
    </div>

<?php elseif ($vista === 'registro'): ?>
    <div class="acc-card">
      <h1>Crear cuenta</h1>
      <p class="acc-lead">Necesitas una cuenta para pagar de forma segura y ver tus pagos.</p>
      <?php if ($google): ?>
        <a class="btn btn--ghost acc-google" href="google.php">Continuar con Google</a>
        <p class="acc-or"><span>o con tu email</span></p>
      <?php endif; ?>
      <form method="post" class="acc-form" novalidate>
        <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
        <div class="f"><label for="email">Email</label>
          <input id="email" name="email" type="email" autocomplete="email" required value="<?= $v('email') ?>"<?= inv($errores, 'email') ?>><?= err($errores, 'email') ?></div>
        <div class="f"><label for="telefono">Teléfono</label>
          <input id="telefono" name="telefono" type="tel" autocomplete="tel" required value="<?= $v('telefono') ?>"<?= inv($errores, 'telefono') ?>><?= err($errores, 'telefono') ?></div>
        <div class="f"><label for="usuario">Nombre de usuario</label>
          <input id="usuario" name="usuario" autocomplete="username" autocapitalize="none" required value="<?= $v('usuario') ?>"<?= inv($errores, 'usuario') ?>><?= err($errores, 'usuario') ?></div>
        <div class="f"><label for="password">Contraseña <span class="f-hint">(mínimo 10 caracteres)</span></label>
          <input id="password" name="password" type="password" autocomplete="new-password" minlength="10" required<?= inv($errores, 'password') ?>><?= err($errores, 'password') ?></div>
        <div class="f"><label for="password2">Repite la contraseña</label>
          <input id="password2" name="password2" type="password" autocomplete="new-password" required<?= inv($errores, 'password2') ?>><?= err($errores, 'password2') ?></div>
        <label class="f-check"><input type="checkbox" name="acepto" value="1"<?= !empty($viejo['acepto']) ? ' checked' : '' ?><?= inv($errores, 'acepto') ?>>
          <span>He leído y acepto la <a href="../privacidad.html#cuentas">política de privacidad</a>.</span></label>
        <?= err($errores, 'acepto') ?>
        <button class="btn" name="accion" value="registro">Crear cuenta</button>
      </form>
      <p class="acc-alt">¿Ya tienes cuenta? <a href="./">Iniciar sesión</a></p>
    </div>

<?php elseif ($vista === 'olvide'): ?>
    <div class="acc-card">
      <h1>Recuperar contraseña</h1>
      <p class="acc-lead">Escribe el email de tu cuenta y te enviaremos un enlace para crear una contraseña nueva.</p>
      <form method="post" class="acc-form" novalidate>
        <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
        <div class="f"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="email" required></div>
        <button class="btn" name="accion" value="olvide">Enviar enlace</button>
      </form>
      <p class="acc-alt"><a href="./">Volver a iniciar sesión</a></p>
    </div>

<?php elseif ($vista === 'restablecer'): ?>
    <div class="acc-card">
      <h1>Contraseña nueva</h1>
      <form method="post" class="acc-form" novalidate>
        <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
        <input type="hidden" name="t" value="<?= e($_GET['t'] ?? '') ?>">
        <div class="f"><label for="password">Contraseña nueva <span class="f-hint">(mínimo 10 caracteres)</span></label>
          <input id="password" name="password" type="password" autocomplete="new-password" minlength="10" required<?= inv($errores, 'password') ?>><?= err($errores, 'password') ?></div>
        <div class="f"><label for="password2">Repite la contraseña</label>
          <input id="password2" name="password2" type="password" autocomplete="new-password" required<?= inv($errores, 'password2') ?>><?= err($errores, 'password2') ?></div>
        <button class="btn" name="accion" value="restablecer">Guardar contraseña</button>
      </form>
    </div>

<?php elseif ($vista === 'pendiente'): ?>
    <div class="acc-card">
      <div class="acc-icon acc-icon--mail" aria-hidden="true"></div>
      <h1>Confirma tu email</h1>
      <p class="acc-lead">Te hemos enviado un enlace a <strong><?= e($u['email']) ?></strong>. Ábrelo para activar tu cuenta. Si no lo ves, revisa la carpeta de spam.</p>
      <form method="post" class="acc-form">
        <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
        <button class="btn btn--ghost" name="accion" value="reenviar">Enviar el email otra vez</button>
      </form>
    </div>

<?php elseif ($vista === 'perfil'): ?>
    <div class="acc-card">
      <h1>Completa tu perfil</h1>
      <p class="acc-lead">Solo falta tu nombre de usuario y un teléfono de contacto.</p>
      <form method="post" class="acc-form" novalidate>
        <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
        <div class="f"><label for="usuario">Nombre de usuario</label>
          <input id="usuario" name="usuario" autocomplete="username" autocapitalize="none" required value="<?= e($viejo['usuario'] ?? $u['usuario'] ?? '') ?>"<?= inv($errores, 'usuario') ?>><?= err($errores, 'usuario') ?></div>
        <div class="f"><label for="telefono">Teléfono</label>
          <input id="telefono" name="telefono" type="tel" autocomplete="tel" required value="<?= e($viejo['telefono'] ?? $u['telefono'] ?? '') ?>"<?= inv($errores, 'telefono') ?>><?= err($errores, 'telefono') ?></div>
        <label class="f-check"><input type="checkbox" name="acepto" value="1"<?= inv($errores, 'acepto') ?>>
          <span>He leído y acepto la <a href="../privacidad.html#cuentas">política de privacidad</a>.</span></label>
        <?= err($errores, 'acepto') ?>
        <button class="btn" name="accion" value="perfil">Continuar</button>
      </form>
    </div>

<?php elseif ($vista === 'inicio'): ?>
    <div class="acc-wide">
      <h1>Hola, <?= e($u['usuario']) ?></h1>
      <section class="acc-card acc-card--code" aria-labelledby="t-codigo">
        <h2 id="t-codigo">Introduce tu código de pago</h2>
        <p class="acc-lead">Es el código que te hemos enviado, con el formato NOV-XXXX-XXXX.</p>
        <form method="post" class="acc-code" novalidate>
          <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
          <label for="codigo" class="sr">Código de pago</label>
          <input id="codigo" name="codigo" placeholder="NOV-XXXX-XXXX" autocomplete="off" autocapitalize="characters" spellcheck="false" required value="<?= $v('codigo') ?>"<?= inv($errores, 'codigo') ?>>
          <button class="btn" name="accion" value="codigo">Continuar</button>
        </form>
        <?= err($errores, 'codigo') ?>
      </section>

      <section class="acc-list" aria-labelledby="t-mis">
        <h2 id="t-mis">Tus pagos</h2>
        <?php if (!$mis): ?>
          <p class="acc-empty">Aquí aparecerán los pagos que hagas con tu código.</p>
        <?php else: ?>
          <ul>
            <?php foreach ($mis as $p): ?>
              <li>
                <a href="?a=resumen&amp;id=<?= (int)$p['id'] ?>">
                  <span class="acc-list__main"><strong><?= e($p['concepto']) ?></strong><small><?= e($p['codigo']) ?></small></span>
                  <span class="acc-list__amount"><?= e(nv_euros((int)$p['importe'])) ?></span>
                  <span class="pill pill--<?= e($p['estado']) ?>"><?= ['pendiente' => 'Pendiente', 'pagado' => 'Pagado', 'cancelado' => 'Anulado'][$p['estado']] ?? e($p['estado']) ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
      <p class="acc-alt"><a href="?a=cuenta">Datos de tu cuenta</a></p>
    </div>

<?php elseif ($vista === 'resumen'): ?>
    <div class="acc-card acc-card--wide">
      <p class="acc-back"><a href="./">‹ Tus pagos</a></p>
      <?php if (!empty($_GET['cancelado'])): ?><p class="acc-flash acc-flash--info" role="status">Has cancelado el pago. Puedes intentarlo de nuevo cuando quieras.</p><?php endif; ?>
      <h1><?= e($p['concepto']) ?></h1>
      <?php if ($p['descripcion']): ?><p class="acc-desc"><?= nl2br(e($p['descripcion'])) ?></p><?php endif; ?>
      <dl class="acc-sum">
        <div><dt>Código</dt><dd><?= e($p['codigo']) ?></dd></div>
        <?php if ($p['caduca']): ?><div><dt>Válido hasta</dt><dd><?= e(date('d/m/Y', strtotime($p['caduca']))) ?></dd></div><?php endif; ?>
        <div class="acc-sum__total"><dt>Total a pagar</dt><dd><?= e(nv_euros((int)$p['importe'])) ?></dd></div>
      </dl>
      <?php if ($p['estado'] === 'cancelado'): ?>
        <p class="acc-flash acc-flash--err">Este pago se ha anulado. Si crees que es un error, escríbenos a hola@thenovis.com.</p>
      <?php else: ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button class="btn acc-pay" name="accion" value="pagar">Pagar <?= e(nv_euros((int)$p['importe'])) ?></button>
        </form>
        <p class="acc-secure">Pago seguro con Stripe: tarjeta, Apple Pay o Google Pay. Novis nunca ve ni guarda los datos de tu tarjeta.</p>
      <?php endif; ?>
    </div>

<?php elseif ($vista === 'exito'): ?>
    <div class="acc-card">
      <?php if ($p['estado'] === 'pagado'): ?>
        <div class="acc-icon acc-icon--ok" aria-hidden="true"></div>
        <h1>¡Pago completado!</h1>
        <p class="acc-lead">Hemos recibido <strong><?= e(nv_euros((int)$p['importe'])) ?></strong> por «<?= e($p['concepto']) ?>». Te hemos enviado la confirmación por email.</p>
      <?php else: ?>
        <div class="acc-icon acc-icon--wait" aria-hidden="true"></div>
        <h1>Estamos confirmando tu pago</h1>
        <p class="acc-lead">Stripe nos está enviando la confirmación. Puede tardar unos segundos; recibirás un email en cuanto esté listo.</p>
        <p><a class="btn btn--ghost" href="?a=exito&amp;id=<?= (int)$p['id'] ?>">Actualizar</a></p>
      <?php endif; ?>
      <p class="acc-alt"><a href="./">Ver tus pagos</a></p>
    </div>

<?php elseif ($vista === 'cuenta'): ?>
    <div class="acc-card acc-card--wide">
      <p class="acc-back"><a href="./">‹ Tus pagos</a></p>
      <h1>Tu cuenta</h1>
      <dl class="acc-sum">
        <div><dt>Usuario</dt><dd><?= e($u['usuario']) ?></dd></div>
        <div><dt>Email</dt><dd><?= e($u['email']) ?></dd></div>
        <div><dt>Teléfono</dt><dd><?= e($u['telefono']) ?></dd></div>
        <div><dt>Acceso con Google</dt><dd><?= $u['google_sub'] ? 'Vinculado' : 'No' ?></dd></div>
      </dl>
      <p class="acc-desc">Para cambiar tus datos, escríbenos a <a href="mailto:hola@thenovis.com">hola@thenovis.com</a>. También puedes <a href="/datos/">descargar o eliminar todos tus datos</a>, incluidos los mensajes que nos enviaste por el formulario.</p>
      <details class="acc-danger">
        <summary>Eliminar mi cuenta</summary>
        <p>Se borrarán tus datos de acceso. Los pagos realizados se conservan el tiempo que exige la ley, desvinculados de la cuenta.</p>
        <form method="post" class="acc-form">
          <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
          <div class="f"><label for="confirmar">Escribe ELIMINAR para confirmar</label><input id="confirmar" name="confirmar" autocomplete="off" required></div>
          <button class="btn btn--danger" name="accion" value="eliminar">Eliminar mi cuenta</button>
        </form>
      </details>
    </div>
<?php endif; ?>
  </main>

  <footer class="footer">
    <div class="footer__inner">
      <div class="footer__brand"><a href="../" class="logo"><span class="logo__mark"></span>novis</a></div>
      <nav aria-label="Información legal">
        <a href="../aviso-legal.html">Aviso legal</a>
        <a href="../privacidad.html">Privacidad</a>
        <a href="../cookies.html">Cookies</a>
      </nav>
      <p class="footer__copy">¿Dudas? <a href="mailto:hola@thenovis.com">hola@thenovis.com</a></p>
    </div>
  </footer>
</body>
</html>
