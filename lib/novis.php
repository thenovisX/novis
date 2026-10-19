<?php
// Novis — utilidades compartidas (configuración y base de datos).
// Esta carpeta está bloqueada al público con .htaccess.

date_default_timezone_set('Europe/Madrid');

define('NOVIS_PUBLIC', dirname(__DIR__));          // public_html
define('NOVIS_PRIVATE', dirname(NOVIS_PUBLIC));    // carpeta que contiene public_html

/** Lee gemini-config.php (fuera de public_html). */
function novis_config(): array {
    static $config = null;
    if ($config !== null) return $config;
    $archivo = NOVIS_PRIVATE . '/gemini-config.php';
    $config = is_readable($archivo) ? (include $archivo) : [];
    if (!is_array($config)) $config = [];
    return $config;
}

/** Conexión a la base de datos de solicitudes (SQLite, fuera de public_html). */
function novis_db(): PDO {
    static $db = null;
    if ($db) return $db;
    $dir = NOVIS_PRIVATE . '/novis-data';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $db = new PDO('sqlite:' . $dir . '/solicitudes.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('CREATE TABLE IF NOT EXISTS solicitudes (
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
    $cols = array_column($db->query('PRAGMA table_info(solicitudes)')->fetchAll(), 'name');
    if (!in_array('consentimiento', $cols, true)) $db->exec('ALTER TABLE solicitudes ADD COLUMN consentimiento TEXT');
    return $db;
}

/** RGPD: borra solicitudes con más de 2 años (plazo indicado en la política de privacidad). */
function novis_purgar(): void {
    novis_db()->prepare('DELETE FROM solicitudes WHERE creado < ?')->execute([date('c', strtotime('-2 years'))]);
}

/** Libera de verdad el espacio en disco tras borrar (SQLite no encoge el archivo por sí solo). */
function novis_compactar(): void {
    $db = novis_db();
    $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    $db->exec('VACUUM');
}

/** Espacio que ocupa la base de datos, en formato legible. */
function novis_tamano(): string {
    $base = NOVIS_PRIVATE . '/novis-data/solicitudes.sqlite';
    $bytes = 0;
    foreach (['', '-wal', '-shm'] as $suf) if (is_file($base . $suf)) $bytes += filesize($base . $suf);
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

/** Límite simple por IP: devuelve false si se supera $max en $segundos. La IP solo se guarda cifrada (hash). */
function novis_limite(string $clave, int $max, int $segundos): bool {
    $dir = NOVIS_PRIVATE . '/novis-data';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $f = $dir . '/lim-' . $clave . '-' . hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'x') . 'novis') . '.json';
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
