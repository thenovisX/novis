<?php
// Novis — cifrado de los datos personales guardados en la base de datos.
// Esta carpeta está bloqueada al público con .htaccess.
//
// Cómo funciona:
//  · Cada dato personal (nombre, email, teléfono, mensajes…) se guarda cifrado con XChaCha20-Poly1305
//    (o AES-256-GCM si el servidor no tuviera libsodium). Con el archivo de la base de datos solo
//    se ven bytes ilegibles; hace falta la clave para leerlos.
//  · La clave NO está en la base de datos ni en el repositorio: vive en gemini-config.php ('data_key')
//    o, si no se ha puesto allí, en novis-clave.key (ambos fuera de public_html y con permisos privados).
//  · Para poder buscar por email o usuario sin descifrar todo, se guarda además un «índice ciego»:
//    un HMAC-SHA256 del valor con otra clave derivada. Sirve para comparar, no se puede invertir.
//  · Al leer, los valores cifrados se descifran solos (ver NvStmt); al escribir, se cifra con nv_cifrar().

const NV_CIF_V1 = 'nv1:';   // XChaCha20-Poly1305 (libsodium)
const NV_CIF_V2 = 'nv2:';   // AES-256-GCM (OpenSSL), solo de respaldo
const NV_CIF_AAD = 'novis';
const NV_CIF_ILEGIBLE = '[dato ilegible]';

function nv_b64u(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function nv_b64u_dec(string $s): string|false { return base64_decode(strtr($s, '-_', '+/'), true); }

/* ================= La clave ================= */

function nv_clave_ruta(): string { return NOVIS_PRIVATE . '/novis-clave.key'; }

/** Dónde está la clave: 'config' (gemini-config.php), 'archivo' (novis-clave.key) o null si aún no existe. */
function nv_clave_origen(): ?string {
    if (trim((string)(novis_config()['data_key'] ?? '')) !== '') return 'config';
    return is_file(nv_clave_ruta()) ? 'archivo' : null;
}

/** Lee la clave maestra (32 bytes) sin crearla. null si no existe. Lanza error si está mal formada. */
function nv_clave_leer(): ?string {
    static $clave = null;
    if ($clave !== null) return $clave;
    $txt = trim((string)(novis_config()['data_key'] ?? ''));
    $desde = 'data_key de gemini-config.php';
    if ($txt === '') {
        $f = nv_clave_ruta();
        $txt = is_readable($f) ? trim((string)file_get_contents($f)) : '';
        $desde = 'novis-clave.key';
    }
    if ($txt === '') return null;
    $raw = base64_decode($txt, true);
    if ($raw === false || strlen($raw) !== 32) throw new RuntimeException("La clave de cifrado ($desde) no es válida: debe ser de 32 bytes en base64.");
    return $clave = $raw;
}

/** Crea la clave en novis-clave.key (una sola vez y de forma atómica, aunque lleguen dos visitas a la vez). */
function nv_clave_crear(): string {
    $f = nv_clave_ruta();
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, base64_encode(random_bytes(32)) . "\n", LOCK_EX) === false) {
        throw new RuntimeException('No se puede crear la clave de cifrado en ' . dirname($f) . '. Revisa los permisos de esa carpeta.');
    }
    @chmod($tmp, 0600);
    // link() falla si el destino ya existe: gana la primera visita y las demás usan su clave
    if (!@link($tmp, $f) && !is_file($f)) @rename($tmp, $f);
    @unlink($tmp);
    $clave = nv_clave_leer();
    if ($clave === null) throw new RuntimeException('No se pudo crear la clave de cifrado.');
    return $clave;
}

/** Clave maestra. Si no existe, abre la base de datos, que decide si puede crearla (nunca si ya hay datos cifrados). */
function nv_clave(): string {
    $k = nv_clave_leer();
    if ($k === null) { novis_db(); $k = nv_clave_leer(); }
    if ($k === null) throw new RuntimeException('Falta la clave de cifrado.');
    return $k;
}

