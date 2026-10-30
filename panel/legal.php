<?php
// The Novis — panel privado: datos legales que salen en el aviso legal, la privacidad, las cookies y las condiciones.
// Titular, NIF, domicilio y contacto se editan en Ajustes; aquí, el resto (registro, delegado de protección de datos,
// juzgados, Google Analytics y la lista de servicios externos que tratan datos).
require_once __DIR__ . '/../lib/cuentas.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

nv_panel_sesion();
if (empty($_SESSION['ok'])) { header('Location: ./'); exit; }

function volver(string $q = ''): void { header('Location: legal.php' . ($q ? '?' . $q : '')); exit; }

$errores = [];
$viejo = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!nv_csrf_ok()) volver();
    $accion = (string)($_POST['accion'] ?? '');
    $viejo = $_POST;

    if ($accion === 'datos') {
        $registro = trim(preg_replace('/\s+/', ' ', (string)($_POST['registro'] ?? '')));
        $dpd = trim((string)($_POST['dpd'] ?? ''));
        $fuero = trim((string)($_POST['fuero'] ?? ''));
        $ga = strtoupper(trim((string)($_POST['ga_id'] ?? '')));
        if (mb_strlen($registro) > 250) $errores['registro'] = 'Máximo 250 caracteres.';
        if (mb_strlen($dpd) > 120) $errores['dpd'] = 'Máximo 120 caracteres.';
        if (mb_strlen($fuero) > 60) $errores['fuero'] = 'Máximo 60 caracteres.';
        if ($ga !== '' && !preg_match('/^G-[A-Z0-9]{4,20}$/', $ga)) $errores['ga_id'] = 'El ID de medición empieza por G- (por ejemplo, G-ABC123XYZ9).';
        if (!$errores) {
            nv_ajustes_guardar(['registro' => $registro ?: null, 'dpd' => $dpd ?: null, 'fuero' => $fuero ?: null,
                'ga_id' => !empty($_POST['ga_no']) ? 'no' : ($ga ?: null)]);
            volver('ok=datos');
        }
    }

    if ($accion === 'proveedores') {
        $lista = [];
        foreach ((array)($_POST['p'] ?? []) as $i => $f) {
            if (!is_array($f) || !empty($f['quitar'])) continue;
            $f = array_map(fn($v) => trim(preg_replace('/\s+/', ' ', (string)$v)), array_intersect_key($f, ['nombre' => 1, 'uso' => 1, 'lugar' => 1, 'url' => 1]) + ['nombre' => '', 'uso' => '', 'lugar' => '', 'url' => '']);
            if ($f['nombre'] === '' && $f['uso'] === '' && $f['lugar'] === '' && $f['url'] === '') continue;
            if ($f['nombre'] === '' || $f['uso'] === '') { $errores['p' . $i] = 'Escribe al menos el nombre y para qué se usa.'; continue; }
            if ($f['url'] !== '' && !preg_match('#^https://[^\s<>"]{4,300}$#', $f['url'])) { $errores['p' . $i] = 'El enlace debe empezar por https://'; continue; }
            foreach (['nombre' => 120, 'uso' => 300, 'lugar' => 300] as $k => $max) $f[$k] = mb_substr($f[$k], 0, $max);
            $lista[] = $f;
        }
        if (!$errores) {
            nv_ajustes_guardar(['proveedores' => json_encode($lista, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            volver('ok=proveedores#proveedores');
        }
    }

    if ($accion === 'proveedores_defecto') {
        nv_ajustes_guardar(['proveedores' => null]);
        volver('ok=proveedores#proveedores');
    }
}

$a = nv_ajustes();
$val = fn($k) => e(array_key_exists($k, $viejo) ? $viejo[$k] : ($a[$k] ?? ''));
$err = fn($k) => isset($errores[$k]) ? '<p class="f-err">' . e($errores[$k]) . '</p>' : '';
$ga = nv_ga_ajuste();
$proveedores = isset($viejo['p']) && is_array($viejo['p']) ? array_values(array_filter($viejo['p'], 'is_array')) : nv_proveedores();
$proveedores[] = ['nombre' => '', 'uso' => '', 'lugar' => '', 'url' => ''];
$avisos = ['datos' => 'Guardado. Ya se ve en el aviso legal, la política de privacidad y las condiciones.',
    'proveedores' => 'Lista de servicios guardada. Ya sale en la política de privacidad.'];
$ok = $avisos[$_GET['ok'] ?? ''] ?? '';
$faltan = array_filter(['titular' => 'titular', 'nif' => 'NIF', 'domicilio' => 'domicilio'], fn($k) => ($a[$k] ?? '') === '', ARRAY_FILTER_USE_KEY);
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Legal · The Novis</title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="../assets/favicon.svg?v=10" type="image/svg+xml">
<link rel="apple-touch-icon" href="../assets/apple-touch-icon.png?v=10">
<meta name="theme-color" content="#f5f6f8">
<link rel="stylesheet" href="panel.css?v=22">
</head>
<body class="v-pagos">
  <header class="top">
    <div class="top__row">
      <h1>Legal</h1>
    </div>
    <?php $seccionActual = 'legal'; include __DIR__ . '/nav.php'; ?>
    <div class="pad"></div>
  </header>
  <main class="detail">
    <?php if ($ok): ?><p class="toast" role="status"><?= e($ok) ?></p><?php endif; ?>

    <section class="card">
      <h2>Tus páginas legales</h2>
      <p class="muted">Se rellenan solas con los datos de esta página y de <a href="ajustes.php#info">Ajustes → Tu negocio</a> (titular, NIF, domicilio, correo y teléfono).</p>
      <?php if ($faltan): ?><p class="warn">Falta<?= count($faltan) > 1 ? 'n' : '' ?> <?= e(implode(', ', $faltan)) ?>: la ley (LSSI-CE) obliga a mostrarlos. <a href="ajustes.php#info">Rellenar ahora</a></p><?php endif; ?>
      <ul class="mini">
        <li><a href="../aviso-legal.html" target="_blank" rel="noopener"><span>Aviso legal<small>Quién eres y condiciones de uso de la web</small></span></a></li>
        <li><a href="../privacidad.html" target="_blank" rel="noopener"><span>Política de privacidad<small>Qué datos guardas, para qué y con quién</small></span></a></li>
        <li><a href="../cookies.html" target="_blank" rel="noopener"><span>Política de cookies<small>Cookies necesarias y de analítica</small></span></a></li>
        <li><a href="../condiciones.html" target="_blank" rel="noopener"><span>Condiciones de contratación<small>Precios, pagos, baja y desistimiento</small></span></a></li>
      </ul>
      <p class="muted small">Estos textos son una base adaptada al RGPD, la LOPDGDD y la LSSI-CE. Pide a un abogado o a tu gestoría que los revise antes de darlos por definitivos.</p>
    </section>

    <form method="post" class="card form-pago" id="datos" novalidate>
      <h2>Datos legales</h2>
      <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
      <label>Inscripción en el Registro Mercantil <span class="muted">(solo si eres una sociedad; déjalo vacío si eres autónomo)</span>
        <input name="registro" maxlength="250" placeholder="Registro Mercantil de Pontevedra, tomo 1234, folio 56, hoja PO-78901" value="<?= $val('registro') ?>"></label><?= $err('registro') ?>
      <label>Delegado de protección de datos <span class="muted">(opcional; casi ningún negocio pequeño está obligado a tenerlo)</span>
        <input name="dpd" maxlength="120" placeholder="Nombre y email del delegado" value="<?= $val('dpd') ?>"></label><?= $err('dpd') ?>
      <label>Juzgados competentes <span class="muted">(ciudad para los conflictos con empresas; los consumidores siempre pueden reclamar en la suya)</span>
        <input name="fuero" maxlength="60" placeholder="Vigo" value="<?= $val('fuero') ?>"></label><?= $err('fuero') ?>
      <label>ID de Google Analytics <span class="muted">(déjalo vacío para usar el de siempre)</span>
        <input name="ga_id" maxlength="24" autocapitalize="characters" spellcheck="false" placeholder="G-15CTJ93EKN" value="<?= e(array_key_exists('ga_id', $viejo) ? $viejo['ga_id'] : ($ga !== 'no' ? $ga : '')) ?>"></label><?= $err('ga_id') ?>
      <label class="check"><input type="checkbox" name="ga_no" value="1"<?= (array_key_exists('ga_id', $viejo) ? !empty($viejo['ga_no']) : $ga === 'no') ? ' checked' : '' ?>> No usar Google Analytics (entonces no hace falta pedir permiso para las cookies)</label>
      <p class="muted small">Google Analytics solo se carga si el visitante acepta las cookies de analítica en el aviso que sale al entrar.</p>
      <button class="btn" name="accion" value="datos">Guardar</button>
    </form>

    <form method="post" class="card form-pago" id="proveedores" novalidate>
      <h2>Servicios externos</h2>
      <p class="muted">Empresas que tratan datos de tus visitantes o clientes por ti (alojamiento, pagos, estadísticas, chat…). Salen en la política de privacidad. Si empiezas a usar uno nuevo, añádelo aquí. Si además pone cookies que no son necesarias, hay que añadirlo también al aviso de cookies (ver <code>legal/LEEME.md</code>).</p>
      <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
      <?php foreach ($proveedores as $i => $p): $nuevo = ($p['nombre'] ?? '') === '' && $i === count($proveedores) - 1; ?>
        <fieldset class="prov">
          <legend><?= $nuevo ? 'Añadir un servicio' : e($p['nombre'] ?? '') ?></legend>
          <div class="dos">
            <label>Nombre <input name="p[<?= $i ?>][nombre]" maxlength="120" value="<?= e($p['nombre'] ?? '') ?>" placeholder="Mailchimp (Intuit Inc.)"></label>
            <label>Enlace a su privacidad <input name="p[<?= $i ?>][url]" type="url" maxlength="300" value="<?= e($p['url'] ?? '') ?>" placeholder="https://"></label>
          </div>
          <label>Para qué lo usas <input name="p[<?= $i ?>][uso]" maxlength="300" value="<?= e($p['uso'] ?? '') ?>" placeholder="Enviar la publicidad por email a quien la ha aceptado."></label>
          <label>Dónde trata los datos <input name="p[<?= $i ?>][lugar]" maxlength="300" value="<?= e($p['lugar'] ?? '') ?>" placeholder="EE. UU., con el Marco de Privacidad de Datos UE-EE. UU."></label>
          <?= $err('p' . $i) ?>
          <?php if (!$nuevo): ?><label class="check"><input type="checkbox" name="p[<?= $i ?>][quitar]" value="1"> Quitar este servicio</label><?php endif; ?>
        </fieldset>
      <?php endforeach; ?>
      <button class="btn" name="accion" value="proveedores">Guardar servicios</button>
    </form>
    <form method="post" class="card" data-confirm="¿Volver a la lista original (Hostinger, Stripe, Google Analytics, Gemini y Google)?">
      <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
      <p class="muted small">¿Te has liado con la lista? Puedes volver a la original.</p>
      <button class="btn btn--ghost" name="accion" value="proveedores_defecto">Restaurar la lista original</button>
    </form>
  </main>
  <script src="panel.js?v=5" defer></script>
</body>
</html>
