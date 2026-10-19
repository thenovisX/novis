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
    return $db;
}

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
