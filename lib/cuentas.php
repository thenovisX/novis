<?php
// Novis — cuentas de clientes y pagos con Stripe.
require_once __DIR__ . '/novis.php';
require_once __DIR__ . '/correo.php';
require_once __DIR__ . '/plantilla.php';
require_once __DIR__ . '/productos.php';
require_once __DIR__ . '/legal.php';

/* ================= Configuración ================= */

function nv_url(string $ruta = ''): string {
    $base = rtrim((string)(novis_config()['site_url'] ?? 'https://thenovis.com'), '/');
    return $base . '/' . ltrim($ruta, '/');
}
function nv_stripe_ok(): bool { return (string)(novis_config()['stripe_secret'] ?? '') !== ''; }
function nv_google_ok(): bool {
    $c = novis_config();
    return !empty($c['google_client_id']) && !empty($c['google_client_secret']);
}

/* ================= Base de datos ================= */

function nv_db(): PDO {
    static $listo = false;
    $db = novis_db();
    if ($listo) return $db;
    // En MySQL todas las tablas ya se crean completas al conectar (lib/mysql.php)
    $mysql = novis_es_mysql($db);
    if (!$mysql) {
    $db->exec('CREATE TABLE IF NOT EXISTS usuarios (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL UNIQUE COLLATE NOCASE,
        usuario TEXT UNIQUE COLLATE NOCASE,
        telefono TEXT,
        password_hash TEXT,
        google_sub TEXT UNIQUE,
        email_verificado TEXT,
        creado TEXT NOT NULL,
        ultimo_acceso TEXT
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario_id INTEGER NOT NULL,
        tipo TEXT NOT NULL,
        hash TEXT NOT NULL UNIQUE,
        caduca INTEGER NOT NULL,
        usado INTEGER NOT NULL DEFAULT 0
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS pagos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        codigo TEXT NOT NULL UNIQUE,
        concepto TEXT NOT NULL,
        descripcion TEXT,
        importe INTEGER NOT NULL,
        moneda TEXT NOT NULL DEFAULT "eur",
        email_cliente TEXT,
        caduca TEXT,
        estado TEXT NOT NULL DEFAULT "pendiente",
        creado TEXT NOT NULL,
        usuario_id INTEGER,
        pagador_email TEXT,
        stripe_session TEXT,
        stripe_pi TEXT,
        pagado_en TEXT
    )');
    // Enlaces para ver / descargar / borrar datos personales (RGPD), por email y sin cuenta
    $db->exec('CREATE TABLE IF NOT EXISTS datos_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL COLLATE NOCASE,
        hash TEXT NOT NULL UNIQUE,
        caduca INTEGER NOT NULL,
        usado INTEGER NOT NULL DEFAULT 0
    )');
    // Fecha del último cambio de contraseña (para cerrar sesiones abiertas)
    $cols = novis_columnas($db, 'usuarios');
    if (!in_array('pass_cambiada', $cols, true)) novis_alter($db, 'ALTER TABLE usuarios ADD COLUMN pass_cambiada INTEGER');
    // Suscripciones (cobro recurrente mensual o anual)
    $cols = novis_columnas($db, 'pagos');
    foreach (['periodo TEXT', 'stripe_sub TEXT', 'stripe_customer TEXT', 'sub_estado TEXT', 'sub_fin TEXT', 'sub_cancela INTEGER NOT NULL DEFAULT 0'] as $col) {
        if (!in_array(strtok($col, ' '), $cols, true)) novis_alter($db, 'ALTER TABLE pagos ADD COLUMN ' . $col);
    }
    if (!in_array('servicio', $cols, true)) novis_alter($db, 'ALTER TABLE pagos ADD COLUMN servicio TEXT');
    // Índices ciegos (huella con clave) para buscar por email o usuario sin guardarlos en claro
    if (!in_array('pagador_idx', $cols, true)) novis_alter($db, 'ALTER TABLE pagos ADD COLUMN pagador_idx TEXT');
    $colsU = novis_columnas($db, 'usuarios');
    foreach (['email_idx', 'usuario_idx'] as $col) if (!in_array($col, $colsU, true)) novis_alter($db, "ALTER TABLE usuarios ADD COLUMN $col TEXT");
    $colsT = novis_columnas($db, 'datos_tokens');
    if (!in_array('email_idx', $colsT, true)) novis_alter($db, 'ALTER TABLE datos_tokens ADD COLUMN email_idx TEXT');
    // Conversación de cada mensaje (solicitud) entre el cliente y Novis
    $db->exec('CREATE TABLE IF NOT EXISTS respuestas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        solicitud_id INTEGER NOT NULL,
        autor TEXT NOT NULL,
        texto TEXT NOT NULL,
        fecha TEXT NOT NULL
    )');
    // Cada renovación cobrada de una suscripción (la primera cuota es el propio pago)
    $db->exec('CREATE TABLE IF NOT EXISTS renovaciones (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        pago_id INTEGER NOT NULL,
        importe INTEGER NOT NULL,
        fecha TEXT NOT NULL,
        stripe_invoice TEXT NOT NULL UNIQUE
    )');
    // Registro de brechas de seguridad (art. 33.5 RGPD: se anotan todas, se notifiquen o no). Los textos van cifrados.
    $db->exec('CREATE TABLE IF NOT EXISTS incidentes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        creado TEXT NOT NULL,
        descubierto TEXT NOT NULL,
        descripcion TEXT NOT NULL,
        datos TEXT,
        personas INTEGER,
        riesgo TEXT NOT NULL DEFAULT \'bajo\',
        aepd TEXT,
        afectados TEXT,
        medidas TEXT,
        cerrado TEXT
    )');
    // Registro de auditoría: prueba de cuándo se aceptaron las condiciones y la privacidad y de los cambios de seguridad
    // de una cuenta. Solo se añaden filas (nunca se modifican); IP y navegador van cifrados; se borran a los 3 años.
    $db->exec('CREATE TABLE IF NOT EXISTS auditoria (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        fecha TEXT NOT NULL,
        evento TEXT NOT NULL,
        usuario_id INTEGER,
        detalle TEXT,
        ip TEXT,
        agente TEXT
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS auditoria_usuario ON auditoria(usuario_id)');
    }
    // Perfil de cliente y permiso de publicidad (solo de quien los acepta)
    nv_perfiles_preparar($db);
    nv_contactos_preparar($db);
    // Datos personales cifrados: los que se guardaron antes sin cifrar se cifran ahora (una sola vez)
    foreach (['usuarios', 'pagos', 'respuestas', 'datos_tokens', 'perfiles', 'contactos'] as $t) novis_cifrar_tabla($db, $t);
    if (!$mysql) {
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS usuarios_email_idx ON usuarios(email_idx)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS usuarios_usuario_idx ON usuarios(usuario_idx)');
        $db->exec('CREATE INDEX IF NOT EXISTS pagos_pagador_idx ON pagos(pagador_idx)');
        $db->exec('CREATE INDEX IF NOT EXISTS datos_tokens_email_idx ON datos_tokens(email_idx)');
    }
    nv_productos_preparar($db);
    $listo = true;
    return $db;
}

/** Versión vigente de las condiciones de contratación (se anota con cada aceptación). */
const NV_CONDICIONES = '2026-10-25';

/** Anota un hecho importante de una cuenta. Nunca interrumpe la operación si falla. */
function nv_auditar(string $evento, ?int $usuario, string $detalle = '', bool $anonimo = false): void {
    try {
        nv_db()->prepare('INSERT INTO auditoria (fecha, evento, usuario_id, detalle, ip, agente) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([date('c'), $evento, $usuario, $detalle ?: null,
                $anonimo ? null : nv_cifrar((string)($_SERVER['REMOTE_ADDR'] ?? '')),
                $anonimo ? null : nv_cifrar(mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250))]);
    } catch (Throwable $e) {
        error_log('Novis auditoría: ' . $e->getMessage());
    }
}

