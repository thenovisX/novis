<?php
// Novis — envío del formulario de contacto (funciona en Hostinger con PHP)
header('Content-Type: application/json; charset=utf-8');

// ====== CONFIGURA AQUÍ ======
$destino   = 'hola@thenovis.com';       // Email donde quieres recibir los mensajes
$remitente = 'no-reply@thenovis.com';   // Debe ser un email de TU dominio en Hostinger
$respuesta = 'hola@thenovis.com';       // Desde dónde se envía la confirmación al cliente
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

require_once __DIR__ . '/lib/novis.php';
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
$cabeceras = "From: Novis <$remitente>\r\n"
           . "Reply-To: $email\r\n"
           . "Content-Type: text/plain; charset=UTF-8\r\n";

$enviado = mail($destino, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $cuerpo, $cabeceras);

if ($enviado || $guardado) {
    // Confirmación automática al cliente
    try {
        novis_confirmacion($email, $nombre, $plan, $respuesta);
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
function novis_confirmacion(string $para, string $nombre, string $plan, string $desde): bool {
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

    $planHtml = $plan ? '<p style="margin:0 0 16px">Nos has indicado interés en el plan <strong>' . $h($plan) . '</strong>.</p>' : '';
    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hemos recibido tu solicitud</title></head>'
      . '<body style="margin:0;padding:0;background:#f5f5f7;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;color:#1d1d1f">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f7;padding:32px 12px"><tr><td align="center">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:20px;overflow:hidden">'
      . '<tr><td style="height:5px;background:#8e5cf7;background:linear-gradient(90deg,#0071e3,#8e5cf7,#ff6b8b)"></td></tr>'
      . '<tr><td style="padding:36px 36px 8px">'
      . '<table role="presentation" cellpadding="0" cellspacing="0"><tr>'
      . '<td><img src="https://thenovis.com/assets/apple-touch-icon.png" width="40" height="40" alt="" style="display:block;border:0;border-radius:10px"></td>'
      . '<td style="padding-left:10px;font-size:24px;font-weight:800;letter-spacing:-1px;color:#1d1d1f">novis</td>'
      . '</tr></table></td></tr>'
      . '<tr><td style="padding:24px 36px 8px;font-size:16px;line-height:1.6">'
      . '<h1 style="margin:0 0 18px;font-size:26px;line-height:1.2;letter-spacing:-0.5px">Hemos recibido tu solicitud</h1>'
      . '<p style="margin:0 0 16px">' . $h($saludo) . '</p>'
      . '<p style="margin:0 0 16px">¡Gracias por contactar con Novis! Te responderemos <strong>lo antes posible</strong>, normalmente en menos de 24 horas.</p>'
      . $planHtml
      . '<p style="margin:0 0 24px">Si quieres añadir algo —ejemplos de webs que te gusten, tu logo, fechas…— responde directamente a este email.</p>'
      . '<p style="margin:0 0 28px"><a href="https://thenovis.com" style="display:inline-block;background:#0071e3;color:#ffffff;text-decoration:none;font-weight:600;padding:12px 24px;border-radius:980px">Visitar thenovis.com</a></p>'
      . '<p style="margin:0">Un saludo,<br><strong>El equipo de Novis</strong></p>'
      . '</td></tr>'
      . '<tr><td style="padding:28px 36px 32px;font-size:12px;line-height:1.5;color:#6e6e73;border-top:1px solid #ececf0">'
      . 'Has recibido este mensaje porque has enviado el formulario de contacto de thenovis.com. Si no has sido tú, puedes ignorarlo. '
      . '<a href="https://thenovis.com/privacidad.html" style="color:#0066cc">Política de privacidad</a>'
      . '</td></tr></table></td></tr></table></body></html>';

    $limite = 'novis_' . bin2hex(random_bytes(12));
    $cuerpo = "--$limite\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($texto))
            . "--$limite\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . "--$limite--\r\n";
    $cabeceras = "From: Novis <$desde>\r\n"
               . "Reply-To: Novis <$desde>\r\n"
               . "MIME-Version: 1.0\r\n"
               . "Auto-Submitted: auto-replied\r\n"
               . "X-Auto-Response-Suppress: All\r\n"
               . "Content-Type: multipart/alternative; boundary=\"$limite\"\r\n";
    $asunto = '=?UTF-8?B?' . base64_encode('Hemos recibido tu solicitud · Novis') . '?=';
    return mail($para, $asunto, $cuerpo, $cabeceras, '-f' . $desde);
}
