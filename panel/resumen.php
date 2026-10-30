<?php
// The Novis — panel: resumen del negocio (lo primero que se ve al entrar).
//  · Qué hay que hacer hoy: solicitudes sin abrir, conversaciones paradas, cobros fallidos y códigos sin pagar.
//  · Cifras del periodo elegido comparadas con el anterior, y lo que entra cada mes por suscripciones.
//  · Ingresos y solicitudes de los últimos 12 meses, planes más pedidos, mejores clientes y lo último que ha pasado.
require_once __DIR__ . '/../lib/cuentas.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

nv_panel_sesion();
if (empty($_SESSION['ok'])) { header('Location: ./'); exit; }
if (!novis_db_config()) { header('Location: instalar.php'); exit; }
$db = nv_db();

const PERIODOS = ['7' => 'Últimos 7 días', '30' => 'Últimos 30 días', '90' => 'Últimos 3 meses', '365' => 'Último año'];
$p = is_string($_GET['p'] ?? '') && isset(PERIODOS[$_GET['p'] ?? '']) ? $_GET['p'] : '30';
$dias = (int)$p;
$ahora = date('c');
$desde = date('c', strtotime("-$dias days"));
$antes = date('c', strtotime('-' . (2 * $dias) . ' days'));
$hace12 = date('Y-m-01', strtotime('first day of -11 months'));

// ---------- Datos (fechas e importes están en claro; los nombres se descifran al leerlos) ----------
$cobros = [];   // [fecha, importe] de cada cobro: pagos únicos, primera cuota y renovaciones
foreach ($db->query("SELECT pagado_en f, importe FROM pagos WHERE estado = 'pagado' AND pagado_en IS NOT NULL") as $r) $cobros[] = [$r['f'], (int)$r['importe']];
foreach ($db->query('SELECT fecha f, importe FROM renovaciones') as $r) $cobros[] = [$r['f'], (int)$r['importe']];
$sols = $db->query('SELECT creado, estado, plan, actualizado FROM solicitudes')->fetchAll();
$altas = $db->query('SELECT creado FROM usuarios')->fetchAll(PDO::FETCH_COLUMN);

$entre = fn(?string $f, string $a, string $b) => $f !== null && $f >= $a && $f < $b;
$suma = fn(string $a, string $b) => array_sum(array_map(fn($c) => $entre($c[0], $a, $b) ? $c[1] : 0, $cobros));
$cuenta = fn(array $fechas, string $a, string $b) => count(array_filter($fechas, fn($f) => $entre($f, $a, $b)));
$fechasSol = array_column($sols, 'creado');

// Tasa de cierre: de las solicitudes del periodo que ya están decididas, cuántas acabaron en proyecto
$cierre = function (string $a, string $b) use ($sols, $entre): ?int {
    $g = $d = 0;
    foreach ($sols as $s) if ($entre($s['creado'], $a, $b)) { if ($s['estado'] === 'cerrada') $g++; elseif ($s['estado'] === 'descartada') $d++; }
    return $g + $d ? (int)round(100 * $g / ($g + $d)) : null;
};

$kpi = [
    'ingresos' => [$suma($desde, $ahora), $suma($antes, $desde)],
    'solicitudes' => [$cuenta($fechasSol, $desde, $ahora), $cuenta($fechasSol, $antes, $desde)],
    'clientes' => [$cuenta($altas, $desde, $ahora), $cuenta($altas, $antes, $desde)],
    'cierre' => [$cierre($desde, $ahora), $cierre($antes, $desde)],
];

// Suscripciones activas y lo que suman al mes (igual que en Pagos: las anuales cuentan 1/12 y las de 3 años 1/36)
$vivas = "estado = 'pagado' AND stripe_sub IS NOT NULL AND COALESCE(sub_estado, '') NOT IN ('canceled', 'incomplete_expired')";
$subs = $db->query("SELECT periodo, importe FROM pagos WHERE $vivas AND sub_cancela = 0")->fetchAll();
$alMes = array_sum(array_map(fn($x) => intdiv((int)$x['importe'], NV_PERIODOS_MESES[$x['periodo']] ?? 1), $subs));
$pendiente = $db->query("SELECT COUNT(*) n, COALESCE(SUM(importe),0) t FROM pagos WHERE estado = 'pendiente'")->fetch();
$totalClientes = count($altas);