/* ================= Utilidades ================= */

/** Código fácil de dictar: sin 0/O ni 1/I/L. Ej.: NOV-7KQP-H4WX */
function nv_generar_codigo(): string {
    $abc = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $c = '';
    for ($i = 0; $i < 8; $i++) $c .= $abc[random_int(0, strlen($abc) - 1)];
    return 'NOV-' . substr($c, 0, 4) . '-' . substr($c, 4);
}
function nv_normalizar_codigo(string $c): string {
    $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c));
    if (str_starts_with($c, 'NOV')) $c = substr($c, 3);
    return strlen($c) === 8 ? 'NOV-' . substr($c, 0, 4) . '-' . substr($c, 4) : '';
}
function nv_euros(int $centimos): string {
    return number_format($centimos / 100, 2, ',', '.') . ' €';
}

/* ================= Catálogo (lo que el cliente puede contratar él solo) ================= */
// Sale de los productos que se editan en el panel → Planes: ver nv_catalogo() en lib/productos.php.

/* ================= Mensajes del cliente ================= */

const NV_TEMAS = ['cambio' => 'Cambio en mi web', 'nuevo' => 'Contratar algo nuevo', 'pagos' => 'Facturas o pagos', 'otro' => 'Otra consulta'];
const NV_ESTADO_MSG = ['nueva' => 'Recibido', 'en_curso' => 'En curso', 'cerrada' => 'Resuelto', 'descartada' => 'Cerrado'];

function nv_hilo(int $solicitudId): array {
    $st = nv_db()->prepare('SELECT * FROM respuestas WHERE solicitud_id = ? ORDER BY id');
    $st->execute([$solicitudId]);
    return $st->fetchAll();
}

