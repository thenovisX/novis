<?php
// The Novis — planes y precios (se crean, editan y borran en el panel → Planes).
// La página principal, el formulario de contacto, el chat y el área de cliente los leen de la base de datos.
// Esta carpeta está bloqueada al público con .htaccess.
require_once __DIR__ . '/novis.php';

const NV_IVA = 0.21;
function nv_con_iva(int $base): int { return (int)round($base * (1 + NV_IVA)); }

/** Tipos de producto: los planes salen en «Precios claros»; los servicios, en «Después de publicar» y en el área de cliente. */
const NV_TIPOS_PRODUCTO = ['plan' => 'Plan web', 'servicio' => 'Servicio después de publicar'];
/** Cada cuánto se paga. '' = pago único. */
const NV_PERIODOS_PRODUCTO = ['' => 'Pago único', 'month' => 'Cada mes', 'year' => 'Cada año', 'year3' => 'Cada 3 años'];
const NV_PERIODOS_CORTO = ['month' => 'al mes', 'year' => 'al año', 'year3' => 'por 3 años'];

/** Crea la tabla en SQLite (en MySQL ya la crea lib/mysql.php) y la rellena con los planes de siempre la primera vez. */
function nv_productos_preparar(PDO $db): void {
    static $hecho = false;
    if ($hecho) return;
    if (!novis_es_mysql($db)) {
        $db->exec('CREATE TABLE IF NOT EXISTS productos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            clave TEXT NOT NULL UNIQUE,
            tipo TEXT NOT NULL DEFAULT \'plan\',
            nombre TEXT NOT NULL,
            descripcion TEXT,
            caracteristicas TEXT,
            precio INTEGER,
            desde INTEGER NOT NULL DEFAULT 0,
            periodo TEXT NOT NULL DEFAULT \'\',
            nota TEXT,
            etiqueta TEXT,
            destacado INTEGER NOT NULL DEFAULT 0,
            contratable INTEGER NOT NULL DEFAULT 0,
            visible INTEGER NOT NULL DEFAULT 1,
            orden INTEGER NOT NULL DEFAULT 0,
            creado TEXT NOT NULL,
            actualizado TEXT
        )');
    }
    $hecho = true;
    // Solo una vez: si luego borras todos los planes, no vuelven a aparecer solos
    $ya = $db->query("SELECT 1 FROM ajustes WHERE clave = 'productos:iniciales'")->fetchColumn();
    if ($ya) return;
    if ((int)$db->query('SELECT COUNT(*) FROM productos')->fetchColumn() === 0) {
        $st = $db->prepare(novis_sql_ignorar($db) . ' INTO productos (clave, tipo, nombre, descripcion, caracteristicas, precio, desde, periodo, nota, etiqueta, destacado, contratable, visible, orden, creado)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)');
        foreach (NV_PRODUCTOS_INICIALES as $i => $p) {
            $st->execute([$p['clave'], $p['tipo'], $p['nombre'], $p['descripcion'], $p['caracteristicas'] ?? '', $p['precio'], $p['desde'] ?? 0,
                $p['periodo'] ?? '', $p['nota'] ?? null, $p['etiqueta'] ?? null, $p['destacado'] ?? 0, $p['contratable'] ?? 0, ($i + 1) * 10, date('c')]);
        }
    }
    $db->prepare('REPLACE INTO ajustes (clave, valor) VALUES (?, ?)')->execute(['productos:iniciales', date('c')]);
}

