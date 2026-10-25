<?php
// The Novis — sirve las páginas públicas con tus datos de negocio y tus planes (ver lib/pagina.php).
// El .htaccess envía aquí aviso-legal.html, privacidad.html, condiciones.html, cookies.html, accesibilidad.html y gl/.
const NV_PAGINAS = ['index' => 'index.html', 'gl' => 'gl/index.html', 'aviso-legal' => 'aviso-legal.html', 'privacidad' => 'privacidad.html',
    'condiciones' => 'condiciones.html', 'cookies' => 'cookies.html', 'accesibilidad' => 'accesibilidad.html'];
$p = is_string($_GET['p'] ?? null) ? $_GET['p'] : 'index';
if (!isset(NV_PAGINAS[$p])) { http_response_code(404); readfile(__DIR__ . '/404.html'); exit; }
$html = (string)file_get_contents(__DIR__ . '/' . NV_PAGINAS[$p]);
try {
    require_once __DIR__ . '/lib/pagina.php';
    if ($p === 'index') $html = nv_portada($html);
    $html = nv_pagina($html);
} catch (Throwable $e) {
    error_log('Novis página ' . $p . ': ' . $e->getMessage());
}
header('Content-Type: text/html; charset=utf-8');
echo $html;