/** Todos los datos que Novis guarda de un email (RGPD: acceso y portabilidad). */
function nv_datos_de(string $email): array {
    $db = nv_db();
    $st = $db->prepare('SELECT id, creado, nombre, empresa, email, plan, mensaje, estado, consentimiento FROM solicitudes WHERE email_idx = ? ORDER BY id');
    $st->execute([nv_indice($email)]);
    $sol = $st->fetchAll();
    foreach ($sol as &$x) {
        $x['conversacion'] = array_map(fn($r) => ['autor' => $r['autor'] === 'novis' ? 'Novis' : 'Tú', 'texto' => $r['texto'], 'fecha' => $r['fecha']], nv_hilo((int)$x['id']));
        unset($x['id']);
    }
    unset($x);
    $st = $db->prepare('SELECT id, email, usuario, telefono, email_verificado, creado, ultimo_acceso, (google_sub IS NOT NULL) AS google FROM usuarios WHERE email_idx = ?');
    $st->execute([nv_indice($email)]);
    $cuenta = $st->fetch() ?: null;
    $campos = 'codigo, concepto, importe, periodo, estado, pagado_en, sub_estado, sub_fin, sub_cancela';
    $pagos = [];
    if ($cuenta) {
        $st = $db->prepare("SELECT $campos FROM pagos WHERE usuario_id = ? ORDER BY id");
        $st->execute([$cuenta['id']]);
        $pagos = $st->fetchAll();
    }
    $st = $db->prepare("SELECT $campos FROM pagos WHERE pagador_idx = ? AND usuario_id IS NULL");
    $st->execute([nv_indice($email)]);
    $pagos = array_merge($pagos, $st->fetchAll());
    $prefs = $cuenta ? nv_perfil_exportar((int)$cuenta['id']) : null;
    $contacto = nv_contacto($email);
    if ($contacto) $contacto = array_intersect_key($contacto, array_flip(['id', 'creado', 'nombre', 'empresa', 'email', 'perfil', 'marketing']));
    return ['solicitudes' => $sol, 'cuenta' => $cuenta, 'preferencias' => $prefs, 'contacto' => $contacto, 'pagos' => $pagos];
}

