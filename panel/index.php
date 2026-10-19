<?php
// Novis — panel privado de solicitudes
require_once __DIR__ . '/../lib/novis.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,   // 30 días conectado
    'path' => '/panel/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_name('novis_panel');
session_start();

$config = novis_config();
$clave  = (string)($config['panel_password'] ?? '');
$usuario = (string)($config['panel_user'] ?? '');
$error  = '';
$aviso  = '';

const ESTADOS = ['nueva' => 'Nueva', 'en_curso' => 'En curso', 'cerrada' => 'Cerrada', 'descartada' => 'Descartada'];

function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_ok(): bool {
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}
function volver(string $q = ''): void {
    header('Location: ./' . ($q ? '?' . $q : ''));
    exit;
}

// ---------- Sin contraseña configurada ----------
if (strlen($clave) < 8 || $usuario === '') {
    $vista = 'sin_config';
}
// ---------- Login ----------
elseif (empty($_SESSION['ok'])) {
    $vista = 'login';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $dir = NOVIS_PRIVATE . '/novis-data';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $f = $dir . '/intentos-' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'x') . '.json';
        $intentos = is_readable($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
        $intentos = array_values(array_filter($intentos, fn($t) => $t > time() - 900));
        if (count($intentos) >= 5) {
            $error = 'Demasiados intentos. Espera 15 minutos.';
        } elseif (
            hash_equals(mb_strtolower($usuario), mb_strtolower(trim((string)($_POST['usuario'] ?? '')))) &
            hash_equals($clave, (string)($_POST['password'] ?? ''))
        ) {
            session_regenerate_id(true);
            $_SESSION['ok'] = true;
            @unlink($f);
            volver();
        } else {
            $intentos[] = time();
            @file_put_contents($f, json_encode($intentos), LOCK_EX);
            sleep(1);
            $error = 'Usuario o contraseña incorrectos.';
        }
    }
}
// ---------- Panel ----------
else {
    $db = novis_db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_ok()) volver();
        $accion = $_POST['accion'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        if ($accion === 'salir') {
            $_SESSION = [];
            session_destroy();
            volver();
        }
        if ($accion === 'estado' && isset(ESTADOS[$_POST['estado'] ?? ''])) {
            $db->prepare('UPDATE solicitudes SET estado = ?, actualizado = ? WHERE id = ?')->execute([$_POST['estado'], date('c'), $id]);
            volver('id=' . $id);
        }
        if ($accion === 'notas') {
            $db->prepare('UPDATE solicitudes SET notas = ?, actualizado = ? WHERE id = ?')->execute([mb_substr($_POST['notas'] ?? '', 0, 5000), date('c'), $id]);
            volver('id=' . $id . '&guardado=1');
        }
        if ($accion === 'borrar') {
            $db->prepare('DELETE FROM solicitudes WHERE id = ?')->execute([$id]);
            volver();
        }
        volver();
    }

    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $st = $db->prepare('SELECT * FROM solicitudes WHERE id = ?');
        $st->execute([$id]);
        $sol = $st->fetch();
        if (!$sol) volver();
        $vista = 'detalle';
        if (!empty($_GET['guardado'])) $aviso = 'Notas guardadas';
    } else {
        $vista = 'lista';
        $filtro = $_GET['f'] ?? 'activas';
        $buscar = trim($_GET['q'] ?? '');
        $donde = []; $params = [];
        if ($filtro === 'activas') { $donde[] = "estado IN ('nueva','en_curso')"; }
        elseif (isset(ESTADOS[$filtro])) { $donde[] = 'estado = ?'; $params[] = $filtro; }
        if ($buscar !== '') {
            $donde[] = '(nombre LIKE ? OR empresa LIKE ? OR email LIKE ? OR mensaje LIKE ?)';
            array_push($params, "%$buscar%", "%$buscar%", "%$buscar%", "%$buscar%");
        }
        $sql = 'SELECT * FROM solicitudes' . ($donde ? ' WHERE ' . implode(' AND ', $donde) : '') . ' ORDER BY id DESC LIMIT 300';
        $st = $db->prepare($sql); $st->execute($params);
        $lista = $st->fetchAll();
        $cuenta = ['todas' => 0, 'activas' => 0];
        foreach (array_keys(ESTADOS) as $k) $cuenta[$k] = 0;
        foreach ($db->query('SELECT estado, COUNT(*) n FROM solicitudes GROUP BY estado') as $r) {
            $cuenta[$r['estado']] = (int)$r['n'];
            $cuenta['todas'] += (int)$r['n'];
        }
        $cuenta['activas'] = $cuenta['nueva'] + $cuenta['en_curso'];
        $semana = (int)$db->query("SELECT COUNT(*) FROM solicitudes WHERE creado >= '" . date('c', strtotime('-7 days')) . "'")->fetchColumn();
    }
}

