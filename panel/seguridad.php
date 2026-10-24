<?php
// The Novis — panel: seguridad.
//  · Estado del cifrado de los datos y de la protección de la base de datos.
//  · Comprobación de que ningún archivo interno se puede abrir desde internet.
//  · Copia de la clave de cifrado (hay que guardarla: sin ella los datos no se pueden leer).
//  · Contraseña de administrador cifrada (hash bcrypt) para gemini-config.php.
require_once __DIR__ . '/../lib/cuentas.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

nv_panel_sesion();
if (empty($_SESSION['ok'])) { header('Location: ./'); exit; }

function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

$config = novis_config();
$db = nv_db();
$yaHash = trim((string)($config['panel_password_hash'] ?? '')) !== '' || password_get_info((string)($config['panel_password'] ?? ''))['algo'] !== null;
$linea = null;          // línea cifrada de la contraseña
$claveVista = null;     // clave de cifrado (solo tras escribir la contraseña)
$sondeo = null;         // resultado de la comprobación de archivos
$error = '';
$aviso = '';
$seccionError = '';

/** Pide una dirección pública del sitio y devuelve el código HTTP (0 si no hubo respuesta). */
function sondear(string $ruta): int {
    $ch = curl_init(nv_url($ruta));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false, CURLOPT_RANGE => '0-255']);
    curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $codigo;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? 'hash');
    if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
        $error = 'La página ha caducado. Inténtalo de nuevo.';
    } elseif ($accion === 'hash') {
        $seccionError = 'hash';
        $p1 = (string)($_POST['password'] ?? '');
        if (mb_strlen($p1) < 12) $error = 'Usa al menos 12 caracteres (mejor una frase larga).';
        elseif ($p1 !== (string)($_POST['password2'] ?? '')) $error = 'Las contraseñas no coinciden.';
        else $linea = "'panel_password_hash' => '" . password_hash($p1, PASSWORD_DEFAULT) . "',";
    } elseif ($accion === 'clave') {
        $seccionError = 'clave';
        if (!novis_limite('clave-panel', 5, 900)) $error = 'Demasiados intentos. Espera un rato.';
        elseif (!nv_panel_clave_ok((string)($_POST['password'] ?? ''))) { sleep(1); $error = 'Contraseña incorrecta.'; }
        else $claveVista = base64_encode(nv_clave());
    } elseif ($accion === 'probar') {
        $rutas = [
            'lib/cuentas.php' => 'Código interno de la aplicación',
            'lib/cifrado.php' => 'Código del cifrado',
            'gemini-config.php' => 'Archivo de claves y contraseñas',
            'gemini-config.example.php' => 'Ejemplo de configuración',
            'novis-clave.key' => 'Clave de cifrado',
            'novis-data/solicitudes.sqlite' => 'Base de datos',
            '.git/config' => 'Repositorio de código',
            'README.md' => 'Documentación interna',
            '.htaccess' => 'Reglas del servidor',
        ];
        $sondeo = [];
        foreach ($rutas as $r => $nombre) $sondeo[] = ['ruta' => $r, 'nombre' => $nombre, 'codigo' => sondear($r)];
    } elseif ($accion === 'cifrar') {
        $n = 0;
        foreach (array_keys(NOVIS_CIFRADO) as $t) $n += novis_cifrar_tabla($db, $t, true);
        $aviso = $n ? "Se han cifrado $n registros que estaban sin cifrar." : 'Todo estaba ya cifrado.';
    }
}

