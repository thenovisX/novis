<?php
// Novis — envío de emails.
// Si en gemini-config.php hay 'smtp_password', envía por SMTP autenticado con el buzón de Hostinger
// (lo más fiable). Si no, usa la función mail() de PHP como respaldo.
require_once __DIR__ . '/novis.php';
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

/**
 * @param string      $para     destinatario
 * @param string      $asunto
 * @param string      $texto    versión texto plano
 * @param string|null $html     versión HTML (opcional)
 * @param string|null $responder dirección para "Responder a" (opcional)
 * @param array       $extra    cabeceras adicionales ['Nombre' => 'valor']
 */
function novis_mail(string $para, string $asunto, string $texto, ?string $html = null, ?string $responder = null, array $extra = []): bool {
    $c = novis_config();
    $usuario = $c['smtp_user'] ?? 'hola@thenovis.com';
    $clave   = $c['smtp_password'] ?? '';

    $m = new PHPMailer(true);
    try {
        $m->CharSet = 'UTF-8';
        $m->Encoding = 'base64';
        if ($clave !== '') {
            $m->isSMTP();
            $m->Host = $c['smtp_host'] ?? 'smtp.hostinger.com';
            $m->Port = (int)($c['smtp_port'] ?? 465);
            $m->SMTPSecure = $m->Port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $m->SMTPAuth = true;
            $m->Username = $usuario;
            $m->Password = $clave;
            $m->Timeout = 15;
        } else {
            $m->isMail();
        }
        $m->setFrom($usuario, 'Novis');
        $m->Sender = $usuario;
        $m->addAddress($para);
        if ($responder) $m->addReplyTo($responder);
        foreach ($extra as $k => $v) $m->addCustomHeader($k, $v);
        $m->Subject = $asunto;
        if ($html) {
            $m->isHTML(true);
            $m->Body = $html;
            $m->AltBody = $texto;
        } else {
            $m->Body = $texto;
        }
        return $m->send();
    } catch (Throwable $e) {
        error_log('Novis correo: ' . $m->ErrorInfo . ' ' . $e->getMessage());
        return false;
    }
}
