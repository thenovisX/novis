<?php
// Novis — panel privado: códigos de pago (Stripe)
require_once __DIR__ . '/../lib/cuentas.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

nv_panel_sesion();
if (empty($_SESSION['ok'])) { header('Location: ./'); exit; }

function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_ok(): bool {
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}
function volver(string $q = ''): void { header('Location: pagos.php' . ($q ? '?' . $q : '')); exit; }

const EST = ['pendiente' => 'Pendiente', 'pagado' => 'Pagado', 'cancelado' => 'Anulado'];
const CLASE = ['pagado' => 'cerrada', 'pendiente' => 'en_curso', 'cancelado' => 'descartada', 'error' => 'error'];
$db = nv_db();
$errores = [];
$viejo = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) volver();
    $accion = $_POST['accion'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($accion === 'crear') {
        $viejo = $_POST;
        $concepto = trim((string)($_POST['concepto'] ?? ''));
        $descripcion = trim((string)($_POST['descripcion'] ?? ''));
        $importeTxt = str_replace([' ', '€'], '', (string)($_POST['importe'] ?? ''));
        $importeTxt = str_contains($importeTxt, ',') ? str_replace(['.', ','], ['', '.'], $importeTxt) : $importeTxt;
        $importe = (int)round((float)$importeTxt * 100);
        $email = strtolower(trim((string)($_POST['email_cliente'] ?? '')));
        $caduca = trim((string)($_POST['caduca'] ?? ''));
        $periodo = (string)($_POST['periodo'] ?? '');
        if (!isset(NV_PERIODOS_TXT[$periodo])) $periodo = '';
        if ($concepto === '' || mb_strlen($concepto) > 120) $errores['concepto'] = 'Escribe un concepto (máximo 120 caracteres).';
        if (mb_strlen($descripcion) > 1000) $errores['descripcion'] = 'Máximo 1000 caracteres.';
        if (!is_numeric($importeTxt) || $importe < 50 || $importe > 99999900) $errores['importe'] = 'Importe entre 0,50 € y 999.999 €.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errores['email_cliente'] = 'Email no válido.';
        if ($caduca !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $caduca) || $caduca < date('Y-m-d'))) $errores['caduca'] = 'Elige una fecha de hoy en adelante.';
        if (!$errores) {
            do { $codigo = nv_generar_codigo(); $ya = $db->prepare('SELECT 1 FROM pagos WHERE codigo = ?'); $ya->execute([$codigo]); } while ($ya->fetch());
            $db->prepare('INSERT INTO pagos (codigo, concepto, descripcion, importe, email_cliente, caduca, creado, periodo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
               ->execute([$codigo, nv_cifrar($concepto), $descripcion !== '' ? nv_cifrar($descripcion) : null, $importe, $email !== '' ? nv_cifrar($email) : null, $caduca ?: null, date('c'), $periodo ?: null]);
            volver('id=' . $db->lastInsertId() . '&nuevo=1');
        }
    }
    if ($accion === 'anular') {
        $db->prepare("UPDATE pagos SET estado = 'cancelado' WHERE id = ? AND estado = 'pendiente'")->execute([$id]);
        volver('id=' . $id);
    }
    if ($accion === 'reactivar') {
        $db->prepare("UPDATE pagos SET estado = 'pendiente' WHERE id = ? AND estado = 'cancelado'")->execute([$id]);
        volver('id=' . $id);
    }
    if ($accion === 'comprobar') {
        $st = $db->prepare('SELECT * FROM pagos WHERE id = ?');
        $st->execute([$id]);
        if ($p = $st->fetch()) { nv_sincronizar($p); $st->execute([$id]); if ($p = $st->fetch()) nv_sub_sincronizar($p); }
        volver('id=' . $id . '&comprobado=1');
    }
    // Suscripciones: baja al final del periodo, anular la baja o cancelar ya
    if (in_array($accion, ['sub_baja', 'sub_reanudar', 'sub_cancelar'], true)) {
        $st = $db->prepare('SELECT * FROM pagos WHERE id = ?');
        $st->execute([$id]);
        $p = $st->fetch();
        if ($p && nv_sub_viva($p)) {
            try {
                if ($accion === 'sub_cancelar') nv_sub_guardar($id, nv_stripe('DELETE', '/v1/subscriptions/' . rawurlencode($p['stripe_sub'])));
                else nv_sub_baja($p, $accion === 'sub_baja');
                volver('id=' . $id . '&sub=ok');
            } catch (Throwable $e) {
                volver('id=' . $id . '&sub=error');
            }
        }
        volver('id=' . $id);
    }
    if ($accion === 'eliminar') {
        $db->prepare("DELETE FROM pagos WHERE id = ? AND estado != 'pagado'")->execute([$id]);
        novis_compactar();
        volver('eliminado=1');
    }
    if (!$errores) volver();
}

