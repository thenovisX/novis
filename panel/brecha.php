<?php
// The Novis — panel: protocolo ante una brecha de seguridad y registro de incidentes.
//  · Pasos a seguir (contener, evaluar, notificar, registrar, revisar).
//  · Botón de emergencia: cerrar todas las sesiones abiertas (clientes y panel).
//  · Registro de incidentes (art. 33.5 RGPD), con los textos cifrados y cuenta atrás de 72 h.
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
function txt(string $k, int $max): string {
    $v = $_POST[$k] ?? '';
    return is_string($v) ? trim(mb_substr($v, 0, $max)) : '';
}
/** 'AAAA-MM-DDTHH:MM' del formulario → ISO 8601, o null si no es válida. */
function fecha_form(string $v): ?string {
    if ($v === '') return null;
    $d = DateTime::createFromFormat('Y-m-d\TH:i', $v) ?: DateTime::createFromFormat('Y-m-d', $v);
    return $d ? $d->format('c') : null;
}
function fecha_input(?string $iso): string { return $iso ? date('Y-m-d\TH:i', strtotime($iso)) : ''; }
function fecha_ver(?string $iso): string { return $iso ? date('d/m/Y H:i', strtotime($iso)) : '—'; }

$db = nv_db();
$error = '';
$aviso = '';
$RIESGOS = ['bajo' => 'Bajo (improbable que afecte a nadie)', 'medio' => 'Medio', 'alto' => 'Alto (puede dañar a las personas)'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');
    if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
        $error = 'La página ha caducado. Inténtalo de nuevo.';
    } elseif ($accion === 'sesiones') {
        if (!novis_limite('brecha-panel', 5, 900)) $error = 'Demasiados intentos. Espera un rato.';
        elseif (!nv_panel_clave_ok((string)($_POST['password'] ?? ''))) { sleep(1); $error = 'Contraseña incorrecta.'; }
        else {
            $n = 0;
            foreach (['cliente', 'panel'] as $c) {
                foreach (glob(novis_dir('sesiones/' . $c) . '/sess_*') ?: [] as $f) if (@unlink($f)) $n++;
            }
            $_SESSION = [];
            session_destroy();
            header('Location: ./?cerradas=' . $n);
            exit;
        }
    } elseif ($accion === 'guardar') {
        $id = (int)($_POST['id'] ?? 0);
        $descubierto = fecha_form(txt('descubierto', 20));
        $descripcion = txt('descripcion', 4000);
        $riesgo = array_key_exists($_POST['riesgo'] ?? '', $RIESGOS) ? $_POST['riesgo'] : 'bajo';
        $personas = txt('personas', 9);
        if (!$descubierto) $error = 'Indica cuándo te diste cuenta (fecha y hora).';
        elseif ($descripcion === '') $error = 'Describe qué ha pasado.';
        else {
            $vals = [$descubierto, nv_cifrar($descripcion), nv_cifrar(txt('datos', 2000)), $personas === '' ? null : max(0, (int)$personas),
                $riesgo, fecha_form(txt('aepd', 20)), fecha_form(txt('afectados', 20)), nv_cifrar(txt('medidas', 4000)),
                !empty($_POST['cerrado']) ? date('c') : null];
            if ($id > 0) {
                $prev = $db->prepare('SELECT cerrado FROM incidentes WHERE id = ?');
                $prev->execute([$id]);
                $antes = $prev->fetchColumn();
                if ($antes && !empty($_POST['cerrado'])) $vals[8] = $antes;
                $vals[] = $id;
                $db->prepare('UPDATE incidentes SET descubierto=?, descripcion=?, datos=?, personas=?, riesgo=?, aepd=?, afectados=?, medidas=?, cerrado=? WHERE id=?')->execute($vals);
                $aviso = 'Incidente actualizado.';
            } else {
                array_unshift($vals, date('c'));
                $db->prepare('INSERT INTO incidentes (creado, descubierto, descripcion, datos, personas, riesgo, aepd, afectados, medidas, cerrado) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute($vals);
                $aviso = 'Incidente registrado. Sigue los pasos de arriba y vuelve aquí para anotar lo que hagas.';
            }
        }
    }
}

