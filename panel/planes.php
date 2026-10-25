<?php
// The Novis — panel privado: planes y precios.
// Lo que se guarda aquí sale en la página principal («Precios claros» y «Después de publicar»), en el formulario
// de contacto, en el chat y, para los servicios que se pueden contratar, en el área de cliente.
require_once __DIR__ . '/../lib/cuentas.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

nv_panel_sesion();
if (empty($_SESSION['ok'])) { header('Location: ./'); exit; }
if (!novis_db_config()) { header('Location: instalar.php'); exit; }

function volver(string $q = ''): void { header('Location: planes.php' . ($q ? '?' . $q : '')); exit; }

/** «1.234,50» o «249» → céntimos. null si está vacío; false si no es un número. */
function leer_euros(string $t): int|null|false {
    $t = str_replace([' ', '€'], '', trim($t));
    if ($t === '') return null;
    $t = str_contains($t, ',') ? str_replace(['.', ','], ['', '.'], $t) : $t;
    if (!is_numeric($t) || (float)$t < 0 || (float)$t > 999999) return false;
    return (int)round((float)$t * 100);
}
function euros_campo(?int $c): string {
    if ($c === null) return '';
    return $c % 100 === 0 ? (string)intdiv($c, 100) : number_format($c / 100, 2, ',', '');
}

