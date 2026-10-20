<?php
// Novis — ¿hay un cliente con la sesión iniciada? Lo usa el icono de cuenta de la cabecera.
// Sin cookie de sesión no se crea ninguna: los visitantes normales no reciben cookies.
require_once __DIR__ . '/../lib/cuentas.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (empty($_COOKIE['novis_cliente'])) { echo '{}'; exit; }
nv_sesion();
$u = nv_usuario();
session_write_close();
if (!$u) { echo '{}'; exit; }
$nombre = (string)($u['usuario'] ?: $u['email']);
echo json_encode(['inicial' => mb_strtoupper(mb_substr($nombre, 0, 1)), 'nombre' => $nombre], JSON_UNESCAPED_UNICODE);
