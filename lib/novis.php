<?php
// Novis — utilidades compartidas (configuración y base de datos).
// Esta carpeta está bloqueada al público con .htaccess.

date_default_timezone_set('Europe/Madrid');

// Los errores van al registro del servidor, nunca a la pantalla del visitante (rutas, consultas…)
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
// Todo archivo que cree la aplicación es privado: solo lo lee quien ejecuta PHP
umask(0077);

define('NOVIS_PUBLIC', dirname(__DIR__));          // public_html
define('NOVIS_PRIVATE', dirname(NOVIS_PUBLIC));    // carpeta que contiene public_html

require_once __DIR__ . '/cifrado.php';
require_once __DIR__ . '/mysql.php';

/**
 * Si algo falla sin control, el visitante ve un aviso sencillo (nunca una página en blanco ni detalles internos)
 * y el error queda anotado en novis-data/errores.log, que se puede abrir desde el Administrador de archivos de hPanel.
 */
set_exception_handler(function (Throwable $e) {
    $linea = date('c') . ' ' . get_class($e) . ': ' . $e->getMessage() . ' en ' . basename($e->getFile()) . ':' . $e->getLine()
        . ' (' . ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') . ")\n";
    error_log('Novis: ' . trim($linea));
    try {
        $log = NOVIS_PRIVATE . '/novis-data/errores.log';
        if (is_dir(dirname($log))) {
            if (is_file($log) && filesize($log) > 200000) @rename($log, $log . '.1');
            @file_put_contents($log, $linea, FILE_APPEND | LOCK_EX);
        }
    } catch (Throwable $x) {}
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $linea); exit(1); }
    if (!headers_sent()) {
        http_response_code(503);
        header('Cache-Control: no-store');
        foreach (headers_list() as $h) {
            if (stripos($h, 'content-type: application/json') === 0) { echo json_encode(['ok' => false, 'error' => 'Ahora mismo no está disponible. Inténtalo en unos minutos.']); exit; }
        }
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Novis</title>'
        . '<div style="font:16px/1.5 system-ui,sans-serif;max-width:480px;margin:15vh auto;padding:0 20px;color:#1d1d1f">'
        . '<h1 style="font-size:24px">Ahora mismo no está disponible</h1><p>Ha ocurrido un error. Vuelve a intentarlo en unos minutos o escríbenos a <a href="mailto:hola@thenovis.com">hola@thenovis.com</a>.</p></div>';
    exit;
});

/** Lee gemini-config.php (fuera de public_html). */
function novis_config(): array {
    static $config = null;
    if ($config !== null) return $config;
    $archivo = NOVIS_PRIVATE . '/gemini-config.php';
    $config = is_readable($archivo) ? (include $archivo) : [];
    if (!is_array($config)) $config = [];
    return $config;
}

/**
 * Carpeta privada de datos (base de datos, sesiones, límites). Fuera de public_html, con permisos 0700
 * y, por si acaso la carpeta de arriba se publicara por error, con un .htaccess que lo prohíbe todo.
 */
function novis_dir(string $sub = ''): string {
    $base = NOVIS_PRIVATE . '/novis-data';
    foreach ($sub === '' ? [$base] : [$base, $base . '/' . $sub] as $d) {
        if (!is_dir($d)) @mkdir($d, 0700, true);
        elseif ((fileperms($d) & 0077) !== 0) @chmod($d, 0700);
    }
    if (!is_file($base . '/.htaccess')) {
        @file_put_contents($base . '/.htaccess', "Require all denied\n");
        @file_put_contents($base . '/index.html', '');
    }
    return $sub === '' ? $base : $base . '/' . $sub;
}

/** ¿La conexión es MySQL? (si no, es la base de datos SQLite de antes) */
function novis_es_mysql(PDO $db): bool { return $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; }

/** «INSERT IGNORE» en MySQL, «INSERT OR IGNORE» en SQLite. */
function novis_sql_ignorar(PDO $db): string { return novis_es_mysql($db) ? 'INSERT IGNORE' : 'INSERT OR IGNORE'; }

