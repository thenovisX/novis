<?php
// Novis — plantilla común para los emails (HTML compatible con Gmail, Outlook y Apple Mail).

/**
 * @param string      $titulo    título grande del email
 * @param string[]    $parrafos  párrafos en HTML ya escapado
 * @param string|null $boton     texto del botón
 * @param string|null $url       enlace del botón
 * @param string      $pie       nota final en pequeño (HTML ya escapado)
 */
function novis_email_html(string $titulo, array $parrafos, ?string $boton = null, ?string $url = null, string $pie = ''): string {
    $h = fn($t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    $cuerpo = '';
    foreach ($parrafos as $p) $cuerpo .= '<p style="margin:0 0 16px">' . $p . '</p>';
    $btn = ($boton && $url)
        ? '<p style="margin:8px 0 28px"><a href="' . $h($url) . '" style="display:inline-block;background:#1d63ed;color:#ffffff;text-decoration:none;font-weight:600;padding:13px 24px;border-radius:12px">' . $h($boton) . '</a></p>'
        : '';
    if ($pie === '') {
        // Quién envía el mensaje, con los datos de panel → Ajustes si ya están puestos
        $quien = 'Novis';
        if (function_exists('nv_ajuste')) {
            $quien = (string)nv_ajuste('negocio', 'Novis');
            $titular = (string)nv_ajuste('titular', '');
            $nif = (string)nv_ajuste('nif', '');
            if ($titular !== '') $quien .= ' · ' . $titular . ($nif !== '' ? ' (NIF ' . $nif . ')' : '');
        }
        $pie = 'Este mensaje lo envía ' . $h($quien) . ' (thenovis.com). <a href="https://thenovis.com/privacidad.html" style="color:#1d63ed">Política de privacidad</a>';
    }
    return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $h($titulo) . '</title></head>'
      . '<body style="margin:0;padding:0;background:#f3f5f8;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;color:#1c2230">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f5f8;padding:32px 12px"><tr><td align="center">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:20px;overflow:hidden;border:1px solid #e1e5ec">'
      . '<tr><td style="padding:32px 36px 4px">'
      . '<table role="presentation" cellpadding="0" cellspacing="0"><tr>'
      . '<td><img src="https://thenovis.com/assets/apple-touch-icon.png?v=10" width="36" height="36" alt="" style="display:block;border:0;border-radius:9px"></td>'
      . '<td style="padding-left:10px;font-size:22px;font-weight:700;letter-spacing:-1px;color:#1c2230">novis</td>'
      . '</tr></table></td></tr>'
      . '<tr><td style="padding:24px 36px 8px;font-size:16px;line-height:1.6;color:#3d4554">'
      . '<h1 style="margin:0 0 18px;font-size:25px;line-height:1.2;letter-spacing:-0.5px;color:#0b2545">' . $h($titulo) . '</h1>'
      . $cuerpo . $btn
      . '<p style="margin:0 0 4px">Un saludo,<br><strong style="color:#1c2230">El equipo de Novis</strong></p>'
      . '</td></tr>'
      . '<tr><td style="padding:24px 36px 30px;font-size:12px;line-height:1.5;color:#646c7a;border-top:1px solid #e1e5ec">' . $pie . '</td></tr>'
      . '</table></td></tr></table></body></html>';
}