/** Los planes y precios que ya tenía la web (precios sin IVA, en céntimos). */
const NV_PRODUCTOS_INICIALES = [
    ['clave' => 'esencial', 'tipo' => 'plan', 'nombre' => 'Esencial', 'descripcion' => 'Para empezar a estar en internet.', 'precio' => 24900, 'desde' => 1,
        'caracteristicas' => "Web de una página\nAdaptada a móvil\nFormulario de contacto\nLo básico para Google"],
    ['clave' => 'profesional', 'tipo' => 'plan', 'nombre' => 'Profesional', 'descripcion' => 'La web completa de tu empresa.', 'precio' => 54900, 'desde' => 1,
        'destacado' => 1, 'etiqueta' => 'El más pedido',
        'caracteristicas' => "Hasta 6 secciones\nDiseño totalmente a medida\nBlog o noticias\nPosicionamiento en Google y estadísticas\n1 mes de soporte incluido"],
    ['clave' => 'restaurante', 'tipo' => 'plan', 'nombre' => 'Restaurante', 'descripcion' => 'Web y app de reservas.', 'precio' => null, 'nota' => 'Alquiler o compra',
        'caracteristicas' => "Web con diseño único\nApp de gestión en el móvil\nReservas online sin comisiones\nCarta con los 14 alérgenos"],
    ['clave' => 'alojamiento', 'tipo' => 'servicio', 'nombre' => 'Alojamiento y dominio (1 año)', 'periodo' => 'year', 'precio' => 12000, 'contratable' => 1,
        'descripcion' => 'Tu web publicada con su dominio, certificado de seguridad (https) y copias de seguridad. Se renueva cada año.',
        'nota' => 'Se paga por adelantado y se renueva al terminar el periodo. Si decides no renovar, te entregamos todos los archivos de tu web.'],
    ['clave' => 'alojamiento3', 'tipo' => 'servicio', 'nombre' => 'Alojamiento y dominio (3 años)', 'periodo' => 'year3', 'precio' => 28800, 'contratable' => 1,
        'descripcion' => 'Lo mismo que el anual con un 20 % de descuento. Se renueva cada 3 años.'],
    ['clave' => 'mantenimiento', 'tipo' => 'servicio', 'nombre' => 'Mantenimiento', 'periodo' => 'month', 'precio' => 2900, 'contratable' => 1,
        'descripcion' => 'Nos ocupamos de tu web para que tú no tengas que hacerlo.',
        'caracteristicas' => "Cambios pequeños: textos, fotos, precios u horarios (hasta 2 h al mes)\nActualizaciones y revisiones de seguridad\nRespuesta en 48 h laborables",
        'nota' => 'Sin permanencia. Puedes darte de baja cuando quieras, sin penalización, y tu web sigue online igual.'],
];

/** Conexión lista para los productos. */
function nv_productos_db(): PDO {
    $db = novis_db();
    nv_productos_preparar($db);
    return $db;
}

/**
 * Productos ordenados. $soloVisibles = lo que se enseña en la web.
 * @return array<int,array>
 */
function nv_productos(?string $tipo = null, bool $soloVisibles = false): array {
    $donde = [];
    $p = [];
    if ($tipo !== null) { $donde[] = 'tipo = ?'; $p[] = $tipo; }
    if ($soloVisibles) $donde[] = 'visible = 1';
    $st = nv_productos_db()->prepare('SELECT * FROM productos' . ($donde ? ' WHERE ' . implode(' AND ', $donde) : '') . ' ORDER BY orden, id');
    $st->execute($p);
    return $st->fetchAll();
}

function nv_producto(int $id): ?array {
    $st = nv_productos_db()->prepare('SELECT * FROM productos WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Lista de características (una por línea). */
function nv_caracteristicas(array $p): array {
    return array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($p['caracteristicas'] ?? '')))));
}

/** «249 €» o «29,50 €» (sin decimales si no hacen falta). */
function nv_precio_corto(int $centimos): string {
    return ($centimos % 100 === 0 ? number_format($centimos / 100, 0, ',', '.') : number_format($centimos / 100, 2, ',', '.')) . ' €';
}

/** Nombre sin la parte entre paréntesis: «Alojamiento y dominio (1 año)» → «Alojamiento y dominio». */
function nv_nombre_corto(string $n): string { return trim(preg_replace('/\s*\([^)]*\)\s*$/u', '', $n)) ?: $n; }

/** Clave única a partir del nombre: «Plan Tienda 2» → «plan-tienda-2». */
function nv_clave_producto(string $nombre, PDO $db): string {
    $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombre) ?: ''), '-')) ?: 'plan';
    $base = substr($base, 0, 50);
    $clave = $base;
    $st = $db->prepare('SELECT 1 FROM productos WHERE clave = ?');
    for ($i = 2; ; $i++) {
        $st->execute([$clave]);
        if (!$st->fetchColumn()) return $clave;
        $clave = $base . '-' . $i;
    }
}

