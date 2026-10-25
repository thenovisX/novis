<?php
// The Novis — base de datos MySQL de Hostinger.
// Esta carpeta está bloqueada al público con .htaccess.
//
// Cómo funciona:
//  · Los datos de conexión (servidor, nombre de la base de datos, usuario y contraseña) los pide el asistente
//    del panel (panel/instalar.php) la primera vez y se guardan en novis-db.php, FUERA de public_html,
//    con permisos privados. Ese archivo no está en GitHub (.gitignore) y el .htaccess prohíbe servirlo.
//  · Mientras no exista, la web sigue usando la base de datos SQLite de antes (novis-data/solicitudes.sqlite).
//  · Al instalar, si había datos en SQLite se copian tal cual a MySQL (siguen cifrados con la misma clave).

/** Ruta del archivo con los datos de conexión (fuera de public_html). */
function novis_db_config_ruta(): string { return NOVIS_PRIVATE . '/novis-db.php'; }

/** Datos de conexión a MySQL, o null si aún no se ha instalado. También valen 'db' => [...] en gemini-config.php. */
function novis_db_config(bool $recargar = false): ?array {
    static $cfg = false;
    if ($cfg !== false && !$recargar) return $cfg;
    $f = novis_db_config_ruta();
    $c = is_readable($f) ? (include $f) : null;
    if (!is_array($c)) $c = novis_config()['db'] ?? null;
    $cfg = (is_array($c) && ($c['nombre'] ?? '') !== '' && ($c['usuario'] ?? '') !== '') ? $c : null;
    return $cfg;
}

/** Texto del archivo novis-db.php. */
function novis_db_config_texto(array $c): string {
    $datos = [
        'host' => (string)$c['host'],
        'puerto' => (int)$c['puerto'],
        'nombre' => (string)$c['nombre'],
        'usuario' => (string)$c['usuario'],
        'clave' => (string)$c['clave'],
    ];
    return "<?php\n// The Novis — conexión a la base de datos MySQL (creado por el asistente del panel el " . date('d/m/Y H:i') . ").\n"
        . "// Contiene la contraseña de la base de datos: NO lo subas a GitHub ni lo compartas.\n"
        . 'return ' . var_export($datos, true) . ";\n";
}

/**
 * Guarda los datos de conexión en novis-db.php con permisos 600. Se escribe primero en un archivo temporal
 * y luego se renombra, para que nunca quede un archivo a medias. Devuelve false si la carpeta no deja escribir.
 */
function novis_db_config_guardar(array $c): bool {
    $f = novis_db_config_ruta();
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, novis_db_config_texto($c), LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    if (!@rename($tmp, $f)) { @unlink($tmp); return false; }
    @chmod($f, 0600);
    if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true);
    return true;
}