$vista = !empty($_GET['nuevo']) && empty($_GET['id']) || $errores ? 'nuevo' : (!empty($_GET['id']) ? 'detalle' : 'lista');
if ($vista === 'detalle') {
    $st = $db->prepare('SELECT p.*, u.usuario, u.telefono, u.email AS cuenta_email FROM pagos p LEFT JOIN usuarios u ON u.id = p.usuario_id WHERE p.id = ?');
    $st->execute([(int)$_GET['id']]);
    $p = $st->fetch();
    if (!$p) volver();
    $esSub = nv_es_sub($p);
    if ($esSub) {
        $r = $db->prepare('SELECT * FROM renovaciones WHERE pago_id = ? ORDER BY id DESC');
        $r->execute([$p['id']]);
        $renov = $r->fetchAll();
    }
    $mensaje = "Hola,\n\n" . ($esSub
            ? "Ya puedes activar «{$p['concepto']}» (" . nv_importe_txt($p) . "). Se renueva sola cada " . NV_PERIODOS[$p['periodo']] . " y no tiene permanencia: puedes darte de baja cuando quieras desde tu área de cliente.\n\n"
            : "Ya puedes realizar el pago de «{$p['concepto']}» (" . nv_euros((int)$p['importe']) . ") de forma segura:\n\n")
        . "1. Entra en " . nv_url('pagos/') . " y crea tu cuenta o inicia sesión.\n"
        . "2. Introduce este código: {$p['codigo']}\n"
        . ($esSub ? "3. Suscríbete con tarjeta, Apple Pay o Google Pay.\n" : "3. Paga con tarjeta, Apple Pay o Google Pay.\n")
        . ($p['caduca'] ? "\nEl código es válido hasta el " . date('d/m/Y', strtotime($p['caduca'])) . ".\n" : '')
        . "\nCualquier duda, responde a este email.\n\nUn saludo,\nNovis";
}
if ($vista === 'lista') {
    $f = is_string($_GET['f'] ?? 'pendiente') ? ($_GET['f'] ?? 'pendiente') : 'pendiente';
    if (!isset(EST[$f]) && !in_array($f, ['todos', 'subs'], true)) $f = 'pendiente';
    $vivas = "p.estado = 'pagado' AND p.stripe_sub IS NOT NULL AND COALESCE(p.sub_estado, '') NOT IN ('canceled', 'incomplete_expired')";
    $st = match ($f) {
        'todos' => $db->query('SELECT p.*, u.usuario FROM pagos p LEFT JOIN usuarios u ON u.id = p.usuario_id ORDER BY p.id DESC LIMIT 300'),
        'subs' => $db->query("SELECT p.*, u.usuario FROM pagos p LEFT JOIN usuarios u ON u.id = p.usuario_id WHERE $vivas ORDER BY p.sub_fin LIMIT 300"),
        default => $db->prepare('SELECT p.*, u.usuario FROM pagos p LEFT JOIN usuarios u ON u.id = p.usuario_id WHERE p.estado = ? ORDER BY p.id DESC LIMIT 300'),
    };
    if (!in_array($f, ['todos', 'subs'], true)) $st->execute([$f]);
    $lista = $st->fetchAll();
    $cuenta = ['todos' => 0, 'pendiente' => 0, 'pagado' => 0, 'cancelado' => 0, 'subs' => 0];
    foreach ($db->query('SELECT estado, COUNT(*) n FROM pagos GROUP BY estado') as $r) { $cuenta[$r['estado']] = (int)$r['n']; $cuenta['todos'] += (int)$r['n']; }
    $mes = (int)$db->query("SELECT COALESCE(SUM(importe),0) FROM pagos WHERE estado = 'pagado' AND pagado_en >= '" . date('Y-m-01') . "'")->fetchColumn()
         + (int)$db->query("SELECT COALESCE(SUM(importe),0) FROM renovaciones WHERE fecha >= '" . date('Y-m-01') . "'")->fetchColumn();
    // Suscripciones activas y lo que suman al mes (las anuales cuentan como 1/12 y las de 3 años como 1/36)
    $subs = $db->query("SELECT p.periodo, p.importe FROM pagos p WHERE $vivas AND p.sub_cancela = 0")->fetchAll();
    $cuenta['subs'] = (int)$db->query("SELECT COUNT(*) FROM pagos p WHERE $vivas")->fetchColumn();
    $alMes = array_sum(array_map(fn($x) => intdiv((int)$x['importe'], NV_PERIODOS_MESES[$x['periodo']] ?? 1), $subs));
    $pend = (int)$db->query("SELECT COALESCE(SUM(importe),0) FROM pagos WHERE estado = 'pendiente'")->fetchColumn();
    $clientes = (int)$db->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
}
$v = fn($k) => e($viejo[$k] ?? '');
$err = fn($k) => isset($errores[$k]) ? '<p class="f-err">' . e($errores[$k]) . '</p>' : '';
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Pagos · Novis</title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="../assets/favicon.svg?v=10" type="image/svg+xml">
<link rel="apple-touch-icon" href="../assets/apple-touch-icon.png?v=10">
<meta name="theme-color" content="#f5f5f7">
<link rel="stylesheet" href="panel.css?v=19">
</head>
<body class="v-pagos">

