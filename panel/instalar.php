<?php
// The Novis — asistente de instalación del panel.
//  1. Pide los datos de la base de datos MySQL de Hostinger, comprueba que funcionan, crea las tablas
//     y copia los datos que ya había (cuentas, pagos, mensajes…) desde la base de datos SQLite de antes.
//  2. Guarda la conexión en novis-db.php, fuera de public_html y con permisos privados (nunca en GitHub).
//  3. Pide tu información (nombre, correo) y el usuario y la contraseña del panel.
// Solo puede usarlo quien ya ha entrado en el panel o, en una instalación nueva, quien tiene el código
// del archivo novis-instalar.txt (en el Administrador de archivos de hPanel, fuera de public_html).
require_once __DIR__ . '/../lib/cuentas.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

nv_panel_sesion();

function ir_a(string $q = ''): void { header('Location: instalar.php' . ($q ? '?' . $q : '')); exit; }

$instalado = novis_db_config() !== null;
$cambiar = !empty($_GET['cambiar']) || !empty($_POST['cambiar']);
$hayAdmin = nv_panel_configurado();
$paso = (string)($_GET['paso'] ?? '');
$error = '';
$errores = [];
$viejo = [];
$manual = null;   // texto de novis-db.php si no se pudo escribir solo

// ---------- ¿Quién puede usar el asistente? ----------
$codigoArchivo = NOVIS_PRIVATE . '/novis-instalar.txt';
if ($hayAdmin && empty($_SESSION['ok'])) { header('Location: ./'); exit; }
$puedeEntrar = !empty($_SESSION['ok']) || !empty($_SESSION['instalar_ok']);
if (!$hayAdmin && !$puedeEntrar) {
    // Instalación nueva: hace falta el código del archivo privado (así nadie de fuera puede adelantarse)
    if (!is_file($codigoArchivo)) @file_put_contents($codigoArchivo, strtoupper(bin2hex(random_bytes(5))) . "\n", LOCK_EX);
    @chmod($codigoArchivo, 0600);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'codigo') {
        $bueno = trim((string)@file_get_contents($codigoArchivo));
        if (!novis_limite('instalar-codigo', 8, 900)) $error = 'Demasiados intentos. Espera un rato.';
        elseif ($bueno !== '' && nv_csrf_ok() && hash_equals($bueno, strtoupper(trim((string)($_POST['codigo'] ?? ''))))) {
            session_regenerate_id(true);
            $_SESSION['instalar_ok'] = true;
            ir_a($instalado ? 'paso=admin' : '');
        } else { sleep(1); $error = 'El código no es correcto.'; }
    }
    $paso = 'codigo';
} elseif ($instalado && !$cambiar && !in_array($paso, ['admin', 'listo'], true)) {
    ir_a($hayAdmin ? 'paso=listo' : 'paso=admin');
}

// ---------- Paso 1: conectar la base de datos ----------
if ($paso === '' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'conectar') {
    $viejo = $_POST;
    $c = [
        'host' => trim((string)($_POST['host'] ?? '')) ?: 'localhost',
        'puerto' => (int)($_POST['puerto'] ?? 3306) ?: 3306,
        'nombre' => trim((string)($_POST['nombre'] ?? '')),
        'usuario' => trim((string)($_POST['usuario'] ?? '')),
        'clave' => (string)($_POST['clave'] ?? ''),
    ];
    if (!nv_csrf_ok()) $error = 'La página ha caducado. Inténtalo de nuevo.';
    elseif (!novis_limite('instalar', 20, 900)) $error = 'Demasiados intentos. Espera unos minutos.';
    else {
        if (!preg_match('/^[A-Za-z0-9._:-]{1,190}$/', $c['host'])) $errores['host'] = 'Escribe solo el nombre del servidor (normalmente «localhost»).';
        if ($c['puerto'] < 1 || $c['puerto'] > 65535) $errores['puerto'] = 'Puerto no válido (normalmente 3306).';
        if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $c['nombre'])) $errores['nombre'] = 'Escribe el nombre completo de la base de datos, p. ej. u123456789_novis.';
        if (!preg_match('/^[A-Za-z0-9_.$-]{1,80}$/', $c['usuario'])) $errores['usuario'] = 'Escribe el usuario completo, p. ej. u123456789_admin.';
        if ($c['clave'] === '') $errores['clave'] = 'Escribe la contraseña de la base de datos.';
    }
    if (!$error && !$errores) {
        try {
            $my = novis_mysql_conectar($c);
        } catch (Throwable $e) {
            error_log('Novis instalar (conectar): ' . $e->getMessage());
            $error = novis_mysql_error($e);
        }
        if (isset($my)) {
            try {
                novis_esquema_mysql($my);
                // Si esta base de datos ya se usó, tiene que ser con la misma clave de cifrado
                $cent = $my->query("SELECT valor FROM ajustes WHERE clave = 'centinela'")->fetchColumn();
                if ($cent && (nv_clave_leer() === null || nv_descifrar($cent) !== 'novis-ok')) {
                    $error = 'Esta base de datos ya tiene datos guardados con otra clave de cifrado. Usa una base de datos vacía o la clave con la que se crearon.';
                } else {
                    $copiadas = null;
                    $sqlite = novis_sqlite_ruta();
                    if (!$instalado && $sqlite && !novis_mysql_tiene_datos($my)) {
                        if (nv_clave_leer() === null) throw new RuntimeException('Falta la clave de cifrado de los datos actuales.');
                        $copiadas = novis_migrar_sqlite($my, $sqlite);
                    }
                    $_SESSION['instalar_resumen'] = ['copiadas' => $copiadas, 'base' => $c['nombre'], 'cuando' => date('c')];
                    if (novis_db_config_guardar($c)) {
                        novis_db_config(true);
                        ir_a(nv_ajuste('panel_hash') ? 'paso=listo' : 'paso=admin');
                    }
                    // La carpeta no deja escribir: se enseña el archivo para crearlo a mano
                    $manual = novis_db_config_texto($c);
                }
            } catch (Throwable $e) {
                error_log('Novis instalar (tablas/copia): ' . $e->getMessage());
                $error = 'Se conectó con la base de datos, pero no se pudieron preparar las tablas o copiar los datos. No se ha cambiado nada: la web sigue funcionando como antes. Detalle: ' . mb_substr($e->getMessage(), 0, 160);
            }
        }
    }
}