// ---------- Para hoy ----------
$porEstado = ['nueva' => 0, 'en_curso' => 0, 'cerrada' => 0, 'descartada' => 0];
$paradas = 0;
$semana = date('c', strtotime('-7 days'));
foreach ($sols as $s) {
    if (isset($porEstado[$s['estado']])) $porEstado[$s['estado']]++;
    if ($s['estado'] === 'en_curso' && ($s['actualizado'] ?: $s['creado']) < $semana) $paradas++;
}
$fallidas = (int)$db->query("SELECT COUNT(*) FROM pagos WHERE $vivas AND sub_estado IN ('past_due', 'unpaid', 'incomplete')")->fetchColumn();
$tareas = array_filter([
    $porEstado['nueva'] ? ['./?f=nueva', $porEstado['nueva'] === 1 ? '1 solicitud nueva por contestar' : $porEstado['nueva'] . ' solicitudes nuevas por contestar', 'nueva'] : null,
    $paradas ? ['./?f=en_curso', $paradas === 1 ? '1 conversación lleva más de una semana sin moverse' : "$paradas conversaciones llevan más de una semana sin moverse", 'en_curso'] : null,
    $fallidas ? ['pagos.php?f=subs', $fallidas === 1 ? '1 suscripción con el cobro fallido' : "$fallidas suscripciones con el cobro fallido", 'error'] : null,
    (int)$pendiente['n'] ? ['pagos.php?f=pendiente', ((int)$pendiente['n'] === 1 ? '1 código de pago' : $pendiente['n'] . ' códigos de pago') . ' sin pagar (' . nv_euros((int)$pendiente['t']) . ')', 'descartada'] : null,
]);

// ---------- Últimos 12 meses ----------
$meses = [];
for ($i = 11; $i >= 0; $i--) {
    $k = date('Y-m', strtotime("first day of -$i months"));
    $meses[$k] = ['ingresos' => 0, 'solicitudes' => 0];
}
foreach ($cobros as [$f, $imp]) { $k = substr((string)$f, 0, 7); if (isset($meses[$k])) $meses[$k]['ingresos'] += $imp; }
foreach ($fechasSol as $f) { $k = substr((string)$f, 0, 7); if (isset($meses[$k])) $meses[$k]['solicitudes']++; }
const MES_CORTO = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const MES_LARGO = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$nombreMes = fn(string $k, bool $largo = false) => ($largo ? MES_LARGO : MES_CORTO)[(int)substr($k, 5, 2) - 1] . ($largo ? ' ' . substr($k, 0, 4) : '');

// ---------- Planes más pedidos (en el periodo) ----------
$planes = [];
foreach ($sols as $s) {
    if (!$entre($s['creado'], $desde, $ahora)) continue;
    $pl = trim(preg_replace('/^Cliente · /', '', (string)$s['plan'])) ?: 'Sin indicar';
    $planes[$pl] = ($planes[$pl] ?? 0) + 1;
}
arsort($planes);
$planes = array_slice($planes, 0, 6, true);

// ---------- Mejores clientes (todo lo cobrado, renovaciones incluidas) ----------
$porCliente = [];
foreach ($db->query("SELECT usuario_id, SUM(importe) t FROM pagos WHERE estado = 'pagado' AND usuario_id IS NOT NULL GROUP BY usuario_id") as $r) $porCliente[(int)$r['usuario_id']] = (int)$r['t'];
foreach ($db->query('SELECT p.usuario_id, SUM(r.importe) t FROM renovaciones r JOIN pagos p ON p.id = r.pago_id WHERE p.usuario_id IS NOT NULL GROUP BY p.usuario_id') as $r) $porCliente[(int)$r['usuario_id']] = ($porCliente[(int)$r['usuario_id']] ?? 0) + (int)$r['t'];
arsort($porCliente);
$porCliente = array_slice($porCliente, 0, 5, true);
$top = [];
if ($porCliente) {
    $st = $db->prepare('SELECT id, usuario, email FROM usuarios WHERE id = ?');
    foreach ($porCliente as $uid => $t) { $st->execute([$uid]); if ($u = $st->fetch()) $top[] = $u + ['total' => $t]; $st->closeCursor(); }
}