<?php if ($vista === 'lista'): ?>
  <header class="top">
    <div class="top__row">
      <h1>Pagos</h1>
      <div class="top__actions"><a class="btn btn--small" href="?nuevo=1">Nuevo pago</a></div>
    </div>
    <nav class="sw" aria-label="Secciones del panel"><a href="./">Solicitudes</a><a href="pagos.php" class="on" aria-current="page">Pagos</a><a href="cuentas.php">Cuentas</a></nav>
    <div class="stats">
      <div><strong><?= e(nv_euros($mes)) ?></strong><span>cobrado este mes</span></div>
      <div><strong><?= e(nv_euros($pend)) ?></strong><span>pendiente</span></div>
      <div><strong><?= e(nv_euros($alMes)) ?></strong><span><?= count($subs) ?> suscripci<?= count($subs) === 1 ? 'ón' : 'ones' ?> · al mes</span></div>
    </div>
    <nav class="tabs">
      <?php foreach (['pendiente' => 'Pendientes', 'pagado' => 'Pagados', 'subs' => 'Suscripciones', 'cancelado' => 'Anulados', 'todos' => 'Todos'] as $k => $t): ?>
        <a href="?f=<?= $k ?>" class="<?= $f === $k ? 'on' : '' ?>"><?= $t ?> <small><?= $cuenta[$k] ?></small></a>
      <?php endforeach; ?>
    </nav>
  </header>
  <main class="list">
    <?php if (!empty($_GET['eliminado'])): ?><p class="toast" role="status">Código eliminado</p><?php endif; ?>
    <?php if (!nv_stripe_ok()): ?><p class="warn">Stripe aún no está configurado: los clientes podrán introducir el código, pero no pagar. Añade <code>stripe_secret</code> y <code>stripe_webhook_secret</code> al archivo de configuración.</p><?php endif; ?>
    <?php if (!$lista): ?>
      <div class="empty"><p><strong>No hay pagos aquí</strong></p><p class="muted">Crea un pago nuevo para generar un código y enviárselo a tu cliente.</p><p><a class="btn btn--small" href="?nuevo=1">Nuevo pago</a></p></div>
    <?php endif; ?>
    <?php foreach ($lista as $p): ?>
      <a class="item" href="?id=<?= (int)$p['id'] ?>">
        <?php [$cl, $tx] = nv_estado($p); ?>
        <span class="dot dot--<?= CLASE[$cl] ?? 'descartada' ?>"></span>
        <div class="item__main">
          <div class="item__row"><strong><?= e($p['concepto']) ?></strong><b class="amount"><?= e(nv_importe_txt($p)) ?></b></div>
          <div class="item__sub"><?= e($p['codigo']) ?> · <?= e($tx) ?><?php if ($p['usuario']): ?> · <?= e($p['usuario']) ?><?php elseif ($p['email_cliente']): ?> · <?= e($p['email_cliente']) ?><?php endif; ?></div>
        </div>
      </a>
    <?php endforeach; ?>
    <p class="space">Los clientes pagan en <?= e(nv_url('pagos/')) ?></p>
  </main>