$incidentes = $db->query('SELECT * FROM incidentes ORDER BY descubierto DESC')->fetchAll();
$editar = null;
$idEditar = is_string($_GET['editar'] ?? null) ? (int)$_GET['editar'] : 0;
foreach ($incidentes as $i) if ((int)$i['id'] === $idEditar) $editar = $i;
// Si falló el guardado, se vuelve a mostrar lo escrito
if ($error && ($_POST['accion'] ?? '') === 'guardar') {
    $editar = ['id' => (int)($_POST['id'] ?? 0), 'descubierto' => fecha_form(txt('descubierto', 20)), 'descripcion' => txt('descripcion', 4000),
        'datos' => txt('datos', 2000), 'personas' => txt('personas', 9), 'riesgo' => (string)($_POST['riesgo'] ?? 'bajo'),
        'aepd' => fecha_form(txt('aepd', 20)), 'afectados' => fecha_form(txt('afectados', 20)), 'medidas' => txt('medidas', 4000), 'cerrado' => !empty($_POST['cerrado']) ? 'x' : null];
}
$f = fn(string $k) => e((string)($editar[$k] ?? ''));

/** Horas que quedan del plazo de 72 h para avisar a la AEPD (negativo si ya pasó). */
function horas_restantes(array $i): ?int {
    if ($i['aepd'] || $i['cerrado'] || $i['riesgo'] === 'bajo') return null;
    return (int)floor((strtotime($i['descubierto']) + 72 * 3600 - time()) / 3600);
}

