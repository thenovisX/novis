<?php
// The Novis — panel: estadísticas a fondo.
//  · Embudo de solicitudes (recibidas → contestadas → ganadas) y tiempo hasta la primera respuesta.
//  · Ventas: ticket medio, pagos únicos frente a suscripciones y servicios que más facturan.
//  · Mes a mes: este año frente al anterior. Qué planes se piden y cuáles se ganan. Qué días escriben más.
//  · Clientes: con pagos, que repiten y lo que deja de media cada uno.
require_once __DIR__ . '/../lib/cuentas.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

nv_panel_sesion();
if (empty($_SESSION['ok'])) { header('Location: ./'); exit; }
if (!novis_db_config()) { header('Location: instalar.php'); exit; }
$db = nv_db();

const PERIODOS = ['30' => '30 días', '90' => '3 meses', '365' => '12 meses', 'todo' => 'Todo'];
$p = is_string($_GET['p'] ?? '') && isset(PERIODOS[$_GET['p'] ?? '']) ? $_GET['p'] : '365';
$desde = $p === 'todo' ? '0000' : date('c', strtotime('-' . (int)$p . ' days'));
$en = fn(?string $f) => $f !== null && $f >= $desde;

// ---------- Solicitudes y respuestas ----------
$sols = array_values(array_filter($db->query('SELECT id, creado, estado, plan FROM solicitudes')->fetchAll(), fn($s) => $en($s['creado'])));
$primera = [];   // primera respuesta tuya a cada solicitud
foreach ($db->query("SELECT solicitud_id, MIN(fecha) f FROM respuestas WHERE autor = 'novis' GROUP BY solicitud_id") as $r) $primera[(int)$r['solicitud_id']] = $r['f'];
$recibidas = count($sols);
$contestadas = $ganadas = $descartadas = 0;
$esperas = [];
$porPlan = [];
$porDia = array_fill(1, 7, 0);
foreach ($sols as $s) {
    $resp = $primera[(int)$s['id']] ?? null;
    if ($resp || $s['estado'] !== 'nueva') $contestadas++;
    if ($s['estado'] === 'cerrada') $ganadas++;
    if ($s['estado'] === 'descartada') $descartadas++;
    if ($resp) $esperas[] = max(0, strtotime($resp) - strtotime($s['creado']));
    $pl = trim(preg_replace('/^Cliente · /', '', (string)$s['plan'])) ?: 'Sin indicar';
    $porPlan[$pl] ??= ['n' => 0, 'g' => 0, 'd' => 0];
    $porPlan[$pl]['n']++;
    if ($s['estado'] === 'cerrada') $porPlan[$pl]['g']++;
    if ($s['estado'] === 'descartada') $porPlan[$pl]['d']++;
    $porDia[(int)date('N', strtotime($s['creado']))]++;
}
uasort($porPlan, fn($a, $b) => $b['n'] <=> $a['n']);
sort($esperas);
$mediana = $esperas ? $esperas[intdiv(count($esperas), 2)] : null;
$en24 = $esperas ? (int)round(100 * count(array_filter($esperas, fn($x) => $x <= 86400)) / count($esperas)) : null;
$pct = fn(int $a, int $b) => $b ? (int)round(100 * $a / $b) : 0;