/** Claves derivadas, una para cada uso (cifrar, índices ciegos, límites de intentos…). */
function nv_subclave(string $uso): string {
    static $cache = [];
    return $cache[$uso] ??= hash_hkdf('sha256', nv_clave(), 32, 'novis/' . $uso);
}

/* ================= Cifrar y descifrar ================= */

function nv_esta_cifrado(mixed $v): bool {
    return is_string($v) && (str_starts_with($v, NV_CIF_V1) || str_starts_with($v, NV_CIF_V2));
}

/** Cifra un texto. null se queda en null. */
function nv_cifrar(?string $v): ?string {
    if ($v === null) return null;
    $k = nv_subclave('cifrado');
    if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
        $n = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return NV_CIF_V1 . nv_b64u($n . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($v, NV_CIF_AAD, $n, $k));
    }
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($v, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag, NV_CIF_AAD, 16);
    if ($ct === false) throw new RuntimeException('No se pudo cifrar.');
    return NV_CIF_V2 . nv_b64u($iv . $tag . $ct);
}

/** Descifra un valor cifrado por nv_cifrar(). Un texto sin cifrar (datos antiguos) se devuelve tal cual. */
function nv_descifrar(?string $v): ?string {
    if ($v === null || !nv_esta_cifrado($v)) return $v;
    $raw = nv_b64u_dec(substr($v, 4));
    $k = nv_subclave('cifrado');
    $claro = false;
    if ($raw !== false) {
        if (str_starts_with($v, NV_CIF_V1) && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
            $np = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
            if (strlen($raw) > $np) $claro = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $np), NV_CIF_AAD, substr($raw, 0, $np), $k);
        } elseif (str_starts_with($v, NV_CIF_V2) && strlen($raw) >= 28) {
            $claro = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), NV_CIF_AAD);
        }
    }
    if ($claro === false) {
        error_log('Novis: un dato cifrado no se puede leer (clave distinta o dato alterado).');
        return NV_CIF_ILEGIBLE;
    }
    return $claro;
}

/** Índice ciego: huella con clave para buscar un email o usuario sin guardarlo en claro. */
function nv_indice(string $v): string {
    return hash_hmac('sha256', mb_strtolower(trim($v)), nv_subclave('indice'));
}

/** Huella con clave de cualquier texto (p. ej. una IP) para los límites de intentos. No se puede invertir. */
function nv_huella(string $v): string {
    try {
        return hash_hmac('sha256', $v, nv_subclave('huella'));
    } catch (Throwable $e) {
        return hash('sha256', $v . 'novis');   // solo si aún no hay clave (primer arranque fallido)
    }
}

/** Descifra todos los valores cifrados de una fila (o un valor suelto). */
function nv_abrir(mixed $x): mixed {
    if (is_string($x)) return nv_esta_cifrado($x) ? nv_descifrar($x) : $x;
    if (is_array($x)) foreach ($x as $k => $v) if (is_string($v) && nv_esta_cifrado($v)) $x[$k] = nv_descifrar($v);
    return $x;
}

/**
 * Consultas que descifran solas lo que devuelven: el resto del código trabaja con texto normal,
 * y en el archivo de la base de datos todo queda cifrado.
 */
class NvStmt extends PDOStatement {
    #[\ReturnTypeWillChange]
    public function fetch(...$a) { return nv_abrir(parent::fetch(...$a)); }
    #[\ReturnTypeWillChange]
    public function fetchAll(...$a) { return array_map('nv_abrir', parent::fetchAll(...$a)); }
    #[\ReturnTypeWillChange]
    public function fetchColumn(...$a) { return nv_abrir(parent::fetchColumn(...$a)); }
    public function getIterator(): Iterator {
        return (function () { while (($r = $this->fetch()) !== false) yield $r; })();
    }
}
