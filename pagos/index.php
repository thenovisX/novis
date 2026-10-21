<?php
// Novis — área de clientes: cuenta y pagos con Stripe.
require_once __DIR__ . '/../lib/cuentas.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');
nv_sesion();

$a = preg_replace('/[^a-z-]/', '', is_string($_GET['a'] ?? '') ? ($_GET['a'] ?? '') : '');
$db = nv_db();
$u = nv_usuario();
$errores = [];
$viejo = [];

function ir(string $q = ''): void { header('Location: ./' . ($q ? '?' . $q : '')); exit; }
function aviso(string $m, string $tipo = 'ok'): void { $_SESSION['flash'] = [$tipo, $m]; }
function campo(string $k): string { return trim((string)($_POST[$k] ?? '')); }

/* ---------- Enlaces de los emails (funcionan con o sin sesión) ---------- */
if ($a === 'verificar') {
    $id = nv_token_usar((string)($_GET['t'] ?? ''), 'verificar');
    if ($id) {
        $ver = $db->prepare('UPDATE usuarios SET email_verificado = ? WHERE id = ? AND email_verificado IS NULL');
        $ver->execute([date('c'), $id]);
        if ($ver->rowCount() > 0) nv_auditar('email_verificado', (int)$id);
        nv_entrar($id);
        aviso('Email confirmado. ¡Tu cuenta ya está activa!');
    } else {
        aviso('El enlace no es válido o ha caducado. Inicia sesión para pedir uno nuevo.', 'err');
    }
    ir();
}