/** Archivo JSON con los datos de un email, listo para descargar. */
function nv_datos_json(string $email): string {
    $d = nv_datos_de($email);
    if ($d['cuenta']) unset($d['cuenta']['id']);
    if ($d['contacto']) unset($d['contacto']['id']);
    $d = ['generado' => date('c'), 'responsable' => 'Novis (thenovis.com)', 'email' => $email] + $d;
    return json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Borra todos los datos de un email (RGPD: supresión). Los pagos se conservan desvinculados (obligación legal). */
function nv_borrar_datos(string $email, string $desde): array {
    $db = nv_db();
    $d = nv_datos_de($email);
    $idx = nv_indice($email);
    $db->prepare('UPDATE datos_tokens SET usado = 1 WHERE email_idx = ?')->execute([$idx]);
    $db->prepare('DELETE FROM respuestas WHERE solicitud_id IN (SELECT id FROM solicitudes WHERE email_idx = ?)')->execute([$idx]);
    $db->prepare('DELETE FROM solicitudes WHERE email_idx = ?')->execute([$idx]);
    $db->prepare('DELETE FROM contactos WHERE email_idx = ?')->execute([$idx]);
    if ($d['contacto']) nv_auditar('contacto_eliminado', null, 'contacto #' . $d['contacto']['id'], true);
    if ($d['cuenta']) {
        $id = (int)$d['cuenta']['id'];
        nv_sub_cancelar_de_usuario($id);   // que no se le siga cobrando
        $db->prepare('UPDATE pagos SET usuario_id = NULL WHERE usuario_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM tokens WHERE usuario_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM perfiles WHERE usuario_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
        // La auditoría pierde el vínculo con la persona (sin IP ni navegador) y queda solo la constancia de la baja
        $db->prepare('UPDATE auditoria SET usuario_id = NULL, ip = NULL, agente = NULL WHERE usuario_id = ?')->execute([$id]);
        nv_auditar('cuenta_eliminada', null, '', true);
    }
    novis_compactar();
    // Aviso a Novis para borrar también los emails del buzón
    novis_mail(nv_email_negocio(), 'Solicitud de supresión de datos completada',
        "Una persona ha eliminado sus datos desde $desde.\n\nEmail: $email\nMensajes borrados: " . count($d['solicitudes'])
        . "\nCuenta borrada: " . ($d['cuenta'] ? 'sí' : 'no') . "\nPagos conservados por obligación legal: " . count($d['pagos'])
        . "\n\nPendiente (máximo 30 días): borra del buzón los emails de esta persona.");
    return $d;
}

/* ================= Suscripciones ================= */

const NV_PERIODOS = ['month' => 'mes', 'year' => 'año', 'year3' => '3 años'];
const NV_PERIODOS_TXT = ['' => 'Pago único', 'month' => 'Suscripción mensual', 'year' => 'Suscripción anual', 'year3' => 'Suscripción cada 3 años'];
/** Periodo en el formato de Stripe: [interval, interval_count] */
const NV_PERIODOS_STRIPE = ['month' => ['month', 1], 'year' => ['year', 1], 'year3' => ['year', 3]];
/** Meses que cubre cada cuota (para calcular lo que entra al mes) */
const NV_PERIODOS_MESES = ['month' => 1, 'year' => 12, 'year3' => 36];

function nv_es_sub(array $p): bool { return isset(NV_PERIODOS[$p['periodo'] ?? '']); }
/** «29,00 €» o «29,00 € / mes» */
function nv_importe_txt(array $p): string {
    return nv_euros((int)$p['importe']) . (nv_es_sub($p) ? ' / ' . NV_PERIODOS[$p['periodo']] : '');
}
/** Estado para mostrar: [clave, texto]. Claves: pendiente, pagado, cancelado, error. */
function nv_estado(array $p): array {
    if (!nv_es_sub($p) || $p['estado'] !== 'pagado') {
        return [$p['estado'], ['pendiente' => 'Pendiente', 'pagado' => 'Pagado', 'cancelado' => 'Anulado'][$p['estado']] ?? $p['estado']];
    }
    $fin = $p['sub_fin'] ? date('d/m/Y', strtotime($p['sub_fin'])) : '';
    return match ($p['sub_estado'] ?? 'active') {
        'canceled', 'incomplete_expired' => ['cancelado', 'Cancelada'],
        'past_due', 'unpaid', 'incomplete' => ['error', 'Pago fallido'],
        default => $p['sub_cancela'] ? ['pendiente', 'Activa hasta el ' . $fin] : ['pagado', 'Activa'],
    };
}
function nv_sub_viva(array $p): bool {
    return nv_es_sub($p) && $p['stripe_sub'] && !in_array($p['sub_estado'], ['canceled', 'incomplete_expired'], true);
}

/** Guarda en el pago el estado de su suscripción tal y como lo da Stripe. */
function nv_sub_guardar(int $pagoId, array $sub): void {
    $fin = $sub['current_period_end'] ?? ($sub['items']['data'][0]['current_period_end'] ?? null);
    nv_db()->prepare('UPDATE pagos SET stripe_sub = ?, stripe_customer = COALESCE(?, stripe_customer), sub_estado = ?, sub_fin = ?, sub_cancela = ? WHERE id = ?')
        ->execute([$sub['id'], is_string($sub['customer'] ?? null) ? $sub['customer'] : null, (string)($sub['status'] ?? ''),
            $fin ? date('c', (int)$fin) : null, !empty($sub['cancel_at_period_end']) || !empty($sub['cancel_at']) ? 1 : 0, $pagoId]);
}
function nv_sub_sincronizar(array $p): ?array {
    if (empty($p['stripe_sub']) || !nv_stripe_ok()) return null;
    try { $s = nv_stripe('GET', '/v1/subscriptions/' . rawurlencode($p['stripe_sub'])); } catch (Throwable $e) { return null; }
    nv_sub_guardar((int)$p['id'], $s);
    return $s;
}
/** Programa la baja al final del periodo ya pagado ($cancelar = true) o la anula ($cancelar = false). */
function nv_sub_baja(array $p, bool $cancelar): void {
    $s = nv_stripe('POST', '/v1/subscriptions/' . rawurlencode($p['stripe_sub']), ['cancel_at_period_end' => $cancelar ? 'true' : 'false']);
    nv_sub_guardar((int)$p['id'], $s);
}
/** Cancela ya (sin esperar al final del periodo) todas las suscripciones vivas de una cuenta. Para cuando se borra la cuenta. */
function nv_sub_cancelar_de_usuario(int $usuarioId): void {
    $st = nv_db()->prepare("SELECT * FROM pagos WHERE usuario_id = ? AND stripe_sub IS NOT NULL");
    $st->execute([$usuarioId]);
    foreach ($st->fetchAll() as $p) {
        if (!nv_sub_viva($p)) continue;
        try { nv_sub_guardar((int)$p['id'], nv_stripe('DELETE', '/v1/subscriptions/' . rawurlencode($p['stripe_sub']))); }
        catch (Throwable $e) { error_log('Novis cancelar suscripción ' . $p['id'] . ': ' . $e->getMessage()); }
    }
}
/** Abre el portal de Stripe para que el cliente cambie su tarjeta o descargue facturas. */
function nv_portal_url(array $p): string {
    $s = nv_stripe('POST', '/v1/billing_portal/sessions', ['customer' => $p['stripe_customer'], 'return_url' => nv_url('pagos/?a=resumen&id=' . $p['id']), 'locale' => 'es']);
    return (string)$s['url'];
}

/** Crea un token de un solo uso (verificar email / restablecer contraseña). Devuelve el token en claro. */
function nv_token_crear(int $usuarioId, string $tipo, int $segundos): string {
    $t = bin2hex(random_bytes(24));
    $db = nv_db();
    $db->prepare('UPDATE tokens SET usado = 1 WHERE usuario_id = ? AND tipo = ? AND usado = 0')->execute([$usuarioId, $tipo]);
    $db->prepare('INSERT INTO tokens (usuario_id, tipo, hash, caduca) VALUES (?, ?, ?, ?)')
       ->execute([$usuarioId, $tipo, hash('sha256', $t), time() + $segundos]);
    $db->prepare('DELETE FROM tokens WHERE caduca < ?')->execute([time() - 86400 * 7]);
    return $t;
}
/** Consume un token válido y devuelve el id de usuario, o null. */
function nv_token_usar(string $t, string $tipo): ?int {
    $db = nv_db();
    $st = $db->prepare('SELECT id, usuario_id FROM tokens WHERE hash = ? AND tipo = ? AND usado = 0 AND caduca >= ?');
    $st->execute([hash('sha256', $t), $tipo, time()]);
    $fila = $st->fetch();
    if (!$fila) return null;
    $db->prepare('UPDATE tokens SET usado = 1 WHERE id = ?')->execute([$fila['id']]);
    return (int)$fila['usuario_id'];
}

/* ================= Emails ================= */

function nv_email_verificacion(array $u): bool {
    $t = nv_token_crear((int)$u['id'], 'verificar', 86400);
    $url = nv_url('pagos/?a=verificar&t=' . $t);
    $html = novis_email_html('Confirma tu email', [
        'Hola' . ($u['usuario'] ? ', ' . e($u['usuario']) : '') . ':',
        'Para activar tu cuenta en Novis, confirma que este email es tuyo. El enlace caduca en 24 horas.',
    ], 'Confirmar mi email', $url, 'Si no has creado una cuenta en thenovis.com, puedes ignorar este mensaje.');
    $texto = "Para activar tu cuenta en Novis, confirma tu email abriendo este enlace (caduca en 24 horas):\n$url\n\nSi no has creado una cuenta en thenovis.com, ignora este mensaje.";
    return novis_mail($u['email'], 'Confirma tu email · Novis', $texto, $html);
}

function nv_email_restablecer(array $u): bool {
    $t = nv_token_crear((int)$u['id'], 'reset', 3600);
    $url = nv_url('pagos/?a=restablecer&t=' . $t);
    $html = novis_email_html('Crea una contraseña nueva', [
        'Hemos recibido una petición para cambiar la contraseña de tu cuenta en Novis.',
        'El enlace caduca en 1 hora. Si no lo has pedido tú, ignora este mensaje: tu contraseña no cambiará.',
    ], 'Crear contraseña nueva', $url);
    $texto = "Para crear una contraseña nueva en Novis, abre este enlace (caduca en 1 hora):\n$url\n\nSi no lo has pedido tú, ignora este mensaje.";
    return novis_mail($u['email'], 'Cambia tu contraseña · Novis', $texto, $html);
}

function nv_emails_pago(array $p): void {
    $destino = nv_email_negocio();
    $importe = nv_importe_txt($p);
    $sub = nv_es_sub($p);
    $email = (string)($p['pagador_email'] ?? '');
    if ($email !== '') {
        $html = novis_email_html($sub ? 'Suscripción activada' : 'Pago recibido', [
            $sub ? 'Tu suscripción ya está activa. ¡Gracias por confiar en Novis!' : 'Hemos recibido tu pago. ¡Gracias por confiar en Novis!',
            '<strong>Concepto:</strong> ' . e($p['concepto']) . '<br><strong>Importe:</strong> ' . e($importe) . '<br><strong>Referencia:</strong> ' . e($p['codigo']),
            $sub ? 'Se renovará automáticamente cada ' . NV_PERIODOS[$p['periodo']] . '. <strong>No tiene permanencia</strong>: puedes cancelarla cuando quieras desde tu área de cliente.'
                 : 'Stripe te enviará también su propio recibo. Si necesitas factura o tienes cualquier duda, responde a este email.',
        ], $sub ? 'Gestionar mi suscripción' : 'Ver mis pagos', nv_url($sub ? 'pagos/?a=resumen&id=' . $p['id'] : 'pagos/'));
        $texto = ($sub ? 'Tu suscripción ya está activa.' : 'Hemos recibido tu pago.') . " ¡Gracias por confiar en Novis!\n\nConcepto: {$p['concepto']}\nImporte: $importe\nReferencia: {$p['codigo']}\n\n"
            . ($sub ? 'Se renueva automáticamente y puedes cancelarla cuando quieras: ' . nv_url('pagos/?a=resumen&id=' . $p['id']) : 'Si necesitas factura, responde a este email.');
        novis_mail($email, ($sub ? 'Suscripción activada' : 'Pago recibido') . ' · Novis', $texto, $html, $destino);
    }
    $texto = (nv_es_sub($p) ? 'Nueva suscripción' : 'Pago recibido por Stripe') . "\n\nConcepto: {$p['concepto']}\nImporte: $importe\nCódigo: {$p['codigo']}\nCliente: $email\n\nVer en el panel: " . nv_url('panel/pagos.php');
    novis_mail($destino, ($sub ? "Nueva suscripción" : "Pago recibido") . ": $importe · {$p['concepto']}", $texto, null, $email ?: null);
}

/* ================= Stripe (API REST, sin librerías) ================= */

function nv_stripe(string $metodo, string $ruta, array $datos = []): array {
    $c = novis_config();
    $base = rtrim((string)($c['stripe_api'] ?? 'https://api.stripe.com'), '/');
    $ch = curl_init($base . $ruta . ($metodo === 'GET' && $datos ? '?' . http_build_query($datos) : ''));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERPWD => (string)$c['stripe_secret'] . ':',
        CURLOPT_HTTPHEADER => ['Stripe-Version: 2024-06-20'],
    ]);
    if ($metodo === 'DELETE') curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    if ($metodo === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($datos));
    }
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode((string)$r, true);
    if ($code < 200 || $code >= 300 || !is_array($j)) {
        error_log('Novis Stripe ' . $ruta . ' HTTP ' . $code . ' ' . substr((string)$r, 0, 300));
        throw new RuntimeException('Stripe no respondió correctamente.');
    }
    return $j;
}

