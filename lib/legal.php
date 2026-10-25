<?php
// The Novis — datos legales editables y consentimientos de los clientes.
//  · Servicios externos (proveedores) que salen en la política de privacidad: panel → Legal.
//  · Perfil de cliente y publicidad: SOLO si el cliente lo acepta (casillas opcionales, sin marcar, al pagar
//    o en «Tu cuenta»). Cada aceptación o retirada queda anotada con fecha en la tabla «auditoria».
// Esta carpeta está bloqueada al público con .htaccess.
// Las funciones de perfil necesitan lib/cuentas.php (nv_db, nv_auditar), que ya incluye este archivo.
require_once __DIR__ . '/novis.php';

/* ================= Servicios externos (encargados y terceros) ================= */

/**
 * Lista por defecto. Cada servicio: nombre, para qué se usa, dónde trata los datos (y garantías si sale de la UE)
 * y enlace a su política. Se puede cambiar o ampliar desde el panel → Legal.
 */
const NV_PROVEEDORES = [
    ['nombre' => 'Hostinger International Ltd.', 'uso' => 'Alojamiento de la web, base de datos y correo electrónico.',
     'lugar' => 'Unión Europea.', 'url' => 'https://www.hostinger.es/politica-de-privacidad'],
    ['nombre' => 'Stripe Payments Europe, Ltd.', 'uso' => 'Cobro de los pagos con tarjeta y facturas. Stripe trata los datos de pago como responsable independiente.',
     'lugar' => 'Irlanda (UE). Puede transferir datos a EE. UU. con el Marco de Privacidad de Datos UE-EE. UU. y cláusulas contractuales tipo.', 'url' => 'https://stripe.com/es/privacy'],
    ['nombre' => 'Google Ireland Ltd. (Google Analytics)', 'uso' => 'Estadísticas de uso de la web, solo si aceptas las cookies de analítica.',
     'lugar' => 'Irlanda (UE). Puede transferir datos a EE. UU. con el Marco de Privacidad de Datos UE-EE. UU. y cláusulas contractuales tipo.', 'url' => 'https://policies.google.com/privacy?hl=es'],
    ['nombre' => 'Google (API Gemini)', 'uso' => 'Generar las respuestas del asistente virtual (chat con inteligencia artificial).',
     'lugar' => 'Puede tratar datos en EE. UU. con el Marco de Privacidad de Datos UE-EE. UU. y cláusulas contractuales tipo.', 'url' => 'https://ai.google.dev/gemini-api/terms'],
    ['nombre' => 'Google (inicio de sesión)', 'uso' => 'Confirmar tu identidad, solo si eliges «Continuar con Google».',
     'lugar' => 'Puede tratar datos en EE. UU. con el Marco de Privacidad de Datos UE-EE. UU. y cláusulas contractuales tipo.', 'url' => 'https://policies.google.com/privacy?hl=es'],
];

/** @return list<array{nombre:string,uso:string,lugar:string,url:string}> */
function nv_proveedores(): array {
    $j = json_decode((string)nv_ajuste('proveedores', ''), true);
    return is_array($j) ? array_values(array_filter($j, 'is_array')) : NV_PROVEEDORES;
}

/** Lista de servicios para la política de privacidad. */
function nv_html_proveedores(): string {
    $h = "<ul>\n";
    foreach (nv_proveedores() as $p) {
        $url = (string)($p['url'] ?? '');
        $h .= '        <li><strong>' . e($p['nombre'] ?? '') . '</strong>: ' . e($p['uso'] ?? '')
            . (($p['lugar'] ?? '') !== '' ? ' <em>Ubicación:</em> ' . e($p['lugar']) : '')
            . (preg_match('#^https://#', $url) ? ' <a href="' . e($url) . '" target="_blank" rel="noopener">Su política de privacidad</a>.' : '') . "</li>\n";
    }
    return $h . '      </ul>';
}

/** ID de Google Analytics: '' = el de js/consent.js, 'no' = desactivado, 'G-…' = otro. */
function nv_ga_ajuste(): string {
    $g = strtoupper(trim((string)nv_ajuste('ga_id', '')));
    return $g === 'NO' ? 'no' : (preg_match('/^G-[A-Z0-9]{4,20}$/', $g) ? $g : '');
}

/* ================= Perfil de cliente y publicidad ================= */

/** Crea la tabla «perfiles» en SQLite (en MySQL se crea en lib/mysql.php). */
function nv_perfiles_preparar(PDO $db): void {
    if (novis_es_mysql($db)) return;
    $db->exec('CREATE TABLE IF NOT EXISTS perfiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario_id INTEGER NOT NULL UNIQUE,
        marketing TEXT,
        perfil TEXT,
        nombre TEXT,
        empresa TEXT,
        nif TEXT,
        direccion TEXT,
        telefono TEXT,
        actualizado TEXT
    )');
}