$db = nv_productos_db();
$errores = [];
$viejo = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!nv_csrf_ok()) volver();
    $accion = (string)($_POST['accion'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($accion === 'guardar') {
        $viejo = $_POST;
        $tipo = isset(NV_TIPOS_PRODUCTO[$_POST['tipo'] ?? '']) ? $_POST['tipo'] : 'plan';
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $desc = trim((string)($_POST['descripcion'] ?? ''));
        $car = trim(preg_replace("/\R{2,}/", "\n", str_replace("\r", '', (string)($_POST['caracteristicas'] ?? ''))));
        $precio = leer_euros((string)($_POST['precio'] ?? ''));
        $periodo = isset(NV_PERIODOS_PRODUCTO[$_POST['periodo'] ?? '']) ? (string)$_POST['periodo'] : '';
        $nota = trim((string)($_POST['nota'] ?? ''));
        $etiqueta = trim((string)($_POST['etiqueta'] ?? ''));
        $contratable = $tipo === 'servicio' && !empty($_POST['contratable']) ? 1 : 0;
        if ($nombre === '' || mb_strlen($nombre) > 60) $errores['nombre'] = 'Escribe un nombre (máximo 60 caracteres).';
        if (mb_strlen($desc) > 400) $errores['descripcion'] = 'Máximo 400 caracteres.';
        if (mb_strlen($car) > 1500) $errores['caracteristicas'] = 'Máximo 1500 caracteres.';
        if ($precio === false) $errores['precio'] = 'Escribe el precio en euros, p. ej. 249 o 29,90 (o déjalo vacío para «Consultar precio»).';
        if (mb_strlen($nota) > 250) $errores['nota'] = 'Máximo 250 caracteres.';
        if (mb_strlen($etiqueta) > 40) $errores['etiqueta'] = 'Máximo 40 caracteres.';
        if ($contratable && ($precio === null || $precio === false || $precio < 50 || $periodo === '')) $errores['contratable'] = 'Para que tus clientes lo contraten solos necesita un precio (mínimo 0,50 €) y un cobro periódico (mes, año o 3 años).';
        if (!$errores) {
            $vals = [$tipo, $nombre, $desc, $car, $precio, !empty($_POST['desde']) ? 1 : 0, $periodo, $nota ?: null, $etiqueta ?: null,
                !empty($_POST['destacado']) ? 1 : 0, $contratable, !empty($_POST['visible']) ? 1 : 0, date('c')];
            if ($id && nv_producto($id)) {
                $db->prepare('UPDATE productos SET tipo = ?, nombre = ?, descripcion = ?, caracteristicas = ?, precio = ?, desde = ?, periodo = ?, nota = ?, etiqueta = ?,
                    destacado = ?, contratable = ?, visible = ?, actualizado = ? WHERE id = ?')->execute([...$vals, $id]);
            } else {
                $orden = (int)$db->query('SELECT COALESCE(MAX(orden), 0) FROM productos')->fetchColumn() + 10;
                $db->prepare('INSERT INTO productos (tipo, nombre, descripcion, caracteristicas, precio, desde, periodo, nota, etiqueta, destacado, contratable, visible, creado, clave, orden)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$vals, nv_clave_producto($nombre, $db), $orden]);
                $id = (int)$db->lastInsertId();
            }
            // Solo un plan destacado a la vez (la tarjeta oscura «El más pedido»)
            if (!empty($_POST['destacado'])) $db->prepare('UPDATE productos SET destacado = 0 WHERE tipo = ? AND id != ?')->execute([$tipo, $id]);
            volver('ok=guardado');
        }
    }
    if ($accion === 'visible') {
        $db->prepare('UPDATE productos SET visible = 1 - visible, actualizado = ? WHERE id = ?')->execute([date('c'), $id]);
        volver();
    }
    if ($accion === 'subir' || $accion === 'bajar') {
        $p = nv_producto($id);
        if ($p) {
            $lista = nv_productos($p['tipo']);
            $pos = array_search($id, array_map('intval', array_column($lista, 'id')), true);
            $j = $pos + ($accion === 'subir' ? -1 : 1);
            if ($pos !== false && isset($lista[$j])) {
                // Se intercambian los dos y se renumera todo de 10 en 10
                [$lista[$pos], $lista[$j]] = [$lista[$j], $lista[$pos]];
                $st = $db->prepare('UPDATE productos SET orden = ? WHERE id = ?');
                foreach ($lista as $i => $x) $st->execute([($i + 1) * 10, $x['id']]);
            }
        }
        volver();
    }
    if ($accion === 'borrar') {
        $db->prepare('DELETE FROM productos WHERE id = ?')->execute([$id]);
        volver('ok=borrado');
    }
    if ($accion === 'restaurar') {
        // Vuelve a crear los planes de siempre que falten (no toca los que ya existen)
        $st = $db->prepare(novis_sql_ignorar($db) . ' INTO productos (clave, tipo, nombre, descripcion, caracteristicas, precio, desde, periodo, nota, etiqueta, destacado, contratable, visible, orden, creado)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)');
        $n = 0;
        foreach (NV_PRODUCTOS_INICIALES as $i => $p) {
            $st->execute([$p['clave'], $p['tipo'], $p['nombre'], $p['descripcion'], $p['caracteristicas'] ?? '', $p['precio'], $p['desde'] ?? 0,
                $p['periodo'] ?? '', $p['nota'] ?? null, $p['etiqueta'] ?? null, 0, $p['contratable'] ?? 0, ($i + 1) * 10, date('c')]);
            $n += $st->rowCount();
        }
        volver('ok=restaurado&n=' . $n);
    }
    if (!$errores) volver();
}

$editar = null;
$vista = 'lista';
if (!empty($_GET['id']) || !empty($_GET['nuevo']) || $errores) {
    $vista = 'editar';
    $editar = !empty($_GET['id']) ? nv_producto((int)$_GET['id']) : (!empty($_POST['id']) ? nv_producto((int)$_POST['id']) : null);
    if (!empty($_GET['id']) && !$editar) volver();
}
$f = $viejo ?: ($editar ?? ['tipo' => ($_GET['tipo'] ?? '') === 'servicio' ? 'servicio' : 'plan', 'visible' => 1, 'periodo' => '', 'desde' => 0]);
if (!$viejo && $editar) $f['precio'] = euros_campo($editar['precio'] === null ? null : (int)$editar['precio']);
$v = fn($k) => e($f[$k] ?? '');
$marcado = fn($k) => !empty($f[$k]) ? ' checked' : '';
$err = fn($k) => isset($errores[$k]) ? '<p class="f-err">' . e($errores[$k]) . '</p>' : '';

$avisos = ['guardado' => 'Guardado. Ya se ve en la web.', 'borrado' => 'Eliminado de la web.',
    'restaurado' => ((int)($_GET['n'] ?? 0)) ? 'Se han vuelto a crear ' . (int)$_GET['n'] . ' planes de siempre.' : 'Los planes de siempre ya estaban todos.'];
$ok = $avisos[$_GET['ok'] ?? ''] ?? '';
$grupos = $vista === 'lista' ? ['plan' => nv_productos('plan'), 'servicio' => nv_productos('servicio')] : [];
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Planes · The Novis</title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="../assets/favicon.svg?v=10" type="image/svg+xml">
<link rel="apple-touch-icon" href="../assets/apple-touch-icon.png?v=10">
<meta name="theme-color" content="#f5f5f7">
<link rel="stylesheet" href="panel.css?v=21">
</head>
<body class="v-pagos v-planes">

<?php if ($vista === 'lista'): ?>
  <header class="top">
    <div class="top__row">
      <h1>Planes</h1>
      <div class="top__actions"><a class="btn btn--small" href="?nuevo=1">Nuevo</a></div>
    </div>
    <?php $seccionActual = 'planes'; include __DIR__ . '/nav.php'; ?>
    <div class="pad"></div>
  </header>
  <main class="list">
    <?php if ($ok): ?><p class="toast" role="status"><?= e($ok) ?></p><?php endif; ?>
    <?php foreach ($grupos as $tipo => $lista): ?>
      <h2 class="grupo"><?= $tipo === 'plan' ? 'Planes web · «Precios claros»' : 'Servicios · «Después de publicar»' ?></h2>
      <p class="muted small grupo-sub"><?= $tipo === 'plan' ? 'Las tarjetas de precios de la página principal. Precios sin IVA.' : 'Alojamiento, mantenimiento y otros servicios. Los marcados como «se contrata online» los puede contratar el cliente desde su área. Precios sin IVA.' ?></p>
      <?php if (!$lista): ?><div class="empty"><p class="muted">Todavía no hay ninguno.</p><p><a class="btn btn--small btn--ghost" href="?nuevo=1&amp;tipo=<?= $tipo ?>">Crear <?= $tipo === 'plan' ? 'un plan' : 'un servicio' ?></a></p></div><?php endif; ?>
      <?php foreach ($lista as $i => $p): ?>
        <div class="prod<?= $p['visible'] ? '' : ' is-oculto' ?>">
          <a class="item" href="?id=<?= (int)$p['id'] ?>">
            <span class="dot dot--<?= $p['visible'] ? 'cerrada' : 'descartada' ?>"></span>
            <div class="item__main">
              <div class="item__row"><strong><?= e($p['nombre']) ?></strong>
                <b class="amount"><?= $p['precio'] === null ? 'Consultar' : e(($p['desde'] ? 'desde ' : '') . nv_precio_corto((int)$p['precio']) . (isset(NV_PERIODOS_CORTO[$p['periodo']]) ? ' ' . NV_PERIODOS_CORTO[$p['periodo']] : '')) ?></b></div>
              <div class="item__sub"><?= e($p['descripcion'] ?: '—') ?></div>
              <div class="badges">
                <?php if (!$p['visible']): ?><span class="pill">Oculto</span><?php endif; ?>
                <?php if ($p['destacado']): ?><span class="pill pill--nueva">Destacado</span><?php endif; ?>
                <?php if ($p['contratable']): ?><span class="pill pill--cerrada">Se contrata online</span><?php endif; ?>
                <?php if ($p['precio'] !== null): ?><span class="pill"><?= e(nv_euros(nv_con_iva((int)$p['precio']))) ?> con IVA</span><?php endif; ?>
              </div>
            </div>
          </a>
          <form method="post" class="prod__acc">
            <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="ico" name="accion" value="subir" aria-label="Subir <?= e($p['nombre']) ?>"<?= $i === 0 ? ' disabled' : '' ?>>↑</button>
            <button class="ico" name="accion" value="bajar" aria-label="Bajar <?= e($p['nombre']) ?>"<?= $i === count($lista) - 1 ? ' disabled' : '' ?>>↓</button>
            <button class="ico ico--txt" name="accion" value="visible"><?= $p['visible'] ? 'Ocultar' : 'Mostrar' ?></button>
          </form>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <div class="space">Los cambios se ven al momento en <a href="../#planes" target="_blank" rel="noopener">la página principal</a>.
      <form method="post" class="vaciar" data-confirm="¿Volver a crear los planes que traía la web (Esencial, Profesional, Restaurante, alojamiento y mantenimiento) que hayas borrado?"><input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>"><button class="link" name="accion" value="restaurar">Recuperar los planes de siempre</button></form></div>
  </main>

<?php else: ?>
  <header class="top top--detail"><a href="planes.php" class="back">‹ Planes</a></header>
  <main class="detail">
    <form method="post" class="card form-pago" novalidate>
      <h1><?= $editar ? 'Editar «' . e($editar['nombre']) . '»' : 'Nuevo plan o servicio' ?></h1>
      <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
      <?php if ($editar): ?><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>"><?php endif; ?>
      <fieldset class="tipo">
        <legend>¿Dónde sale?</legend>
        <?php foreach (NV_TIPOS_PRODUCTO as $k => $t): ?>
          <label><input type="radio" name="tipo" value="<?= $k ?>"<?= ($f['tipo'] ?? 'plan') === $k ? ' checked' : '' ?>> <?= e($k === 'plan' ? 'Plan web («Precios claros»)' : 'Servicio («Después de publicar» y área de cliente)') ?></label>
        <?php endforeach; ?>
      </fieldset>
      <label>Nombre
        <input name="nombre" maxlength="60" required placeholder="Tienda online" value="<?= $v('nombre') ?>"></label><?= $err('nombre') ?>
      <label>Descripción corta <span class="muted">(debajo del nombre)</span>
        <textarea name="descripcion" rows="2" maxlength="400" placeholder="Para vender tus productos por internet."><?= $v('descripcion') ?></textarea></label><?= $err('descripcion') ?>
      <label>Qué incluye <span class="muted">(una cosa por línea)</span>
        <textarea name="caracteristicas" rows="5" placeholder="Hasta 50 productos&#10;Pago con tarjeta&#10;Adaptada a móvil"><?= $v('caracteristicas') ?></textarea></label><?= $err('caracteristicas') ?>
      <label>Precio en euros <span class="muted">(sin IVA; vacío = «Consultar precio»)</span>
        <input name="precio" inputmode="decimal" placeholder="249" value="<?= $v('precio') ?>" id="precio"></label><?= $err('precio') ?>
      <p class="muted small" id="conIva" aria-live="polite"></p>
      <label class="check"><input type="checkbox" name="desde" value="1"<?= $marcado('desde') ?>> Mostrar «desde» delante del precio</label>
      <fieldset class="tipo">
        <legend>¿Cada cuánto se paga?</legend>
        <?php foreach (NV_PERIODOS_PRODUCTO as $k => $t): ?>
          <label><input type="radio" name="periodo" value="<?= $k ?>"<?= (string)($f['periodo'] ?? '') === $k ? ' checked' : '' ?>> <?= e($t) ?></label>
        <?php endforeach; ?>
      </fieldset>
      <label>Nota <span class="muted">(opcional; texto pequeño, p. ej. «Alquiler o compra» o «Sin permanencia»)</span>
        <input name="nota" maxlength="250" value="<?= $v('nota') ?>"></label><?= $err('nota') ?>
      <label>Etiqueta <span class="muted">(opcional, encima del nombre, p. ej. «El más pedido»)</span>
        <input name="etiqueta" maxlength="40" value="<?= $v('etiqueta') ?>"></label><?= $err('etiqueta') ?>
      <label class="check"><input type="checkbox" name="destacado" value="1"<?= $marcado('destacado') ?>> Destacado (tarjeta oscura; solo puede haber uno)</label>
      <label class="check"><input type="checkbox" name="contratable" value="1"<?= $marcado('contratable') ?>> Solo servicios: el cliente lo puede contratar online desde su área (suscripción con Stripe)</label><?= $err('contratable') ?>
      <label class="check"><input type="checkbox" name="visible" value="1"<?= $marcado('visible') ?>> Visible en la web</label>
      <button class="btn" name="accion" value="guardar"><?= $editar ? 'Guardar cambios' : 'Crear' ?></button>
    </form>
    <?php if ($editar): ?>
      <form method="post" class="danger" data-confirm="¿Eliminar «<?= e($editar['nombre']) ?>»? Deja de salir en la web. Los clientes que ya lo pagaron no se ven afectados.">
        <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>">
        <button name="accion" value="borrar" class="link link--red">Eliminar</button>
      </form>
    <?php endif; ?>
  </main>
<?php endif; ?>

<script src="panel.js?v=4" defer></script>
</body>
</html>