// ---------- Ventas ----------
$pagos = $db->query("SELECT id, concepto, importe, periodo, pagado_en, usuario_id FROM pagos WHERE estado = 'pagado' AND pagado_en IS NOT NULL")->fetchAll();
$renovs = $db->query('SELECT r.importe, r.fecha, p.concepto, p.usuario_id FROM renovaciones r JOIN pagos p ON p.id = r.pago_id')->fetchAll();
$cobros = [];   // [fecha, importe, concepto, usuario, es suscripción]
foreach ($pagos as $x) $cobros[] = [$x['pagado_en'], (int)$x['importe'], (string)$x['concepto'], $x['usuario_id'], nv_es_sub($x)];
foreach ($renovs as $x) $cobros[] = [$x['fecha'], (int)$x['importe'], (string)$x['concepto'], $x['usuario_id'], true];
$periodo = array_values(array_filter($cobros, fn($c) => $en($c[0])));
$ingresos = array_sum(array_column($periodo, 1));
$nCobros = count($periodo);
$ticket = $nCobros ? intdiv($ingresos, $nCobros) : 0;
$deSubs = array_sum(array_map(fn($c) => $c[4] ? $c[1] : 0, $periodo));
$servicios = [];
foreach ($periodo as $c) { $k = trim($c[2]) ?: 'Sin concepto'; $servicios[$k] ??= ['t' => 0, 'n' => 0]; $servicios[$k]['t'] += $c[1]; $servicios[$k]['n']++; }
uasort($servicios, fn($a, $b) => $b['t'] <=> $a['t']);
$servicios = array_slice($servicios, 0, 6, true);
$maxServ = $servicios ? max(array_column($servicios, 't')) : 1;

// Este año frente al anterior, mes a mes (no depende del periodo elegido)
$ano = (int)date('Y');
$anual = [];
for ($m = 1; $m <= 12; $m++) $anual[$m] = [0, 0];
foreach ($cobros as $c) {
    $y = (int)substr((string)$c[0], 0, 4); $m = (int)substr((string)$c[0], 5, 2);
    if ($m < 1 || $m > 12) continue;
    if ($y === $ano) $anual[$m][0] += $c[1]; elseif ($y === $ano - 1) $anual[$m][1] += $c[1];
}
$mesActual = (int)date('n');
$hastaHoy = [array_sum(array_map(fn($m) => $anual[$m][0], range(1, $mesActual))), array_sum(array_map(fn($m) => $anual[$m][1], range(1, $mesActual)))];

// ---------- Clientes ----------
$totalClientes = (int)$db->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
$porUsuario = [];
foreach ($cobros as $c) if ($c[3]) { $porUsuario[(int)$c[3]] = ($porUsuario[(int)$c[3]] ?? 0) + 1; }
$conPagos = count($porUsuario);
$repiten = count(array_filter($porUsuario, fn($n) => $n > 1));
$totalCobrado = array_sum(array_map(fn($c) => $c[3] ? $c[1] : 0, $cobros));
$valorCliente = $conPagos ? intdiv($totalCobrado, $conPagos) : 0;
$publicidad = (int)$db->query('SELECT (SELECT COUNT(*) FROM perfiles WHERE marketing IS NOT NULL) + (SELECT COUNT(*) FROM contactos WHERE marketing IS NOT NULL)')->fetchColumn();