/** Marca un pago como pagado (idempotente) y envía los emails la primera vez. */
function nv_marcar_pagado(int $pagoId, array $sesion): void {
    $db = nv_db();
    $mail = $sesion['customer_details']['email'] ?? null;
    $st = $db->prepare("UPDATE pagos SET estado = 'pagado', pagado_en = ?, stripe_session = ?, stripe_pi = ?,
        pagador_email = COALESCE(pagador_email, ?), pagador_idx = COALESCE(pagador_idx, ?) WHERE id = ? AND estado != 'pagado'");
    $st->execute([date('c'), $sesion['id'] ?? null, is_string($sesion['payment_intent'] ?? null) ? $sesion['payment_intent'] : null,
        is_string($mail) ? nv_cifrar($mail) : null, is_string($mail) ? nv_indice($mail) : null, $pagoId]);
    $primera = $st->rowCount() === 1;
    // Suscripción: se guarda su identificador y su estado (próxima renovación, etc.)
    if (is_string($sesion['subscription'] ?? null)) {
        $db->prepare("UPDATE pagos SET stripe_sub = ?, stripe_customer = COALESCE(stripe_customer, ?), sub_estado = COALESCE(sub_estado, 'active') WHERE id = ?")
           ->execute([$sesion['subscription'], is_string($sesion['customer'] ?? null) ? $sesion['customer'] : null, $pagoId]);
        if ($primera) { $q = $db->prepare('SELECT * FROM pagos WHERE id = ?'); $q->execute([$pagoId]); nv_sub_sincronizar($q->fetch()); }
    }
    if ($primera) {
        $p = $db->prepare('SELECT * FROM pagos WHERE id = ?');
        $p->execute([$pagoId]);
        $pago = $p->fetch();
        // Perfil de cliente: solo si el cliente dio permiso para guardarlo
        try { if (!empty($pago['usuario_id'])) nv_perfil_desde_stripe((int)$pago['usuario_id'], $sesion); } catch (Throwable $e) { error_log('Novis perfil: ' . $e->getMessage()); }
        try { nv_emails_pago($pago); } catch (Throwable $e) { error_log('Novis email pago: ' . $e->getMessage()); }
    }
}

/** Renovación cobrada (factura de un ciclo nuevo). Idempotente: cada factura se registra una sola vez. */
function nv_renovacion(array $p, array $factura): void {
    $db = nv_db();
    $st = $db->prepare(novis_sql_ignorar($db) . ' INTO renovaciones (pago_id, importe, fecha, stripe_invoice) VALUES (?, ?, ?, ?)');
    $st->execute([$p['id'], (int)($factura['amount_paid'] ?? $p['importe']), date('c'), (string)$factura['id']]);
    if ($st->rowCount() !== 1) return;
    nv_sub_sincronizar($p);
    $importe = nv_euros((int)($factura['amount_paid'] ?? $p['importe']));
    $email = (string)($p['pagador_email'] ?? '');
    try {
        if ($email !== '') {
            $html = novis_email_html('Suscripción renovada', [
                'Hemos cobrado la renovación de tu suscripción. ¡Gracias por seguir con Novis!',
                '<strong>Concepto:</strong> ' . e($p['concepto']) . '<br><strong>Importe:</strong> ' . e($importe),
                'Puedes cancelarla cuando quieras desde tu área de cliente, sin permanencia ni penalización.',
            ], 'Gestionar mi suscripción', nv_url('pagos/?a=resumen&id=' . $p['id']));
            $texto = "Hemos cobrado la renovación de tu suscripción.\n\nConcepto: {$p['concepto']}\nImporte: $importe\n\nPuedes cancelarla cuando quieras: " . nv_url('pagos/?a=resumen&id=' . $p['id']);
            novis_mail($email, 'Suscripción renovada · Novis', $texto, $html, nv_email_negocio());
        }
        novis_mail(nv_email_negocio(), "Renovación cobrada: $importe · {$p['concepto']}",
            "Se ha renovado una suscripción.\n\nConcepto: {$p['concepto']}\nImporte: $importe\nCliente: $email\n\n" . nv_url('panel/pagos.php?id=' . $p['id']));
    } catch (Throwable $e) { error_log('Novis email renovación: ' . $e->getMessage()); }
}

/** Avisos de una suscripción a Hugo (y al cliente si el cobro ha fallado). */
function nv_aviso_sub(array $p, string $que): void {
    $email = (string)($p['pagador_email'] ?? '');
    $url = nv_url('pagos/?a=resumen&id=' . $p['id']);
    try {
        if ($que === 'fallo') {
            if ($email !== '') {
                $html = novis_email_html('No hemos podido cobrar tu suscripción', [
                    'El cobro de «' . e($p['concepto']) . '» no se ha podido completar. Suele pasar cuando la tarjeta ha caducado o no tiene saldo.',
                    'Actualiza tu tarjeta para que el servicio siga activo. Stripe volverá a intentarlo en los próximos días.',
                ], 'Actualizar mi tarjeta', $url);
                novis_mail($email, 'No hemos podido cobrar tu suscripción · Novis',
                    "El cobro de «{$p['concepto']}» no se ha podido completar. Actualiza tu tarjeta aquí: $url", $html, nv_email_negocio());
            }
            novis_mail(nv_email_negocio(), "Cobro fallido: {$p['concepto']}", "Stripe no ha podido cobrar la suscripción.\nCliente: $email\n\n" . nv_url('panel/pagos.php?id=' . $p['id']));
        } else {
            $txt = ['baja' => 'El cliente ha pedido la baja: la suscripción terminará al final del periodo pagado.',
                    'reanuda' => 'El cliente ha anulado la baja: la suscripción sigue activa.',
                    'fin' => 'La suscripción ha terminado y ya no se cobrará más.'][$que] ?? $que;
            novis_mail(nv_email_negocio(), "Suscripción: {$p['concepto']}", "$txt\n\nCliente: $email\n" . nv_url('panel/pagos.php?id=' . $p['id']));
        }
    } catch (Throwable $e) { error_log('Novis aviso suscripción: ' . $e->getMessage()); }
}

/** Comprueba en Stripe el estado de una sesión de pago y actualiza el pago si ya está pagado. */
function nv_sincronizar(array $pago): ?array {
    if (empty($pago['stripe_session']) || !nv_stripe_ok()) return null;
    try {
        $s = nv_stripe('GET', '/v1/checkout/sessions/' . rawurlencode($pago['stripe_session']));
    } catch (Throwable $e) {
        return null;
    }
    if (($s['payment_status'] ?? '') === 'paid' && (int)($s['metadata']['pago_id'] ?? 0) === (int)$pago['id']) {
        nv_marcar_pagado((int)$pago['id'], $s);
    }
    return $s;
}

/* ================= Google (OAuth 2.0 / OpenID Connect) ================= */

function nv_google_url(string $state, string $nonce): string {
    $c = novis_config();
    return ($c['google_auth_url'] ?? 'https://accounts.google.com/o/oauth2/v2/auth') . '?' . http_build_query([
        'client_id' => $c['google_client_id'],
        'redirect_uri' => nv_url('pagos/google.php'),
        'response_type' => 'code',
        'scope' => 'openid email',
        'state' => $state,
        'nonce' => $nonce,
        'prompt' => 'select_account',
    ]);
}

/** Intercambia el código de Google por los datos del usuario (sub, email, email_verified). */
function nv_google_usuario(string $code, string $nonce): ?array {
    $c = novis_config();
    $ch = curl_init($c['google_token_url'] ?? 'https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'code' => $code,
            'client_id' => $c['google_client_id'],
            'client_secret' => $c['google_client_secret'],
            'redirect_uri' => nv_url('pagos/google.php'),
            'grant_type' => 'authorization_code',
        ]),
    ]);
    $r = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    $jwt = $r['id_token'] ?? '';
    $partes = explode('.', $jwt);
    if (count($partes) !== 3) return null;
    // El token llega directamente de Google por HTTPS, así que basta con validar su contenido.
    $d = json_decode(base64_decode(strtr($partes[1], '-_', '+/')), true);
    if (!is_array($d)) return null;
    $iss_ok = in_array($d['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true);
    if (!$iss_ok || ($d['aud'] ?? '') !== $c['google_client_id'] || ($d['exp'] ?? 0) < time()
        || !hash_equals($nonce, (string)($d['nonce'] ?? '')) || empty($d['sub']) || empty($d['email'])) return null;
    return ['sub' => (string)$d['sub'], 'email' => strtolower((string)$d['email']), 'verificado' => !empty($d['email_verified'])];
}

/* ================= Sesión del cliente ================= */

/**
 * Usuario y contraseña del panel. Primero los guardados en la base de datos (panel → Ajustes, siempre como hash);
 * si aún no hay, los de gemini-config.php (panel_user + panel_password_hash o panel_password).
 * @return array{usuario:string,hash:string,clave:string,origen:string}
 */
function nv_panel_credenciales(): array {
    $u = (string)nv_ajuste('panel_usuario', '');
    $h = (string)nv_ajuste('panel_hash', '');
    if ($u !== '' && $h !== '') return ['usuario' => $u, 'hash' => $h, 'clave' => '', 'origen' => 'bd'];
    $c = novis_config();
    $clave = (string)($c['panel_password'] ?? '');
    $hash = trim((string)($c['panel_password_hash'] ?? ''));
    // Si se pegó el hash (empieza por $2y$…) en panel_password por error, se trata igualmente como hash
    if ($hash === '' && password_get_info($clave)['algo'] !== null) { $hash = $clave; $clave = ''; }
    return ['usuario' => (string)($c['panel_user'] ?? ''), 'hash' => $hash, 'clave' => $clave, 'origen' => 'config'];
}

/** ¿Hay usuario y contraseña de administrador? */
function nv_panel_configurado(): bool {
    $c = nv_panel_credenciales();
    return $c['usuario'] !== '' && ($c['hash'] !== '' || strlen($c['clave']) >= 8);
}

/** ¿Es correcta esta contraseña de administrador? (con hash configurado se usa siempre password_verify) */
function nv_panel_clave_ok(string $pass): bool {
    $c = nv_panel_credenciales();
    if ($c['hash'] !== '') return password_verify($pass, $c['hash']);
    return $c['clave'] !== '' && hash_equals($c['clave'], $pass);
}

/** ¿La visita llegó por HTTPS? (también si hay un proxy o CDN delante) */
function nv_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/**
 * Ajustes comunes de las sesiones: se guardan en una carpeta privada propia (no en la carpeta temporal
 * compartida del servidor), solo se aceptan identificadores que creó este servidor y se limpian solas.
 */
function nv_sesion_ajustes(string $carpeta, int $segundos): void {
    session_save_path(novis_dir('sesiones/' . $carpeta));
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.use_trans_sid', '0');
    @ini_set('session.gc_maxlifetime', (string)$segundos);
    @ini_set('session.gc_probability', '1');
    @ini_set('session.gc_divisor', '50');
}

function nv_sesion(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    nv_sesion_ajustes('cliente', 7200);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/pagos/',
        'secure' => nv_https(),
        'httponly' => true,
        'samesite' => 'Lax',   // Lax para poder volver desde Stripe y Google con la sesión iniciada
    ]);
    session_name('novis_cliente');
    session_start();
    // Cierre automático tras 2 horas sin actividad
    if (!empty($_SESSION['uid']) && ($_SESSION['visto'] ?? 0) < time() - 7200) { $_SESSION = []; session_regenerate_id(true); }
    $_SESSION['visto'] = time();
}