/** Nombres de las columnas de una tabla (sirve igual para MySQL y SQLite). */
function novis_columnas(PDO $db, string $tabla): array {
    if (novis_es_mysql($db)) return array_column($db->query("SHOW COLUMNS FROM `$tabla`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    return array_column($db->query("PRAGMA table_info($tabla)")->fetchAll(PDO::FETCH_ASSOC), 'name');
}

/** ALTER TABLE que no falla si otra visita añadió la misma columna justo antes (pasa con dos primeras visitas a la vez). */
function novis_alter(PDO $db, string $sql): void {
    try { $db->exec($sql); }
    catch (PDOException $e) { if (stripos($e->getMessage(), 'duplicate column') === false) throw $e; }
}

/**
 * Conexión a la base de datos (con los datos personales cifrados).
 * Si el asistente del panel ya guardó los datos de MySQL (novis-db.php), se usa la base de datos MySQL de Hostinger;
 * si no, la base de datos SQLite de siempre, fuera de public_html.
 */
function novis_db(): PDO {
    static $db = null;
    if ($db) return $db;
    $archivo = null;
    if ($cfg = novis_db_config()) {
        $conn = novis_mysql_conectar($cfg);
        novis_esquema_mysql($conn);
    } else {
        $dir = novis_dir();
        $archivo = $dir . '/solicitudes.sqlite';
        $nueva = !is_file($archivo);
        $conn = new PDO('sqlite:' . $archivo);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $conn->setAttribute(PDO::ATTR_TIMEOUT, 10);          // espera si otra visita está escribiendo
        if ($nueva) @chmod($archivo, 0600);
        $conn->exec('PRAGMA journal_mode = WAL');
        $conn->exec('PRAGMA secure_delete = ON');              // lo borrado se sobrescribe con ceros, no se queda en el archivo
        $conn->exec('CREATE TABLE IF NOT EXISTS ajustes (clave TEXT PRIMARY KEY, valor TEXT)');
        $conn->exec('CREATE TABLE IF NOT EXISTS solicitudes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            creado TEXT NOT NULL,
            nombre TEXT NOT NULL,
            empresa TEXT,
            email TEXT NOT NULL,
            plan TEXT,
            mensaje TEXT NOT NULL,
            estado TEXT NOT NULL DEFAULT "nueva",
            notas TEXT DEFAULT "",
            actualizado TEXT
        )');
        // Columna con la fecha en que el usuario aceptó la política de privacidad
        $cols = novis_columnas($conn, 'solicitudes');
        if (!in_array('consentimiento', $cols, true)) novis_alter($conn, 'ALTER TABLE solicitudes ADD COLUMN consentimiento TEXT');
        if (!in_array('email_idx', $cols, true)) novis_alter($conn, 'ALTER TABLE solicitudes ADD COLUMN email_idx TEXT');
    }

    // ---- Clave de cifrado ----
    // Si la base de datos ya tiene datos cifrados, la clave tiene que existir: nunca se crea una nueva
    // (los datos quedarían ilegibles). Solo se crea en una instalación nueva o al cifrar datos antiguos.
    $centinela = $conn->query("SELECT valor FROM ajustes WHERE clave = 'centinela'")->fetchColumn();
    if (nv_clave_leer() === null) {
        if ($centinela) throw new RuntimeException('Falta la clave de cifrado (novis-clave.key o data_key en gemini-config.php). Los datos no se pueden leer sin ella.');
        nv_clave_crear();
    }
    if ($centinela) {
        if (nv_descifrar($centinela) !== 'novis-ok') throw new RuntimeException('La clave de cifrado no corresponde a esta base de datos.');
    } else {
        $conn->prepare(novis_sql_ignorar($conn) . " INTO ajustes (clave, valor) VALUES ('centinela', ?)")->execute([nv_cifrar('novis-ok')]);
    }
    if ($archivo && is_file($archivo) && (fileperms($archivo) & 0077) !== 0) @chmod($archivo, 0600);

    // ---- Cifrar los datos que se guardaron antes sin cifrar (una sola vez) ----
    novis_cifrar_tabla($conn, 'solicitudes');
    if (!novis_es_mysql($conn)) $conn->exec('CREATE INDEX IF NOT EXISTS solicitudes_email_idx ON solicitudes(email_idx)');

    // A partir de aquí las consultas descifran solas lo que devuelven
    $conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['NvStmt']);
    return $db = $conn;
}

/* ================= Ajustes guardados en la base de datos (tu información, correo, acceso al panel) ================= */

/** Lee un ajuste. Si la base de datos no responde, devuelve el valor por defecto (la web nunca se cae por esto). */
function nv_ajuste(string $clave, ?string $def = null): ?string {
    $todos = nv_ajustes();
    $v = $todos[$clave] ?? null;
    return ($v === null || $v === '') ? $def : $v;
}

/** Todos los ajustes (descifrados). */
function nv_ajustes(bool $recargar = false): array {
    static $cache = null;
    if ($cache !== null && !$recargar) return $cache;
    try {
        $cache = [];
        foreach (novis_db()->query('SELECT clave, valor FROM ajustes') as $f) $cache[$f['clave']] = $f['valor'];
    } catch (Throwable $e) {
        error_log('Novis ajustes: ' . $e->getMessage());
        $cache = [];
    }
    return $cache;
}

/** Guarda varios ajustes. Los que están en $cifrar se guardan cifrados (p. ej. la contraseña del correo). */
function nv_ajustes_guardar(array $valores, array $cifrar = []): void {
    $db = novis_db();
    $st = $db->prepare('REPLACE INTO ajustes (clave, valor) VALUES (?, ?)');
    foreach ($valores as $k => $v) {
        if ($v === null) { $db->prepare('DELETE FROM ajustes WHERE clave = ?')->execute([$k]); continue; }
        $st->execute([$k, in_array($k, $cifrar, true) ? nv_cifrar((string)$v) : (string)$v]);
    }
    nv_ajustes(true);
}

/** Tu correo: donde llegan los avisos y el que ven los clientes. Se cambia en el panel → Ajustes. */
function nv_email_negocio(): string {
    $e = nv_ajuste('email');
    if ($e && filter_var($e, FILTER_VALIDATE_EMAIL)) return $e;
    return 'hola@thenovis.com';
}

/**
 * Qué se cifra en cada tabla: [columnas con datos personales, índices ciegos ['columna_idx' => 'de qué columna sale'],
 * columnas que se guardan solo como huella con clave].
 * Quedan en claro a propósito: fechas, estados, importes, códigos de pago e identificadores de Stripe (no son datos personales).
 */
const NOVIS_CIFRADO = [
    'solicitudes'  => [['nombre', 'empresa', 'email', 'mensaje', 'notas'], ['email_idx' => 'email'], []],
    'respuestas'   => [['texto'], [], []],
    'usuarios'     => [['email', 'usuario', 'telefono'], ['email_idx' => 'email', 'usuario_idx' => 'usuario'], ['google_sub']],
    'pagos'        => [['concepto', 'descripcion', 'email_cliente', 'pagador_email'], ['pagador_idx' => 'pagador_email'], []],
    'datos_tokens' => [['email'], ['email_idx' => 'email'], []],
    'incidentes'   => [['descripcion', 'datos', 'medidas'], [], []],
    'auditoria'    => [['ip', 'agente'], [], []],
];

/**
 * Cifra las filas de una tabla que aún tengan texto sin cifrar y calcula sus índices ciegos.
 * Se hace una sola vez por tabla (queda anotado en «ajustes») y dentro de una transacción:
 * o se cifra todo o no se cambia nada. Los datos nuevos ya se guardan cifrados desde el principio.
 * Con $forzar se repasa la tabla aunque ya estuviera anotada (lo usa la página Seguridad del panel).
 */
function novis_cifrar_tabla(PDO $db, string $tabla, bool $forzar = false): int {
    [$cols, $indices, $huella] = NOVIS_CIFRADO[$tabla];
    $marca = $db->prepare("SELECT 1 FROM ajustes WHERE clave = ?");
    $marca->execute(['cifrado:' . $tabla]);
    if (!$forzar && $marca->fetchColumn()) return 0;
    $marca->closeCursor();

    $cambiadas = 0;
    $mysql = novis_es_mysql($db);
    $mysql ? $db->beginTransaction() : $db->exec('BEGIN IMMEDIATE');
    try {
        // Otra visita pudo hacerlo mientras esperábamos
        $marca->execute(['cifrado:' . $tabla]);
        $hecho = (bool)$marca->fetchColumn();
        $marca->closeCursor();
        if ($hecho && !$forzar) { $mysql ? $db->commit() : $db->exec('COMMIT'); return 0; }

        foreach ($db->query("SELECT id AS _rid, $tabla.* FROM $tabla")->fetchAll() as $f) {
            $nuevo = [];
            $claro = [];
            foreach ($cols as $c) {
                $v = $f[$c] ?? null;
                $claro[$c] = $v === null ? null : (nv_esta_cifrado($v) ? nv_descifrar($v) : $v);
                if ($v !== null && $v !== '' && !nv_esta_cifrado($v)) $nuevo[$c] = nv_cifrar($v);
            }
            foreach ($indices as $ci => $fuente) {
                $v = $claro[$fuente] ?? null;
                $idx = ($v === null || $v === '') ? null : nv_indice($v);
                if ($idx !== ($f[$ci] ?? null)) $nuevo[$ci] = $idx;
            }
            foreach ($huella as $c) {
                $v = $f[$c] ?? null;
                if ($v !== null && !preg_match('/^[a-f0-9]{64}$/', $v)) $nuevo[$c] = nv_indice($v);
            }
            if (!$nuevo) continue;
            $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($nuevo)));
            $db->prepare("UPDATE $tabla SET $set WHERE id = ?")->execute([...array_values($nuevo), $f['_rid']]);
            $cambiadas++;
        }
        $db->prepare("REPLACE INTO ajustes (clave, valor) VALUES (?, ?)")->execute(['cifrado:' . $tabla, date('c')]);
        $mysql ? $db->commit() : $db->exec('COMMIT');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $mysql ? $db->rollBack() : $db->exec('ROLLBACK');
        error_log("Novis: no se pudo cifrar la tabla $tabla: " . $e->getMessage());
        throw $e;
    }
    if ($cambiadas && !$mysql) {
        // Que no quede ningún resto del texto antiguo en el archivo ni en el registro de escritura
        try { $db->exec('PRAGMA wal_checkpoint(TRUNCATE)'); $db->exec('VACUUM'); } catch (Throwable $e) { error_log('Novis cifrado (compactar): ' . $e->getMessage()); }
    }
    return $cambiadas;
}