$plantilla = "Asunto: Información importante sobre tus datos en The Novis\n\n"
    . "Hola:\n\n"
    . "Te escribimos porque el [FECHA] detectamos un incidente de seguridad en thenovis.com que puede afectar a tus datos.\n\n"
    . "Qué ha pasado: [DESCRIPCIÓN BREVE Y CLARA].\n"
    . "Qué datos tuyos están afectados: [P. EJ. NOMBRE Y EMAIL]. No están afectados: [P. EJ. DATOS DE TARJETA, QUE NUNCA GUARDAMOS].\n"
    . "Qué hemos hecho: [P. EJ. CERRAR EL ACCESO, CAMBIAR CLAVES, AVISAR A LA AEPD].\n"
    . "Qué te recomendamos: [P. EJ. CAMBIAR TU CONTRASEÑA DE NOVIS Y DESCONFIAR DE EMAILS QUE PIDAN DATOS O PAGOS EN NUESTRO NOMBRE].\n\n"
    . "Puedes escribirnos a " . nv_email_negocio() . " con cualquier duda o para ejercer tus derechos. También puedes reclamar ante la Agencia Española de Protección de Datos (www.aepd.es).\n\n"
    . "Sentimos las molestias.\nEl equipo de The Novis\n";
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Brecha de seguridad · The Novis</title>
<link rel="icon" href="../assets/favicon.svg?v=10" type="image/svg+xml">
<meta name="theme-color" content="#f5f5f7">
<link rel="stylesheet" href="panel.css?v=20">
</head>
<body class="v-pagos">
  <header class="top top--detail"><a href="seguridad.php" class="back">‹ Seguridad</a></header>
  <main class="detail">
    <section class="card">
      <h1>Protocolo ante una brecha</h1>
      <?php if ($aviso): ?><p class="toast" role="status"><?= e($aviso) ?></p><?php endif; ?>
      <p class="muted">Una brecha es cualquier incidente por el que datos personales se pierden, se destruyen, se modifican o los ve alguien que no debe: un acceso no autorizado al hosting o al panel, una copia de la base de datos que se filtra, un email enviado a quien no era, un portátil robado con datos… Ante la duda, trátalo como brecha y anótalo.</p>
      <?php foreach ($incidentes as $i): $h = horas_restantes($i); if ($h === null) continue; ?>
        <p class="warn"><strong>Incidente del <?= e(fecha_ver($i['descubierto'])) ?>:</strong>
          <?= $h > 0 ? "quedan $h h para avisar a la AEPD." : 'el plazo de 72 h para avisar a la AEPD ya ha pasado: notifícalo cuanto antes explicando el motivo del retraso.' ?></p>
      <?php endforeach; ?>
    </section>

    <section class="card">
      <h2>1 · Primera hora: contener</h2>
      <ol class="pasos">
        <li><strong>Anota la fecha y hora</strong> en que te diste cuenta (registro de abajo). Desde ese momento corren las <strong>72 horas</strong> para avisar a la AEPD.</li>
        <li><strong>Cierra todas las sesiones</strong> con el botón de abajo: saca a cualquiera que esté dentro del panel o de las áreas de clientes (tú también tendrás que volver a entrar).</li>
        <li><strong>Cambia las contraseñas</strong>, empezando por la que creas comprometida: Hostinger (hPanel), GitHub, el panel (Seguridad → Contraseña del panel), el buzón hola@ (y <code>smtp_password</code>), y regenera las claves de Stripe, Gemini y Google, poniéndolas en <code>gemini-config.php</code>.</li>
        <li><strong>Si alguien ha modificado la web</strong>, en hPanel restaura la última copia de seguridad buena o vuelve a desplegar desde GitHub. No borres pruebas: antes descarga una copia de lo que encuentres raro.</li>
        <li>Si crees que la <strong>clave de cifrado</strong> también ha salido del servidor (o <code>gemini-config.php</code>), los datos cifrados ya no están protegidos: trátalo como riesgo alto. Hay que generar una clave nueva y volver a cifrar todos los datos con ella; <strong>no cambies la clave a mano</strong>, o los datos quedarían ilegibles.</li>
      </ol>
      <form method="post" class="form-pago" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="accion" value="sesiones">
        <label>Contraseña del panel <span class="muted">(para cerrar todas las sesiones)</span>
          <input name="password" type="password" autocomplete="current-password" required></label>
        <?php if ($error && ($_POST['accion'] ?? '') === 'sesiones'): ?><p class="f-err"><?= e($error) ?></p><?php endif; ?>
        <button class="btn">Cerrar todas las sesiones</button>
      </form>
    </section>

    <section class="card">
      <h2>2 · Evaluar el alcance</h2>
      <ul class="pasos">
        <li><strong>¿Qué datos?</strong> Solicitudes de contacto, cuentas de clientes, pagos, conversaciones… Recuerda que los datos de tarjeta nunca pasan por The Novis (los guarda Stripe).</li>
        <li><strong>¿De cuántas personas?</strong> Una cifra aproximada vale.</li>
        <li><strong>¿Estaban cifrados y la clave sigue a salvo?</strong> Si solo se llevaron el archivo de la base de datos, sin la clave, los datos personales son ilegibles y el riesgo es mucho menor.</li>
        <li><strong>¿Qué daño podría causar?</strong> Spam o suplantación (emails de phishing en nombre de The Novis), pérdida de información de clientes, datos de pagos…</li>
      </ul>
    </section>

    <section class="card">
      <h2>3 · Notificar (si hace falta)</h2>
      <ul class="pasos">
        <li><strong>A la AEPD, en 72 h</strong>, salvo que sea improbable que la brecha suponga un riesgo para las personas (por ejemplo, datos cifrados con la clave a salvo). Se hace en la <a href="https://sedeagpd.gob.es" rel="noopener" target="_blank">sede electrónica de la AEPD</a>. Si te falta información, notifica lo que sepas y complétalo después. La herramienta <strong>Asesora Brecha</strong> de <a href="https://www.aepd.es" rel="noopener" target="_blank">aepd.es</a> te ayuda a decidirlo.</li>
        <li><strong>A las personas afectadas, sin demora</strong>, si el riesgo es <strong>alto</strong>. No hace falta si los datos estaban cifrados y la clave sigue a salvo, o si ya has tomado medidas que eliminan ese riesgo. Abajo tienes una plantilla.</li>
        <li><strong>A los proveedores implicados</strong>: soporte de Hostinger si el problema viene del hosting; Stripe o Google si afecta a sus claves o cuentas.</li>
        <li>Si hay un delito (alguien ha entrado sin permiso, te piden un rescate…), puedes denunciarlo ante la Policía Nacional o la Guardia Civil.</li>
      </ul>
      <details>
        <summary><strong>Plantilla de aviso a los afectados</strong></summary>
        <textarea class="msgbox" rows="14" readonly id="plantilla"><?= e($plantilla) ?></textarea>
        <div class="actions"><button class="btn btn--ghost" type="button" data-copy-from="plantilla" data-copied="Copiada ✓">Copiar plantilla</button></div>
        <p class="muted small">Envíala a cada persona por separado (o en copia oculta), con lenguaje sencillo y sin minimizar lo ocurrido.</p>
      </details>
    </section>

    <section class="card">
      <h2>4 · Registrar y aprender</h2>
      <p class="muted">Todas las brechas se anotan, también las que no se notifican: la AEPD puede pedir este registro. Cuando todo esté resuelto, apunta qué falló y qué has cambiado para que no vuelva a pasar, y márcalo como cerrado.</p>
    </section>

    <section class="card" id="registro">
      <h2><?= !empty($editar['id']) ? 'Editar incidente' : 'Registrar un incidente' ?></h2>
      <form method="post" action="brecha.php#registro" class="form-pago" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" value="<?= (int)($editar['id'] ?? 0) ?>">
        <label>Cuándo te diste cuenta
          <input name="descubierto" type="datetime-local" required value="<?= e(fecha_input($editar['descubierto'] ?? null) ?: ($editar ? '' : date('Y-m-d\TH:i'))) ?>"></label>
        <label>Qué ha pasado
          <textarea name="descripcion" rows="4" class="msgbox" maxlength="4000" required><?= $f('descripcion') ?></textarea></label>
        <label>Datos afectados <span class="muted">(qué tipo de datos; ¿estaban cifrados?)</span>
          <textarea name="datos" rows="2" class="msgbox" maxlength="2000"><?= $f('datos') ?></textarea></label>
        <label>Personas afectadas <span class="muted">(aproximado)</span>
          <input name="personas" inputmode="numeric" maxlength="9" value="<?= $f('personas') ?>"></label>
        <fieldset class="tipo">
          <legend>Riesgo para las personas</legend>
          <?php foreach ($RIESGOS as $k => $t): ?>
            <label><input type="radio" name="riesgo" value="<?= e($k) ?>"<?= ($editar['riesgo'] ?? 'bajo') === $k ? ' checked' : '' ?>> <?= e($t) ?></label>
          <?php endforeach; ?>
        </fieldset>
        <label>Notificada a la AEPD el <span class="muted">(vacío si no se ha notificado)</span>
          <input name="aepd" type="datetime-local" value="<?= e(fecha_input($editar['aepd'] ?? null)) ?>"></label>
        <label>Afectados avisados el <span class="muted">(vacío si no ha hecho falta)</span>
          <input name="afectados" type="datetime-local" value="<?= e(fecha_input($editar['afectados'] ?? null)) ?>"></label>
        <label>Medidas tomadas y conclusiones
          <textarea name="medidas" rows="4" class="msgbox" maxlength="4000"><?= $f('medidas') ?></textarea></label>
        <fieldset class="tipo">
          <label><input type="checkbox" name="cerrado" value="1"<?= !empty($editar['cerrado']) ? ' checked' : '' ?>> Incidente resuelto y cerrado</label>
        </fieldset>
        <?php if ($error && ($_POST['accion'] ?? '') === 'guardar'): ?><p class="f-err"><?= e($error) ?></p><?php endif; ?>
        <div class="actions">
          <button class="btn"><?= !empty($editar['id']) ? 'Guardar cambios' : 'Registrar' ?></button>
          <?php if (!empty($editar['id'])): ?><a class="btn btn--ghost" href="brecha.php#registro">Cancelar</a><?php endif; ?>
        </div>
      </form>
    </section>

    <section class="card">
      <h2>Registro de incidentes</h2>
      <?php if (!$incidentes): ?>
        <p class="muted">No hay ningún incidente registrado.</p>
      <?php else: ?>
        <ul class="incidentes">
          <?php foreach ($incidentes as $i): ?>
            <li>
              <div class="badges">
                <span class="pill <?= $i['cerrado'] ? 'pill--cerrada' : 'pill--en_curso' ?>"><?= $i['cerrado'] ? 'Cerrado' : 'Abierto' ?></span>
                <span class="pill<?= $i['riesgo'] === 'alto' ? ' pill--error' : '' ?>">Riesgo <?= e($i['riesgo']) ?></span>
                <?php if ($i['aepd']): ?><span class="pill">AEPD avisada</span><?php endif; ?>
                <?php if ($i['afectados']): ?><span class="pill">Afectados avisados</span><?php endif; ?>
              </div>
              <p><strong><?= e(fecha_ver($i['descubierto'])) ?></strong> · <?= nl2br(e($i['descripcion'])) ?></p>
              <?php if ($i['datos'] !== null && $i['datos'] !== ''): ?><p class="muted small">Datos: <?= e($i['datos']) ?><?= $i['personas'] !== null ? ' · ' . (int)$i['personas'] . ' personas' : '' ?></p><?php endif; ?>
              <?php if ($i['medidas'] !== null && $i['medidas'] !== ''): ?><p class="muted small">Medidas: <?= nl2br(e($i['medidas'])) ?></p><?php endif; ?>
              <a href="brecha.php?editar=<?= (int)$i['id'] ?>#registro">Editar</a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </main>
  <script src="panel.js?v=4" defer></script>
</body>
</html>