/** Sesión del panel de administración: 30 días como máximo y se cierra sola tras 7 días sin usarla. */
function nv_panel_sesion(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    nv_sesion_ajustes('panel', 86400 * 30);
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => '/panel/',
        'secure' => nv_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_name('novis_panel');
    session_start();
    if (!empty($_SESSION['ok'])) {
        $ahora = time();
        // También se cierra si la contraseña del panel se cambió después de entrar (en otro dispositivo)
        if (($_SESSION['visto'] ?? 0) < $ahora - 86400 * 7 || ($_SESSION['desde'] ?? 0) < $ahora - 86400 * 30
            || (int)nv_ajuste('panel_cambio', '0') > (int)($_SESSION['desde'] ?? 0)) {
            $_SESSION = [];
            session_regenerate_id(true);
        } else {
            $_SESSION['visto'] = $ahora;
        }
    }
}
function nv_csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function nv_csrf_ok(): bool {
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}
function nv_entrar(int $id): void {
    session_regenerate_id(true);
    $_SESSION['uid'] = $id;
    $_SESSION['desde'] = time();
    nv_db()->prepare('UPDATE usuarios SET ultimo_acceso = ? WHERE id = ?')->execute([date('c'), $id]);
}
function nv_usuario(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $st = nv_db()->prepare('SELECT * FROM usuarios WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch() ?: null;
    // Si la contraseña se cambió después de iniciar sesión (o la cuenta ya no existe), se cierra la sesión
    if (!$u || ((int)$u['pass_cambiada'] > (int)($_SESSION['desde'] ?? 0))) {
        unset($_SESSION['uid'], $_SESSION['desde']);
        return null;
    }
    return $u;
}