// ---------- Paso 2: tu información y el acceso al panel ----------
if ($paso === 'admin') {
    if (!novis_db_config()) ir_a();
    $cred = nv_panel_credenciales();
    $haySinBd = $cred['origen'] === 'config' && nv_panel_configurado();   // ya entras con el usuario de gemini-config.php
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'admin') {
        $viejo = $_POST;
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $usuario = trim((string)($_POST['usuario'] ?? ''));
        $p1 = (string)($_POST['password'] ?? '');
        $p2 = (string)($_POST['password2'] ?? '');
        if (!nv_csrf_ok()) $error = 'La página ha caducado. Inténtalo de nuevo.';
        if (mb_strlen($nombre) > 80) $errores['nombre'] = 'Máximo 80 caracteres.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errores['email'] = 'Escribe un email válido.';
        if (!preg_match('/^[\p{L}0-9._@-]{3,40}$/u', $usuario)) $errores['usuario'] = 'Entre 3 y 40 letras o números (sin espacios).';
        if ($p1 === '' && !$haySinBd) $errores['password'] = 'Elige una contraseña.';
        elseif ($p1 !== '' && mb_strlen($p1) < 10) $errores['password'] = 'Usa al menos 10 caracteres (mejor una frase).';
        elseif ($p1 !== $p2) $errores['password2'] = 'Las contraseñas no coinciden.';
        if ($p1 === '' && $haySinBd && mb_strtolower($usuario) !== mb_strtolower($cred['usuario'])) $errores['password'] = 'Para cambiar el usuario, elige también la contraseña.';
        if (!$error && !$errores) {
            $guardar = ['nombre' => $nombre, 'email' => $email, 'negocio' => nv_ajuste('negocio', 'The Novis')];
            if ($p1 !== '') $guardar += ['panel_usuario' => $usuario, 'panel_hash' => nv_hash($p1), 'panel_cambio' => (string)time()];
            nv_ajustes_guardar($guardar);
            session_regenerate_id(true);
            $_SESSION['ok'] = true;
            $_SESSION['desde'] = $_SESSION['visto'] = time();
            unset($_SESSION['instalar_ok']);
            @unlink($codigoArchivo);
            ir_a('paso=listo');
        }
    }
}

if ($paso === 'listo') {
    if (!novis_db_config()) ir_a();
    if (!nv_panel_configurado()) ir_a('paso=admin');
    unset($_SESSION['instalar_ok']);
    @unlink($codigoArchivo);
    $resumen = $_SESSION['instalar_resumen'] ?? null;
}