// ---------- Estado ----------
$publica = rtrim((string)realpath(NOVIS_PUBLIC), '/');
$raiz = rtrim((string)realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
$datos = rtrim((string)realpath(novis_dir()), '/');
$fueraDePublic = $datos !== '' && !str_starts_with($datos . '/', $publica . '/') && ($raiz === '' || !str_starts_with($datos . '/', $raiz . '/'));
$archivoDb = $datos . '/solicitudes.sqlite';
$permDir = (fileperms($datos) & 0077) === 0;
$permDb = is_file($archivoDb) && (fileperms($archivoDb) & 0077) === 0;
$sinCifrar = novis_sin_cifrar($db);
$totalSin = array_sum(array_map(fn($n) => max(0, $n), $sinCifrar));
$origen = nv_clave_origen();
$sodium = function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt');
$cfgArchivo = NOVIS_PRIVATE . '/gemini-config.php';
$cfgPrivado = is_file($cfgArchivo) && (fileperms($cfgArchivo) & 0004) === 0;
$https = nv_https();

/** estado (ok | aviso | mal), título y detalle */
// PHP2Shell (CVE-2026-109618 / 109619, 21-10-2026): versiones que ya traen el parche
$phpParches = ['8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'];
$phpRama = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
if (isset($phpParches[$phpRama])) {
    $phpOk = version_compare(PHP_VERSION, $phpParches[$phpRama], '>=');
} else {
    $phpOk = version_compare($phpRama, '8.5', '>');   // 8.6+ ya sale corregida; 8.1 o anterior ya no tiene soporte
}
$progresoOff = !filter_var(ini_get('session.upload_progress.enabled'), FILTER_VALIDATE_BOOLEAN);
$phpDetalle = $phpOk
    ? 'PHP ' . PHP_VERSION . ': ya incluye el parche de PHP2Shell.'
    : 'PHP ' . PHP_VERSION . ': NO incluye el parche de PHP2Shell (fallo grave publicado el 21-10-2026). '
      . (isset($phpParches[$phpRama]) ? 'Hace falta ' . $phpParches[$phpRama] . ' o posterior. ' : 'Esta versión ya no recibe parches: cambia a PHP 8.4 o 8.5. ')
      . 'En hPanel → Avanzado → Configuración de PHP elige la versión más nueva; si sigue igual, pídeselo al soporte de Hostinger (puede que hayan aplicado el parche sin cambiar el número: confírmalo con ellos).';
$phpDetalle .= $progresoOff ? ' Protección extra activa (barra de progreso de subidas desactivada).' : ' Falta la protección extra: la barra de progreso de subidas sigue activa.';

$comprobaciones = [
    [$fueraDePublic ? 'ok' : 'mal', 'La base de datos está fuera de la parte pública',
        $fueraDePublic ? 'Nadie puede descargarla ni abrirla desde internet: no tiene dirección web.' : 'Está dentro de la carpeta pública. Mueve la carpeta public_html para que no contenga a novis-data.'],
    [$permDir && $permDb ? 'ok' : 'aviso', 'Permisos privados',
        $permDir && $permDb ? 'La carpeta de datos y la base de datos solo las puede leer la cuenta del servidor.' : 'Otros usuarios del servidor podrían leer la carpeta de datos. Si sigue así tras recargar, pon permisos 700 a novis-data en el Administrador de archivos.'],
    [$totalSin === 0 ? 'ok' : 'mal', 'Datos personales cifrados',
        $totalSin === 0 ? 'Nombres, emails, teléfonos, mensajes y notas están cifrados con ' . ($sodium ? 'XChaCha20-Poly1305' : 'AES-256-GCM') . '. En el archivo de la base de datos solo hay texto ilegible.'
                        : "Quedan $totalSin datos sin cifrar. Pulsa «Cifrar lo que falte»."],
    [$origen === 'config' ? 'ok' : 'aviso', 'La clave de cifrado',
        $origen === 'config' ? 'Está en gemini-config.php (data_key), separada de la base de datos.'
            : 'Está en el archivo novis-clave.key, fuera de la parte pública. Guarda una copia más abajo: si se pierde, los datos no se pueden recuperar.'],
    [$yaHash ? 'ok' : 'aviso', 'Contraseña del panel',
        $yaHash ? 'Guardada cifrada (hash): ni viendo el archivo de configuración se puede deducir.' : 'Está escrita tal cual en gemini-config.php. Cámbiala por su versión cifrada más abajo.'],
    [$cfgPrivado ? 'ok' : 'aviso', 'Permisos de gemini-config.php',
        $cfgPrivado ? 'Otros usuarios del servidor no pueden leerlo.' : 'Cualquier usuario del servidor podría leerlo. En el Administrador de archivos de hPanel ponle permisos 600 (si el sitio dejara de funcionar, vuelve a 644).'],
    [$phpOk ? ($progresoOff ? 'ok' : 'aviso') : 'mal', 'Versión de PHP', $phpDetalle],
    [$https ? 'ok' : 'mal', 'Conexión cifrada (HTTPS)', $https ? 'Esta visita llegó por HTTPS.' : 'Esta visita NO llegó por HTTPS.'],
];
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Seguridad · The Novis</title>
<link rel="icon" href="../assets/favicon.svg?v=10" type="image/svg+xml">
<meta name="theme-color" content="#f5f5f7">
<link rel="stylesheet" href="panel.css?v=19">
</head>
<body class="v-pagos">
  <header class="top top--detail"><a href="./" class="back">‹ Solicitudes</a></header>
  <main class="detail">
    <section class="card">
      <h1>Seguridad</h1>
      <?php if ($aviso): ?><p class="toast" role="status"><?= e($aviso) ?></p><?php endif; ?>
      <ul class="checks">
        <?php foreach ($comprobaciones as [$estado, $titulo, $detalle]): ?>
          <li class="c-<?= e($estado) ?>"><span class="ic" aria-hidden="true"><?= $estado === 'ok' ? '✓' : ($estado === 'aviso' ? '!' : '✕') ?></span>
            <span><strong><?= e($titulo) ?></strong> <span class="vh"><?= $estado === 'ok' ? '(correcto)' : ($estado === 'aviso' ? '(mejorable)' : '(corregir)') ?></span><small><?= e($detalle) ?></small></span></li>
        <?php endforeach; ?>
      </ul>
      <?php if ($totalSin > 0): ?>
        <form method="post" class="actions">
          <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
          <button class="btn" name="accion" value="cifrar">Cifrar lo que falte</button>
        </form>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>¿Crees que ha habido una brecha?</h2>
      <p class="muted">Si alguien ha podido entrar, ver o llevarse datos, sigue el protocolo paso a paso: contener, evaluar, avisar a quien corresponda en 72 h y dejarlo registrado.</p>
      <div class="actions"><a class="btn btn--ghost" href="brecha.php">Abrir el protocolo y el registro de incidentes</a></div>
    </section>

    <section class="card">
      <h2>¿Se puede abrir algo desde internet?</h2>
      <p class="muted">Pide a tu propia web, desde fuera, los archivos internos más delicados. Todos deben dar «no encontrado» o «prohibido».</p>
      <?php if ($sondeo): ?>
        <ul class="checks">
          <?php foreach ($sondeo as $s): $ok = in_array($s['codigo'], [403, 404, 410], true); $sin = $s['codigo'] === 0; ?>
            <li class="c-<?= $ok ? 'ok' : ($sin ? 'aviso' : 'mal') ?>"><span class="ic" aria-hidden="true"><?= $ok ? '✓' : ($sin ? '?' : '✕') ?></span>
              <span><strong><?= e($s['nombre']) ?></strong> <code><?= e($s['ruta']) ?></code>
              <small><?= $ok ? 'Protegido (respuesta ' . (int)$s['codigo'] . ').' : ($sin ? 'No se pudo comprobar.' : 'ABIERTO: respondió ' . (int)$s['codigo'] . '. Hay que corregirlo.') ?></small></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <form method="post" class="actions">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <button class="btn btn--ghost" name="accion" value="probar"><?= $sondeo ? 'Comprobar otra vez' : 'Comprobar ahora' ?></button>
      </form>
    </section>

    <section class="card">
      <h2>Copia de la clave de cifrado</h2>
      <?php if ($claveVista): ?>
        <p class="warn">Guárdala en un gestor de contraseñas o impresa en un lugar seguro. <strong>No la envíes por email ni la pegues en un chat.</strong> Con ella se pueden leer todos los datos; sin ella (si se pierde y no hay copia) no se pueden recuperar.</p>
        <textarea class="msgbox" rows="2" readonly id="clave"><?= e($claveVista) ?></textarea>
        <div class="actions"><button class="btn" type="button" data-copy-from="clave" data-copied="Copiada ✓">Copiar clave</button></div>
        <?php if ($origen !== 'config'): ?>
          <p class="muted small">Opcional, más seguro: añade en <code>gemini-config.php</code> la línea <code>'data_key' =&gt; '<?= e($claveVista) ?>',</code> y borra después el archivo <code>novis-clave.key</code>. Así la clave deja de estar junto a la carpeta de datos. Usa exactamente la misma clave o los datos quedarán ilegibles.</p>
        <?php endif; ?>
      <?php else: ?>
        <p class="muted">Todos los datos personales de la base de datos están cifrados con una clave que vive fuera de ella. Para verla, escribe la contraseña del panel.</p>
        <form method="post" class="form-pago" novalidate>
          <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
          <input type="hidden" name="accion" value="clave">
          <label>Contraseña del panel
            <input name="password" type="password" autocomplete="current-password" required></label>
          <?php if ($error && $seccionError === 'clave'): ?><p class="f-err"><?= e($error) ?></p><?php endif; ?>
          <button class="btn btn--ghost">Ver la clave</button>
        </form>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Contraseña del panel</h2>
      <?php if ($yaHash): ?>
        <p class="toast" role="status">La contraseña del panel ya está guardada cifrada ✓</p>
      <?php else: ?>
        <p class="muted">Ahora mismo la contraseña del panel está escrita tal cual en <code>gemini-config.php</code>. Con este paso se guarda solo su versión cifrada: aunque alguien viera el archivo, no podría entrar.</p>
      <?php endif; ?>

      <?php if ($linea): ?>
        <ol class="pasos">
          <li>Copia esta línea:</li>
        </ol>
        <textarea class="msgbox" rows="4" readonly id="linea"><?= e($linea) ?></textarea>
        <div class="actions"><button class="btn" type="button" data-copy-from="linea" data-copied="Copiado ✓">Copiar línea</button></div>
        <ol class="pasos" start="2">
          <li>En hPanel abre el <strong>Administrador de archivos</strong>, entra en la carpeta que contiene <code>public_html</code> y edita <code>gemini-config.php</code>.</li>
          <li><strong>Borra</strong> la línea <code>'panel_password' =&gt; '…'</code> y pega en su lugar la línea de arriba. Guarda.</li>
          <li>Cierra sesión y entra de nuevo con tu contraseña de siempre para comprobar que funciona.</li>
        </ol>
        <p class="muted small">Esta línea no se guarda en ningún sitio: si cierras esta página, genera otra. Si la copias a <code>gemini-config.php</code> sin borrar <code>panel_password</code>, el panel usará el cifrado y ignorará la otra, pero conviene borrarla.</p>
      <?php else: ?>
        <form method="post" class="form-pago" novalidate>
          <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
          <input type="hidden" name="accion" value="hash">
          <label><?= $yaHash ? 'Contraseña nueva' : 'Contraseña del panel' ?> <span class="muted">(la actual, o una nueva; mínimo 12 caracteres)</span>
            <input name="password" type="password" autocomplete="new-password" required></label>
          <label>Repítela
            <input name="password2" type="password" autocomplete="new-password" required></label>
          <?php if ($error && $seccionError !== 'clave'): ?><p class="f-err"><?= e($error) ?></p><?php endif; ?>
          <button class="btn">Generar línea cifrada</button>
        </form>
      <?php endif; ?>
    </section>
  </main>
  <script src="panel.js?v=3" defer></script>
</body>
</html>