<?php elseif ($vista === 'nuevo'): ?>
  <header class="top top--detail"><a href="pagos.php" class="back">‹ Pagos</a></header>
  <main class="detail">
    <form method="post" class="card form-pago" novalidate>
      <h1>Nuevo pago</h1>
      <p class="muted">Se generará un código para que tu cliente pague online.</p>
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <label>Concepto <span class="muted">(lo verá el cliente)</span>
        <input name="concepto" maxlength="120" required placeholder="Web corporativa · primer pago (50%)" value="<?= $v('concepto') ?>"></label><?= $err('concepto') ?>
      <label>Contenido de la venta <span class="muted">(opcional)</span>
        <textarea name="descripcion" rows="4" maxlength="1000" placeholder="Diseño a medida, 5 secciones, formulario de contacto, SEO básico…"><?= $v('descripcion') ?></textarea></label><?= $err('descripcion') ?>
      <fieldset class="tipo">
        <legend>Tipo de cobro</legend>
        <?php foreach (NV_PERIODOS_TXT as $k => $t): ?>
          <label><input type="radio" name="periodo" value="<?= $k ?>"<?= ($viejo['periodo'] ?? '') === $k ? ' checked' : '' ?>> <?= e($t) ?></label>
        <?php endforeach; ?>
      </fieldset>
      <p class="muted small">Las suscripciones se cobran solas cada mes, cada año o cada 3 años hasta que el cliente se dé de baja (puede hacerlo desde su área). Para el alojamiento de 3 años elige «cada 3 años».</p>
      <label>Importe en euros <span class="muted">(IVA incluido; en suscripciones, el de cada cuota)</span>
        <input name="importe" inputmode="decimal" required placeholder="332,15" value="<?= $v('importe') ?>"></label><?= $err('importe') ?>
      <label>Email del cliente <span class="muted">(opcional: solo esa persona podrá usar el código)</span>
        <input name="email_cliente" type="email" placeholder="cliente@empresa.com" value="<?= $v('email_cliente') ?>"></label><?= $err('email_cliente') ?>
      <label>Válido hasta <span class="muted">(opcional)</span>
        <input name="caduca" type="date" min="<?= date('Y-m-d') ?>" value="<?= $v('caduca') ?>"></label><?= $err('caduca') ?>
      <button class="btn" name="accion" value="crear">Crear código de pago</button>
    </form>
  </main>

