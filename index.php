<?php
// The Novis — página principal. Es index.html con los planes y precios que guardas en el panel → Planes.
// Si la base de datos no responde, se sirve index.html tal cual (con los precios que lleva escritos).
$html = (string)file_get_contents(__DIR__ . '/index.html');
try {
    require_once __DIR__ . '/lib/productos.php';
    $html = nv_portada($html);
} catch (Throwable $e) {
    error_log('Novis portada: ' . $e->getMessage());
}
header('Content-Type: text/html; charset=utf-8');
echo $html;