// ---------- Lo último que ha pasado ----------
$actividad = [];
foreach ($db->query('SELECT id, nombre, plan, creado FROM solicitudes ORDER BY id DESC LIMIT 6') as $r)
    $actividad[] = [$r['creado'], './?id=' . $r['id'], $r['nombre'] . ' te ha escrito', $r['plan'] ? preg_replace('/^Cliente · /', '', $r['plan']) : 'Formulario', 'nueva'];
foreach ($db->query("SELECT id, concepto, importe, periodo, pagado_en FROM pagos WHERE estado = 'pagado' AND pagado_en IS NOT NULL ORDER BY pagado_en DESC LIMIT 6") as $r)
    $actividad[] = [$r['pagado_en'], 'pagos.php?id=' . $r['id'], 'Pago recibido: ' . nv_importe_txt($r), $r['concepto'], 'cerrada'];
foreach ($db->query('SELECT id, usuario, email, creado FROM usuarios ORDER BY id DESC LIMIT 6') as $r)
    $actividad[] = [$r['creado'], 'cuentas.php?id=' . $r['id'], 'Cliente nuevo: ' . ($r['usuario'] ?: $r['email']), 'Se ha creado una cuenta', 'en_curso'];
usort($actividad, fn($a, $b) => strcmp((string)$b[0], (string)$a[0]));
$actividad = array_slice($actividad, 0, 8);