function duracion(?int $s): string {
    if ($s === null) return '—';
    if ($s < 3600) return max(1, (int)round($s / 60)) . ' min';
    if ($s < 172800) return round($s / 3600, $s < 36000 ? 1 : 0) . ' h';
    return round($s / 86400) . ' días';
}
function cambioTxt(int $ya, int $antes): string {
    $d = (int)round(100 * ($ya - $antes) / $antes);
    return $d === 0 ? 'Igual' : ($d > 0 ? '↑ ' : '↓ ') . abs($d) . ' % ' . ($d > 0 ? 'más' : 'menos');
}
const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const DIAS = [1 => 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
$maxDia = max(1, ...$porDia);
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Estadísticas · The Novis</title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="../assets/favicon.svg?v=10" type="image/svg+xml">
<link rel="apple-touch-icon" href="../assets/apple-touch-icon.png?v=10">
<meta name="theme-color" content="#f5f6f8">
<link rel="stylesheet" href="panel.css?v=23">
</head>
<body class="v-resumen v-estadisticas">
  <header class="top">
    <div class="top__row">
      <div><h1>Estadísticas</h1><p class="top__sub">Cómo llegan, cuánto tardas en contestar y qué se vende.</p></div>
    </div>
    <?php $seccionActual = 'estadisticas'; include __DIR__ . '/nav.php'; ?>
  </header>

  <main class="dash">
    <div class="dash__cab">
      <h2>Periodo</h2>
      <nav class="tabs tabs--periodo" aria-label="Periodo">
        <?php foreach (PERIODOS as $k => $t): ?><a href="?p=<?= $k ?>"<?= $p === (string)$k ? ' class="on" aria-current="true"' : '' ?>><?= e($t) ?></a><?php endforeach; ?>
      </nav>
    </div>

    <section aria-labelledby="t-captacion">
      <h2 class="dash__sec" id="t-captacion">Captación</h2>
      <div class="kpis">
        <div class="kpi"><span class="kpi__t">Solicitudes recibidas</span><strong><?= $recibidas ?></strong><span class="kpi__vs">en <?= $p === 'todo' ? 'total' : 'los últimos ' . e(PERIODOS[$p]) ?></span></div>
        <div class="kpi"><span class="kpi__t">Contestadas</span><strong><?= $pct($contestadas, $recibidas) ?> %</strong><span class="kpi__vs"><?= $contestadas ?> de <?= $recibidas ?></span></div>
        <div class="kpi"><span class="kpi__t">Tiempo hasta tu primera respuesta</span><strong><?= duracion($mediana) ?></strong><span class="kpi__vs"><?= $en24 === null ? 'aún no has respondido desde el panel' : "$en24 % contestadas en menos de 24 h" ?></span></div>
        <div class="kpi"><span class="kpi__t">Ganadas</span><strong><?= $ganadas + $descartadas ? $pct($ganadas, $ganadas + $descartadas) . ' %' : '—' ?></strong><span class="kpi__vs"><?= $ganadas ?> ganadas · <?= $descartadas ?> descartadas</span></div>
      </div>
    </section>

    <div class="dash__dos">
      <section class="card" aria-labelledby="t-embudo">
        <h2 id="t-embudo">Embudo</h2>
        <ol class="embudo">
          <?php foreach ([['Recibidas', $recibidas], ['Contestadas', $contestadas], ['Ganadas', $ganadas]] as [$t, $n]): ?>
            <li><span class="embudo__txt"><span><?= $t ?></span><b><?= $n ?></b><small><?= $pct($n, $recibidas) ?> %</small></span><span class="hbarras__bar"><span style="width: <?= $pct($n, max(1, $recibidas)) ?>%"></span></span></li>
          <?php endforeach; ?>
        </ol>
        <p class="muted small">Contestadas: les has respondido desde el panel o ya no están como «Nueva». Ganadas: marcadas como «Cerrada».</p>
      </section>

      <section class="card" aria-labelledby="t-dias">
        <h2 id="t-dias">Qué días te escriben más</h2>
        <ul class="hbarras">
          <?php foreach (DIAS as $d => $t): ?>
            <li><span class="hbarras__txt"><span><?= $t ?></span><b><?= $porDia[$d] ?></b></span><span class="hbarras__bar"><span style="width: <?= round(100 * $porDia[$d] / $maxDia) ?>%"></span></span></li>
          <?php endforeach; ?>
        </ul>
      </section>
    </div>

    <section class="card" aria-labelledby="t-planes">
      <h2 id="t-planes">Planes: cuáles se piden y cuáles se ganan</h2>
      <?php if (!$porPlan): ?><p class="muted">Sin solicitudes en este periodo.</p><?php else: ?>
        <div class="tabla"><table>
          <thead><tr><th scope="col">Plan</th><th scope="col" class="num">Solicitudes</th><th scope="col" class="num">Ganadas</th><th scope="col" class="num">Descartadas</th><th scope="col" class="num">% ganadas</th></tr></thead>
          <tbody>
            <?php foreach ($porPlan as $pl => $x): ?>
              <tr><th scope="row"><?= e($pl) ?></th><td class="num"><?= $x['n'] ?></td><td class="num"><?= $x['g'] ?></td><td class="num"><?= $x['d'] ?></td><td class="num"><?= $x['g'] + $x['d'] ? $pct($x['g'], $x['g'] + $x['d']) . ' %' : '—' ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </section>

    <section aria-labelledby="t-ventas">
      <h2 class="dash__sec" id="t-ventas">Ventas</h2>
      <div class="kpis">
        <div class="kpi"><span class="kpi__t">Ingresos</span><strong><?= e(nv_euros($ingresos)) ?></strong><span class="kpi__vs"><?= $nCobros === 1 ? '1 cobro' : "$nCobros cobros" ?></span></div>
        <div class="kpi"><span class="kpi__t">Ticket medio</span><strong><?= e(nv_euros($ticket)) ?></strong><span class="kpi__vs">por cobro</span></div>
        <div class="kpi"><span class="kpi__t">Por suscripciones</span><strong><?= $ingresos ? $pct($deSubs, $ingresos) : 0 ?> %</strong><span class="kpi__vs"><?= e(nv_euros($deSubs)) ?> de cuotas</span></div>
        <div class="kpi"><span class="kpi__t">Pagos únicos</span><strong><?= $ingresos ? 100 - $pct($deSubs, $ingresos) : 0 ?> %</strong><span class="kpi__vs"><?= e(nv_euros($ingresos - $deSubs)) ?></span></div>
      </div>
    </section>

    <div class="dash__dos">
      <section class="card" aria-labelledby="t-serv">
        <h2 id="t-serv">Lo que más factura</h2>
        <?php if (!$servicios): ?><p class="muted">Sin cobros en este periodo.</p><?php else: ?>
          <ul class="hbarras">
            <?php foreach ($servicios as $k => $x): ?>
              <li><span class="hbarras__txt"><span><?= e($k) ?> <small class="muted">· <?= $x['n'] ?></small></span><b><?= e(nv_euros($x['t'])) ?></b></span><span class="hbarras__bar"><span style="width: <?= round(100 * $x['t'] / $maxServ) ?>%"></span></span></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

      <section class="card" aria-labelledby="t-anual">
        <h2 id="t-anual"><?= $ano ?> frente a <?= $ano - 1 ?></h2>
        <p class="grafica__total"><?= e(nv_euros($hastaHoy[0])) ?> <span>hasta <?= MESES[$mesActual - 1] ?> · <?= $hastaHoy[1] ? cambioTxt($hastaHoy[0], $hastaHoy[1]) . ' que el año pasado (' . e(nv_euros($hastaHoy[1])) . ')' : 'sin datos del año pasado' ?></span></p>
        <div class="tabla tabla--compacta"><table>
          <thead><tr><th scope="col">Mes</th><th scope="col" class="num"><?= $ano ?></th><th scope="col" class="num"><?= $ano - 1 ?></th></tr></thead>
          <tbody>
            <?php foreach ($anual as $m => [$a, $b]): if ($m > $mesActual && !$b) continue; ?>
              <tr<?= $m === $mesActual ? ' class="ya"' : '' ?>><th scope="row"><?= MESES[$m - 1] ?></th><td class="num"><?= $m > $mesActual ? '—' : e(nv_euros($a)) ?></td><td class="num"><?= e(nv_euros($b)) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      </section>
    </div>

    <section aria-labelledby="t-clientes">
      <h2 class="dash__sec" id="t-clientes">Clientes</h2>
      <div class="kpis">
        <a class="kpi" href="cuentas.php"><span class="kpi__t">Clientes con cuenta</span><strong><?= $totalClientes ?></strong><span class="kpi__vs"><?= $conPagos ?> han pagado algo</span></a>
        <a class="kpi" href="cuentas.php?f=con_pagos"><span class="kpi__t">Repiten</span><strong><?= $pct($repiten, $conPagos) ?> %</strong><span class="kpi__vs"><?= $repiten ?> con más de un cobro</span></a>
        <div class="kpi"><span class="kpi__t">Lo que deja cada cliente</span><strong><?= e(nv_euros($valorCliente)) ?></strong><span class="kpi__vs">de media, desde siempre</span></div>
        <a class="kpi" href="cuentas.php?f=publicidad"><span class="kpi__t">Aceptan publicidad</span><strong><?= $publicidad ?></strong><span class="kpi__vs">clientes y contactos</span></a>
      </div>
    </section>
    <p class="dash__nota">Los importes incluyen IVA. Las cifras salen de tu base de datos al abrir la página.</p>
  </main>

<script src="panel.js?v=6" defer></script>
</body>
</html>
