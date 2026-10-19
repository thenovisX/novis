<?php
// Novis — envío del formulario de contacto (funciona en Hostinger con PHP)
header('Content-Type: application/json; charset=utf-8');

// ====== CONFIGURA AQUÍ ======
$destino   = 'sales@thenovis.com';       // Email donde quieres recibir los mensajes
$remitente = 'no-reply@thenovis.com';   // Debe ser un email de TU dominio en Hostinger
// ============================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

// Anti-spam: si el campo oculto viene relleno, es un bot
if (!empty($_POST['web'])) {
    echo json_encode(['ok' => true]);
    exit;
}

function limpiar($v) {
    return trim(str_replace(["\r", "\n"], ' ', strip_tags($v ?? '')));
}

$nombre  = limpiar($_POST['nombre'] ?? '');
$empresa = limpiar($_POST['empresa'] ?? '');
$email   = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$plan    = limpiar($_POST['plan'] ?? '');
$mensaje = trim(strip_tags($_POST['mensaje'] ?? ''));

if (!$nombre || !$email || !$mensaje) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Faltan campos obligatorios']);
    exit;
}

// Guardar la solicitud en el panel
$guardado = false;
try {
    require_once __DIR__ . '/lib/novis.php';
    $st = novis_db()->prepare('INSERT INTO solicitudes (creado, nombre, empresa, email, plan, mensaje) VALUES (?, ?, ?, ?, ?, ?)');
    $st->execute([date('c'), mb_substr($nombre, 0, 120), mb_substr($empresa, 0, 120), $email, mb_substr($plan, 0, 40), mb_substr($mensaje, 0, 5000)]);
    $guardado = true;
} catch (Throwable $err) {
    error_log('Novis contacto (BD): ' . $err->getMessage());
}

$asunto = "Nuevo contacto web: $nombre" . ($empresa ? " ($empresa)" : '');
$cuerpo = "Nombre: $nombre\nEmpresa: $empresa\nEmail: $email\nPlan: $plan\n\nMensaje:\n$mensaje\n\nVer en el panel: https://thenovis.com/panel/\n";
$cabeceras = "From: Novis <$remitente>\r\n"
           . "Reply-To: $email\r\n"
           . "Content-Type: text/plain; charset=UTF-8\r\n";

$enviado = mail($destino, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $cuerpo, $cabeceras);

if ($enviado || $guardado) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo enviar']);
}