function hace(string $fecha): string {
    $s = time() - strtotime($fecha);
    if ($s < 60) return 'ahora';
    if ($s < 3600) return 'hace ' . floor($s / 60) . ' min';
    if ($s < 86400) return 'hace ' . floor($s / 3600) . ' h';
    if ($s < 172800) return 'ayer';
    if ($s < 604800) return 'hace ' . floor($s / 86400) . ' días';
    return date('d/m/Y', strtotime($fecha));
}
/** «↑ 20 %» frente al periodo anterior (o nada si no hay con qué comparar) */
function cambio(?int $ya, ?int $antes, bool $puntos = false): string {
    if ($ya === null || $antes === null) return '<span class="kpi__vs">sin datos para comparar</span>';
    if ($puntos) { $d = $ya - $antes; $txt = abs($d) . ' puntos'; }
    elseif ($antes === 0) return $ya ? '<span class="kpi__vs">antes: 0</span>' : '<span class="kpi__vs">igual que antes</span>';
    else { $d = (int)round(100 * ($ya - $antes) / $antes); $txt = $d >= 200 ? '×' . str_replace('.', ',', (string)round($ya / $antes, 1)) : abs($d) . ' %'; }
    if ($d === 0) return '<span class="kpi__vs">igual que antes</span>';
    return '<span class="kpi__vs kpi__vs--' . ($d > 0 ? 'sube' : 'baja') . '">' . ($d > 0 ? '↑ ' : '↓ ') . $txt . '</span>';
}
$saludo = (int)date('G') < 14 ? 'Buenos días' : ((int)date('G') < 21 ? 'Buenas tardes' : 'Buenas noches');
$maxIng = max(1, ...array_column($meses, 'ingresos'));
$maxSol = max(1, ...array_column($meses, 'solicitudes'));
$maxPlan = $planes ? max($planes) : 1;
$vsTxt = ['7' => 'frente a los 7 días anteriores', '30' => 'frente a los 30 días anteriores', '90' => 'frente a los 3 meses anteriores', '365' => 'frente al año anterior'][$p];
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Resumen · The Novis</title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="../assets/favicon.svg?v=10" type="image/svg+xml">
<link rel="apple-touch-icon" href="../assets/apple-touch-icon.png?v=10">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="The Novis">
<meta name="theme-color" content="#f5f6f8">
<link rel="stylesheet" href="panel.css?v=22">
</head>
<body class="v-resumen" data-autorefresh="1">
  <header class="top">
    <div class="top__row">
      <div><h1>Resumen</h1><p class="top__sub"><?= $saludo ?>. <?= $tareas ? 'Esto es lo que tienes pendiente.' : 'Lo tienes todo al día.' ?></p></div>
    </div>
    <?php $seccionActual = 'resumen'; include __DIR__ . '/nav.php'; ?>
  </header>

  <main class="dash">
    <section class="card hoy" aria-labelledby="t-hoy">
      <h2 id="t-hoy">Para hoy</h2>
      <?php if (!$tareas): ?>
        <p class="hoy__ok"><span class="ic" aria-hidden="true">✓</span> No hay nada pendiente. Las solicitudes nuevas y los cobros fallidos aparecerán aquí.</p>
      <?php else: ?>
        <ul class="hoy__lista">
          <?php foreach ($tareas as [$url, $txt, $cl]): ?>
            <li><a href="<?= e($url) ?>"><span class="dot dot--<?= $cl ?>"></span><span><?= e($txt) ?></span><span class="flecha" aria-hidden="true">›</span></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <div class="dash__cab">
      <h2>Cómo va el negocio</h2>
      <nav class="tabs tabs--periodo" aria-label="Periodo">
        <?php foreach (PERIODOS as $k => $t): ?><a href="?p=<?= $k ?>"<?= $p === (string)$k ? ' class="on" aria-current="true"' : '' ?>><?= e($t) ?></a><?php endforeach; ?>
      </nav>
    </div>

    <section class="kpis" aria-label="Cifras del periodo">
      <a class="kpi" href="pagos.php?f=pagado">
        <span class="kpi__t">Ingresos</span>
        <strong><?= e(nv_euros($kpi['ingresos'][0])) ?></strong>
        <?= cambio($kpi['ingresos'][0], $kpi['ingresos'][1]) ?>
      </a>
      <a class="kpi" href="./?f=todas">
        <span class="kpi__t">Solicitudes recibidas</span>
        <strong><?= $kpi['solicitudes'][0] ?></strong>
        <?= cambio($kpi['solicitudes'][0], $kpi['solicitudes'][1]) ?>
      </a>
      <a class="kpi" href="cuentas.php">
        <span class="kpi__t">Clientes nuevos</span>
        <strong><?= $kpi['clientes'][0] ?></strong>
        <?= cambio($kpi['clientes'][0], $kpi['clientes'][1]) ?>
      </a>
      <a class="kpi" href="./?f=cerrada">
        <span class="kpi__t">Solicitudes ganadas</span>
        <strong><?= $kpi['cierre'][0] === null ? '—' : $kpi['cierre'][0] . ' %' ?></strong>
        <?= $kpi['cierre'][0] === null ? '<span class="kpi__vs">ninguna cerrada o descartada aún</span>' : cambio($kpi['cierre'][0], $kpi['cierre'][1], true) ?>
      </a>
    </section>
    <p class="dash__nota">Cifras de <?= e(mb_strtolower(PERIODOS[$p])) ?>, <?= $vsTxt ?>. Los importes incluyen IVA.</p>

    <section class="kpis kpis--fijos" aria-label="Situación actual">
      <a class="kpi kpi--suave" href="pagos.php?f=subs">
        <span class="kpi__t">Entra cada mes por suscripciones</span>
        <strong><?= e(nv_euros($alMes)) ?></strong>
        <span class="kpi__vs"><?= count($subs) === 1 ? '1 suscripción activa' : count($subs) . ' suscripciones activas' ?></span>
      </a>
      <a class="kpi kpi--suave" href="pagos.php?f=pendiente">
        <span class="kpi__t">Pendiente de cobro</span>
        <strong><?= e(nv_euros((int)$pendiente['t'])) ?></strong>
        <span class="kpi__vs"><?= (int)$pendiente['n'] === 1 ? '1 código sin pagar' : (int)$pendiente['n'] . ' códigos sin pagar' ?></span>
      </a>
      <a class="kpi kpi--suave" href="cuentas.php">
        <span class="kpi__t">Clientes en total</span>
        <strong><?= $totalClientes ?></strong>
        <span class="kpi__vs">con cuenta en el área de cliente</span>
      </a>
    </section>

    <div class="dash__dos">
      <?php foreach (['ingresos' => ['Ingresos por mes', $maxIng, fn($v) => nv_euros($v)], 'solicitudes' => ['Solicitudes por mes', $maxSol, fn($v) => $v === 1 ? '1 solicitud' : "$v solicitudes"]] as $serie => [$titulo, $max, $fmt]): ?>
        <section class="card grafica" aria-labelledby="g-<?= $serie ?>">
          <h2 id="g-<?= $serie ?>"><?= $titulo ?></h2>
          <?php $total12 = array_sum(array_column($meses, $serie)); ?>
          <p class="grafica__total"><?= e($fmt($total12)) ?> <span>en los últimos 12 meses</span></p>
          <div class="barras" aria-hidden="true">
            <?php foreach ($meses as $k => $m): $v = $m[$serie]; ?>
              <div class="barra<?= $k === date('Y-m') ? ' barra--ya' : '' ?>" tabindex="-1" data-tip="<?= e(ucfirst($nombreMes($k, true)) . ': ' . $fmt($v)) ?>">
                <span class="barra__col"><span style="height: <?= $v ? max(3, round(100 * $v / $max)) : 0 ?>%"></span></span>
                <span class="barra__mes"><?= $nombreMes($k) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
          <table class="vh"><caption><?= $titulo ?></caption><tbody>
            <?php foreach ($meses as $k => $m): ?><tr><th scope="row"><?= e($nombreMes($k, true)) ?></th><td><?= e($fmt($m[$serie])) ?></td></tr><?php endforeach; ?>
          </tbody></table>
        </section>
      <?php endforeach; ?>
    </div>

    <div class="dash__dos">
      <section class="card" aria-labelledby="t-estado">
        <h2 id="t-estado">Solicitudes por estado</h2>
        <ul class="estados">
          <?php foreach (['nueva' => 'Nuevas', 'en_curso' => 'En curso', 'cerrada' => 'Ganadas (cerradas)', 'descartada' => 'Descartadas'] as $k => $t): ?>
            <li><a href="./?f=<?= $k ?>"><span class="dot dot--<?= $k ?>"></span><span><?= $t ?></span><b><?= $porEstado[$k] ?></b></a></li>
          <?php endforeach; ?>
        </ul>
      </section>

      <section class="card" aria-labelledby="t-planes">
        <h2 id="t-planes">Lo que más te piden</h2>
        <?php if (!$planes): ?>
          <p class="muted">Sin solicitudes en este periodo.</p>
        <?php else: ?>
          <ul class="hbarras">
            <?php foreach ($planes as $pl => $n): ?>
              <li><span class="hbarras__txt"><span><?= e($pl) ?></span><b><?= $n ?></b></span><span class="hbarras__bar"><span style="width: <?= round(100 * $n / $maxPlan) ?>%"></span></span></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    </div>

    <div class="dash__dos">
      <section class="card" aria-labelledby="t-top">
        <h2 id="t-top">Mejores clientes</h2>
        <?php if (!$top): ?>
          <p class="muted">Aparecerán aquí cuando tus clientes paguen desde su área de cliente.</p>
        <?php else: ?>
          <ol class="mini mini--top">
            <?php foreach ($top as $i => $u): ?>
              <li><a href="cuentas.php?id=<?= (int)$u['id'] ?>">
                <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($u['usuario'] ?: $u['email'], 0, 1))) ?></span>
                <span><?= e($u['usuario'] ?: 'Sin usuario') ?><small><?= e($u['email']) ?></small></span>
                <b class="amount"><?= e(nv_euros($u['total'])) ?></b></a></li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
      </section>

      <section class="card" aria-labelledby="t-act">
        <h2 id="t-act">Lo último</h2>
        <?php if (!$actividad): ?>
          <p class="muted">Todavía no ha pasado nada. Aquí verás las solicitudes, pagos y clientes nuevos.</p>
        <?php else: ?>
          <ul class="mini">
            <?php foreach ($actividad as [$f, $url, $txt, $sub, $cl]): ?>
              <li><a href="<?= e($url) ?>"><span class="dot dot--<?= $cl ?>"></span><span><?= e($txt) ?><small><?= e(mb_strimwidth((string)$sub, 0, 60, '…')) ?></small></span><time><?= e(hace((string)$f)) ?></time></a></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    </div>
  </main>

<script src="panel.js?v=5" defer></script>
</body>
</html>
