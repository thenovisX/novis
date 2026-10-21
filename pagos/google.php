<?php
// Novis — inicio de sesión con Google (OAuth 2.0). Sin scripts de Google en la web.
require_once __DIR__ . '/../lib/cuentas.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
nv_sesion();

function volver_con(string $msg, string $tipo = 'err'): void {
    $_SESSION['flash'] = [$tipo, $msg];
    header('Location: ./');
    exit;
}

if (!nv_google_ok()) volver_con('El acceso con Google no está disponible en este momento.');

// 1) Ida: enviar al usuario a Google
if (!isset($_GET['code']) && !isset($_GET['error'])) {
    $_SESSION['g_state'] = bin2hex(random_bytes(16));
    $_SESSION['g_nonce'] = bin2hex(random_bytes(16));
    header('Location: ' . nv_google_url($_SESSION['g_state'], $_SESSION['g_nonce']));
    exit;
}

// 2) Vuelta desde Google
$state = (string)($_GET['state'] ?? '');
$esperado = (string)($_SESSION['g_state'] ?? '');
$nonce = (string)($_SESSION['g_nonce'] ?? '');
unset($_SESSION['g_state'], $_SESSION['g_nonce']);

if (isset($_GET['error'])) volver_con('Has cancelado el acceso con Google.', 'info');
if ($esperado === '' || !hash_equals($esperado, $state)) volver_con('La sesión ha caducado. Vuelve a intentarlo.');

$g = nv_google_usuario((string)$_GET['code'], $nonce);
if (!$g) volver_con('No hemos podido iniciar sesión con Google. Inténtalo de nuevo.');
if (!$g['verificado']) volver_con('Tu cuenta de Google no tiene el email verificado.');

$db = nv_db();
// El identificador de Google se guarda solo como huella con clave (sirve para reconocer la cuenta, no para leerlo)
$huellaSub = nv_indice($g['sub']);
$st = $db->prepare('SELECT * FROM usuarios WHERE google_sub = ?');
$st->execute([$huellaSub]);
$u = $st->fetch();

if (!$u) {
    $st = $db->prepare('SELECT * FROM usuarios WHERE email_idx = ?');
    $st->execute([nv_indice($g['email'])]);
    $u = $st->fetch();
    if ($u) {
        // Ya existía una cuenta con ese email: se vincula a Google.
        // Si esa cuenta nunca confirmó el email, se borra su contraseña por seguridad
        // (podría haberla creado otra persona con este email).
        $db->prepare('UPDATE usuarios SET google_sub = ?, email_verificado = COALESCE(email_verificado, ?),
            password_hash = CASE WHEN email_verificado IS NULL THEN NULL ELSE password_hash END WHERE id = ?')
           ->execute([$huellaSub, date('c'), $u['id']]);
        nv_auditar('google_vinculado', (int)$u['id']);
    } else {
        $db->prepare('INSERT INTO usuarios (email, email_idx, google_sub, email_verificado, creado) VALUES (?, ?, ?, ?, ?)')
           ->execute([nv_cifrar($g['email']), nv_indice($g['email']), $huellaSub, date('c'), date('c')]);
        $u = ['id' => (int)$db->lastInsertId()];
        nv_auditar('alta_google', $u['id']);
    }
}

nv_entrar((int)$u['id']);
header('Location: ./');
exit;