/**
 * Lo que el cliente puede contratar él solo desde su área (servicios visibles, con precio y cobro periódico).
 * Precios sin IVA, en céntimos: el cliente paga el precio con IVA.
 * @return array<string,array{nombre:string,periodo:string,base:int,desc:string}>
 */
function nv_catalogo(): array {
    $c = [];
    try {
        foreach (nv_productos('servicio', true) as $p) {
            if (!$p['contratable'] || $p['precio'] === null || !isset(NV_PERIODOS_CORTO[$p['periodo']])) continue;
            $desc = trim((string)$p['descripcion']);
            if ($car = nv_caracteristicas($p)) $desc = trim($desc . ' ' . implode('. ', $car) . '.');
            $c[$p['clave']] = ['nombre' => $p['nombre'], 'periodo' => $p['periodo'], 'base' => (int)$p['precio'], 'desc' => $desc];
        }
    } catch (Throwable $e) {
        error_log('Novis catálogo: ' . $e->getMessage());
    }
    return $c;
}

/** Texto con los precios actuales para el asistente del chat. */
function nv_productos_texto(): string {
    $lin = [];
    foreach (nv_productos(null, true) as $p) {
        $precio = $p['precio'] === null ? 'precio a consultar' : (($p['desde'] ? 'desde ' : '') . nv_precio_corto((int)$p['precio'])
            . (isset(NV_PERIODOS_CORTO[$p['periodo']]) ? ' ' . NV_PERIODOS_CORTO[$p['periodo']] : '') . ' + IVA');
        $car = nv_caracteristicas($p);
        $lin[] = '- ' . $p['nombre'] . ' (' . ($p['tipo'] === 'plan' ? 'plan web' : 'servicio') . '): ' . $precio . '. ' . trim((string)$p['descripcion'])
            . ($car ? ' Incluye: ' . implode('; ', $car) . '.' : '') . ($p['nota'] ? ' ' . $p['nota'] : '');
    }
    return implode("\n", $lin);
}

/* ================= Página principal: los precios salen de la base de datos ================= */

/** Tarjetas de «Precios claros». */
function nv_html_planes(array $planes): string {
    $h = '';
    foreach ($planes as $p) {
        $dest = (bool)$p['destacado'];
        $h .= "\n        <article class=\"plan" . ($dest ? ' plan--featured' : '') . '">';
        if ($p['etiqueta']) $h .= "\n          <p class=\"plan__tag\">" . e($p['etiqueta']) . '</p>';
        $h .= "\n          <h3>" . e($p['nombre']) . '</h3>';
        if ($p['descripcion']) $h .= "\n          <p class=\"plan__desc\">" . e($p['descripcion']) . '</p>';
        if ($p['precio'] === null) {
            $h .= "\n          <p class=\"plan__price plan__price--text\">Consultar precio" . ($p['nota'] ? '<span>' . e($p['nota']) . '</span>' : '') . '</p>';
        } else {
            $h .= "\n          <p class=\"plan__price\">" . ($p['desde'] ? '<span>desde</span> ' : '') . e(nv_precio_corto((int)$p['precio']))
                . (isset(NV_PERIODOS_CORTO[$p['periodo']]) ? ' <span>' . e(NV_PERIODOS_CORTO[$p['periodo']]) . '</span>' : '') . '</p>';
        }
        if ($car = nv_caracteristicas($p)) {
            $h .= "\n          <ul>";
            foreach ($car as $c) $h .= "\n            <li>" . e($c) . '</li>';
            $h .= "\n          </ul>";
        }
        if ($p['clave'] === 'restaurante') {
            $h .= "\n          <div class=\"plan__info\"><button type=\"button\" class=\"plan__more\" data-modal=\"restaurante-info\" aria-haspopup=\"dialog\" aria-label=\"Ver precios y detalles del plan Restaurante\">Ver precios y detalles</button></div>";
        } elseif ($p['precio'] !== null && $p['nota']) {
            $h .= "\n          <p class=\"plan__desc\">" . e($p['nota']) . '</p>';
        }
        $h .= "\n          <a href=\"#contacto\" class=\"btn " . ($dest ? 'btn--light' : 'btn--ghost') . '" data-plan="' . e($p['nombre']) . '">Pedir el plan ' . e($p['nombre']) . '</a>';
        $h .= "\n        </article>";
    }
    return $h . "\n        ";
}