<?php else: ?>
  <header class="top top--detail"><a href="pagos.php" class="back">‹ Pagos</a></header>
  <main class="detail">
    <?php if (!empty($_GET['nuevo'])): ?><p class="toast" role="status">Código creado. Envíaselo a tu cliente.</p><?php endif; ?>
    <?php if (!empty($_GET['comprobado'])): ?><p class="toast" role="status">Estado comprobado con Stripe</p><?php endif; ?>
    <section class="card">
      <?php [$cl, $tx] = nv_estado($p); ?>
      <span class="pill pill--<?= CLASE[$cl] ?? 'descartada' ?>"><?= e($tx) ?></span>
      <h1><?= e($p['concepto']) ?></h1>
      <p class="big-amount"><?= e(nv_importe_txt($p)) ?></p>
      <div class="codebox">
        <span class="muted">Código</span>
        <strong><?= e($p['codigo']) ?></strong>
        <button class="btn btn--ghost btn--small" type="button" data-copy="<?= e($p['codigo']) ?>" data-copied="Copiado ✓">Copiar código</button>
      </div>
      <?php if ($p['descripcion']): ?><p class="msg"><?= nl2br(e($p['descripcion'])) ?></p><?php endif; ?>
      <dl>
        <dt>Tipo</dt><dd><?= e(NV_PERIODOS_TXT[$p['periodo'] ?? ''] ?? 'Pago único') ?></dd>
        <dt>Creado</dt><dd><?= e(date('d/m/Y H:i', strtotime($p['creado']))) ?></dd>
        <?php if ($p['email_cliente']): ?><dt>Solo para</dt><dd><?= e($p['email_cliente']) ?></dd><?php endif; ?>
        <?php if ($p['caduca']): ?><dt>Válido hasta</dt><dd><?= e(date('d/m/Y', strtotime($p['caduca']))) ?></dd><?php endif; ?>
        <?php if ($p['usuario']): ?><dt>Cliente</dt><dd><a href="cuentas.php?id=<?= (int)$p['usuario_id'] ?>"><?= e($p['usuario']) ?></a> · <?= e($p['cuenta_email']) ?><?= $p['telefono'] ? ' · ' . e($p['telefono']) : '' ?></dd><?php endif; ?>
        <?php if ($p['pagado_en']): ?><dt>Pagado</dt><dd><?= e(date('d/m/Y H:i', strtotime($p['pagado_en']))) ?></dd><?php endif; ?>
        <?php if ($esSub && $p['estado'] === 'pagado'): ?>
          <?php if ($p['sub_fin'] && nv_sub_viva($p)): ?><dt><?= $p['sub_cancela'] ? 'Termina el' : 'Próxima renovación' ?></dt><dd><?= e(date('d/m/Y', strtotime($p['sub_fin']))) ?></dd><?php endif; ?>
          <dt>Cuotas cobradas</dt><dd><?= 1 + count($renov) ?> · <?= e(nv_euros((int)$p['importe'] + array_sum(array_column($renov, 'importe')))) ?> en total</dd>
          <?php if ($p['stripe_sub']): ?><dt>Stripe</dt><dd><a href="https://dashboard.stripe.com/subscriptions/<?= e($p['stripe_sub']) ?>" target="_blank" rel="noopener">Ver suscripción en Stripe</a></dd><?php endif; ?>
        <?php endif; ?>
        <?php if ($p['stripe_pi']): ?><dt>Stripe</dt><dd><a href="https://dashboard.stripe.com/payments/<?= e($p['stripe_pi']) ?>" target="_blank" rel="noopener">Ver en Stripe</a></dd><?php endif; ?>
      </dl>
    </section>

    <?php if ($p['estado'] === 'pendiente'): ?>
    <section class="card">
      <h2>Mensaje para el cliente</h2>
      <textarea class="msgbox" rows="11" readonly id="msgCliente"><?= e($mensaje) ?></textarea>
      <div class="actions">
        <button class="btn" type="button" data-copy-from="msgCliente" data-copied="Copiado ✓">Copiar mensaje</button>
        <?php if ($p['email_cliente']): ?><a class="btn btn--ghost" href="mailto:<?= e($p['email_cliente']) ?>?subject=<?= rawurlencode('Pago de ' . $p['concepto'] . ' · Novis') ?>&body=<?= rawurlencode($mensaje) ?>">Abrir en el email</a><?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($_GET['sub'])): ?><p class="toast" role="status"><?= $_GET['sub'] === 'ok' ? 'Suscripción actualizada' : 'Stripe no respondió. Inténtalo de nuevo.' ?></p><?php endif; ?>
    <?php if ($esSub && nv_sub_viva($p)): ?>
    <section class="card actions-card">
      <h2>Suscripción</h2>
      <?php if ($p['sub_cancela']): ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn--ghost" name="accion" value="sub_reanudar">Anular la baja</button></form>
      <?php else: ?>
        <form method="post" data-confirm="¿Dar de baja? Seguirá activa hasta el final del periodo pagado y no se cobrará más."><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn--ghost" name="accion" value="sub_baja">Dar de baja al final del periodo</button></form>
      <?php endif; ?>
      <form method="post" data-confirm="¿Cancelar ya? Se corta en este momento, sin esperar al final del periodo (no se devuelve dinero automáticamente)."><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="link link--red" name="accion" value="sub_cancelar">Cancelar ahora</button></form>
    </section>
    <?php endif; ?>

    <section class="card actions-card">
      <?php if ($p['stripe_session'] && ($p['estado'] !== 'pagado' || $esSub)): ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn--ghost" name="accion" value="comprobar">Comprobar pago en Stripe</button></form>
      <?php endif; ?>
      <?php if ($p['estado'] === 'pendiente'): ?>
        <form method="post" data-confirm="¿Anular este código? El cliente ya no podrá pagarlo."><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn--ghost" name="accion" value="anular">Anular código</button></form>
      <?php elseif ($p['estado'] === 'cancelado'): ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn--ghost" name="accion" value="reactivar">Reactivar código</button></form>
      <?php endif; ?>
      <?php if ($p['estado'] !== 'pagado'): ?>
        <form method="post" data-confirm="¿Eliminar este código? No se puede deshacer."><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="link link--red" name="accion" value="eliminar">Eliminar</button></form>
      <?php else: ?>
        <p class="muted small">Los pagos completados no se pueden eliminar: se conservan como registro contable. Las devoluciones se hacen desde Stripe.</p>
      <?php endif; ?>
    </section>
  </main>
<?php endif; ?>

<script src="panel.js?v=3" defer></script>
</body>
</html>
