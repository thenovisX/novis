<?php
// The Novis — páginas públicas con tus datos de negocio (panel → Ajustes → Tu negocio).
// Las páginas siguen siendo HTML normales; los datos van entre marcas <!--d:clave-->valor por defecto<!--/d-->
// y aquí se cambian por lo guardado en la base de datos. Si algo falla, se sirve la página tal cual.
// Esta carpeta está bloqueada al público con .htaccess.
require_once __DIR__ . '/productos.php';

/** Datos del negocio que se pueden mostrar en la web. Los vacíos se quedan con el texto por defecto de la página. */
function nv_datos_negocio(): array {
    $tel = trim((string)nv_ajuste('telefono', ''));
    return [
        'negocio' => (string)nv_ajuste('negocio', 'The Novis'),
        'titular' => (string)nv_ajuste('titular', ''),
        'nif' => (string)nv_ajuste('nif', ''),
        'domicilio' => (string)nv_ajuste('domicilio', ''),
        'localidad' => (string)nv_ajuste('localidad', ''),
        'email' => nv_email_negocio(),
        // El teléfono solo sale en la web si así lo has marcado
        'telefono' => nv_ajuste('telefono_publico') === '1' ? $tel : '',
    ];
}

function nv_tel_enlace(string $tel): string {
    return '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', $tel)) . '">' . e($tel) . '</a>';
}

/** Cambia las marcas de una página por tus datos. */
function nv_pagina(string $html): string {
    try {
        $d = nv_datos_negocio();
    } catch (Throwable $e) {
        error_log('Novis página: ' . $e->getMessage());
        return $html;
    }
    $html = preg_replace_callback('/<!--d:([a-z-]+)-->(.*?)<!--\/d-->/s', function ($m) use ($d) {
        $tel = $d['telefono'];
        return match ($m[1]) {
            'tel-legal' => $tel !== '' ? "\n        <dt>Teléfono</dt><dd>" . nv_tel_enlace($tel) . '</dd>' : '',
            'tel-contacto' => $tel !== '' ? '<div><dt>Teléfono</dt><dd>' . nv_tel_enlace($tel) . '</dd></div>' : '',
            default => ($d[$m[1]] ?? '') !== '' ? e($d[$m[1]]) : $m[2],
        };
    }, $html);
    // Tu correo en todos los enlaces y textos de la página
    if ($d['email'] !== 'hola@thenovis.com') $html = str_replace('hola@thenovis.com', e($d['email']), $html);
    // Datos estructurados para Google: teléfono si es público
    if ($d['telefono'] !== '') {
        $html = preg_replace('/("email": ?"[^"]*")/', '$1, "telephone": ' . json_encode($d['telefono'], JSON_UNESCAPED_UNICODE), $html, 1);
    }
    return $html;
}