/** Crea la tabla «contactos» en SQLite (en MySQL se crea en lib/mysql.php). */
function nv_contactos_preparar(PDO $db): void {
    if (novis_es_mysql($db)) return;
    $db->exec('CREATE TABLE IF NOT EXISTS contactos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        creado TEXT NOT NULL,
        nombre TEXT,
        empresa TEXT,
        email TEXT NOT NULL,
        email_idx TEXT NOT NULL UNIQUE,
        perfil TEXT,
        marketing TEXT,
        actualizado TEXT
    )');
}

/* ---------- Perfil de contacto (formulario de la web, sin cuenta) ---------- */

function nv_contacto(string $email): ?array {
    $st = nv_db()->prepare('SELECT * FROM contactos WHERE email_idx = ?');
    $st->execute([nv_indice($email)]);
    return $st->fetch() ?: null;
}

/**
 * Guarda el perfil de contacto de quien escribe por el formulario, SOLO si marcó alguna casilla opcional:
 *  · $perfil: guardar su nombre, empresa y email como contacto (sale en panel → Cuentas → Contactos).
 *  · $publicidad: recibir promociones por email (entonces se guarda el email y el nombre, lo justo para enviarlas).
 * Desmarcar aquí no retira nada (eso se hace en «Tus datos»). Cada permiso nuevo se anota con fecha en auditoría.
 */
function nv_contacto_guardar(string $nombre, string $empresa, string $email, bool $perfil, bool $publicidad, string $origen): void {
    if (!$perfil && !$publicidad) return;
    $db = nv_db();
    $c = nv_contacto($email);
    $ahora = date('c');
    if (!$c) {
        $db->prepare(novis_sql_ignorar($db) . ' INTO contactos (creado, email, email_idx) VALUES (?, ?, ?)')->execute([$ahora, nv_cifrar($email), nv_indice($email)]);
        $c = nv_contacto($email);
    }
    $id = (int)$c['id'];
    $db->prepare('UPDATE contactos SET nombre = ?, actualizado = ? WHERE id = ?')->execute([nv_cifrar(mb_substr($nombre, 0, 120)), $ahora, $id]);
    if ($perfil) {
        $db->prepare('UPDATE contactos SET empresa = ? WHERE id = ?')->execute([$empresa !== '' ? nv_cifrar(mb_substr($empresa, 0, 120)) : null, $id]);
        if (!$c['perfil']) {
            $db->prepare('UPDATE contactos SET perfil = ? WHERE id = ?')->execute([$ahora, $id]);
            nv_auditar('contacto_perfil_acepta', null, "contacto #$id · $origen · privacidad " . NV_PRIVACIDAD);
        }
    }
    if ($publicidad && !$c['marketing']) {
        $db->prepare('UPDATE contactos SET marketing = ? WHERE id = ?')->execute([$ahora, $id]);
        nv_auditar('contacto_publicidad_acepta', null, "contacto #$id · $origen · privacidad " . NV_PRIVACIDAD);
    }
}

/** Retira un permiso de un contacto ('perfil' o 'publicidad'). Si no le queda ninguno, se borra el contacto. */
function nv_contacto_retirar(int $id, string $que, string $origen): void {
    $db = nv_db();
    if ($que === 'perfil') $db->prepare('UPDATE contactos SET perfil = NULL, empresa = NULL WHERE id = ? AND perfil IS NOT NULL')->execute([$id]);
    elseif ($que === 'publicidad') $db->prepare('UPDATE contactos SET marketing = NULL WHERE id = ? AND marketing IS NOT NULL')->execute([$id]);
    else return;
    $db->prepare('DELETE FROM contactos WHERE id = ? AND perfil IS NULL AND marketing IS NULL')->execute([$id]);
    nv_auditar($que === 'perfil' ? 'contacto_perfil_retira' : 'contacto_publicidad_retira', null, "contacto #$id · $origen");
}

/** Preferencias y perfil de un cliente (o una fila vacía si nunca ha aceptado nada). */
function nv_perfil(int $usuarioId): array {
    $st = nv_db()->prepare('SELECT * FROM perfiles WHERE usuario_id = ?');
    $st->execute([$usuarioId]);
    return $st->fetch() ?: ['usuario_id' => $usuarioId, 'marketing' => null, 'perfil' => null,
        'nombre' => null, 'empresa' => null, 'nif' => null, 'direccion' => null, 'telefono' => null, 'actualizado' => null];
}

function nv_perfil_fila(int $usuarioId): void {
    $db = nv_db();
    $db->prepare(novis_sql_ignorar($db) . ' INTO perfiles (usuario_id) VALUES (?)')->execute([$usuarioId]);
}

/** Si ya no queda nada aceptado ni guardado, la fila se borra. */
function nv_perfil_limpiar(int $usuarioId): void {
    nv_db()->prepare('DELETE FROM perfiles WHERE usuario_id = ? AND marketing IS NULL AND perfil IS NULL')->execute([$usuarioId]);
}

