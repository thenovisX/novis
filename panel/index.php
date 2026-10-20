<?php
// Novis — panel privado de solicitudes
require_once __DIR__ . '/../lib/cuentas.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

nv_panel_sesion();

$config = novis_config();
$clave  = (string)($config['panel_password'] ?? '');
$hashClave = (string)($config['panel_password_hash'] ?? '');   // recomendado: solo el hash, nunca la contraseña
// Si se pegó el hash (empieza por $2y$…) en panel_password por error, se trata igualmente como hash
if ($hashClave === '' && password_get_info($clave)['algo'] !== null) { $hashClave = $clave; $clave = ''; }
$hashClave = trim($hashClave);
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
if (($hashClave === '' && strlen($clave) < 8) || $usuario === '') {
    $vista = 'sin_config';
}
// ---------- Login ----------
elseif (empty($_SESSION['ok'])) {
    $vista = 'login';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $dir = novis_dir();
        $f = $dir . '/intentos-' . nv_huella($_SERVER['REMOTE_ADDR'] ?? 'x') . '.json';
        $g = $dir . '/intentos-global.json';   // todos los fallos, vengan de donde vengan: frena los ataques repartidos entre muchas IP
        $leer = function (string $archivo, int $ventana): array {
            $t = is_readable($archivo) ? (json_decode((string)file_get_contents($archivo), true) ?: []) : [];
            return array_values(array_filter($t, fn($x) => $x > time() - $ventana));
        };
        $intentos = $leer($f, 900);
        $globales = $leer($g, 3600);
        if (count($intentos) >= 5 || count($globales) >= 40) {
            $error = 'Demasiados intentos. Espera un rato antes de volver a probar.';
        } elseif (
            hash_equals(mb_strtolower($usuario), mb_strtolower(trim((string)($_POST['usuario'] ?? '')))) &
            // Con hash configurado se usa siempre password_verify; la contraseña en texto plano solo sirve si no hay hash
            ($hashClave !== '' ? password_verify((string)($_POST['password'] ?? ''), $hashClave)
                               : hash_equals($clave, (string)($_POST['password'] ?? '')))
        ) {
            session_regenerate_id(true);
            $_SESSION['ok'] = true;
            $_SESSION['desde'] = $_SESSION['visto'] = time();
            @unlink($f);
            volver();
        } else {
            $intentos[] = time();
            $globales[] = time();
            @file_put_contents($f, json_encode($intentos), LOCK_EX);
            @file_put_contents($g, json_encode($globales), LOCK_EX);
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
        // Responder al cliente: queda en la conversación de su área de cliente y le llega por email
        if ($accion === 'responder') {
            $texto = trim((string)($_POST['texto'] ?? ''));
            $st = $db->prepare('SELECT * FROM solicitudes WHERE id = ?');
            $st->execute([$id]);
            $sol = $st->fetch();
            if ($sol && $texto !== '') {
                nv_db()->prepare("INSERT INTO respuestas (solicitud_id, autor, texto, fecha) VALUES (?, 'novis', ?, ?)")->execute([$id, nv_cifrar(mb_substr($texto, 0, 5000)), date('c')]);
                $db->prepare("UPDATE solicitudes SET estado = CASE WHEN estado = 'nueva' THEN 'en_curso' ELSE estado END, actualizado = ? WHERE id = ?")->execute([date('c'), $id]);
                $c = $db->prepare('SELECT 1 FROM usuarios WHERE email_idx = ?');
                $c->execute([nv_indice($sol['email'])]);
                $conCuenta = (bool)$c->fetchColumn();
                $url = $conCuenta ? nv_url('pagos/?a=mensaje&id=' . $id) : nv_url('');
                $parrafos = ['Hola' . ($sol['nombre'] ? ', ' . e($sol['nombre']) : '') . ':', nl2br(e($texto)),
                    $conCuenta ? 'Puedes contestar desde tu área de cliente.' : 'Puedes contestar respondiendo a este email.'];
                $html = novis_email_html('Respuesta de Novis', $parrafos, $conCuenta ? 'Ver la conversación' : 'Visitar thenovis.com', $url);
                novis_mail($sol['email'], 'Respuesta de Novis', "Hola,\n\n$texto\n\n" . ($conCuenta ? "Contesta desde tu área de cliente: $url" : 'Puedes contestar respondiendo a este email.') . "\n\nUn saludo,\nNovis", $html, 'hola@thenovis.com');
            }
            volver('id=' . $id . '&respondido=1');
        }
        if ($accion === 'notas') {
            $db->prepare('UPDATE solicitudes SET notas = ?, actualizado = ? WHERE id = ?')->execute([nv_cifrar(mb_substr((string)($_POST['notas'] ?? ''), 0, 5000)), date('c'), $id]);
            volver('id=' . $id . '&guardado=1');
        }
        $filtroVolver = preg_replace('/[^a-z_]/', '', $_POST['f'] ?? '');
        if ($accion === 'borrar') {
            $db->prepare('DELETE FROM solicitudes WHERE id = ?')->execute([$id]);
            novis_compactar();
            volver('borradas=1');
        }
        if ($accion === 'borrar_varias') {
            $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
            if ($ids) {
                $marcas = implode(',', array_fill(0, count($ids), '?'));
                $st = $db->prepare("DELETE FROM solicitudes WHERE id IN ($marcas)");
                $st->execute($ids);
                novis_compactar();
            }
            volver('f=' . $filtroVolver . '&borradas=' . count($ids));
        }
        if ($accion === 'vaciar' && in_array($_POST['estado'] ?? '', ['cerrada', 'descartada'], true)) {
            $st = $db->prepare('DELETE FROM solicitudes WHERE estado = ?');
            $st->execute([$_POST['estado']]);
            $n = $st->rowCount();
            novis_compactar();
            volver('f=' . $filtroVolver . '&borradas=' . $n);
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
        $hilo = nv_hilo($id);
        $c = $db->prepare('SELECT id FROM usuarios WHERE email_idx = ?');
        $c->execute([nv_indice($sol['email'])]);
        $cuentaId = (int)$c->fetchColumn();
    } else {
        $vista = 'lista';
        $filtro = is_string($_GET['f'] ?? 'activas') ? ($_GET['f'] ?? 'activas') : 'activas';
        $buscar = is_string($_GET['q'] ?? '') ? trim($_GET['q'] ?? '') : '';
        $donde = []; $params = [];
        if ($filtro === 'activas') { $donde[] = "estado IN ('nueva','en_curso')"; }
        elseif (isset(ESTADOS[$filtro])) { $donde[] = 'estado = ?'; $params[] = $filtro; }
        $sql = 'SELECT * FROM solicitudes' . ($donde ? ' WHERE ' . implode(' AND ', $donde) : '') . ' ORDER BY id DESC';
        $st = $db->prepare($sql); $st->execute($params);
        $lista = $st->fetchAll();
        // Los datos personales están cifrados en la base de datos, así que la búsqueda se hace aquí, con ellos ya descifrados
        if ($buscar !== '') {
            $lista = array_values(array_filter($lista, function ($x) use ($buscar) {
                foreach (['nombre', 'empresa', 'email', 'mensaje'] as $c) if (mb_stripos((string)$x[$c], $buscar) !== false) return true;
                return false;
            }));
        }
        $lista = array_slice($lista, 0, 300);
        $cuenta = ['todas' => 0, 'activas' => 0];
        foreach (array_keys(ESTADOS) as $k) $cuenta[$k] = 0;
        foreach ($db->query('SELECT estado, COUNT(*) n FROM solicitudes GROUP BY estado') as $r) {
            $cuenta[$r['estado']] = (int)$r['n'];
            $cuenta['todas'] += (int)$r['n'];
        }
        $cuenta['activas'] = $cuenta['nueva'] + $cuenta['en_curso'];
        $borradas = (int)($_GET['borradas'] ?? 0);
        $espacio = novis_tamano();
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
<link rel="icon" href="../assets/favicon.svg?v=10" type="image/svg+xml">
<link rel="apple-touch-icon" href="../assets/apple-touch-icon.png?v=10">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Novis">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="theme-color" content="#f5f5f7">
<link rel="stylesheet" href="panel.css?v=18">
</head>
<body class="v-<?= e($vista) ?>"<?= ($vista ?? '') === 'lista' ? ' data-autorefresh="1"' : '' ?>>

<?php if ($vista === 'sin_config'): ?>
  <main class="center">
    <div class="box">
      <span class="logo"></span>
      <h1>Panel sin configurar</h1>
      <p class="muted">Añade un usuario y una contraseña (mínimo 8 caracteres) en el archivo <code>gemini-config.php</code> que está fuera de <code>public_html</code>. Después, desde el panel, podrás sustituirla por su versión cifrada:</p>
      <pre tabindex="0">&lt;?php
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
      <div class="top__actions">
        <?php if ($lista): ?><button type="button" class="link" id="btnSeleccionar">Seleccionar</button><?php endif; ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><button class="link" name="accion" value="salir">Salir</button></form>
      </div>
    </div>
    <nav class="sw" aria-label="Secciones del panel"><a href="./" class="on" aria-current="page">Solicitudes</a><a href="pagos.php">Pagos</a><a href="cuentas.php">Cuentas</a></nav>
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
    <?php if ($borradas): ?><p class="toast" role="status"><?= $borradas === 1 ? 'Solicitud eliminada' : $borradas . ' solicitudes eliminadas' ?> · espacio liberado</p><?php endif; ?>
    <?php if (!$lista): ?>
      <div class="empty">
        <p><strong><?= $buscar ? 'Sin resultados' : 'Nada por aquí' ?></strong></p>
        <p class="muted"><?= $buscar ? 'Prueba con otra búsqueda.' : 'Las nuevas solicitudes del formulario aparecerán aquí.' ?></p>
      </div>
    <?php endif; ?>
    <form method="post" id="seleccion" data-confirm="¿Eliminar las solicitudes seleccionadas? No se puede deshacer.">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="accion" value="borrar_varias">
      <input type="hidden" name="f" value="<?= e($filtro) ?>">
      <?php foreach ($lista as $s): ?>
        <div class="row">
          <label class="pick"><input type="checkbox" name="ids[]" value="<?= (int)$s['id'] ?>" aria-label="Seleccionar a <?= e($s['nombre']) ?>"><span></span></label>
          <a class="item" href="?id=<?= (int)$s['id'] ?>">
            <span class="dot dot--<?= e($s['estado']) ?>"></span>
            <div class="item__main">
              <div class="item__row"><strong><?= e($s['nombre']) ?></strong><time><?= e(hace($s['creado'])) ?></time></div>
              <div class="item__sub"><?= e($s['empresa'] ?: $s['email']) ?><?php if ($s['plan'] && $s['plan'] !== 'Aún no lo sé'): ?> · <b><?= e($s['plan']) ?></b><?php endif; ?></div>
              <p><?= e(mb_strimwidth($s['mensaje'], 0, 110, '…')) ?></p>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
      <div class="selbar" id="selbar">
        <button type="button" class="btn btn--ghost" id="btnTodas">Todas</button>
        <button type="submit" class="btn btn--red" id="btnBorrarSel" disabled>Eliminar</button>
        <button type="button" class="link" id="btnCancelar">Cancelar</button>
      </div>
    </form>
    <?php if (in_array($filtro, ['cerrada', 'descartada'], true) && $cuenta[$filtro] > 0): ?>
      <form method="post" class="vaciar" data-confirm="¿Eliminar las <?= $cuenta[$filtro] ?> solicitudes <?= $filtro === 'cerrada' ? 'cerradas' : 'descartadas' ?>? No se puede deshacer.">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="accion" value="vaciar">
        <input type="hidden" name="estado" value="<?= e($filtro) ?>">
        <input type="hidden" name="f" value="<?= e($filtro) ?>">
        <button class="link link--red">Eliminar todas las <?= $filtro === 'cerrada' ? 'cerradas' : 'descartadas' ?> (<?= $cuenta[$filtro] ?>)</button>
      </form>
    <?php endif; ?>
    <p class="space">Espacio usado: <?= e($espacio) ?> · las solicitudes se borran solas a los 2 años · <a href="seguridad.php">Seguridad</a></p>
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
      <h2>Conversación</h2>
      <?php if (!empty($_GET['respondido'])): ?><p class="toast" role="status">Respuesta enviada<?= $cuentaId ? ': el cliente la verá en su área y por email' : ' por email' ?></p><?php endif; ?>
      <ol class="hilo">
        <li class="hilo__msg hilo__msg--cliente"><p class="hilo__meta"><?= e($sol['nombre']) ?> · <?= e(date('d/m/Y H:i', strtotime($sol['creado']))) ?></p><p><?= nl2br(e($sol['mensaje'])) ?></p></li>
        <?php foreach ($hilo as $r): ?>
          <li class="hilo__msg hilo__msg--<?= $r['autor'] === 'novis' ? 'novis' : 'cliente' ?>"><p class="hilo__meta"><?= $r['autor'] === 'novis' ? 'Tú (Novis)' : e($sol['nombre']) ?> · <?= e(date('d/m/Y H:i', strtotime($r['fecha']))) ?></p><p><?= nl2br(e($r['texto'])) ?></p></li>
        <?php endforeach; ?>
      </ol>
      <form method="post" class="responder">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="id" value="<?= (int)$sol['id'] ?>">
        <label for="respuesta">Responder<?= $cuentaId ? ' (lo verá en su área de cliente y le llegará por email)' : ' (le llegará por email)' ?></label>
        <textarea id="respuesta" name="texto" rows="4" required placeholder="Hola, ya está cambiado. ¡Echa un vistazo!"></textarea>
        <button class="btn" name="accion" value="responder">Enviar respuesta</button>
      </form>
      <?php if ($cuentaId): ?><p class="muted small">Tiene cuenta de cliente: <a href="cuentas.php?id=<?= $cuentaId ?>">ver cuenta y servicios</a></p><?php endif; ?>
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

<script src="panel.js?v=3" defer></script>
</body>
</html>