/**
 * Cuenta los datos personales que siguen sin cifrar (debería ser 0). Lo usa la página Seguridad del panel.
 * @return array<string,int> tabla => valores sin cifrar
 */
function novis_sin_cifrar(PDO $db): array {
    $tablas = array_map(fn($t) => $t[0], NOVIS_CIFRADO);
    $res = [];
    foreach ($tablas as $t => $cols) {
        try {
            $n = 0;
            foreach ($cols as $c) {
                $n += (int)$db->query("SELECT COUNT(*) FROM $t WHERE $c IS NOT NULL AND $c != '' AND $c NOT LIKE 'nv1:%' AND $c NOT LIKE 'nv2:%'")->fetchColumn();
            }
            $res[$t] = $n;
        } catch (Throwable $e) { $res[$t] = -1; }   // la tabla aún no existe
    }
    return $res;
}

/** RGPD: borra solicitudes con más de 2 años (plazo indicado en la política de privacidad). */
function novis_purgar(): void {
    novis_db()->prepare('DELETE FROM solicitudes WHERE creado < ?')->execute([date('c', strtotime('-2 years'))]);
    // El registro de auditoría se conserva 3 años (plazo de prescripción de las infracciones leves de protección de datos)
    try { novis_db()->prepare('DELETE FROM auditoria WHERE fecha < ?')->execute([date('c', strtotime('-3 years'))]); } catch (Throwable $e) {}
}