/**
 * Da o retira el consentimiento para recibir publicidad por email (art. 21 LSSI-CE y art. 6.1.a RGPD).
 * Se anota la fecha y de dónde vino (compra, cuenta, panel). No hace nada si no cambia.
 */
function nv_marketing(int $usuarioId, bool $si, string $origen): void {
    $p = nv_perfil($usuarioId);
    if ((bool)$p['marketing'] === $si) return;
    nv_perfil_fila($usuarioId);
    nv_db()->prepare('UPDATE perfiles SET marketing = ? WHERE usuario_id = ?')->execute([$si ? date('c') : null, $usuarioId]);
    if (!$si) nv_perfil_limpiar($usuarioId);
    nv_auditar($si ? 'publicidad_acepta' : 'publicidad_retira', $usuarioId, $origen . ' · privacidad ' . NV_PRIVACIDAD);
}

/** Da o retira el permiso para guardar el perfil de cliente. Al retirarlo se borran los datos del perfil. */
function nv_perfil_permiso(int $usuarioId, bool $si, string $origen): void {
    $p = nv_perfil($usuarioId);
    if ((bool)$p['perfil'] === $si) return;
    nv_perfil_fila($usuarioId);
    if ($si) {
        nv_db()->prepare('UPDATE perfiles SET perfil = ? WHERE usuario_id = ?')->execute([date('c'), $usuarioId]);
    } else {
        nv_db()->prepare('UPDATE perfiles SET perfil = NULL, nombre = NULL, empresa = NULL, nif = NULL, direccion = NULL, telefono = NULL, actualizado = NULL WHERE usuario_id = ?')
            ->execute([$usuarioId]);
        nv_perfil_limpiar($usuarioId);
    }
    nv_auditar($si ? 'perfil_guardar_acepta' : 'perfil_guardar_retira', $usuarioId, $origen . ' · privacidad ' . NV_PRIVACIDAD);
}

/**
 * Tras un pago: si el cliente dio permiso, guarda en su perfil los datos de facturación que escribió en Stripe
 * (nombre, empresa, NIF, dirección y teléfono). Si no dio permiso, no se guarda nada aquí: solo queda el pago
 * (obligación legal) y Stripe conserva los datos de la factura.
 */
function nv_perfil_desde_stripe(int $usuarioId, array $sesion): void {
    $p = nv_perfil($usuarioId);
    if (!$p['perfil']) return;
    $c = is_array($sesion['customer_details'] ?? null) ? $sesion['customer_details'] : [];
    $a = is_array($c['address'] ?? null) ? $c['address'] : [];
    $dir = trim(implode(', ', array_filter([trim(($a['line1'] ?? '') . ' ' . ($a['line2'] ?? '')), trim(($a['postal_code'] ?? '') . ' ' . ($a['city'] ?? '')), $a['state'] ?? '', $a['country'] ?? ''],
        fn($x) => is_string($x) && trim($x) !== '')));
    $nif = '';
    foreach ((array)($c['tax_ids'] ?? []) as $t) if (is_array($t) && !empty($t['value'])) { $nif = (string)$t['value']; break; }
    $nombre = (string)($c['individual_name'] ?? $c['name'] ?? '');
    $empresa = (string)($c['business_name'] ?? '');
    $d = ['nombre' => $nombre, 'empresa' => $empresa, 'nif' => $nif, 'direccion' => $dir, 'telefono' => (string)($c['phone'] ?? '')];
    $d = array_filter(array_map(fn($v) => mb_substr(trim($v), 0, 250), $d), fn($v) => $v !== '');
    if (!$d) return;
    $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($d)));
    nv_db()->prepare("UPDATE perfiles SET $set, actualizado = ? WHERE usuario_id = ?")
        ->execute([...array_map('nv_cifrar', array_values($d)), date('c'), $usuarioId]);
}

/** Lo que se guarda del perfil y las preferencias, para «Descargar mis datos». */
function nv_perfil_exportar(int $usuarioId): array {
    $p = nv_perfil($usuarioId);
    return [
        'publicidad_por_email' => $p['marketing'] ? 'Aceptada el ' . $p['marketing'] : 'No aceptada',
        'perfil_de_cliente' => $p['perfil'] ? array_filter(['aceptado' => $p['perfil'], 'nombre' => $p['nombre'], 'empresa' => $p['empresa'],
            'nif' => $p['nif'], 'direccion' => $p['direccion'], 'telefono' => $p['telefono'], 'actualizado' => $p['actualizado']]) : 'No aceptado (no guardamos perfil)',
    ];
}

/** Versión vigente de la política de privacidad (se anota con cada consentimiento). */
const NV_PRIVACIDAD = '2026-10-25';
