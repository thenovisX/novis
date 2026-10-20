<?php
// Novis — cuentas de clientes y pagos con Stripe.
require_once __DIR__ . '/novis.php';
require_once __DIR__ . '/correo.php';
require_once __DIR__ . '/plantilla.php';

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
    // Fecha del último cambio de contraseña (para cerrar sesiones abiertas)
    $cols = array_column($db->query('PRAGMA table_info(usuarios)')->fetchAll(), 'name');
    if (!in_array('pass_cambiada', $cols, true)) $db->exec('ALTER TABLE usuarios ADD COLUMN pass_cambiada INTEGER');
    $listo = true;
    return $db;
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
    $destino = 'hola@thenovis.com';
    $importe = nv_euros((int)$p['importe']);
    $email = (string)($p['pagador_email'] ?? '');
    if ($email !== '') {
        $html = novis_email_html('Pago recibido', [
            'Hemos recibido tu pago. ¡Gracias por confiar en Novis!',
            '<strong>Concepto:</strong> ' . e($p['concepto']) . '<br><strong>Importe:</strong> ' . e($importe) . '<br><strong>Referencia:</strong> ' . e($p['codigo']),
            'Stripe te enviará también su propio recibo. Si necesitas factura o tienes cualquier duda, responde a este email.',
        ], 'Ver mis pagos', nv_url('pagos/'));
        $texto = "Hemos recibido tu pago. ¡Gracias por confiar en Novis!\n\nConcepto: {$p['concepto']}\nImporte: $importe\nReferencia: {$p['codigo']}\n\nSi necesitas factura, responde a este email.";
        novis_mail($email, 'Pago recibido · Novis', $texto, $html, $destino);
    }
    $texto = "Pago recibido por Stripe\n\nConcepto: {$p['concepto']}\nImporte: $importe\nCódigo: {$p['codigo']}\nCliente: $email\n\nVer en el panel: " . nv_url('panel/pagos.php');
    novis_mail($destino, "Pago recibido: $importe · {$p['concepto']}", $texto, null, $email ?: null);
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
    $st = $db->prepare("UPDATE pagos SET estado = 'pagado', pagado_en = ?, stripe_session = ?, stripe_pi = ?,
        pagador_email = COALESCE(pagador_email, ?) WHERE id = ? AND estado != 'pagado'");
    $st->execute([date('c'), $sesion['id'] ?? null, is_string($sesion['payment_intent'] ?? null) ? $sesion['payment_intent'] : null,
        $sesion['customer_details']['email'] ?? null, $pagoId]);
    if ($st->rowCount() === 1) {
        $p = $db->prepare('SELECT * FROM pagos WHERE id = ?');
        $p->execute([$pagoId]);
        try { nv_emails_pago($p->fetch()); } catch (Throwable $e) { error_log('Novis email pago: ' . $e->getMessage()); }
    }
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

function nv_sesion(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/pagos/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',   // Lax para poder volver desde Stripe y Google con la sesión iniciada
    ]);
    session_name('novis_cliente');
    session_start();
    // Cierre automático tras 2 horas sin actividad
    if (!empty($_SESSION['uid']) && ($_SESSION['visto'] ?? 0) < time() - 7200) { $_SESSION = []; session_regenerate_id(true); }
    $_SESSION['visto'] = time();
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