/** Tarjetas de «Después de publicar». El alojamiento de 1 y 3 años va en una sola tarjeta con selector. */
function nv_html_servicios(array $servicios): string {
    $porClave = array_column($servicios, null, 'clave');
    $a1 = $porClave['alojamiento'] ?? null;
    $a3 = $porClave['alojamiento3'] ?? null;
    $h = '';
    foreach ($servicios as $s) {
        if ($a1 && $a3 && $a1['precio'] !== null && $a3['precio'] !== null && in_array($s['clave'], ['alojamiento', 'alojamiento3'], true)) {
            if ($s['clave'] === 'alojamiento3') continue;
            $p1 = (int)$a1['precio']; $p3 = (int)$a3['precio'];
            $ahorro = $p1 * 3 - $p3;
            $dto = $p1 > 0 ? (int)round($ahorro / ($p1 * 3) * 100) : 0;
            $h .= "\n        <article class=\"extra\">"
                . "\n          <h4>" . e(nv_nombre_corto($a1['nombre'])) . '</h4>'
                . "\n          <p class=\"extra__desc\">" . e(preg_replace('/\s*Se renueva cada año\.?$/u', '', (string)$a1['descripcion'])) . '</p>'
                . "\n          <fieldset class=\"seg\">\n            <legend class=\"sr\">Duración del alojamiento</legend>"
                . "\n            <label><input type=\"radio\" name=\"aloj\" value=\"1\" checked> 1 año</label>"
                . "\n            <label><input type=\"radio\" name=\"aloj\" value=\"3\"> 3 años" . ($dto > 0 ? ' <span class="seg__save">−' . $dto . ' %</span>' : '') . '</label>'
                . "\n            <span class=\"seg__thumb\" aria-hidden=\"true\"></span>\n          </fieldset>"
                . "\n          <p class=\"extra__opt extra__opt--host\" aria-live=\"polite\">"
                . "\n            <span class=\"roll extra__price\"><span>" . e(nv_precio_corto($p1)) . '</span><span>' . e(nv_precio_corto($p3)) . '</span></span>'
                . "\n            <span class=\"roll extra__per\"><span>al año</span><span>por 3 años</span></span>\n          </p>"
                . ($ahorro > 0 ? "\n          <p class=\"extra__ahorro\"><span>Sale a <strong>" . e(nv_precio_corto((int)round($p3 / 3))) . ' al año</strong>: te ahorras ' . e(nv_precio_corto($ahorro)) . ' frente a renovar cada año.</span></p>' : '')
                . ($a1['nota'] ? "\n          <p class=\"extra__note\">" . e($a1['nota']) . '</p>' : '')
                . "\n        </article>";
            continue;
        }
        $corto = nv_nombre_corto($s['nombre']);
        $h .= "\n        <article class=\"extra\">\n          <h4>" . e($corto) . '</h4>';
        if ($s['descripcion']) $h .= "\n          <p class=\"extra__desc\">" . e($s['descripcion']) . '</p>';
        if ($s['precio'] !== null) {
            $euros = (int)$s['precio'] % 100 === 0 ? (int)($s['precio'] / 100) : 0;
            $h .= "\n          <div class=\"extra__opts\">\n            <p class=\"extra__opt\"><span class=\"extra__price\"" . ($euros ? ' data-contar="' . $euros . '"' : '') . '>'
                . e(($s['desde'] ? 'desde ' : '') . nv_precio_corto((int)$s['precio'])) . '</span>'
                . (isset(NV_PERIODOS_CORTO[$s['periodo']]) ? ' <span class="extra__per">' . e(NV_PERIODOS_CORTO[$s['periodo']]) . '</span>' : '') . "</p>\n          </div>";
        }
        if ($car = nv_caracteristicas($s)) {
            $h .= "\n          <ul>";
            foreach ($car as $c) $h .= "\n            <li>" . e($c) . '</li>';
            $h .= "\n          </ul>";
        }
        if ($s['nota']) {
            // «Sin permanencia.» al principio de la nota se resalta, como antes
            $nota = e($s['nota']);
            $nota = preg_replace('/^(Sin permanencia\.)/u', '<strong>$1</strong>', $nota);
            $h .= "\n          <p class=\"extra__note\">" . $nota . '</p>';
        }
        $h .= "\n          <a href=\"#contacto\" class=\"btn btn--ghost\" data-plan=\"" . e($corto) . '">' . ($s['clave'] === 'mantenimiento' ? 'Quiero mantenimiento' : 'Me interesa') . '</a>'
            . "\n        </article>";
    }
    return $h . "\n        ";
}