/** Libera de verdad el espacio en disco tras borrar (SQLite no encoge el archivo por sí solo). */
function novis_compactar(): void {
    // Es una optimización: si falla (p. ej. otra consulta en curso), el borrado ya está hecho igualmente
    try {
        $db = novis_db();
        // Respuestas de mensajes que ya no existen (p. ej. borrados desde el panel)
        try { $db->exec('DELETE FROM respuestas WHERE solicitud_id NOT IN (SELECT id FROM solicitudes)'); } catch (Throwable $e) {}
        if (novis_es_mysql($db)) return;   // MySQL reaprovecha el espacio por sí solo
        $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $db->exec('VACUUM');
    } catch (Throwable $e) {
        error_log('Novis compactar: ' . $e->getMessage());
    }
}

/** Espacio que ocupa la base de datos, en formato legible. */
function novis_tamano(): string {
    $bytes = 0;
    if (novis_db_config()) {
        try { $bytes = (int)novis_db()->query('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn(); }
        catch (Throwable $e) { return '—'; }
    } else {
        $base = NOVIS_PRIVATE . '/novis-data/solicitudes.sqlite';
        foreach (['', '-wal', '-shm'] as $suf) if (is_file($base . $suf)) $bytes += filesize($base . $suf);
    }
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

/**
 * Límite simple de intentos: devuelve false si se supera $max en $segundos.
 * Por defecto se cuenta por IP; con $sujeto se cuenta por otra cosa (una cuenta, un email…).
 * Lo único que se guarda es una huella con clave (HMAC) y las horas de los intentos: ni la IP ni el email se pueden recuperar.
 */
function novis_limite(string $clave, int $max, int $segundos, ?string $sujeto = null): bool {
    $dir = novis_dir();
    $f = $dir . '/lim-' . preg_replace('/[^a-z0-9-]/i', '', $clave) . '-' . nv_huella($sujeto ?? ($_SERVER['REMOTE_ADDR'] ?? 'x')) . '.json';
    $t = is_readable($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
    $t = array_values(array_filter($t, fn($x) => $x > time() - $segundos));
    // Limpieza: borra registros de más de 1 hora (política de privacidad)
    if (mt_rand(1, 20) === 1) {
        foreach (glob($dir . '/{lim,intentos}-*.json', GLOB_BRACE) ?: [] as $viejo) {
            if (filemtime($viejo) < time() - 3600) @unlink($viejo);
        }
    }
    if (count($t) >= $max) return false;
    $t[] = time();
    @file_put_contents($f, json_encode($t), LOCK_EX);
    return true;
}

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
