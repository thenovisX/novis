<?php
// The Novis — panel: buscador de todo el panel (solicitudes, clientes, contactos y pagos).
// Los datos personales están cifrados en la base de datos, así que se buscan aquí, ya descifrados (como en cada lista).
require_once __DIR__ . '/../lib/cuentas.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

nv_panel_sesion();
if (empty($_SESSION['ok'])) { header('Location: ./'); exit; }
if (!novis_db_config()) { header('Location: instalar.php'); exit; }
$db = nv_db();

$q = is_string($_GET['q'] ?? '') ? trim(mb_substr($_GET['q'] ?? '', 0, 100)) : '';
$hay = fn(array $fila, array $cols) => (bool)array_filter($cols, fn($c) => mb_stripos((string)($fila[$c] ?? ''), $q) !== false);
$grupos = [];
if ($q !== '') {
    $f = array_filter($db->query('SELECT id, nombre, empresa, email, plan, mensaje, estado, creado FROM solicitudes ORDER BY id DESC')->fetchAll(), fn($x) => $hay($x, ['nombre', 'empresa', 'email', 'mensaje']));
    $grupos['Solicitudes'] = array_map(fn($x) => ['./?id=' . $x['id'], $x['nombre'], ($x['empresa'] ?: $x['email']) . ' · ' . date('d/m/Y', strtotime($x['creado'])), 'dot dot--' . $x['estado']], array_slice($f, 0, 20));
    $f = array_filter($db->query('SELECT id, usuario, email, telefono FROM usuarios ORDER BY id DESC')->fetchAll(), fn($x) => $hay($x, ['usuario', 'email', 'telefono']));
    $grupos['Clientes'] = array_map(fn($x) => ['cuentas.php?id=' . $x['id'], $x['usuario'] ?: 'Sin usuario', $x['email'], 'avatar'], array_slice($f, 0, 20));
    $f = array_filter($db->query('SELECT id, nombre, empresa, email FROM contactos ORDER BY id DESC')->fetchAll(), fn($x) => $hay($x, ['nombre', 'empresa', 'email']));
    $grupos['Contactos'] = array_map(fn($x) => ['cuentas.php?contacto=' . $x['id'], $x['nombre'] ?: 'Sin nombre', $x['email'], 'avatar'], array_slice($f, 0, 20));
    $f = array_filter($db->query('SELECT id, codigo, concepto, importe, periodo, estado, email_cliente FROM pagos ORDER BY id DESC')->fetchAll(), fn($x) => $hay($x, ['codigo', 'concepto', 'email_cliente']));
    $grupos['Pagos'] = array_map(fn($x) => ['pagos.php?id=' . $x['id'], $x['concepto'], $x['codigo'] . ' · ' . nv_importe_txt($x), 'dot dot--' . (['pagado' => 'cerrada', 'pendiente' => 'en_curso'][$x['estado']] ?? 'descartada')], array_slice($f, 0, 20));
    $grupos = array_filter($grupos);
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Buscar · The Novis</title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="../assets/favicon.svg?v=10" type="image/svg+xml">
<link rel="apple-touch-icon" href="../assets/apple-touch-icon.png?v=10">
<meta name="theme-color" content="#f5f6f8">
<link rel="stylesheet" href="panel.css?v=23">
</head>
<body class="v-buscar">
  <header class="top">
    <div class="top__row"><h1>Buscar</h1></div>
    <?php $seccionActual = 'buscar'; include __DIR__ . '/nav.php'; ?>
    <form class="search" method="get" role="search">
      <label for="q" class="vh">Buscar en el panel</label>
      <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Nombre, empresa, email, teléfono o código de pago" autofocus>
    </form>
  </header>
  <main class="list">
    <?php if ($q === ''): ?>
      <div class="empty"><p><strong>Busca en todo el panel</strong></p><p class="muted">Solicitudes, clientes, contactos y pagos a la vez.</p></div>
    <?php elseif (!$grupos): ?>
      <div class="empty"><p><strong>Sin resultados para «<?= e($q) ?>»</strong></p><p class="muted">Prueba con otra palabra o con parte del email.</p></div>
    <?php endif; ?>
    <?php foreach ($grupos as $titulo => $filas): ?>
      <h2 class="grupo"><?= $titulo ?> <small class="muted"><?= count($filas) ?></small></h2>
      <?php foreach ($filas as [$url, $txt, $sub, $ic]): ?>
        <a class="item" href="<?= e($url) ?>">
          <?php if ($ic === 'avatar'): ?><span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string)$txt, 0, 1))) ?></span><?php else: ?><span class="<?= e($ic) ?>"></span><?php endif; ?>
          <div class="item__main"><div class="item__row"><strong><?= e($txt) ?></strong></div><div class="item__sub"><?= e($sub) ?></div></div>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </main>
<script src="panel.js?v=6" defer></script>
</body>
</html>