/** Nombres que se pueden elegir en el formulario de contacto (planes y servicios visibles). */
function nv_nombres_productos(): array {
    $n = [];
    foreach (nv_productos(null, true) as $p) if (!in_array($p['clave'], ['alojamiento', 'alojamiento3'], true)) $n[] = $p['tipo'] === 'plan' ? $p['nombre'] : nv_nombre_corto($p['nombre']);
    return $n;
}

/** Opciones del desplegable «Plan que te interesa» del formulario. */
function nv_html_opciones(array $planes, array $servicios): string {
    $nombres = ['Aún no lo sé'];
    foreach ($planes as $p) $nombres[] = $p['nombre'];
    foreach ($servicios as $s) if (!in_array($s['clave'], ['alojamiento', 'alojamiento3'], true)) $nombres[] = nv_nombre_corto($s['nombre']);
    $h = '';
    foreach (array_unique($nombres) as $n) $h .= "\n              <option value=\"" . e($n) . '">' . e($n) . '</option>';
    return $h . "\n              ";
}

/** Ofertas para los datos estructurados de Google (precios sin IVA). */
function nv_ofertas_ld(array $productos): array {
    $o = [];
    foreach ($productos as $p) {
        if ($p['clave'] === 'alojamiento3') continue;
        $x = ['@type' => 'Offer', 'name' => ($p['tipo'] === 'plan' ? 'Plan ' : '') . nv_nombre_corto($p['nombre'])];
        if ($p['precio'] !== null) { $x['price'] = rtrim(rtrim(number_format($p['precio'] / 100, 2, '.', ''), '0'), '.'); $x['priceCurrency'] = 'EUR'; }
        $desc = trim((string)$p['descripcion']);
        if ($desc !== '') $x['description'] = $desc;
        $o[] = $x;
    }
    return $o;
}

/**
 * Sustituye en el HTML de la portada los bloques marcados con <!-- planes:inicio --> … <!-- planes:fin -->
 * (y lo mismo con servicios y opciones) por lo que hay en la base de datos. Si algo falla, la portada se queda como está.
 */
function nv_portada(string $html): string {
    try {
        $planes = nv_productos('plan', true);
        $servicios = nv_productos('servicio', true);
    } catch (Throwable $e) {
        error_log('Novis portada: ' . $e->getMessage());
        return $html;
    }
    $bloques = ['planes' => nv_html_planes($planes), 'servicios' => nv_html_servicios($servicios), 'opciones' => nv_html_opciones($planes, $servicios)];
    foreach ($bloques as $k => $nuevo) {
        $html = preg_replace_callback('/(<!-- ' . $k . ':inicio -->)(.*?)(<!-- ' . $k . ':fin -->)/s', fn($m) => $m[1] . $nuevo . $m[3], $html, 1);
    }
    // Datos estructurados (lo que Google lee de los precios)
    $html = preg_replace_callback('#(<script type="application/ld\+json">)(.*?)(</script>)#s', function ($m) use ($planes, $servicios) {
        $d = json_decode($m[2], true);
        if (!is_array($d) || !isset($d['@graph'][0]['hasOfferCatalog'])) return $m[0];
        $d['@graph'][0]['hasOfferCatalog']['itemListElement'] = nv_ofertas_ld(array_merge($planes, $servicios));
        return $m[1] . json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . $m[3];
    }, $html, 1);
    return $html;
}