$v = fn($k, $def = '') => e($viejo[$k] ?? $def);
$err = fn($k) => isset($errores[$k]) ? '<p class="f-err">' . e($errores[$k]) . '</p>' : '';
const NOMBRES_TABLA = ['usuarios' => 'cuentas de clientes', 'pagos' => 'pagos', 'solicitudes' => 'mensajes y solicitudes', 'respuestas' => 'respuestas',
    'renovaciones' => 'renovaciones', 'auditoria' => 'registros de auditoría', 'incidentes' => 'incidentes', 'productos' => 'planes'];
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Instalación · The Novis</title>
<link rel="icon" href="../assets/favicon.svg?v=10" type="image/svg+xml">
<meta name="theme-color" content="#f5f5f7">
<link rel="stylesheet" href="panel.css?v=21">
</head>
<body class="v-pagos">
  <header class="top top--detail"><?php if (!empty($_SESSION['ok']) && $instalado): ?><a href="ajustes.php" class="back">‹ Ajustes</a><?php else: ?><span class="back muted">The Novis · instalación</span><?php endif; ?></header>
  <main class="detail">

<?php if ($paso === 'codigo'): ?>
    <form method="post" class="card form-pago" novalidate>
      <span class="logo" aria-hidden="true"></span>
      <h1>Primera instalación</h1>
      <p class="muted">Para que nadie más pueda configurar tu web, abre en hPanel el <strong>Administrador de archivos</strong>, entra en la carpeta que contiene <code>public_html</code> y abre el archivo <code>novis-instalar.txt</code>. Copia aquí el código que hay dentro.</p>
      <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
      <label>Código de instalación
        <input name="codigo" autocomplete="off" autocapitalize="characters" spellcheck="false" required autofocus></label>
      <?php if ($error): ?><p class="f-err"><?= e($error) ?></p><?php endif; ?>
      <button class="btn" name="accion" value="codigo">Continuar</button>
    </form>

<?php elseif ($paso === ''): ?>
    <?php if ($manual): ?>
    <section class="card">
      <h1>Falta un paso</h1>
      <p class="muted">La conexión funciona y las tablas están listas, pero el servidor no deja crear el archivo de configuración. Créalo tú en un minuto:</p>
      <ol class="pasos">
        <li>En hPanel abre el <strong>Administrador de archivos</strong> y entra en la carpeta que contiene <code>public_html</code> (no dentro de ella).</li>
        <li>Crea un archivo nuevo llamado <code>novis-db.php</code> y pega dentro este texto:</li>
      </ol>
      <textarea class="msgbox" rows="11" readonly id="dbcfg"><?= e($manual) ?></textarea>
      <div class="actions"><button class="btn btn--ghost" type="button" data-copy-from="dbcfg" data-copied="Copiado ✓">Copiar texto</button></div>
      <ol class="pasos" start="3">
        <li>Guárdalo y ponle permisos <strong>600</strong> (clic derecho → Permisos).</li>
        <li>Vuelve aquí y pulsa «Ya lo he creado».</li>
      </ol>
      <div class="actions"><a class="btn" href="instalar.php?paso=admin">Ya lo he creado</a></div>
    </section>
    <?php else: ?>
    <form method="post" class="card form-pago" novalidate autocomplete="off">
      <span class="logo" aria-hidden="true"></span>
      <h1><?= $instalado ? 'Cambiar la base de datos' : 'Conecta tu base de datos' ?></h1>
      <p class="muted"><?= $instalado
          ? 'Escribe los datos de la nueva base de datos MySQL. Ojo: los datos no se copian de una base de datos MySQL a otra; usa esto solo si has cambiado la contraseña o el usuario en Hostinger.'
          : 'Desde ahora las cuentas de clientes, los pagos, los mensajes, tus planes y tus ajustes se guardarán en la base de datos MySQL de Hostinger. Solo hay que hacerlo una vez.' ?></p>
      <details class="ayuda">
        <summary>¿Dónde encuentro estos datos?</summary>
        <ol class="pasos">
          <li>Entra en <strong>hPanel → Sitios web → Administrar → Bases de datos → Administración</strong>.</li>
          <li>Si aún no tienes ninguna, crea una en «Crear una nueva base de datos MySQL»: escribe un nombre (p. ej. <code>novis</code>), un usuario y una contraseña segura, y pulsa <strong>Crear</strong>.</li>
          <li>En la lista verás el <strong>nombre de la base de datos</strong> y el <strong>usuario</strong> completos (empiezan por <code>u</code> y unos números, p. ej. <code>u123456789_novis</code>). La contraseña es la que elegiste.</li>
          <li>El servidor es <strong>localhost</strong> y el puerto, <strong>3306</strong>.</li>
        </ol>
      </details>
      <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
      <?php if ($cambiar): ?><input type="hidden" name="cambiar" value="1"><?php endif; ?>
      <label>Nombre de la base de datos
        <input name="nombre" required placeholder="u123456789_novis" autocapitalize="none" spellcheck="false" value="<?= $v('nombre') ?>"></label><?= $err('nombre') ?>
      <label>Usuario
        <input name="usuario" required placeholder="u123456789_admin" autocapitalize="none" spellcheck="false" value="<?= $v('usuario') ?>"></label><?= $err('usuario') ?>
      <label>Contraseña de la base de datos
        <input name="clave" type="password" required autocomplete="new-password"></label><?= $err('clave') ?>
      <div class="dos">
        <label>Servidor <span class="muted">(host)</span>
          <input name="host" required value="<?= $v('host', 'localhost') ?>" autocapitalize="none" spellcheck="false"></label>
        <label>Puerto
          <input name="puerto" inputmode="numeric" value="<?= $v('puerto', '3306') ?>"></label>
      </div><?= $err('host') ?><?= $err('puerto') ?>
      <?php if ($error): ?><p class="warn"><?= e($error) ?></p><?php endif; ?>
      <?php if (!$instalado && novis_sqlite_ruta()): ?><p class="muted small">Se copiarán a MySQL todos los datos que ya tienes (cuentas, pagos, mensajes…). Siguen cifrados igual que ahora. Si algo falla, no se cambia nada.</p><?php endif; ?>
      <button class="btn" name="accion" value="conectar">Probar y conectar</button>
      <p class="muted small">La contraseña se guarda en un archivo privado fuera de la parte pública de la web (<code>novis-db.php</code>). Nunca se sube a GitHub.</p>
    </form>
    <?php endif; ?>