function hace(string $fecha): string {
    $s = time() - strtotime($fecha);
    if ($s < 60) return 'ahora';
    if ($s < 3600) return 'hace ' . floor($s / 60) . ' min';
    if ($s < 86400) return 'hace ' . floor($s / 3600) . ' h';
    if ($s < 172800) return 'ayer';
    if ($s < 604800) return 'hace ' . floor($s / 86400) . ' días';
    return date('d/m/Y', strtotime($fecha));
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Solicitudes · Novis</title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="../assets/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="../assets/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Novis">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="theme-color" content="#f5f5f7">
<link rel="stylesheet" href="panel.css?v=3">
</head>
<body class="v-<?= e($vista) ?>"<?= ($vista ?? '') === 'lista' ? ' data-autorefresh="1"' : '' ?>>

<?php if ($vista === 'sin_config'): ?>
  <main class="center">
    <div class="box">
      <span class="logo"></span>
      <h1>Panel sin configurar</h1>
      <p class="muted">Añade un usuario y una contraseña (mínimo 8 caracteres) en el archivo <code>gemini-config.php</code> que está fuera de <code>public_html</code>:</p>
      <pre>&lt;?php
return [
  'key' =&gt; 'TU_CLAVE_DE_GEMINI',
  'panel_user' =&gt; 'TU_USUARIO',
  'panel_password' =&gt; 'TU_CONTRASEÑA',
];</pre>
    </div>
  </main>

<?php elseif ($vista === 'login'): ?>
  <main class="center">
    <form class="box" method="post" autocomplete="on">
      <span class="logo"></span>
      <h1>Solicitudes</h1>
      <p class="muted">Introduce tu usuario y contraseña.</p>
      <div class="fields">
        <input type="text" name="usuario" placeholder="Usuario" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" required autofocus value="<?= e($_POST['usuario'] ?? '') ?>">
        <input type="password" name="password" placeholder="Contraseña" autocomplete="current-password" required>
      </div>
      <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
      <button class="btn" type="submit">Entrar</button>
    </form>
  </main>

<?php elseif ($vista === 'lista'): ?>
  <header class="top">
    <div class="top__row">
      <h1>Solicitudes</h1>
      <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><button class="link" name="accion" value="salir">Salir</button></form>
    </div>
    <div class="stats">
      <div><strong><?= $cuenta['nueva'] ?></strong><span>nuevas</span></div>
      <div><strong><?= $cuenta['en_curso'] ?></strong><span>en curso</span></div>
      <div><strong><?= $semana ?></strong><span>esta semana</span></div>
    </div>
    <form class="search" method="get">
      <input type="hidden" name="f" value="<?= e($filtro) ?>">
      <input type="search" name="q" value="<?= e($buscar) ?>" placeholder="Buscar nombre, empresa o email">
    </form>
    <nav class="tabs">
      <?php foreach (['activas' => 'Pendientes', 'nueva' => 'Nuevas', 'en_curso' => 'En curso', 'cerrada' => 'Cerradas', 'descartada' => 'Descartadas', 'todas' => 'Todas'] as $k => $t): ?>
        <a href="?f=<?= $k ?>" class="<?= $filtro === $k ? 'on' : '' ?>"><?= $t ?> <small><?= $cuenta[$k] ?></small></a>
      <?php endforeach; ?>
    </nav>
  </header>
  <main class="list">
    <?php if (!$lista): ?>
      <div class="empty">
        <p><strong><?= $buscar ? 'Sin resultados' : 'Nada por aquí' ?></strong></p>
        <p class="muted"><?= $buscar ? 'Prueba con otra búsqueda.' : 'Las nuevas solicitudes del formulario aparecerán aquí.' ?></p>
      </div>
    <?php endif; ?>
    <?php foreach ($lista as $s): ?>
      <a class="item" href="?id=<?= (int)$s['id'] ?>">
        <span class="dot dot--<?= e($s['estado']) ?>"></span>
        <div class="item__main">
          <div class="item__row"><strong><?= e($s['nombre']) ?></strong><time><?= e(hace($s['creado'])) ?></time></div>
          <div class="item__sub"><?= e($s['empresa'] ?: $s['email']) ?><?php if ($s['plan'] && $s['plan'] !== 'Aún no lo sé'): ?> · <b><?= e($s['plan']) ?></b><?php endif; ?></div>
          <p><?= e(mb_strimwidth($s['mensaje'], 0, 110, '…')) ?></p>
        </div>
      </a>
    <?php endforeach; ?>
  </main>

<?php elseif ($vista === 'detalle'): ?>
  <header class="top top--detail">
    <a href="./" class="back">‹ Solicitudes</a>
  </header>
  <main class="detail">
    <section class="card">
      <span class="pill pill--<?= e($sol['estado']) ?>"><?= e(ESTADOS[$sol['estado']] ?? $sol['estado']) ?></span>
      <h1><?= e($sol['nombre']) ?></h1>
      <?php if ($sol['empresa']): ?><p class="muted big"><?= e($sol['empresa']) ?></p><?php endif; ?>
      <dl>
        <dt>Email</dt><dd><a href="mailto:<?= e($sol['email']) ?>"><?= e($sol['email']) ?></a></dd>
        <dt>Plan</dt><dd><?= e($sol['plan'] ?: '—') ?></dd>
        <dt>Recibida</dt><dd><?= e(date('d/m/Y H:i', strtotime($sol['creado']))) ?></dd>
        <dt>Privacidad</dt><dd><?= !empty($sol['consentimiento']) ? 'Aceptada el ' . e(date('d/m/Y H:i', strtotime($sol['consentimiento']))) : '—' ?></dd>
      </dl>
      <div class="actions">
        <a class="btn" href="mailto:<?= e($sol['email']) ?>?subject=<?= rawurlencode('Tu proyecto web con Novis') ?>&body=<?= rawurlencode('Hola ' . $sol['nombre'] . ",\n\nGracias por contactar con Novis. ") ?>">Responder</a>
        <button class="btn btn--ghost" type="button" data-copy="<?= e($sol['email']) ?>">Copiar email</button>
      </div>
    </section>

    <section class="card">
      <h2>Mensaje</h2>
      <p class="msg"><?= nl2br(e($sol['mensaje'])) ?></p>
    </section>

    <section class="card">
      <h2>Estado</h2>
      <form method="post" class="seg">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="id" value="<?= (int)$sol['id'] ?>">
        <input type="hidden" name="accion" value="estado">
        <?php foreach (ESTADOS as $k => $t): ?>
          <button name="estado" value="<?= $k ?>" class="<?= $sol['estado'] === $k ? 'on' : '' ?>"><?= $t ?></button>
        <?php endforeach; ?>
      </form>
    </section>

    <section class="card">
      <h2>Notas privadas</h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="id" value="<?= (int)$sol['id'] ?>">
        <input type="hidden" name="accion" value="notas">
        <textarea name="notas" rows="4" placeholder="Presupuesto enviado, llamar el lunes…"><?= e($sol['notas']) ?></textarea>
        <button class="btn btn--small" type="submit">Guardar notas</button>
        <?php if ($aviso): ?><span class="ok"><?= e($aviso) ?></span><?php endif; ?>
      </form>
    </section>

    <form method="post" class="danger" data-confirm="¿Eliminar esta solicitud? No se puede deshacer.">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="id" value="<?= (int)$sol['id'] ?>">
      <button name="accion" value="borrar" class="link link--red">Eliminar solicitud</button>
    </form>
  </main>
<?php endif; ?>

<script src="panel.js?v=1" defer></script>
</body>
</html>
