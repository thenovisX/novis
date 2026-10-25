<?php
// The Novis — pestañas del panel (se incluye desde cada página; sola no muestra nada)
if (!isset($seccionActual)) { http_response_code(404); exit; }
$secciones = ['solicitudes' => ['./', 'Solicitudes'], 'pagos' => ['pagos.php', 'Pagos'], 'cuentas' => ['cuentas.php', 'Cuentas'],
    'planes' => ['planes.php', 'Planes'], 'legal' => ['legal.php', 'Legal'], 'ajustes' => ['ajustes.php', 'Ajustes']];
?>
    <nav class="sw" aria-label="Secciones del panel"><?php foreach ($secciones as $k => [$url, $txt]): ?><a href="<?= $url ?>"<?= $k === $seccionActual ? ' class="on" aria-current="page"' : '' ?>><?= $txt ?></a><?php endforeach; ?></nav>