<?php elseif ($paso === 'admin'): ?>
    <form method="post" class="card form-pago" novalidate>
      <p class="toast" role="status">Base de datos conectada ✓</p>
      <h1>Tu información</h1>
      <p class="muted">Tu correo es donde te llegan los avisos (mensajes nuevos, pagos…) y el que ven tus clientes. Lo podrás cambiar cuando quieras en el panel → Ajustes.</p>
      <input type="hidden" name="csrf" value="<?= e(nv_csrf()) ?>">
      <label>Tu nombre <span class="muted">(opcional)</span>
        <input name="nombre" autocomplete="name" value="<?= $v('nombre', (string)nv_ajuste('nombre', '')) ?>"></label><?= $err('nombre') ?>
      <label>Tu correo
        <input name="email" type="email" required autocomplete="email" value="<?= $v('email', nv_email_negocio()) ?>"></label><?= $err('email') ?>
      <h2 class="sub">Acceso al panel</h2>
      <label>Usuario
        <input name="usuario" required autocomplete="username" autocapitalize="none" spellcheck="false" value="<?= $v('usuario', $cred['usuario']) ?>"></label><?= $err('usuario') ?>
      <label>Contraseña <span class="muted"><?= $haySinBd ? '(déjala en blanco para seguir con la de siempre)' : '(mínimo 10 caracteres)' ?></span>
        <input name="password" type="password" autocomplete="new-password"<?= $haySinBd ? '' : ' required' ?>></label><?= $err('password') ?>
      <label>Repite la contraseña
        <input name="password2" type="password" autocomplete="new-password"></label><?= $err('password2') ?>
      <?php if ($error): ?><p class="f-err"><?= e($error) ?></p><?php endif; ?>
      <button class="btn" name="accion" value="admin">Guardar y entrar al panel</button>
    </form>

<?php else: ?>
    <section class="card">
      <span class="logo" aria-hidden="true"></span>
      <h1>¡Listo!</h1>
      <p class="muted">Tu web ya guarda todo en la base de datos MySQL<?= $resumen ? ' <strong>' . e($resumen['base']) . '</strong>' : '' ?>.</p>
      <?php if ($resumen && $resumen['copiadas']): ?>
        <ul class="checks">
          <?php foreach (NOMBRES_TABLA as $t => $txt): if (!isset($resumen['copiadas'][$t])) continue; ?>
            <li class="c-ok"><span class="ic" aria-hidden="true">✓</span><span><strong><?= (int)$resumen['copiadas'][$t] ?> <?= e($txt) ?></strong> copiados</span></li>
          <?php endforeach; ?>
        </ul>
        <p class="muted small">La base de datos antigua (<code>novis-data/solicitudes.sqlite</code>) se queda como copia de seguridad y ya no se usa. Puedes borrarla dentro de unos días, cuando compruebes que todo está bien.</p>
      <?php endif; ?>
      <p class="muted small">En phpMyAdmin verás las tablas, pero los nombres, emails y mensajes aparecen como texto cifrado (<code>nv1:…</code>): es a propósito, para que nadie pueda leerlos aunque entre en la base de datos. En el panel se ven normales.</p>
      <div class="actions"><a class="btn" href="./">Ir al panel</a><a class="btn btn--ghost" href="planes.php">Ver mis planes</a></div>
    </section>
<?php endif; ?>
  </main>
  <script src="panel.js?v=4" defer></script>
</body>
</html>