/* ---------- Acciones (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!nv_csrf_ok()) { aviso('La página ha caducado. Inténtalo de nuevo.', 'err'); ir($a ? 'a=' . $a : ''); }
    $accion = (string)($_POST['accion'] ?? '');

    // Una cuenta con el email sin confirmar podría ser de otra persona: no puede ver ni tocar nada hasta confirmarlo
    if ($u && !$u['email_verificado'] && !in_array($accion, ['salir', 'reenviar'], true)) {
        aviso('Confirma tu email para continuar.', 'err');
        ir();
    }

    if ($accion === 'salir') {
        $_SESSION = [];
        session_regenerate_id(true);
        aviso('Has cerrado sesión.');
        ir();
    }

    if ($accion === 'registro' && !$u) {
        $viejo = $_POST;
        $email = strtolower(campo('email'));
        $usuario = strtolower(campo('usuario'));
        $tel = preg_replace('/[^\d+]/', '', campo('telefono'));
        $pass = (string)($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errores['email'] = 'Escribe un email válido.';
        if (!preg_match('/^[a-z0-9._-]{3,30}$/', $usuario)) $errores['usuario'] = 'Entre 3 y 30 caracteres: letras, números, punto, guion o guion bajo.';
        if ($tel !== '' && !preg_match('/^\+?\d{9,15}$/', $tel)) $errores['telefono'] = 'Escribe un teléfono válido, por ejemplo 612 345 678, o déjalo vacío.';
        if (mb_strlen($pass) < 10) $errores['password'] = 'La contraseña debe tener al menos 10 caracteres.';
        elseif ($pass !== (string)($_POST['password2'] ?? '')) $errores['password2'] = 'Las contraseñas no coinciden.';
        if (empty($_POST['acepto'])) $errores['acepto'] = 'Debes aceptar las condiciones de contratación y la política de privacidad.';
        // El límite va antes de comprobar si el email o el usuario existen (si no, el formulario serviría para averiguar quién tiene cuenta)
        if (!$errores && !novis_limite('registro', 8, 3600)) $errores['general'] = 'Demasiados intentos. Prueba de nuevo dentro de un rato.';
        if (!$errores) {
            $st = $db->prepare('SELECT email, usuario FROM usuarios WHERE email_idx = ? OR usuario_idx = ?');
            $st->execute([nv_indice($email), nv_indice($usuario)]);
            foreach ($st->fetchAll() as $r) {
                if (strcasecmp($r['email'], $email) === 0) $errores['email'] = 'Ya existe una cuenta con este email. Inicia sesión o recupera tu contraseña.';
                if ($r['usuario'] && strcasecmp($r['usuario'], $usuario) === 0) $errores['usuario'] = 'Este nombre de usuario ya está en uso.';
            }
        }
        if (!$errores) {
            $db->prepare('INSERT INTO usuarios (email, email_idx, usuario, usuario_idx, telefono, password_hash, creado) VALUES (?, ?, ?, ?, ?, ?, ?)')
               ->execute([nv_cifrar($email), nv_indice($email), nv_cifrar($usuario), nv_indice($usuario), $tel === '' ? null : nv_cifrar($tel), password_hash($pass, PASSWORD_DEFAULT), date('c')]);
            $id = (int)$db->lastInsertId();
            nv_auditar('registro_acepta', $id, 'condiciones ' . NV_CONDICIONES . ' + privacidad');
            nv_entrar($id);
            nv_email_verificacion(['id' => $id, 'email' => $email, 'usuario' => $usuario]);
            ir();
        }
        $a = 'registro';
    }

    if ($accion === 'entrar' && !$u) {
        $viejo = $_POST;
        $id_ = strtolower(campo('identificador'));
        $pass = (string)($_POST['password'] ?? '');
        // Límite por IP y también por cuenta (frena a quien prueba contraseñas desde muchas IP distintas)
        if (!novis_limite('login', 10, 900) || !novis_limite('login-cta', 15, 900, $id_)) {
            $errores['general'] = 'Demasiados intentos. Espera 15 minutos.';
        } else {
            $st = $db->prepare('SELECT * FROM usuarios WHERE email_idx = ? OR usuario_idx = ?');
            $st->execute([nv_indice($id_), nv_indice($id_)]);
            $f = $st->fetch();
            // Si la cuenta no existe se hace igualmente una comprobación falsa, para que tarde lo mismo y no delate qué usuarios existen
            if (!$f) password_verify($pass, '$2y$10$4ISSDEyMW6wnv7jNKsoZAu9GsPnuMzwCE6MMIYGgAscLnH/rM3JcC');
            if ($f && !$f['password_hash'] && $f['google_sub']) {
                $errores['general'] = 'Esta cuenta se creó con Google. Usa el botón «Continuar con Google».';
            } elseif ($f && password_verify($pass, (string)$f['password_hash'])) {
                if (password_needs_rehash($f['password_hash'], PASSWORD_DEFAULT)) {
                    $db->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $f['id']]);
                }
                nv_entrar((int)$f['id']);
                ir();
            } else {
                usleep(400000);
                $errores['general'] = 'Usuario o contraseña incorrectos.';
            }
        }
        $a = 'entrar';
    }

    if ($accion === 'olvide' && !$u) {
        $email = strtolower(campo('email'));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && novis_limite('reset', 5, 3600) && novis_limite('reset-cta', 3, 3600, $email)) {
            $st = $db->prepare('SELECT * FROM usuarios WHERE email_idx = ?');
            $st->execute([nv_indice($email)]);
            if ($f = $st->fetch()) nv_email_restablecer($f);
        }
        // Mismo mensaje exista o no la cuenta, para no revelar qué emails están registrados
        aviso('Si hay una cuenta con ese email, te hemos enviado un enlace para crear una contraseña nueva.');
        ir();
    }

    if ($accion === 'restablecer') {
        $pass = (string)($_POST['password'] ?? '');
        $t = (string)($_POST['t'] ?? '');
        if (mb_strlen($pass) < 10) $errores['password'] = 'La contraseña debe tener al menos 10 caracteres.';
        elseif ($pass !== (string)($_POST['password2'] ?? '')) $errores['password2'] = 'Las contraseñas no coinciden.';
        if (!$errores) {
            $id = nv_token_usar($t, 'reset');
            if (!$id) { aviso('El enlace no es válido o ha caducado. Pide uno nuevo.', 'err'); ir('a=olvide'); }
            // Si ha llegado desde el email, el email queda verificado
            $db->prepare('UPDATE usuarios SET password_hash = ?, email_verificado = COALESCE(email_verificado, ?), pass_cambiada = ? WHERE id = ?')
               ->execute([password_hash($pass, PASSWORD_DEFAULT), date('c'), time() - 1, $id]);
            nv_auditar('password_cambiada', (int)$id);
            nv_entrar($id);
            aviso('Contraseña actualizada.');
            ir();
        }
        $a = 'restablecer';
        $_GET['t'] = $t;
    }

    if ($accion === 'reenviar' && $u && !$u['email_verificado']) {
        if (novis_limite('reenviar', 3, 3600)) {
            nv_email_verificacion($u);
            aviso('Te hemos enviado un email nuevo. Revisa también la carpeta de spam.');
        } else {
            aviso('Ya te hemos enviado varios emails. Espera un rato antes de pedir otro.', 'err');
        }
        ir();
    }

    if ($accion === 'perfil' && $u && $u['email_verificado']) {
        $viejo = $_POST;
        $usuario = strtolower(campo('usuario'));
        $tel = preg_replace('/[^\d+]/', '', campo('telefono'));
        if (!preg_match('/^[a-z0-9._-]{3,30}$/', $usuario)) $errores['usuario'] = 'Entre 3 y 30 caracteres: letras, números, punto, guion o guion bajo.';
        if ($tel !== '' && !preg_match('/^\+?\d{9,15}$/', $tel)) $errores['telefono'] = 'Escribe un teléfono válido, por ejemplo 612 345 678, o déjalo vacío.';
        if (empty($_POST['acepto'])) $errores['acepto'] = 'Debes aceptar las condiciones de contratación y la política de privacidad.';
        if (!$errores) {
            $st = $db->prepare('SELECT 1 FROM usuarios WHERE usuario_idx = ? AND id != ?');
            $st->execute([nv_indice($usuario), $u['id']]);
            if ($st->fetch()) $errores['usuario'] = 'Este nombre de usuario ya está en uso.';
        }
        if (!$errores) {
            $db->prepare('UPDATE usuarios SET usuario = ?, usuario_idx = ?, telefono = ? WHERE id = ?')->execute([nv_cifrar($usuario), nv_indice($usuario), $tel === '' ? null : nv_cifrar($tel), $u['id']]);
            nv_auditar('perfil_acepta', (int)$u['id'], 'condiciones ' . NV_CONDICIONES . ' + privacidad');
            ir();
        }
    }

    if ($accion === 'codigo' && $u) {
        $codigo = nv_normalizar_codigo(campo('codigo'));
        $viejo = $_POST;
        if (!novis_limite('codigo', 10, 3600)) {
            $errores['codigo'] = 'Demasiados intentos. Prueba de nuevo dentro de un rato.';
        } else {
            $st = $db->prepare('SELECT * FROM pagos WHERE codigo = ?');
            $st->execute([$codigo]);
            $p = $st->fetch();
            $valido = $p
                && ($p['email_cliente'] === null || $p['email_cliente'] === '' || strcasecmp($p['email_cliente'], $u['email']) === 0)
                && ($p['usuario_id'] === null || (int)$p['usuario_id'] === (int)$u['id']);
            if (!$valido || $p['estado'] === 'cancelado') {
                $errores['codigo'] = 'Este código no es válido. Revisa que esté bien escrito o escríbenos a hola@thenovis.com.';
            } elseif ($p['estado'] === 'pendiente' && $p['caduca'] && $p['caduca'] < date('Y-m-d')) {
                $errores['codigo'] = 'Este código ha caducado. Escríbenos a hola@thenovis.com y te enviamos uno nuevo.';
            } else {
                // El código queda asociado a esta cuenta
                $db->prepare('UPDATE pagos SET usuario_id = ? WHERE id = ? AND usuario_id IS NULL')->execute([$u['id'], $p['id']]);
                ir('a=resumen&id=' . $p['id']);
            }
        }
    }

    if ($accion === 'pagar' && $u) {
        $st = $db->prepare("SELECT * FROM pagos WHERE id = ? AND usuario_id = ?");
        $st->execute([(int)($_POST['id'] ?? 0), $u['id']]);
        $p = $st->fetch();
        if (!$p) ir();
        if ($p['estado'] !== 'pendiente') ir('a=resumen&id=' . $p['id']);
        if (!nv_stripe_ok()) { aviso('Los pagos online aún no están activados. Escríbenos a hola@thenovis.com.', 'err'); ir('a=resumen&id=' . $p['id']); }
        try {
            // Si ya hay una sesión de pago abierta, se reutiliza (evita cobrar dos veces)
            if ($p['stripe_session']) {
                $s = nv_sincronizar($p);
                if ($s && ($s['payment_status'] ?? '') === 'paid') ir('a=exito&id=' . $p['id']);
                if ($s && ($s['status'] ?? '') === 'open' && !empty($s['url'])) { header('Location: ' . $s['url'], true, 303); exit; }
            }
            $sub = nv_es_sub($p);
            $datos = [
                'mode' => $sub ? 'subscription' : 'payment',
                'locale' => 'es',
                'customer_email' => $u['email'],
                'client_reference_id' => (string)$p['id'],
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => array_filter([
                        'currency' => $p['moneda'],
                        'unit_amount' => (int)$p['importe'],
                        // Suscripción: Stripe lo cobrará solo cada mes o cada año
                        'recurring' => $sub ? ['interval' => NV_PERIODOS_STRIPE[$p['periodo']][0], 'interval_count' => NV_PERIODOS_STRIPE[$p['periodo']][1]] : null,
                        'product_data' => array_filter([
                            'name' => mb_substr($p['concepto'], 0, 250),
                            'description' => $p['descripcion'] ? mb_substr($p['descripcion'], 0, 500) : null,
                        ]),
                    ]),
                ]],
                'metadata' => ['pago_id' => (string)$p['id'], 'codigo' => $p['codigo'], 'usuario_id' => (string)$u['id']],
                'success_url' => nv_url('pagos/?a=exito&id=' . $p['id']),
                'cancel_url' => nv_url('pagos/?a=resumen&id=' . $p['id'] . '&cancelado=1'),
            ];
            $ref = ['description' => mb_substr($p['concepto'] . ' (' . $p['codigo'] . ')', 0, 500), 'metadata' => ['pago_id' => (string)$p['id'], 'codigo' => $p['codigo']]];
            if ($sub) $datos['subscription_data'] = $ref; else $datos['payment_intent_data'] = $ref;
            $s = nv_stripe('POST', '/v1/checkout/sessions', $datos);
            $db->prepare('UPDATE pagos SET stripe_session = ?, pagador_email = ?, pagador_idx = ? WHERE id = ?')->execute([$s['id'], nv_cifrar($u['email']), nv_indice($u['email']), $p['id']]);
            header('Location: ' . $s['url'], true, 303);
            exit;
        } catch (Throwable $e) {
            aviso('No hemos podido conectar con el sistema de pago. Inténtalo de nuevo en unos minutos.', 'err');
            ir('a=resumen&id=' . $p['id']);
        }
    }

    // Gestión de una suscripción: darse de baja, mantenerla o cambiar la tarjeta
    if (in_array($accion, ['baja', 'reanudar', 'tarjeta'], true) && $u) {
        $st = $db->prepare("SELECT * FROM pagos WHERE id = ? AND usuario_id = ? AND estado = 'pagado'");
        $st->execute([(int)($_POST['id'] ?? 0), $u['id']]);
        $p = $st->fetch();
        if (!$p || !nv_sub_viva($p)) ir();
        $volver = 'a=resumen&id=' . $p['id'];
        try {
            if ($accion === 'tarjeta') { header('Location: ' . nv_portal_url($p), true, 303); exit; }
            nv_sub_baja($p, $accion === 'baja');
            nv_aviso_sub($p, $accion === 'baja' ? 'baja' : 'reanuda');
            $st->execute([$p['id'], $u['id']]);
            $p = $st->fetch();
            aviso($accion === 'baja'
                ? 'Listo. Tu suscripción seguirá activa hasta el ' . date('d/m/Y', strtotime((string)$p['sub_fin'])) . ' y no se te cobrará nada más.'
                : 'Perfecto, tu suscripción sigue activa.');
        } catch (Throwable $e) {
            aviso($accion === 'tarjeta'
                ? 'Ahora mismo no podemos abrir la gestión de la tarjeta. Escríbenos a hola@thenovis.com y te ayudamos.'
                : 'No hemos podido conectar con el sistema de pago. Inténtalo de nuevo en unos minutos.', 'err');
        }
        ir($volver);
    }

    // Contratar un servicio del catálogo: se crea (o se reutiliza) un pago pendiente y se va a su resumen
    if ($accion === 'contratar' && $u) {
        $k = (string)($_POST['servicio'] ?? '');
        if (!isset(NV_CATALOGO[$k])) ir('a=servicios');
        $c = NV_CATALOGO[$k];
        $st = $db->prepare("SELECT * FROM pagos WHERE usuario_id = ? AND servicio = ? AND estado = 'pagado'");
        $st->execute([$u['id'], $k]);
        foreach ($st->fetchAll() as $x) {
            if (nv_sub_viva($x)) { aviso('Ya tienes «' . $c['nombre'] . '» activo. Si lo necesitas para otra web, escríbenos.', 'info'); ir('a=servicios'); }
        }
        $st = $db->prepare("SELECT id FROM pagos WHERE usuario_id = ? AND servicio = ? AND estado = 'pendiente' ORDER BY id DESC LIMIT 1");
        $st->execute([$u['id'], $k]);
        $id = (int)$st->fetchColumn();
        if (!$id) {
            if (!novis_limite('contratar', 10, 3600)) { aviso('Demasiados intentos. Prueba de nuevo dentro de un rato.', 'err'); ir('a=servicios'); }
            do { $codigo = nv_generar_codigo(); $ya = $db->prepare('SELECT 1 FROM pagos WHERE codigo = ?'); $ya->execute([$codigo]); } while ($ya->fetch());
            $db->prepare('INSERT INTO pagos (codigo, concepto, descripcion, importe, email_cliente, creado, periodo, usuario_id, servicio) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
               ->execute([$codigo, nv_cifrar($c['nombre']), nv_cifrar($c['desc']), nv_con_iva($c['base']), nv_cifrar($u['email']), date('c'), $c['periodo'], $u['id'], $k]);
            $id = (int)$db->lastInsertId();
        }
        ir('a=resumen&id=' . $id);
    }

    // Mensaje nuevo a Novis (aparece en el panel como una solicitud más)
    if ($accion === 'mensaje' && $u) {
        $viejo = $_POST;
        $tema = (string)($_POST['tema'] ?? '');
        $texto = trim((string)($_POST['texto'] ?? ''));
        if (!isset(NV_TEMAS[$tema])) $errores['tema'] = 'Elige un tema.';
        if (mb_strlen($texto) < 5) $errores['texto'] = 'Cuéntanos un poco más.';
        elseif (mb_strlen($texto) > 5000) $errores['texto'] = 'Máximo 5000 caracteres.';
        if (!$errores && !novis_limite('mensaje', 10, 3600)) $errores['texto'] = 'Has enviado muchos mensajes seguidos. Prueba de nuevo dentro de un rato.';
        if (!$errores) {
            $db->prepare("INSERT INTO solicitudes (creado, nombre, empresa, email, email_idx, plan, mensaje, consentimiento) VALUES (?, ?, '', ?, ?, ?, ?, ?)")
               ->execute([date('c'), nv_cifrar($u['usuario']), nv_cifrar($u['email']), nv_indice($u['email']), 'Cliente · ' . NV_TEMAS[$tema], nv_cifrar($texto), date('c')]);
            $mid = (int)$db->lastInsertId();
            novis_mail('hola@thenovis.com', 'Mensaje de cliente: ' . NV_TEMAS[$tema] . ' · ' . $u['usuario'],
                "{$u['usuario']} ({$u['email']}, {$u['telefono']}) te ha escrito desde su área de cliente.\n\nTema: " . NV_TEMAS[$tema] . "\n\n$texto\n\nResponder en el panel: " . nv_url('panel/?id=' . $mid),
                null, $u['email']);
            aviso('Mensaje enviado. Te responderemos aquí y por email, normalmente en menos de 24 horas laborables.');
            ir('a=mensaje&id=' . $mid);
        }
        $a = 'mensajes';
    }

    // El cliente contesta en una conversación
    if ($accion === 'responder' && $u) {
        $st = $db->prepare('SELECT * FROM solicitudes WHERE id = ? AND email_idx = ?');
        $st->execute([(int)($_POST['id'] ?? 0), nv_indice($u['email'])]);
        $m = $st->fetch();
        if (!$m) ir('a=mensajes');
        $texto = trim((string)($_POST['texto'] ?? ''));
        if (mb_strlen($texto) < 2 || mb_strlen($texto) > 5000) { aviso('Escribe tu respuesta (máximo 5000 caracteres).', 'err'); ir('a=mensaje&id=' . $m['id']); }
        if (!novis_limite('responder', 20, 3600)) { aviso('Has enviado muchos mensajes seguidos. Prueba de nuevo dentro de un rato.', 'err'); ir('a=mensaje&id=' . $m['id']); }
        $db->prepare("INSERT INTO respuestas (solicitud_id, autor, texto, fecha) VALUES (?, 'cliente', ?, ?)")->execute([$m['id'], nv_cifrar($texto), date('c')]);
        // Si estaba cerrado se vuelve a abrir, para que Novis lo vea como pendiente
        $db->prepare("UPDATE solicitudes SET estado = CASE WHEN estado IN ('cerrada', 'descartada') THEN 'nueva' ELSE estado END, actualizado = ? WHERE id = ?")->execute([date('c'), $m['id']]);
        novis_mail('hola@thenovis.com', 'Respuesta de cliente: ' . preg_replace('/^Cliente · /', '', (string)$m['plan']) . ' · ' . $u['usuario'],
            "{$u['usuario']} ha contestado:\n\n$texto\n\nVer la conversación: " . nv_url('panel/?id=' . $m['id']), null, $u['email']);
        ir('a=mensaje&id=' . $m['id']);
    }

    // Descargar todos sus datos (RGPD: acceso y portabilidad)
    if ($accion === 'descargar' && $u) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="mis-datos-novis.json"');
        echo nv_datos_json($u['email']);
        exit;
    }

    // Eliminar la cuenta y todos sus datos (RGPD: supresión)
    if ($accion === 'eliminar' && $u) {
        if (strcasecmp(campo('confirmar'), 'ELIMINAR') !== 0) {
            aviso('Para eliminar la cuenta escribe ELIMINAR en la casilla.', 'err');
            ir('a=cuenta');
        }
        nv_borrar_datos($u['email'], 'su área de cliente (' . nv_url('pagos/') . ')');
        $_SESSION = [];
        session_regenerate_id(true);
        aviso('Hemos eliminado tu cuenta y tus datos. Los emails que nos enviaste se borrarán de nuestro buzón en un plazo máximo de 30 días.');
        ir();
    }

    if (!$errores) ir($a ? 'a=' . $a : '');
}

/* ---------- Qué pantalla toca ---------- */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if (!$u) {
    $vista = in_array($a, ['registro', 'olvide', 'restablecer'], true) ? $a : 'entrar';
} elseif (!$u['email_verificado']) {
    $vista = 'pendiente';
} elseif (!$u['usuario']) {
    $vista = 'perfil';
} else {
    $vista = in_array($a, ['resumen', 'exito', 'cuenta', 'servicios', 'mensajes', 'mensaje', 'pagos'], true) ? $a : 'inicio';
    if ($vista === 'resumen' || $vista === 'exito') {
        $st = $db->prepare('SELECT * FROM pagos WHERE id = ? AND usuario_id = ?');
        $st->execute([(int)($_GET['id'] ?? 0), $u['id']]);
        $p = $st->fetch();
        if (!$p) ir();
        if ($p['estado'] === 'pendiente' && $p['stripe_session']) { nv_sincronizar($p); $st->execute([$p['id'], $u['id']]); $p = $st->fetch(); }
        $esSub = nv_es_sub($p);
        if ($vista === 'resumen' && $p['estado'] === 'pagado') {
            if ($esSub) {
                // Estado al día (próxima renovación, baja programada…) y número de cobros
                if (nv_sub_viva($p) && nv_sub_sincronizar($p)) { $st->execute([$p['id'], $u['id']]); $p = $st->fetch(); }
                $r = $db->prepare('SELECT COUNT(*) FROM renovaciones WHERE pago_id = ?');
                $r->execute([$p['id']]);
                $cobros = 1 + (int)$r->fetchColumn();
                $vista = 'suscripcion';
            } else {
                $vista = 'exito';
            }
        }
    }
    // Datos para las pestañas
    $st = $db->prepare('SELECT * FROM pagos WHERE usuario_id = ? ORDER BY id DESC');
    $st->execute([$u['id']]);
    $mis = $st->fetchAll();
    $subs = array_values(array_filter($mis, fn($x) => $x['estado'] === 'pagado' && nv_es_sub($x)));
    $vivas = array_values(array_filter($subs, 'nv_sub_viva'));
    $proyectos = array_values(array_filter($mis, fn($x) => $x['estado'] === 'pagado' && !nv_es_sub($x)));
    $pendientes = array_values(array_filter($mis, fn($x) => $x['estado'] === 'pendiente' && (!$x['caduca'] || $x['caduca'] >= date('Y-m-d'))));
    $contratados = array_flip(array_filter(array_column($vivas, 'servicio')));
    $st = $db->prepare("SELECT s.*, (SELECT COUNT(*) FROM respuestas r WHERE r.solicitud_id = s.id) AS n,
        (SELECT autor FROM respuestas r WHERE r.solicitud_id = s.id ORDER BY r.id DESC LIMIT 1) AS ultimo
        FROM solicitudes s WHERE s.email_idx = ? ORDER BY COALESCE(s.actualizado, s.creado) DESC");
    $st->execute([nv_indice($u['email'])]);
    $mensajes = $st->fetchAll();
    $abiertos = count(array_filter($mensajes, fn($m) => in_array($m['estado'], ['nueva', 'en_curso'], true)));
    if ($vista === 'mensaje') {
        $m = null;
        foreach ($mensajes as $x) if ((int)$x['id'] === (int)($_GET['id'] ?? 0)) $m = $x;
        if (!$m) ir('a=mensajes');
        $hilo = nv_hilo((int)$m['id']);
    }
    // Pestaña marcada en el menú
    $pestana = match ($vista) {
        'resumen', 'exito', 'suscripcion' => (isset($p) && nv_es_sub($p)) ? 'servicios' : 'pagos',
        'mensaje' => 'mensajes',
        default => $vista,
    };
}

$titulos = [
    'entrar' => 'Mi cuenta', 'registro' => 'Crear cuenta', 'olvide' => 'Recuperar contraseña', 'restablecer' => 'Contraseña nueva',
    'pendiente' => 'Confirma tu email', 'perfil' => 'Completa tu perfil', 'inicio' => 'Mi cuenta', 'resumen' => 'Resumen del pago',
    'servicios' => 'Tus servicios', 'mensajes' => 'Mensajes', 'mensaje' => 'Mensaje', 'pagos' => 'Pagos',
    'exito' => 'Pago', 'cuenta' => 'Tu cuenta', 'suscripcion' => 'Tu suscripción',
];
$v = fn(string $k) => e($viejo[$k] ?? '');
function err(array $errores, string $k): string {
    return isset($errores[$k]) ? '<p class="f-err" id="err-' . $k . '">' . e($errores[$k]) . '</p>' : '';
}
function inv(array $errores, string $k): string {
    return isset($errores[$k]) ? ' aria-invalid="true" aria-describedby="err-' . $k . '"' : '';
}
$google = nv_google_ok();
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($titulos[$vista]) ?> — Novis</title>
  <link rel="icon" href="../assets/favicon.svg?v=10" type="image/svg+xml">
  <link rel="apple-touch-icon" href="../assets/apple-touch-icon.png?v=10">
  <link rel="preload" href="../assets/fonts/geist-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="../css/styles.css?v=25">
  <link rel="stylesheet" href="pagos.css?v=5">
</head>
<body class="acc-page">
  <a href="#contenido" class="skip">Saltar al contenido</a>
  <header class="nav is-scrolled">
    <div class="nav__inner">
      <a href="../" class="logo" aria-label="Novis, volver a la web"><span class="logo__mark"></span>novis</a>
      <?php if ($u): ?>
        <form method="post" class="acc-user">
          <span><?= e($u['usuario'] ?: $u['email']) ?></span>
          <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
          <button class="btn btn--ghost btn--small" name="accion" value="salir">Cerrar sesión</button>
        </form>
      <?php else: ?>
        <a href="../" class="link-back">Volver a la web</a>
      <?php endif; ?>
    </div>
  </header>

  <main id="contenido" tabindex="-1" class="acc acc--<?= e($vista) ?>">
    <?php if ($flash): ?><p class="acc-flash acc-flash--<?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></p><?php endif; ?>
    <?php if (!empty($errores['general'])): ?><p class="acc-flash acc-flash--err" role="alert"><?= e($errores['general']) ?></p><?php endif; ?>
    <?php if (isset($pestana)): ?>
      <nav class="acc-tabs" aria-label="Tu cuenta">
        <?php foreach (['inicio' => 'Inicio', 'servicios' => 'Servicios', 'mensajes' => 'Mensajes', 'pagos' => 'Pagos', 'cuenta' => 'Cuenta'] as $k => $t): ?>
          <a href="<?= $k === 'inicio' ? './' : '?a=' . $k ?>"<?= $pestana === $k ? ' aria-current="page"' : '' ?>><?= $t ?><?php if ($k === 'pagos' && $pendientes): ?> <span class="acc-tabs__n" aria-label="(<?= count($pendientes) ?> pendientes)"><?= count($pendientes) ?></span><?php endif; ?></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>

<?php if ($vista === 'entrar'): ?>
    <div class="acc-card">
      <h1>Mi cuenta</h1>
      <p class="acc-lead">Entra para ver tus servicios, hacer pagos y escribirnos para cambios en tu web.</p>
      <?php if ($google): ?>
        <a class="btn btn--ghost acc-google" href="google.php">Continuar con Google</a>
        <p class="acc-or"><span>o con tu usuario</span></p>
      <?php endif; ?>
      <form method="post" class="acc-form" novalidate>
        <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
        <div class="f"><label for="identificador">Email o nombre de usuario</label>
          <input id="identificador" name="identificador" autocomplete="username" autocapitalize="none" required value="<?= $v('identificador') ?>"></div>
        <div class="f"><label for="password">Contraseña</label>
          <input id="password" name="password" type="password" autocomplete="current-password" required></div>
        <button class="btn" name="accion" value="entrar">Iniciar sesión</button>
      </form>
      <p class="acc-links"><a href="?a=olvide">He olvidado mi contraseña</a></p>
      <p class="acc-alt">¿Aún no tienes cuenta? <a href="?a=registro">Crear cuenta</a></p>
    </div>

<?php elseif ($vista === 'registro'): ?>
    <div class="acc-card">
      <h1>Crear cuenta</h1>
      <p class="acc-lead">Con tu cuenta puedes pagar de forma segura, contratar servicios y hablar con nosotros.</p>
      <?php if ($google): ?>
        <a class="btn btn--ghost acc-google" href="google.php">Continuar con Google</a>
        <p class="acc-or"><span>o con tu email</span></p>
      <?php endif; ?>
      <form method="post" class="acc-form" novalidate>
        <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
        <div class="f"><label for="email">Email</label>
          <input id="email" name="email" type="email" autocomplete="email" required value="<?= $v('email') ?>"<?= inv($errores, 'email') ?>><?= err($errores, 'email') ?></div>
        <div class="f"><label for="telefono">Teléfono <span class="f-hint">(opcional)</span></label>
          <input id="telefono" name="telefono" type="tel" autocomplete="tel" value="<?= $v('telefono') ?>"<?= inv($errores, 'telefono') ?>><?= err($errores, 'telefono') ?></div>
        <div class="f"><label for="usuario">Nombre de usuario</label>
          <input id="usuario" name="usuario" autocomplete="username" autocapitalize="none" required value="<?= $v('usuario') ?>"<?= inv($errores, 'usuario') ?>><?= err($errores, 'usuario') ?></div>
        <div class="f"><label for="password">Contraseña <span class="f-hint">(mínimo 10 caracteres)</span></label>
          <input id="password" name="password" type="password" autocomplete="new-password" minlength="10" required<?= inv($errores, 'password') ?>><?= err($errores, 'password') ?></div>
        <div class="f"><label for="password2">Repite la contraseña</label>
          <input id="password2" name="password2" type="password" autocomplete="new-password" required<?= inv($errores, 'password2') ?>><?= err($errores, 'password2') ?></div>
        <label class="f-check"><input type="checkbox" name="acepto" value="1"<?= !empty($viejo['acepto']) ? ' checked' : '' ?><?= inv($errores, 'acepto') ?>>
          <span>He leído y acepto las <a href="../condiciones.html" target="_blank" rel="noopener">condiciones de contratación</a> y la <a href="../privacidad.html#cuentas" target="_blank" rel="noopener">política de privacidad</a>.</span></label>
        <?= err($errores, 'acepto') ?>
        <button class="btn" name="accion" value="registro">Crear cuenta</button>
      </form>
      <p class="acc-alt">¿Ya tienes cuenta? <a href="./">Iniciar sesión</a></p>
    </div>

<?php elseif ($vista === 'olvide'): ?>
    <div class="acc-card">
      <h1>Recuperar contraseña</h1>
      <p class="acc-lead">Escribe el email de tu cuenta y te enviaremos un enlace para crear una contraseña nueva.</p>
      <form method="post" class="acc-form" novalidate>
        <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
        <div class="f"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="email" required></div>
        <button class="btn" name="accion" value="olvide">Enviar enlace</button>
      </form>
      <p class="acc-alt"><a href="./">Volver a iniciar sesión</a></p>
    </div>

<?php elseif ($vista === 'restablecer'): ?>
    <div class="acc-card">
      <h1>Contraseña nueva</h1>
      <form method="post" class="acc-form" novalidate>
        <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
        <input type="hidden" name="t" value="<?= e(is_string($_GET['t'] ?? '') ? ($_GET['t'] ?? '') : '') ?>">
        <div class="f"><label for="password">Contraseña nueva <span class="f-hint">(mínimo 10 caracteres)</span></label>
          <input id="password" name="password" type="password" autocomplete="new-password" minlength="10" required<?= inv($errores, 'password') ?>><?= err($errores, 'password') ?></div>
        <div class="f"><label for="password2">Repite la contraseña</label>
          <input id="password2" name="password2" type="password" autocomplete="new-password" required<?= inv($errores, 'password2') ?>><?= err($errores, 'password2') ?></div>
        <button class="btn" name="accion" value="restablecer">Guardar contraseña</button>
      </form>
    </div>

<?php elseif ($vista === 'pendiente'): ?>
    <div class="acc-card">
      <div class="acc-icon acc-icon--mail" aria-hidden="true"></div>
      <h1>Confirma tu email</h1>
      <p class="acc-lead">Te hemos enviado un enlace a <strong><?= e($u['email']) ?></strong>. Ábrelo para activar tu cuenta. Si no lo ves, revisa la carpeta de spam.</p>
      <form method="post" class="acc-form">
        <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
        <button class="btn btn--ghost" name="accion" value="reenviar">Enviar el email otra vez</button>
      </form>
    </div>

<?php elseif ($vista === 'perfil'): ?>
    <div class="acc-card">
      <h1>Completa tu perfil</h1>
      <p class="acc-lead">Solo falta tu nombre de usuario y un teléfono de contacto.</p>
      <form method="post" class="acc-form" novalidate>
        <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
        <div class="f"><label for="usuario">Nombre de usuario</label>
          <input id="usuario" name="usuario" autocomplete="username" autocapitalize="none" required value="<?= e($viejo['usuario'] ?? $u['usuario'] ?? '') ?>"<?= inv($errores, 'usuario') ?>><?= err($errores, 'usuario') ?></div>
        <div class="f"><label for="telefono">Teléfono <span class="f-hint">(opcional)</span></label>
          <input id="telefono" name="telefono" type="tel" autocomplete="tel" value="<?= e($viejo['telefono'] ?? $u['telefono'] ?? '') ?>"<?= inv($errores, 'telefono') ?>><?= err($errores, 'telefono') ?></div>
        <label class="f-check"><input type="checkbox" name="acepto" value="1"<?= inv($errores, 'acepto') ?>>
          <span>He leído y acepto las <a href="../condiciones.html" target="_blank" rel="noopener">condiciones de contratación</a> y la <a href="../privacidad.html#cuentas" target="_blank" rel="noopener">política de privacidad</a>.</span></label>
        <?= err($errores, 'acepto') ?>
        <button class="btn" name="accion" value="perfil">Continuar</button>
      </form>
    </div>

<?php elseif ($vista === 'inicio'): ?>
    <div class="acc-wide">
      <h1>Hola, <?= e($u['usuario']) ?></h1>
      <div class="acc-tiles">
        <a class="acc-tile" href="?a=servicios"><strong><?= count($vivas) ?></strong><span>servicio<?= count($vivas) === 1 ? '' : 's' ?> activo<?= count($vivas) === 1 ? '' : 's' ?></span></a>
        <a class="acc-tile<?= $pendientes ? ' acc-tile--alerta' : '' ?>" href="?a=pagos"><strong><?= e(nv_euros(array_sum(array_map(fn($x) => (int)$x['importe'], $pendientes)))) ?></strong><span>pendiente de pago</span></a>
        <a class="acc-tile" href="?a=mensajes"><strong><?= $abiertos ?></strong><span>mensaje<?= $abiertos === 1 ? '' : 's' ?> abierto<?= $abiertos === 1 ? '' : 's' ?></span></a>
      </div>

      <?php if ($pendientes): ?>
        <section class="acc-list" aria-labelledby="t-pend">
          <h2 id="t-pend">Pendiente de pago</h2>
          <ul>
            <?php foreach ($pendientes as $p): ?>
              <li>
                <a href="?a=resumen&amp;id=<?= (int)$p['id'] ?>">
                  <span class="acc-list__main"><strong><?= e($p['concepto']) ?></strong><small><?= e($p['codigo']) ?><?= $p['pagado_en'] ? ' · ' . e(date('d/m/Y', strtotime($p['pagado_en']))) : '' ?></small></span>
                  <span class="acc-list__amount"><?= e(nv_importe_txt($p)) ?></span>
                  <?php [$cl, $tx] = nv_estado($p); ?><span class="pill pill--<?= e($cl) ?>"><?= e($tx) ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <div class="acc-acciones">
        <a class="acc-accion" href="?a=mensajes&amp;tema=cambio#nuevo"><span class="acc-accion__ico acc-accion__ico--msg" aria-hidden="true"></span><strong>Pedir un cambio en mi web</strong><small>Textos, fotos, precios… Te respondemos aquí.</small></a>
        <a class="acc-accion" href="?a=servicios#contratar"><span class="acc-accion__ico acc-accion__ico--add" aria-hidden="true"></span><strong>Contratar un servicio</strong><small>Mantenimiento, alojamiento o una web nueva.</small></a>
      </div>

      <section class="acc-card acc-card--code" aria-labelledby="t-codigo">
        <h2 id="t-codigo">¿Tienes un código de pago?</h2>
        <p class="acc-lead">Es el código que te hemos enviado, con el formato NOV-XXXX-XXXX.</p>
        <form method="post" class="acc-code" novalidate>
          <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
          <label for="codigo" class="sr">Código de pago</label>
          <input id="codigo" name="codigo" placeholder="NOV-XXXX-XXXX" autocomplete="off" autocapitalize="characters" spellcheck="false" required value="<?= $v('codigo') ?>"<?= inv($errores, 'codigo') ?>>
          <button class="btn" name="accion" value="codigo">Continuar</button>
        </form>
        <?= err($errores, 'codigo') ?>
      </section>
    </div>

<?php elseif ($vista === 'servicios'): ?>
    <div class="acc-wide">
      <h1>Tus servicios</h1>
      <section class="acc-list" aria-labelledby="t-serv">
        <h2 id="t-serv">Contratados</h2>
        <?php if (!$subs && !$proyectos): ?>
          <p class="acc-empty">Todavía no tienes servicios contratados.</p>
        <?php else: ?>
          <ul>
            <?php foreach (array_merge($subs, $proyectos) as $p): ?>
              <li>
                <a href="?a=resumen&amp;id=<?= (int)$p['id'] ?>">
                  <span class="acc-list__main"><strong><?= e($p['concepto']) ?></strong>
                    <small><?php if (nv_sub_viva($p) && $p['sub_fin']): ?><?= $p['sub_cancela'] ? 'Termina el ' : 'Se renueva el ' ?><?= e(date('d/m/Y', strtotime($p['sub_fin']))) ?><?php elseif (nv_sub_viva($p)): ?><?= e(NV_PERIODOS_TXT[$p['periodo']]) ?><?php elseif (nv_es_sub($p)): ?>Suscripción terminada<?php else: ?>Pagado el <?= e(date('d/m/Y', strtotime((string)$p['pagado_en']))) ?><?php endif; ?></small></span>
                  <span class="acc-list__amount"><?= e(nv_importe_txt($p)) ?></span>
                  <?php [$cl, $tx] = nv_estado($p); ?><span class="pill pill--<?= e($cl) ?>"><?= e($cl === 'pendiente' && nv_es_sub($p) ? 'Baja programada' : $tx) ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

      <section id="contratar" aria-labelledby="t-contratar">
        <h2 id="t-contratar" class="acc-h2">Contratar</h2>
        <div class="acc-catalogo">
          <?php foreach (NV_CATALOGO as $k => $c): $tiene = isset($contratados[$k]); ?>
            <article class="acc-prod<?= $tiene ? ' is-tiene' : '' ?>">
              <h3><?= e($c['nombre']) ?></h3>
              <p class="acc-prod__precio"><strong><?= e(rtrim(rtrim(number_format($c['base'] / 100, 2, ',', '.'), '0'), ',')) ?> €</strong> + IVA / <?= e(NV_PERIODOS[$c['periodo']]) ?></p>
              <p class="acc-prod__iva"><?= e(nv_euros(nv_con_iva($c['base']))) ?> con IVA</p>
              <p class="acc-prod__desc"><?= e($c['desc']) ?></p>
              <?php if ($tiene): ?>
                <p class="acc-prod__ya">Ya lo tienes activo</p>
              <?php else: ?>
                <form method="post"><input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>"><input type="hidden" name="servicio" value="<?= e($k) ?>">
                  <button class="btn" name="accion" value="contratar">Contratar</button></form>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
          <article class="acc-prod acc-prod--medida">
            <h3>Una web nueva o algo a medida</h3>
            <p class="acc-prod__desc">Otra web, una tienda online, más secciones, un rediseño… Cuéntanos qué necesitas y te enviamos un presupuesto cerrado.</p>
            <a class="btn btn--ghost" href="?a=mensajes&amp;tema=nuevo#nuevo">Pedir presupuesto</a>
          </article>
        </div>
        <p class="acc-secure">Sin permanencia: puedes darte de baja de cualquier suscripción cuando quieras desde esta página.</p>
      </section>
    </div>

<?php elseif ($vista === 'mensajes'): ?>
    <div class="acc-wide">
      <h1>Mensajes</h1>
      <section class="acc-card acc-card--code" id="nuevo" aria-labelledby="t-nuevo">
        <h2 id="t-nuevo">Escríbenos</h2>
        <p class="acc-lead">Para cambios en tu web, contratar algo nuevo o cualquier duda. Te respondemos aquí y por email, normalmente en menos de 24 horas laborables.</p>
        <form method="post" class="acc-form" novalidate>
          <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
          <div class="f"><label for="tema">Tema</label>
            <select id="tema" name="tema"<?= inv($errores, 'tema') ?>>
              <?php $temaSel = $viejo['tema'] ?? ($_GET['tema'] ?? 'cambio'); foreach (NV_TEMAS as $k => $t): ?>
                <option value="<?= e($k) ?>"<?= $temaSel === $k ? ' selected' : '' ?>><?= e($t) ?></option>
              <?php endforeach; ?>
            </select><?= err($errores, 'tema') ?></div>
          <div class="f"><label for="texto">Mensaje</label>
            <textarea id="texto" name="texto" rows="5" required maxlength="5000" placeholder="Por ejemplo: cambiar el horario de la página de contacto a L-V de 9 a 14"<?= inv($errores, 'texto') ?>><?= $v('texto') ?></textarea><?= err($errores, 'texto') ?></div>
          <button class="btn" name="accion" value="mensaje">Enviar mensaje</button>
        </form>
      </section>

      <section class="acc-list" aria-labelledby="t-hist">
        <h2 id="t-hist">Tus conversaciones</h2>
        <?php if (!$mensajes): ?>
          <p class="acc-empty">Aquí aparecerán los mensajes que nos envíes.</p>
        <?php else: ?>
          <ul>
            <?php foreach ($mensajes as $m): ?>
              <li>
                <a href="?a=mensaje&amp;id=<?= (int)$m['id'] ?>">
                  <span class="acc-list__main"><strong><?= e(preg_replace('/^Cliente · /', '', (string)($m['plan'] ?: 'Formulario de contacto'))) ?></strong>
                    <small><?= e(mb_strimwidth($m['mensaje'], 0, 80, '…')) ?></small></span>
                  <?php if ($m['ultimo'] === 'novis'): ?><span class="pill pill--nuevo">Respuesta de Novis</span><?php endif; ?>
                  <span class="pill pill--msg-<?= e($m['estado']) ?>"><?= e(NV_ESTADO_MSG[$m['estado']] ?? $m['estado']) ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    </div>

<?php elseif ($vista === 'mensaje'): ?>
    <div class="acc-card acc-card--wide">
      <p class="acc-back"><a href="?a=mensajes">‹ Mensajes</a></p>
      <span class="pill pill--msg-<?= e($m['estado']) ?>"><?= e(NV_ESTADO_MSG[$m['estado']] ?? $m['estado']) ?></span>
      <h1 class="acc-subtitle"><?= e(preg_replace('/^Cliente · /', '', (string)($m['plan'] ?: 'Formulario de contacto'))) ?></h1>
      <ol class="hilo">
        <li class="hilo__msg hilo__msg--cliente"><p class="hilo__meta">Tú · <?= e(date('d/m/Y H:i', strtotime($m['creado']))) ?></p><p><?= nl2br(e($m['mensaje'])) ?></p></li>
        <?php foreach ($hilo as $r): ?>
          <li class="hilo__msg hilo__msg--<?= $r['autor'] === 'novis' ? 'novis' : 'cliente' ?>"><p class="hilo__meta"><?= $r['autor'] === 'novis' ? 'Novis' : 'Tú' ?> · <?= e(date('d/m/Y H:i', strtotime($r['fecha']))) ?></p><p><?= nl2br(e($r['texto'])) ?></p></li>
        <?php endforeach; ?>
      </ol>
      <?php if (!$hilo): ?><p class="acc-secure datos-nota">Hemos recibido tu mensaje. Te responderemos aquí y te avisaremos por email.</p><?php endif; ?>
      <form method="post" class="acc-form">
        <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
        <div class="f"><label for="resp">Añadir un mensaje</label><textarea id="resp" name="texto" rows="3" required maxlength="5000"></textarea></div>
        <button class="btn" name="accion" value="responder">Enviar</button>
      </form>
    </div>

<?php elseif ($vista === 'pagos'): ?>
    <div class="acc-wide">
      <h1>Pagos</h1>
      <section class="acc-card acc-card--code" aria-labelledby="t-codigo">
        <h2 id="t-codigo">¿Tienes un código de pago?</h2>
        <p class="acc-lead">Es el código que te hemos enviado, con el formato NOV-XXXX-XXXX.</p>
        <form method="post" class="acc-code" novalidate>
          <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
          <label for="codigo" class="sr">Código de pago</label>
          <input id="codigo" name="codigo" placeholder="NOV-XXXX-XXXX" autocomplete="off" autocapitalize="characters" spellcheck="false" required value="<?= $v('codigo') ?>"<?= inv($errores, 'codigo') ?>>
          <button class="btn" name="accion" value="codigo">Continuar</button>
        </form>
        <?= err($errores, 'codigo') ?>
      </section>
      <section class="acc-list" aria-labelledby="t-mis">
        <h2 id="t-mis">Historial</h2>
        <?php if (!$mis): ?>
          <p class="acc-empty">Aquí aparecerán tus pagos.</p>
        <?php else: ?>
          <ul>
            <?php foreach ($mis as $p): ?>
              <li>
                <a href="?a=resumen&amp;id=<?= (int)$p['id'] ?>">
                  <span class="acc-list__main"><strong><?= e($p['concepto']) ?></strong><small><?= e($p['codigo']) ?><?= $p['pagado_en'] ? ' · ' . e(date('d/m/Y', strtotime($p['pagado_en']))) : '' ?></small></span>
                  <span class="acc-list__amount"><?= e(nv_importe_txt($p)) ?></span>
                  <?php [$cl, $tx] = nv_estado($p); ?><span class="pill pill--<?= e($cl) ?>"><?= e($tx) ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
      <p class="acc-secure">Los pagos se hacen con Stripe. Novis nunca ve ni guarda los datos de tu tarjeta. Si necesitas una factura, <a href="?a=mensajes&amp;tema=pagos#nuevo">escríbenos</a>.</p>
    </div>

<?php elseif ($vista === 'resumen'): ?>
    <div class="acc-card acc-card--wide">
      <p class="acc-back"><a href="?a=<?= e($pestana) ?>">‹ <?= $pestana === 'servicios' ? 'Tus servicios' : 'Pagos' ?></a></p>
      <?php if (!empty($_GET['cancelado'])): ?><p class="acc-flash acc-flash--info" role="status">Has cancelado el pago. Puedes intentarlo de nuevo cuando quieras.</p><?php endif; ?>
      <h1><?= e($p['concepto']) ?></h1>
      <?php if ($p['descripcion']): ?><p class="acc-desc"><?= nl2br(e($p['descripcion'])) ?></p><?php endif; ?>
      <dl class="acc-sum">
        <div><dt>Código</dt><dd><?= e($p['codigo']) ?></dd></div>
        <?php if ($p['caduca']): ?><div><dt>Válido hasta</dt><dd><?= e(date('d/m/Y', strtotime($p['caduca']))) ?></dd></div><?php endif; ?>
        <?php if ($esSub): ?><div><dt>Tipo</dt><dd><?= e(NV_PERIODOS_TXT[$p['periodo']]) ?>, sin permanencia</dd></div><?php endif; ?>
        <div class="acc-sum__total"><dt><?= $esSub ? 'Cuota' : 'Total a pagar' ?></dt><dd><?= e(nv_importe_txt($p)) ?></dd></div>
      </dl>
      <?php if ($esSub && $p['estado'] !== 'cancelado'): ?>
        <div class="acc-subinfo">
          <p><strong>Se renueva sola cada <?= e(NV_PERIODOS[$p['periodo']]) ?>.</strong> Te cobraremos <?= e(nv_euros((int)$p['importe'])) ?> hoy y después cada <?= e(NV_PERIODOS[$p['periodo']]) ?>, y te avisaremos por email de cada cobro.</p>
          <p><strong>Sin permanencia.</strong> Puedes darte de baja cuando quieras desde esta página: sigue activa hasta el final del periodo pagado y no se te cobra nada más.</p>
        </div>
      <?php endif; ?>
      <?php if ($p['estado'] === 'cancelado'): ?>
        <p class="acc-flash acc-flash--err">Este pago se ha anulado. Si crees que es un error, escríbenos a hola@thenovis.com.</p>
      <?php else: ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button class="btn acc-pay" name="accion" value="pagar"><?= $esSub ? 'Suscribirme · ' . e(nv_importe_txt($p)) : 'Pagar ' . e(nv_euros((int)$p['importe'])) ?></button>
        </form>
        <?php if ($esSub): ?><p class="acc-secure">Al suscribirte autorizas a Novis a cobrar esta cuota cada <?= e(NV_PERIODOS[$p['periodo']]) ?> en tu tarjeta hasta que te des de baja.</p><?php endif; ?>
        <p class="acc-secure">Pago seguro con Stripe: tarjeta, Apple Pay o Google Pay. Novis nunca ve ni guarda los datos de tu tarjeta.</p>
      <?php endif; ?>
    </div>

<?php elseif ($vista === 'exito'): ?>
    <div class="acc-card">
      <?php if ($p['estado'] === 'pagado'): ?>
        <div class="acc-icon acc-icon--ok" aria-hidden="true"></div>
        <?php if ($esSub): ?>
          <h1>¡Suscripción activada!</h1>
          <p class="acc-lead">Hemos cobrado la primera cuota de «<?= e($p['concepto']) ?>» (<strong><?= e(nv_importe_txt($p)) ?></strong>). Se renovará sola y puedes darte de baja cuando quieras.</p>
          <p><a class="btn btn--ghost" href="?a=resumen&amp;id=<?= (int)$p['id'] ?>">Gestionar mi suscripción</a></p>
        <?php else: ?>
        <h1>¡Pago completado!</h1>
        <p class="acc-lead">Hemos recibido <strong><?= e(nv_euros((int)$p['importe'])) ?></strong> por «<?= e($p['concepto']) ?>». Te hemos enviado la confirmación por email.</p>
        <?php endif; ?>
      <?php else: ?>
        <div class="acc-icon acc-icon--wait" aria-hidden="true"></div>
        <h1>Estamos confirmando tu pago</h1>
        <p class="acc-lead">Stripe nos está enviando la confirmación. Puede tardar unos segundos; recibirás un email en cuanto esté listo.</p>
        <p><a class="btn btn--ghost" href="?a=exito&amp;id=<?= (int)$p['id'] ?>">Actualizar</a></p>
      <?php endif; ?>
      <p class="acc-alt"><a href="?a=<?= e($pestana) ?>"><?= $pestana === 'servicios' ? 'Ver tus servicios' : 'Ver tus pagos' ?></a></p>
    </div>

<?php elseif ($vista === 'suscripcion'):
    [$cl, $tx] = nv_estado($p);
    $fin = $p['sub_fin'] ? date('d/m/Y', strtotime($p['sub_fin'])) : '';
    $viva = nv_sub_viva($p); ?>
    <div class="acc-card acc-card--wide">
      <p class="acc-back"><a href="?a=servicios">‹ Tus servicios</a></p>
      <span class="pill pill--<?= e($cl) ?>"><?= e($tx) ?></span>
      <h1 class="acc-subtitle"><?= e($p['concepto']) ?></h1>
      <?php if ($p['descripcion']): ?><p class="acc-desc"><?= nl2br(e($p['descripcion'])) ?></p><?php endif; ?>
      <dl class="acc-sum">
        <div><dt>Cuota</dt><dd><?= e(nv_importe_txt($p)) ?></dd></div>
        <?php if ($fin && $viva): ?><div><dt><?= $p['sub_cancela'] ? 'Activa hasta' : 'Próxima renovación' ?></dt><dd><?= e($fin) ?></dd></div><?php endif; ?>
        <div><dt>Cuotas pagadas</dt><dd><?= (int)$cobros ?></dd></div>
        <div><dt>Referencia</dt><dd><?= e($p['codigo']) ?></dd></div>
      </dl>

      <?php if ($cl === 'error'): ?>
        <p class="acc-flash acc-flash--err">No hemos podido cobrar la última cuota. Actualiza tu tarjeta para que el servicio siga activo; Stripe lo volverá a intentar en los próximos días.</p>
      <?php elseif (!$viva): ?>
        <p class="acc-flash acc-flash--info">Esta suscripción ha terminado y ya no se cobrará más. Si quieres volver, escríbenos a hola@thenovis.com.</p>
      <?php elseif ($p['sub_cancela']): ?>
        <p class="acc-flash acc-flash--info">Te has dado de baja. Seguirá activa hasta el <?= e($fin) ?> y no se te cobrará nada más.</p>
      <?php endif; ?>

      <?php if ($viva): ?>
        <div class="acc-subactions">
          <?php if ($p['sub_cancela']): ?>
            <form method="post"><input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="btn" name="accion" value="reanudar">Mantener mi suscripción</button></form>
          <?php endif; ?>
          <?php if ($p['stripe_customer']): ?>
            <form method="post"><input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="btn <?= $cl === 'error' ? '' : 'btn--ghost' ?>" name="accion" value="tarjeta">Cambiar tarjeta o ver facturas</button></form>
          <?php endif; ?>
        </div>
        <?php if (!$p['sub_cancela']): ?>
          <details class="acc-danger">
            <summary>Darme de baja</summary>
            <p>Sin permanencia ni penalización. Seguirá activa hasta el <?= e($fin ?: 'final del periodo pagado') ?> y después no se te cobrará nada más. Puedes cambiar de idea antes de esa fecha.</p>
            <form method="post" class="acc-form">
              <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="btn btn--danger" name="accion" value="baja">Confirmar la baja</button>
            </form>
          </details>
        <?php endif; ?>
      <?php endif; ?>
    </div>

<?php elseif ($vista === 'cuenta'): ?>
    <div class="acc-card acc-card--wide">
      <h1>Tu cuenta</h1>
      <dl class="acc-sum">
        <div><dt>Usuario</dt><dd><?= e($u['usuario']) ?></dd></div>
        <div><dt>Email</dt><dd><?= e($u['email']) ?></dd></div>
        <div><dt>Teléfono</dt><dd><?= e($u['telefono'] ?: '—') ?></dd></div>
        <div><dt>Acceso con Google</dt><dd><?= $u['google_sub'] ? 'Vinculado' : 'No' ?></dd></div>
        <div><dt>Cliente desde</dt><dd><?= e(date('d/m/Y', strtotime($u['creado']))) ?></dd></div>
      </dl>
      <p class="acc-desc">¿Algún dato ha cambiado? <a href="?a=mensajes&amp;tema=otro#nuevo">Escríbenos</a> y lo actualizamos.</p>

      <h2 class="datos-h">Tus datos</h2>
      <p class="acc-desc">Descarga una copia de todo lo que guardamos sobre ti: cuenta, pagos, servicios y mensajes.</p>
      <form method="post" class="datos-actions">
        <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
        <button class="btn btn--ghost" name="accion" value="descargar">Descargar mis datos</button>
      </form>

      <details class="acc-danger">
        <summary>Eliminar mi cuenta y mis datos</summary>
        <p>Se borrarán tu cuenta y tus mensajes<?php if ($vivas): ?>, y <strong>tus suscripciones se cancelarán en el momento</strong><?php endif; ?>. Los pagos realizados se conservan el tiempo que exige la ley (6 años), sin vincularlos a ti. No se puede deshacer.</p>
        <form method="post" class="acc-form">
          <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
          <div class="f"><label for="confirmar">Escribe ELIMINAR para confirmar</label><input id="confirmar" name="confirmar" autocomplete="off" required></div>
          <button class="btn btn--danger" name="accion" value="eliminar">Eliminar mi cuenta y mis datos</button>
        </form>
      </details>
    </div>
<?php endif; ?>
  </main>

  <footer class="footer">
    <div class="footer__inner">
      <div class="footer__brand"><a href="../" class="logo"><span class="logo__mark"></span>novis</a></div>
      <nav aria-label="Información legal">
        <a href="../aviso-legal.html">Aviso legal</a>
        <a href="../privacidad.html">Privacidad</a>
        <a href="../cookies.html">Cookies</a>
      </nav>
      <p class="footer__copy">¿Dudas? <a href="mailto:hola@thenovis.com">hola@thenovis.com</a></p>
    </div>
  </footer>
</body>
</html>
