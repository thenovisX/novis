<?php
// The Novis — panel privado: cuentas de clientes
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
function volver(string $q = ''): void { header('Location: cuentas.php' . ($q ? '?' . $q : '')); exit; }
function fecha(?string $f): string { return $f ? date('d/m/Y H:i', strtotime($f)) : '—'; }

$db = nv_db();
$errores = [];

// Lista de clientes que han aceptado recibir publicidad (CSV para tu herramienta de email).
// Solo ellos: enviar publicidad a quien no la ha aceptado está prohibido (art. 21 LSSI-CE).
if (($_GET['exportar'] ?? '') === 'publicidad') {
    $filas = $db->query('SELECT u.email, u.usuario, p.marketing, p.nombre, p.empresa FROM perfiles p JOIN usuarios u ON u.id = p.usuario_id WHERE p.marketing IS NOT NULL ORDER BY p.marketing')->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="publicidad-aceptada-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // para que Excel lea bien los acentos
    fputcsv($out, ['email', 'usuario', 'nombre', 'empresa', 'aceptado'], ';');
    // Un valor que empieza por = + - @ se escapa para que la hoja de cálculo no lo ejecute como fórmula
    $seguro = fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : (string)$v;
    foreach ($filas as $f) fputcsv($out, array_map($seguro, [$f['email'], $f['usuario'], $f['nombre'], $f['empresa'], date('d/m/Y H:i', strtotime($f['marketing']))]), ';');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) volver();
    $accion = $_POST['accion'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $st = $db->prepare('SELECT * FROM usuarios WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    $st->closeCursor();
    if (!$u) volver();

    if ($accion === 'enviar_reset') {
        nv_email_restablecer($u);
        volver('id=' . $id . '&ok=reset');
    }
    if ($accion === 'nueva_pass') {
        $pass = (string)($_POST['password'] ?? '');
        if (mb_strlen($pass) < 10) $errores['password'] = 'La contraseña debe tener al menos 10 caracteres.';
        elseif ($pass !== (string)($_POST['password2'] ?? '')) $errores['password2'] = 'Las contraseñas no coinciden.';
        if (!$errores) {
            // Cambiarla cierra cualquier sesión que el cliente tuviera abierta
            $db->prepare('UPDATE usuarios SET password_hash = ?, pass_cambiada = ? WHERE id = ?')
               ->execute([password_hash($pass, PASSWORD_DEFAULT), time(), $id]);
            $db->prepare("UPDATE tokens SET usado = 1 WHERE usuario_id = ? AND tipo = 'reset'")->execute([$id]);
            volver('id=' . $id . '&ok=pass');
        }
        $_GET['id'] = $id;
    }
    if ($accion === 'verificar') {
        $db->prepare('UPDATE usuarios SET email_verificado = ? WHERE id = ? AND email_verificado IS NULL')->execute([date('c'), $id]);
        volver('id=' . $id . '&ok=verificado');
    }
    // El cliente pide (por email o teléfono) que dejes de enviarle publicidad o que borres su perfil
    if ($accion === 'publicidad_no') { nv_marketing($id, false, 'panel (a petición del cliente)'); volver('id=' . $id . '&ok=publicidad'); }
    if ($accion === 'perfil_no') { nv_perfil_permiso($id, false, 'panel (a petición del cliente)'); volver('id=' . $id . '&ok=perfil'); }
    if ($accion === 'eliminar') {
        nv_sub_cancelar_de_usuario($id);   // las suscripciones activas se cancelan para que no se siga cobrando
        // Los pagos se conservan (obligación legal), pero dejan de estar ligados a la cuenta
        $db->prepare('UPDATE pagos SET usuario_id = NULL WHERE usuario_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM tokens WHERE usuario_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM perfiles WHERE usuario_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
        novis_compactar();
        volver('eliminada=1');
    }
    if (!$errores) volver();
}

$vista = !empty($_GET['id']) ? 'detalle' : 'lista';

if ($vista === 'detalle') {
    $st = $db->prepare('SELECT * FROM usuarios WHERE id = ?');
    $st->execute([(int)$_GET['id']]);
    $u = $st->fetch();
    if (!$u) volver();
    $st = $db->prepare('SELECT * FROM pagos WHERE usuario_id = ? ORDER BY id DESC');
    $st->execute([$u['id']]);
    $pagos = $st->fetchAll();
    $st = $db->prepare('SELECT id, creado, plan, estado, mensaje FROM solicitudes WHERE email_idx = ? ORDER BY id DESC');
    $st->execute([nv_indice($u['email'])]);
    $msgs = $st->fetchAll();
    $pf = nv_perfil((int)$u['id']);
    $avisos = ['publicidad' => 'Ya no recibirá publicidad. Quítalo también de tu herramienta de email si lo tenías allí.', 'perfil' => 'Perfil de cliente borrado.', 'reset' => 'Email enviado: el cliente recibirá un enlace para crear su contraseña.',
               'pass' => 'Contraseña cambiada. Las sesiones abiertas de este cliente se han cerrado.',
               'verificado' => 'Email marcado como verificado.'];
    $aviso = $avisos[$_GET['ok'] ?? ''] ?? '';
} else {
    $f = is_string($_GET['f'] ?? 'todas') ? ($_GET['f'] ?? 'todas') : 'todas';
    $q = is_string($_GET['q'] ?? '') ? trim($_GET['q'] ?? '') : '';
    $donde = []; $params = [];
    if ($f === 'sin_verificar') $donde[] = 'u.email_verificado IS NULL';
    elseif ($f === 'google') $donde[] = 'u.google_sub IS NOT NULL';
    elseif ($f === 'publicidad') $donde[] = 'p.marketing IS NOT NULL';
    elseif ($f === 'con_pagos') $donde[] = "EXISTS (SELECT 1 FROM pagos p WHERE p.usuario_id = u.id AND p.estado = 'pagado')";
    else $f = 'todas';
    $st = $db->prepare("SELECT u.*, (SELECT COALESCE(SUM(importe),0) FROM pagos pg WHERE pg.usuario_id = u.id AND pg.estado = 'pagado') AS pagado,
        p.marketing, p.perfil FROM usuarios u LEFT JOIN perfiles p ON p.usuario_id = u.id" . ($donde ? ' WHERE ' . implode(' AND ', $donde) : '') . ' ORDER BY u.id DESC');
    $st->execute($params);
    $lista = $st->fetchAll();
    // Usuario, email y teléfono están cifrados en la base de datos: la búsqueda se hace aquí, ya descifrados
    if ($q !== '') {
        $lista = array_values(array_filter($lista, function ($x) use ($q) {
            foreach (['usuario', 'email', 'telefono'] as $c) if (mb_stripos((string)$x[$c], $q) !== false) return true;
            return false;
        }));
    }
    $lista = array_slice($lista, 0, 300);
    $c = $db->query("SELECT COUNT(*) total,
        SUM(email_verificado IS NULL) sin_verificar,
        SUM(google_sub IS NOT NULL) google,
        SUM(creado >= '" . date('Y-m-01') . "') mes,
        (SELECT COUNT(DISTINCT usuario_id) FROM pagos WHERE estado = 'pagado' AND usuario_id IS NOT NULL) con_pagos,
        (SELECT COUNT(*) FROM perfiles WHERE marketing IS NOT NULL) publicidad
        FROM usuarios")->fetch();
    $c = array_map('intval', $c);
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Cuentas · The Novis</title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="../assets/favicon.svg?v=10" type="image/svg+xml">
<link rel="apple-touch-icon" href="../assets/apple-touch-icon.png?v=10">
<meta name="theme-color" content="#f5f5f7">
<link rel="stylesheet" href="panel.css?v=21">
</head>
<body class="v-cuentas">

<?php if ($vista === 'lista'): ?>
  <header class="top">
    <div class="top__row"><h1>Cuentas</h1></div>
    <?php $seccionActual = 'cuentas'; include __DIR__ . '/nav.php'; ?>
    <div class="stats">
      <div><strong><?= $c['total'] ?></strong><span>cuentas</span></div>
      <div><strong><?= $c['mes'] ?></strong><span>nuevas este mes</span></div>
      <div><strong><?= $c['sin_verificar'] ?></strong><span>sin verificar</span></div>
    </div>
    <form class="search" method="get">
      <input type="hidden" name="f" value="<?= e($f) ?>">
      <label for="q" class="sr">Buscar cuentas</label>
      <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Buscar usuario, email o teléfono">
    </form>
    <nav class="tabs">
      <?php foreach (['todas' => ['Todas', $c['total']], 'con_pagos' => ['Con pagos', $c['con_pagos']], 'sin_verificar' => ['Sin verificar', $c['sin_verificar']], 'google' => ['Con Google', $c['google']], 'publicidad' => ['Publicidad', $c['publicidad']]] as $k => [$t, $n]): ?>
        <a href="?f=<?= $k ?>" class="<?= $f === $k ? 'on' : '' ?>"><?= $t ?> <small><?= $n ?></small></a>
      <?php endforeach; ?>
    </nav>
  </header>
  <main class="list">
    <?php if ($f === 'publicidad'): ?><p class="muted small">Solo puedes enviar publicidad a estas cuentas: lo han aceptado con una casilla (la fecha queda guardada). <a href="?exportar=publicidad">Descargar la lista (CSV)</a></p><?php endif; ?>
    <?php if (!empty($_GET['eliminada'])): ?><p class="toast" role="status">Cuenta eliminada</p><?php endif; ?>
    <?php if (!$lista): ?>
      <div class="empty"><p><strong><?= $q ? 'Sin resultados' : 'Aún no hay cuentas' ?></strong></p><p class="muted"><?= $q ? 'Prueba con otra búsqueda.' : 'Aparecerán aquí cuando tus clientes se registren en la sección de pagos.' ?></p></div>
    <?php endif; ?>
    <?php foreach ($lista as $u): ?>
      <a class="item" href="?id=<?= (int)$u['id'] ?>">
        <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($u['usuario'] ?: $u['email'], 0, 1))) ?></span>
        <div class="item__main">
          <div class="item__row"><strong><?= e($u['usuario'] ?: 'Sin usuario') ?></strong><?php if ((int)$u['pagado'] > 0): ?><b class="amount"><?= e(nv_euros((int)$u['pagado'])) ?></b><?php endif; ?></div>
          <div class="item__sub"><?= e($u['email']) ?></div>
          <div class="badges">
            <?php if (!$u['email_verificado']): ?><span class="pill pill--en_curso">Sin verificar</span><?php endif; ?>
            <?php if ($u['google_sub']): ?><span class="pill">Google</span><?php endif; ?>
            <?php if (!$u['usuario']): ?><span class="pill">Perfil incompleto</span><?php endif; ?>
            <?php if ($u['perfil']): ?><span class="pill">Perfil guardado</span><?php endif; ?>
            <?php if ($u['marketing']): ?><span class="pill pill--cerrada">Publicidad</span><?php endif; ?>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </main>

<?php else: ?>
  <header class="top top--detail"><a href="cuentas.php" class="back">‹ Cuentas</a></header>
  <main class="detail">
    <?php if ($aviso): ?><p class="toast" role="status"><?= e($aviso) ?></p><?php endif; ?>
    <section class="card">
      <div class="person">
        <span class="avatar avatar--big" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($u['usuario'] ?: $u['email'], 0, 1))) ?></span>
        <div><h1><?= e($u['usuario'] ?: 'Sin usuario') ?></h1><p class="muted"><?= e($u['email']) ?></p></div>
      </div>
      <dl>
        <dt>Teléfono</dt><dd><?= $u['telefono'] ? '<a href="tel:' . e($u['telefono']) . '">' . e($u['telefono']) . '</a>' : '—' ?></dd>
        <dt>Email</dt><dd><?= $u['email_verificado'] ? 'Verificado el ' . e(fecha($u['email_verificado'])) : '<span class="pill pill--en_curso">Sin verificar</span>' ?></dd>
        <dt>Acceso</dt><dd><?= $u['password_hash'] ? 'Contraseña' : '' ?><?= $u['password_hash'] && $u['google_sub'] ? ' y ' : '' ?><?= $u['google_sub'] ? 'Google' : '' ?></dd>
        <dt>Creada</dt><dd><?= e(fecha($u['creado'])) ?></dd>
        <dt>Último acceso</dt><dd><?= e(fecha($u['ultimo_acceso'])) ?></dd>
      </dl>
      <div class="actions">
        <a class="btn btn--ghost" href="mailto:<?= e($u['email']) ?>">Escribir email</a>
        <?php if (!$u['email_verificado']): ?>
          <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn btn--ghost" name="accion" value="verificar">Marcar email como verificado</button></form>
        <?php endif; ?>
      </div>
    </section>

    <section class="card">
      <h2>Perfil de cliente y publicidad</h2>
      <dl>
        <dt>Publicidad</dt><dd><?= $pf['marketing'] ? 'Aceptada el ' . e(fecha($pf['marketing'])) : 'No aceptada: no le envíes publicidad' ?></dd>
        <dt>Perfil</dt><dd><?= $pf['perfil'] ? 'Aceptado el ' . e(fecha($pf['perfil'])) : 'No aceptado: solo se guardan su acceso y sus pagos' ?></dd>
        <?php if ($pf['perfil']): foreach (['nombre' => 'Nombre', 'empresa' => 'Empresa', 'nif' => 'NIF', 'direccion' => 'Dirección', 'telefono' => 'Teléfono'] as $k => $txt): ?>
          <dt><?= $txt ?></dt><dd><?= e($pf[$k] ?: '—') ?></dd>
        <?php endforeach; endif; ?>
      </dl>
      <?php if ($pf['perfil'] && !$pf['actualizado']): ?><p class="muted small">Los datos se rellenan solos con lo que escriba en la página de pago de Stripe en su próxima compra.</p><?php endif; ?>
      <?php if ($pf['marketing'] || $pf['perfil']): ?>
        <div class="actions">
          <?php if ($pf['marketing']): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn btn--ghost" name="accion" value="publicidad_no">Dar de baja de la publicidad</button></form><?php endif; ?>
          <?php if ($pf['perfil']): ?><form method="post" data-confirm="¿Borrar el perfil de cliente? Se quitan sus datos de facturación guardados."><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn btn--ghost" name="accion" value="perfil_no">Borrar perfil</button></form><?php endif; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Pagos de esta cuenta</h2>
      <?php if (!$pagos): ?>
        <p class="muted">Todavía no ha usado ningún código.</p>
      <?php else: ?>
        <ul class="mini">
          <?php foreach ($pagos as $p): ?>
            <li><a href="pagos.php?id=<?= (int)$p['id'] ?>"><span><?= e($p['concepto']) ?><small><?= e($p['codigo']) ?></small></span><b><?= e(nv_importe_txt($p)) ?></b>
              <?php [$cl, $tx] = nv_estado($p); ?><span class="pill pill--<?= ['pagado' => 'cerrada', 'pendiente' => 'en_curso', 'error' => 'error'][$cl] ?? 'descartada' ?>"><?= e($tx) ?></span></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Mensajes</h2>
      <?php if (!$msgs): ?>
        <p class="muted">No te ha escrito todavía.</p>
      <?php else: ?>
        <ul class="mini">
          <?php foreach ($msgs as $mm): ?>
            <li><a href="./?id=<?= (int)$mm['id'] ?>"><span><?= e(preg_replace('/^Cliente · /', '', (string)($mm['plan'] ?: 'Formulario'))) ?><small><?= e(mb_strimwidth($mm['mensaje'], 0, 60, '…')) ?></small></span>
              <span class="pill pill--<?= e($mm['estado']) ?>"><?= e(['nueva' => 'Nueva', 'en_curso' => 'En curso', 'cerrada' => 'Cerrada', 'descartada' => 'Descartada'][$mm['estado']] ?? $mm['estado']) ?></span></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Contraseña</h2>
      <p class="muted">Lo más seguro es enviarle un enlace para que la cree él mismo. Si te la pide por teléfono, también puedes ponerle una tú.</p>
      <form method="post" class="actions">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
        <button class="btn" name="accion" value="enviar_reset">Enviarle un enlace por email</button>
      </form>
      <details class="sub"<?= $errores ? ' open' : '' ?>>
        <summary>Poner una contraseña nueva</summary>
        <form method="post" class="form-pago" novalidate>
          <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <label>Contraseña nueva <span class="muted">(mínimo 10 caracteres)</span>
            <input type="password" name="password" autocomplete="new-password" minlength="10" required></label>
          <?= isset($errores['password']) ? '<p class="f-err">' . e($errores['password']) . '</p>' : '' ?>
          <label>Repite la contraseña
            <input type="password" name="password2" autocomplete="new-password" required></label>
          <?= isset($errores['password2']) ? '<p class="f-err">' . e($errores['password2']) . '</p>' : '' ?>
          <button class="btn btn--ghost" name="accion" value="nueva_pass">Guardar contraseña</button>
          <p class="muted small">Al cambiarla se cierran las sesiones que el cliente tenga abiertas. Comunícasela por un canal seguro y pídele que la cambie.</p>
        </form>
      </details>
    </section>

    <section class="card">
      <h2>Eliminar cuenta</h2>
      <p class="muted">Se borran sus datos de acceso. Sus pagos se conservan como registro contable, sin vincular a la cuenta.</p>
      <form method="post" data-confirm="¿Eliminar la cuenta de <?= e($u['usuario'] ?: $u['email']) ?>? Sus suscripciones activas se cancelarán. No se puede deshacer.">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
        <button class="btn btn--danger" name="accion" value="eliminar">Eliminar cuenta</button>
      </form>
    </section>
  </main>
<?php endif; ?>

<script src="panel.js?v=4" defer></script>
</body>
</html>
