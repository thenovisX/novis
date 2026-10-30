<?php
// The Novis — menú del panel (se incluye desde cada página; solo no muestra nada).
// En el ordenador es una barra lateral; en el móvil, una barra abajo con lo del día a día y «Más» para el resto.
if (!isset($seccionActual)) { http_response_code(404); exit; }
$principales = ['resumen' => ['resumen.php', 'Resumen', 'M3 13h7V3H3zm11 8h7V11h-7zM3 21h7v-6H3zm11-12h7V3h-7z'],
    'solicitudes' => ['./', 'Solicitudes', 'M4 4h16v12H7l-3 3zM8 9h8M8 12h5'],
    'cuentas' => ['cuentas.php', 'Clientes', 'M16 19v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1M9.5 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM21 19v-1a4 4 0 0 0-3-3.9M15.5 4.1a3 3 0 0 1 0 5.8'],
    'pagos' => ['pagos.php', 'Pagos', 'M3 6h18v12H3zM3 10h18M7 15h3']];
$otras = ['planes' => ['planes.php', 'Planes y precios', 'M4 7h16M4 12h16M4 17h10'],
    'legal' => ['legal.php', 'Legal', 'M12 3l8 4v5c0 4.5-3.4 8.2-8 9-4.6-.8-8-4.5-8-9V7z'],
    'seguridad' => ['seguridad.php', 'Seguridad', 'M6 10V8a6 6 0 1 1 12 0v2M5 10h14v11H5zM12 14v3'],
    'ajustes' => ['ajustes.php', 'Ajustes', 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 13a7.6 7.6 0 0 0 0-2l2-1.6-2-3.4-2.4 1a7.4 7.4 0 0 0-1.7-1L15 3.5h-4l-.4 2.5a7.4 7.4 0 0 0-1.7 1l-2.4-1-2 3.4 2 1.6a7.6 7.6 0 0 0 0 2l-2 1.6 2 3.4 2.4-1a7.4 7.4 0 0 0 1.7 1l.4 2.5h4l.4-2.5a7.4 7.4 0 0 0 1.7-1l2.4 1 2-3.4z']];
// Aviso en «Solicitudes» con las que aún no has abierto
try { $navNuevas = (int)novis_db()->query("SELECT COUNT(*) FROM solicitudes WHERE estado = 'nueva'")->fetchColumn(); } catch (Throwable $e) { $navNuevas = 0; }
$navIcono = fn(string $d) => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="' . $d . '"/></svg>';
$navEnlace = function (string $k, array $s) use ($seccionActual, $navNuevas, $navIcono): string {
    [$url, $txt, $ico] = $s;
    $n = $k === 'solicitudes' && $navNuevas ? '<span class="nb" aria-label="' . $navNuevas . ' nuevas">' . $navNuevas . '</span>' : '';
    return '<a href="' . $url . '"' . ($k === $seccionActual ? ' class="on" aria-current="page"' : '') . '>' . $navIcono($ico) . '<span>' . $txt . '</span>' . $n . '</a>';
};
$navSalir = '<form method="post" action="./"><input type="hidden" name="csrf" value="' . e(nv_csrf()) . '"><button name="accion" value="salir">'
    . $navIcono('M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10') . '<span>Salir</span></button></form>';
?>
    <nav class="side" aria-label="Secciones del panel">
      <a class="side__logo" href="resumen.php"><span class="logo" aria-hidden="true"></span>The Novis</a>
      <?php foreach ($principales as $k => $s) echo $navEnlace($k, $s); ?>
      <p class="side__grupo">Configuración</p>
      <?php foreach ($otras as $k => $s) echo $navEnlace($k, $s); ?>
      <div class="side__fin"><?= $navSalir ?></div>
    </nav>
    <nav class="tabbar" aria-label="Secciones del panel">
      <?php foreach ($principales as $k => $s) echo $navEnlace($k, $s); ?>
      <details class="mas<?= isset($otras[$seccionActual]) ? ' on' : '' ?>">
        <summary><?= $navIcono('M5 12h.01M12 12h.01M19 12h.01') ?><span>Más</span></summary>
        <div class="mas__menu">
          <?php foreach ($otras as $k => $s) echo $navEnlace($k, $s); ?>
          <?= $navSalir ?>
        </div>
      </details>
    </nav>