/** Abre la conexión con MySQL. Lanza PDOException si los datos no son correctos. */
function novis_mysql_conectar(array $c): PDO {
    $dsn = 'mysql:host=' . ($c['host'] ?: 'localhost') . ';port=' . ((int)($c['puerto'] ?? 0) ?: 3306) . ';dbname=' . $c['nombre'] . ';charset=utf8mb4';
    $db = new PDO($dsn, (string)$c['usuario'], (string)$c['clave'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 8,
        PDO::ATTR_EMULATE_PREPARES => false,     // los números llegan como números, igual que con SQLite
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    $db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'");
    return $db;
}

/** Explica en palabras sencillas por qué no se pudo conectar. */
function novis_mysql_error(Throwable $e): string {
    $m = $e->getMessage();
    if (str_contains($m, '1045') || stripos($m, 'Access denied') !== false)
        return 'El usuario o la contraseña de la base de datos no son correctos. Revísalos en hPanel → Bases de datos → Administración (puedes cambiar la contraseña allí).';
    if (str_contains($m, '1044') || str_contains($m, '1049') || stripos($m, 'Unknown database') !== false)
        return 'La base de datos no existe o ese usuario no tiene permiso para usarla. Comprueba el nombre completo (empieza por u…_).';
    if (str_contains($m, '2002') || str_contains($m, '2005') || stripos($m, 'getaddrinfo') !== false || stripos($m, 'Connection refused') !== false || stripos($m, 'timed out') !== false)
        return 'No se encuentra el servidor de la base de datos. En Hostinger casi siempre es «localhost».';
    return 'No se pudo conectar con la base de datos. Revisa los datos e inténtalo de nuevo.';
}

/**
 * Crea las tablas en MySQL (si no existen). Todas las columnas que antes se añadían poco a poco en SQLite
 * ya están aquí desde el principio. Los datos personales se guardan cifrados, por eso son TEXT.
 */
function novis_esquema_mysql(PDO $db): void {
    $fin = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $tablas = [
        'ajustes' => 'clave VARCHAR(191) NOT NULL PRIMARY KEY,
            valor MEDIUMTEXT',
        'solicitudes' => 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            creado VARCHAR(40) NOT NULL,
            nombre TEXT NOT NULL,
            empresa TEXT,
            email TEXT NOT NULL,
            plan VARCHAR(191),
            mensaje MEDIUMTEXT NOT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT \'nueva\',
            notas MEDIUMTEXT,
            actualizado VARCHAR(40),
            consentimiento VARCHAR(40),
            email_idx VARCHAR(64),
            KEY solicitudes_email_idx (email_idx),
            KEY solicitudes_estado (estado)',
        'usuarios' => 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            email TEXT NOT NULL,
            usuario TEXT,
            telefono TEXT,
            password_hash VARCHAR(255),
            google_sub VARCHAR(191),
            email_verificado VARCHAR(40),
            creado VARCHAR(40) NOT NULL,
            ultimo_acceso VARCHAR(40),
            pass_cambiada BIGINT,
            email_idx VARCHAR(64),
            usuario_idx VARCHAR(64),
            stripe_customer VARCHAR(191),
            UNIQUE KEY usuarios_google_sub (google_sub),
            UNIQUE KEY usuarios_email_idx (email_idx),
            UNIQUE KEY usuarios_usuario_idx (usuario_idx)',
        'tokens' => 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT UNSIGNED NOT NULL,
            tipo VARCHAR(20) NOT NULL,
            hash VARCHAR(64) NOT NULL,
            caduca BIGINT NOT NULL,
            usado TINYINT NOT NULL DEFAULT 0,
            UNIQUE KEY tokens_hash (hash),
            KEY tokens_usuario (usuario_id)',
        'pagos' => 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            codigo VARCHAR(20) NOT NULL,
            concepto TEXT NOT NULL,
            descripcion MEDIUMTEXT,
            importe INT NOT NULL,
            moneda VARCHAR(3) NOT NULL DEFAULT \'eur\',
            email_cliente TEXT,
            caduca VARCHAR(40),
            estado VARCHAR(20) NOT NULL DEFAULT \'pendiente\',
            creado VARCHAR(40) NOT NULL,
            usuario_id INT UNSIGNED,
            pagador_email TEXT,
            stripe_session VARCHAR(191),
            stripe_pi VARCHAR(191),
            pagado_en VARCHAR(40),
            periodo VARCHAR(10),
            stripe_sub VARCHAR(191),
            stripe_customer VARCHAR(191),
            sub_estado VARCHAR(40),
            sub_fin VARCHAR(40),
            sub_cancela TINYINT NOT NULL DEFAULT 0,
            servicio VARCHAR(60),
            pagador_idx VARCHAR(64),
            UNIQUE KEY pagos_codigo (codigo),
            KEY pagos_usuario (usuario_id),
            KEY pagos_estado (estado),
            KEY pagos_stripe_sub (stripe_sub),
            KEY pagos_pagador_idx (pagador_idx)',
        'datos_tokens' => 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            email TEXT NOT NULL,
            hash VARCHAR(64) NOT NULL,
            caduca BIGINT NOT NULL,
            usado TINYINT NOT NULL DEFAULT 0,
            email_idx VARCHAR(64),
            UNIQUE KEY datos_tokens_hash (hash),
            KEY datos_tokens_email_idx (email_idx)',
        'respuestas' => 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            solicitud_id INT UNSIGNED NOT NULL,
            autor VARCHAR(20) NOT NULL,
            texto MEDIUMTEXT NOT NULL,
            fecha VARCHAR(40) NOT NULL,
            KEY respuestas_solicitud (solicitud_id)',
        'renovaciones' => 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pago_id INT UNSIGNED NOT NULL,
            importe INT NOT NULL,
            fecha VARCHAR(40) NOT NULL,
            stripe_invoice VARCHAR(191) NOT NULL,
            UNIQUE KEY renovaciones_invoice (stripe_invoice),
            KEY renovaciones_pago (pago_id)',
        'incidentes' => 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            creado VARCHAR(40) NOT NULL,
            descubierto VARCHAR(40) NOT NULL,
            descripcion MEDIUMTEXT NOT NULL,
            datos MEDIUMTEXT,
            personas INT,
            riesgo VARCHAR(20) NOT NULL DEFAULT \'bajo\',
            aepd VARCHAR(40),
            afectados VARCHAR(40),
            medidas MEDIUMTEXT,
            cerrado VARCHAR(40)',
        'auditoria' => 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            fecha VARCHAR(40) NOT NULL,
            evento VARCHAR(60) NOT NULL,
            usuario_id INT UNSIGNED,
            detalle TEXT,
            ip TEXT,
            agente TEXT,
            KEY auditoria_usuario (usuario_id)',
        // Planes y precios que se editan desde el panel (la web y el área de cliente los leen de aquí)
        'productos' => 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            clave VARCHAR(60) NOT NULL,
            tipo VARCHAR(20) NOT NULL DEFAULT \'plan\',
            nombre VARCHAR(120) NOT NULL,
            descripcion TEXT,
            caracteristicas TEXT,
            precio INT,
            desde TINYINT NOT NULL DEFAULT 0,
            periodo VARCHAR(10) NOT NULL DEFAULT \'\',
            nota VARCHAR(255),
            etiqueta VARCHAR(60),
            destacado TINYINT NOT NULL DEFAULT 0,
            contratable TINYINT NOT NULL DEFAULT 0,
            visible TINYINT NOT NULL DEFAULT 1,
            orden INT NOT NULL DEFAULT 0,
            creado VARCHAR(40) NOT NULL,
            actualizado VARCHAR(40),
            UNIQUE KEY productos_clave (clave)',
    ];
    foreach ($tablas as $t => $cols) $db->exec("CREATE TABLE IF NOT EXISTS $t ($cols)$fin");
}

/** Tablas que se copian de SQLite a MySQL, en este orden. */
const NOVIS_TABLAS = ['ajustes', 'solicitudes', 'respuestas', 'usuarios', 'tokens', 'pagos', 'datos_tokens', 'renovaciones', 'incidentes', 'auditoria', 'productos'];

/** ¿Hay una base de datos SQLite de antes con datos? */
function novis_sqlite_ruta(): ?string {
    $f = NOVIS_PRIVATE . '/novis-data/solicitudes.sqlite';
    return is_file($f) ? $f : null;
}

/** ¿MySQL ya tiene datos (cuentas, pagos o mensajes)? Entonces no se copia nada encima. */
function novis_mysql_tiene_datos(PDO $my): bool {
    foreach (['solicitudes', 'usuarios', 'pagos'] as $t) {
        if ((int)$my->query("SELECT COUNT(*) FROM $t")->fetchColumn() > 0) return true;
    }
    return false;
}

/**
 * Copia todas las filas de la base de datos SQLite a MySQL, sin descifrar nada (los datos personales
 * siguen cifrados con la misma clave) y conservando los números de cada fila. Todo o nada: si algo falla,
 * MySQL se queda como estaba. Devuelve cuántas filas se copiaron de cada tabla.
 * @return array<string,int>
 */
function novis_migrar_sqlite(PDO $my, string $archivo): array {
    $lite = new PDO('sqlite:' . $archivo, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $lite->setAttribute(PDO::ATTR_TIMEOUT, 10);
    $hay = array_column($lite->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(), 'name');
    $copiadas = [];
    $my->beginTransaction();
    try {
        foreach (NOVIS_TABLAS as $t) {
            if (!in_array($t, $hay, true)) continue;
            $destino = novis_columnas($my, $t);
            $origen = novis_columnas($lite, $t);
            $cols = array_values(array_intersect($origen, $destino));
            if (!$cols) continue;
            $lista = implode(', ', array_map(fn($c) => "`$c`", $cols));
            $marcas = implode(', ', array_fill(0, count($cols), '?'));
            // ajustes: lo de SQLite manda (centinela del cifrado, marcas); el resto: si la fila ya existe, se deja
            $ins = $my->prepare(($t === 'ajustes' ? 'REPLACE' : 'INSERT IGNORE') . " INTO $t ($lista) VALUES ($marcas)");
            $n = 0;
            $sel = $lite->query("SELECT $lista FROM $t" . ($t === 'ajustes' ? '' : ' ORDER BY id'));
            while ($f = $sel->fetch()) {
                $ins->execute(array_map(fn($c) => $f[$c], $cols));
                $n += $ins->rowCount() > 0 ? 1 : 0;
            }
            $copiadas[$t] = $n;
        }
        $my->commit();
    } catch (Throwable $e) {
        if ($my->inTransaction()) $my->rollBack();
        throw $e;
    }
    return $copiadas;
}
