<?php
// Novis — envío del formulario de contacto (funciona en Hostinger con PHP)
header('Content-Type: application/json; charset=utf-8');

// ====== CONFIGURA AQUÍ ======
$destino = 'hola@thenovis.com';   // Email donde quieres recibir los mensajes
// Los emails se envían desde el buzón configurado en gemini-config.php (smtp_user / smtp_password).
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

if (empty($_POST['acepto'])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Debes aceptar la política de privacidad']);
    exit;
}

require_once __DIR__ . '/lib/correo.php';
require_once __DIR__ . '/lib/plantilla.php';
if (!novis_limite('form', 5, 3600)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Demasiados envíos. Inténtalo más tarde.']);
    exit;
}

if (!$nombre || !$email || !$mensaje) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Faltan campos obligatorios']);
    exit;
}

// Guardar la solicitud en el panel
$guardado = false;
try {
    $st = novis_db()->prepare('INSERT INTO solicitudes (creado, nombre, empresa, email, plan, mensaje, consentimiento) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $st->execute([date('c'), mb_substr($nombre, 0, 120), mb_substr($empresa, 0, 120), $email, mb_substr($plan, 0, 40), mb_substr($mensaje, 0, 5000), date('c')]);
    novis_purgar();
    $guardado = true;
} catch (Throwable $err) {
    error_log('Novis contacto (BD): ' . $err->getMessage());
}

$asunto = "Nuevo contacto web: $nombre" . ($empresa ? " ($empresa)" : '');
$cuerpo = "Nombre: $nombre\nEmpresa: $empresa\nEmail: $email\nPlan: $plan\n\nMensaje:\n$mensaje\n\nVer en el panel: https://thenovis.com/panel/\n";
$enviado = novis_mail($destino, $asunto, $cuerpo, null, $email);
if (!$enviado) error_log('Novis contacto: no se pudo enviar el aviso a ' . $destino);

if ($enviado || $guardado) {
    // Confirmación automática al cliente
    try {
        novis_confirmacion($email, $nombre, $plan);
    } catch (Throwable $err) {
        error_log('Novis confirmación: ' . $err->getMessage());
    }
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo enviar']);
}

/**
 * Email automático de "hemos recibido tu solicitud".
 * Para que nadie pueda usar el formulario para enviar spam a terceros,
 * NO se incluye el mensaje escrito por el usuario: solo el nombre (limpio y corto)
 * y el plan elegido de una lista cerrada.
 */
function novis_confirmacion(string $para, string $nombre, string $plan): bool {
    $nombre = preg_replace('/[^\p{L}\s\'\-]/u', '', $nombre);
    $nombre = trim(mb_substr(preg_split('/\s+/', trim($nombre))[0] ?? '', 0, 30));
    $saludo = $nombre !== '' ? "Hola, $nombre:" : 'Hola:';
    $planes = ['Esencial', 'Profesional', 'Tienda'];
    $plan = in_array($plan, $planes, true) ? $plan : '';

    $h = fn($t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    $lineaPlan = $plan ? "Nos has indicado interés en el plan $plan." : '';

    $texto = "$saludo\n\n"
        . "¡Gracias por contactar con Novis! Hemos recibido tu solicitud y te responderemos lo antes posible, normalmente en menos de 24 horas.\n\n"
        . ($lineaPlan ? "$lineaPlan\n\n" : '')
        . "Si quieres añadir algo (ejemplos de webs que te gusten, tu logo, fechas…), responde directamente a este email.\n\n"
        . "Un saludo,\nEl equipo de Novis\nhttps://thenovis.com\n\n"
        . "--\nHas recibido este mensaje porque has enviado el formulario de contacto de thenovis.com. Si no has sido tú, puedes ignorarlo.\n"
        . "Política de privacidad: https://thenovis.com/privacidad.html\n";

    $parrafos = [$h($saludo), '¡Gracias por contactar con Novis! Te responderemos <strong>lo antes posible</strong>, normalmente en menos de 24 horas.'];
    if ($plan) $parrafos[] = 'Nos has indicado interés en el plan <strong>' . $h($plan) . '</strong>.';
    $parrafos[] = 'Si quieres añadir algo —ejemplos de webs que te gusten, tu logo, fechas…— responde directamente a este email.';
    $html = novis_email_html('Hemos recibido tu solicitud', $parrafos, 'Visitar thenovis.com', 'https://thenovis.com',
        'Has recibido este mensaje porque has enviado el formulario de contacto de thenovis.com. Si no has sido tú, puedes ignorarlo. <a href="https://thenovis.com/privacidad.html" style="color:#1d63ed">Política de privacidad</a>');
    return novis_mail($para, 'Hemos recibido tu solicitud · Novis', $texto, $html, null,
        ['Auto-Submitted' => 'auto-replied', 'X-Auto-Response-Suppress' => 'All']);
}
