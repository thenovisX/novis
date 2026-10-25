# The Novis

Web de The Novis — diseño y desarrollo web para empresas.

## Estructura

```
index.html        Página principal
css/styles.css    Estilos
js/main.js        Menú, animaciones y formulario
contact.php       Envío del formulario por email (PHP)
assets/           Favicon e imágenes
.htaccess         HTTPS, compresión y caché
```

## Publicar en Hostinger con actualización automática

1. En hPanel entra en **Sitios web → Administrar → Avanzado → Git**.
2. En *Crear un nuevo repositorio* pega la URL del repositorio
   (`https://github.com/thenovisX/novis.git`), rama `main`, y deja el directorio
   vacío para que se instale en `public_html`. Pulsa **Crear**.
3. Si el repositorio es privado, copia la **clave SSH** que te muestra Hostinger y
   añádela en GitHub → repositorio → *Settings → Deploy keys*. Usa entonces la URL SSH
   (`git@github.com:thenovisX/novis.git`).
4. Pulsa **Implementar** una vez para hacer la primera carga.
5. Activa **Implementación automática**: Hostinger te da una URL de webhook.
6. En GitHub → *Settings → Webhooks → Add webhook*, pega esa URL
   (Content type: `application/json`, evento *Just the push event*).

A partir de ahí, cada `git push` a `main` actualiza la web sola.

## Configurar el formulario

Los mensajes llegan a `hola@thenovis.com` (variable `$destino` en `contact.php`).
Los emails se envían por SMTP con el buzón `hola@thenovis.com` (smtp.hostinger.com:465)
usando `smtp_user` y `smtp_password` de la configuración privada.

## Base de datos MySQL de Hostinger (asistente de instalación)

1. En hPanel → **Bases de datos → Administración** crea una base de datos MySQL (nombre, usuario y contraseña).
2. Entra en `thenovis.com/panel/`. La primera vez se abre el **asistente** (`panel/instalar.php`): escribe el nombre de la base de datos,
   el usuario, la contraseña, el servidor (`localhost`) y el puerto (`3306`).
3. El asistente comprueba la conexión, crea las tablas y **copia todo lo que había** en la base de datos SQLite anterior
   (cuentas, pagos, mensajes, renovaciones…), sin descifrar nada. Si algo falla, no cambia nada.
4. Los datos de conexión se guardan en `novis-db.php`, **fuera de `public_html`**, con permisos 600. Está en `.gitignore`
   y el `.htaccess` prohíbe servirlo. Si el servidor no deja crearlo, el asistente enseña el texto para crearlo a mano.
5. Después pide tu información (correo para avisos) y el usuario y la contraseña del panel, que se guardan en la base de datos (hash bcrypt).

Mientras no exista `novis-db.php`, la web sigue usando `novis-data/solicitudes.sqlite`. En una instalación nueva sin usuario de panel,
el asistente pide el código del archivo `novis-instalar.txt` (se crea solo fuera de `public_html`), para que nadie de fuera pueda adelantarse.
También vale poner la conexión en `gemini-config.php`: `'db' => ['host' => 'localhost', 'puerto' => 3306, 'nombre' => '…', 'usuario' => '…', 'clave' => '…']`.

En phpMyAdmin los datos personales se ven cifrados (`nv1:…`): es a propósito. La clave de cifrado sigue en `novis-clave.key` (o `data_key`).

## Panel: Planes y Ajustes

- **Planes** (`panel/planes.php`): crear, editar, ordenar, ocultar y borrar planes web y servicios, con precio sin IVA,
  «desde», periodo de cobro, lo que incluye, nota, etiqueta y destacado. La portada (`index.php` = `index.html` con los bloques
  `<!-- planes:… -->`, `<!-- servicios:… -->` y `<!-- opciones:… -->` sustituidos), los datos estructurados, el chat y el formulario
  los leen de la tabla `productos`. Los servicios marcados como «se contrata online» forman el catálogo del área de cliente
  (antes `NV_CATALOGO`). La versión gallega (`gl/index.html`) sigue con sus precios escritos.
- **Ajustes → Tu negocio y contacto**: nombre comercial, titular, NIF, domicilio, localidad, persona de contacto, correo y teléfono (con casilla para mostrarlo).
  Las páginas públicas llevan marcas `<!--d:clave-->por defecto<!--/d-->` y `pagina.php` (vía `.htaccess`) las cambia por esos datos; `hola@thenovis.com` se sustituye por tu correo.
  También salen en el pie de los emails y en el chat. Las facturas de Stripe usan los datos de tu cuenta de Stripe.
- **Ajustes** (`panel/ajustes.php`): nombre del negocio, tu correo (avisos y el que ven los clientes), teléfono, buzón de envío
  (SMTP; la contraseña se guarda cifrada), usuario y contraseña del panel (cambiarla cierra las demás sesiones) y datos de la base de datos.
  Lo guardado aquí manda sobre `gemini-config.php`.

## Configuración privada (`gemini-config.php`, fuera de `public_html`)

```php
<?php
return [
  'key' => 'CLAVE_GEMINI',          // con facturación activada (obligatorio para usuarios de la UE)
  'panel_user' => 'USUARIO',
  'panel_password' => 'CONTRASEÑA', // mínimo 8 caracteres (provisional)
  // Opcional: clave de cifrado de la base de datos (si no, se crea sola en novis-clave.key). Ver «Seguridad y cifrado».
  // 'data_key' => 'BASE64_DE_32_BYTES',
  // Recomendado: en el panel, abajo del todo → «Seguridad», genera esta línea y sustituye la anterior:
  // 'panel_password_hash' => '$2y$10$...',
  'smtp_user' => 'hola@thenovis.com',
  'smtp_password' => 'CONTRASEÑA_DEL_BUZÓN', // la del email en Hostinger
  // Pagos con Stripe
  'stripe_secret' => 'sk_live_...',          // o sk_test_... para pruebas
  'stripe_webhook_secret' => 'whsec_...',
  // Acceso con Google (opcional)
  'google_client_id' => '....apps.googleusercontent.com',
  'google_client_secret' => '...',
];
```

## Pagos de clientes (`/pagos/`)

1. En el panel → **Pagos → Nuevo pago** creas un código (concepto, contenido, importe con IVA, email opcional, caducidad opcional).
2. El cliente entra en `thenovis.com/pagos/`, crea su cuenta (email, teléfono, usuario y contraseña, o Google), confirma su email e introduce el código.
3. Paga en la página segura de Stripe. Stripe avisa a `pagos/webhook.php` y el pago queda como **Pagado** en el panel; cliente y The Novis reciben un email.

Configuración en Stripe: *Developers → Webhooks → Add endpoint* → `https://thenovis.com/pagos/webhook.php`, eventos:
`checkout.session.completed`, `checkout.session.async_payment_succeeded`, `customer.subscription.updated`,
`customer.subscription.deleted`, `invoice.paid` e `invoice.payment_failed`.

### Suscripciones (mantenimiento mensual, alojamiento anual o cada 3 años)

- Al crear el código en el panel eliges **Tipo de cobro**: pago único, suscripción mensual, anual o cada 3 años (alojamiento con descuento).
- El cliente se suscribe con el código; Stripe cobra solo cada periodo. Cada renovación aparece en el panel y llega un email.
- El cliente puede **darse de baja** (sigue activa hasta el final del periodo pagado), anular la baja o cambiar la tarjeta desde `thenovis.com/pagos/`.
- Para «Cambiar tarjeta o ver facturas» activa el portal en Stripe: *Settings → Billing → Customer portal* (guardar una vez).
- Recomendado: *Settings → Billing → Subscriptions and emails* → reintentos automáticos y cancelar la suscripción si todos fallan.
- Si se borra una cuenta (cliente, panel o página «Tus datos»), sus suscripciones se cancelan al momento.

Configuración en Google Cloud: credenciales OAuth (aplicación web) con URI de redirección
`https://thenovis.com/pagos/google.php`.

## Área de cliente (`/pagos/`, icono de cuenta en la cabecera)

- Pestañas: **Inicio** (resumen y pendientes), **Servicios** (contratados y catálogo para contratar mantenimiento o alojamiento sin código), **Mensajes** (conversaciones con The Novis), **Pagos** (código e historial) y **Cuenta** (descargar datos, eliminar cuenta y datos).
- Precios del catálogo en `NV_CATALOGO` (`lib/cuentas.php`), sin IVA; el cliente paga con el 21 % incluido.
- Los mensajes de clientes llegan al panel como solicitudes («Cliente · …»). Se responden desde el detalle de la solicitud: el cliente lo ve en su área y le llega por email.

## Seguridad y cifrado de la base de datos

- La base de datos (`novis-data/solicitudes.sqlite`), las sesiones y los límites de intentos viven **fuera de `public_html`**
  (carpeta privada con permisos 700; los archivos, 600) y la carpeta lleva además un `.htaccess` que lo prohíbe todo.
- **Todos los datos personales se guardan cifrados** (XChaCha20-Poly1305 con libsodium; AES-256-GCM como respaldo):
  nombre, empresa, email, teléfono, mensajes, notas, conversaciones, conceptos de pago, emails de cobro y enlaces de «Tus datos».
  Quedan en claro a propósito: fechas, estados, importes, códigos de pago e identificadores de Stripe.
  El identificador de Google solo se guarda como huella (HMAC).
- Para buscar por email o usuario sin descifrar todo se guarda un **índice ciego** (HMAC-SHA256 con otra clave derivada); el login, el
  registro, «Tus datos» y el enlace entre mensajes y cuentas funcionan con él. La búsqueda del panel se hace ya con los datos descifrados.
- **La clave** (32 bytes en base64) se crea sola en la primera visita en `novis-clave.key`, junto a `gemini-config.php` (fuera de `public_html`).
  Para tenerla en la configuración en lugar de en ese archivo: `'data_key' => '...'` en `gemini-config.php` (usa exactamente la misma clave) y borra `novis-clave.key`.
  **Guarda una copia** (panel → Seguridad → «Copia de la clave de cifrado»): sin ella los datos no se pueden recuperar. La aplicación nunca crea una clave
  nueva si ya hay datos cifrados; si falta o no coincide, se niega a arrancar (mejor un error que datos corruptos).
- Los datos que ya existían sin cifrar se cifran solos la primera vez que se abre la base de datos (transacción única, repetible) y el archivo se compacta.
- Panel → **Seguridad**: estado del cifrado y de los permisos, comprobación desde internet de que los archivos internos no se pueden abrir y copia de la clave.
- Las copias de seguridad de Hostinger hechas **antes** del cifrado contienen los datos sin cifrar; las posteriores, solo texto cifrado.
- Sesiones: carpeta privada propia, cookies `HttpOnly` + `SameSite`; el panel caduca a los 7 días sin uso (máximo 30) y el área de cliente a las 2 h.
- Límites de intentos por IP (guardada solo como huella con clave), por cuenta y, en el panel, global; el login no delata si una cuenta existe.
- Una cuenta con el email sin confirmar no puede ver, descargar ni borrar nada hasta confirmarlo.

## Cumplimiento legal (RGPD, LOPDGDD, LSSI-CE)

- Páginas: `aviso-legal.html`, `privacidad.html`, `cookies.html`, `condiciones.html`, `accesibilidad.html`.
  Están adaptadas a partir de las plantillas CC0 de [General Legal](https://github.com/General-Legal/legal-templates),
  copiadas en `legal/plantillas/` (bloqueada al público). Detalles y cómo añadir servicios: `legal/LEEME.md`.
  **Son una base: conviene que las revise un abogado.**
- Titular, NIF y domicilio en el panel → Ajustes (mientras falten, las páginas legales muestran `[PENDIENTE]`).
- Panel → **Legal**: Registro Mercantil, delegado de protección de datos, juzgados, ID de Google Analytics (o desactivarlo)
  y la lista de **servicios externos** que sale en la política de privacidad (marca `<!--d:proveedores-->`).
- Formulario con consentimiento expreso; las solicitudes se borran solas a los 2 años.
- Chat identificado como IA (Reglamento UE de IA, art. 50).

### Perfil de cliente y publicidad (solo con permiso)

- **Formulario de contacto**: casilla obligatoria de privacidad y dos opcionales sin marcar: «perfil de contacto» y «promociones».
  Tabla `contactos` (cifrada, una fila por email, solo si marcó alguna); se ve en Panel → Cuentas → **Contactos**.
- **Registro** (también con Google): casilla opcional de promociones. **Pago**: casilla obligatoria de condiciones y privacidad
  (con la petición de empezar ya, por el desistimiento), anotada en `auditoria` como `compra_acepta`.

- Al pagar (`pagos/`, resumen del pago) salen dos casillas **opcionales y sin marcar**: guardar el perfil de cliente y recibir publicidad.
  Si no se marcan, solo se guarda el pago (obligación legal).
- Tabla `perfiles` (una fila por cuenta, solo si aceptó algo): `marketing` y `perfil` son la fecha del permiso; nombre, empresa, NIF,
  dirección y teléfono (cifrados) se copian de la página de pago de Stripe al confirmarse el pago, solo si `perfil` tiene fecha.
- Cada permiso dado o retirado queda en `auditoria` (`publicidad_acepta`, `publicidad_retira`, `perfil_guardar_acepta`, `perfil_guardar_retira`)
  con fecha, versión de la privacidad (`NV_PRIVACIDAD`, `lib/legal.php`), IP y navegador cifrados.
- El cliente lo cambia en «Tu cuenta» o en `datos/` (sin cuenta, con enlace por email). Al eliminar la cuenta se borra todo.
- Panel → Cuentas: insignias «Perfil guardado» y «Publicidad», pestaña **Publicidad** y descarga en CSV de quien la ha aceptado.
  Envía publicidad solo a esa lista y con un enlace de baja en cada email (arts. 21 y 22 LSSI-CE).

## Cookies y Google Analytics

- El aviso de cookies está en `js/consent.js` (todas las páginas públicas): categorías **Necesarias** y **Analítica**, y una de
  **Publicidad** preparada y oculta mientras no haya servicios. «Rechazar todas» y «Aceptar todas» son iguales; hasta que el visitante
  elige no se carga nada de Google. Respeta Global Privacy Control. Desde el pie, «Configurar cookies» permite cambiar la elección.
  Si se añade una categoría nueva, se vuelve a preguntar.
- Google Analytics 4: el ID por defecto está en `GA_POR_DEFECTO` (`js/consent.js`); desde el panel → Legal se puede cambiar o
  desactivar (llega como `<html data-ga="…">`). En Analytics, pon la conservación de datos en 14 meses (o menos) y desactiva las
  señales de Google (publicidad).
- El CSP de `.htaccess` ya permite `googletagmanager.com` y `google-analytics.com`.

## Registro de auditoría

Tabla `auditoria` (solo se añaden filas): aceptación de condiciones y privacidad (con la versión `NV_CONDICIONES` de `lib/cuentas.php`), email verificado, contraseña cambiada, alta con Google y baja de cuenta. IP y navegador van cifrados; se borra a los 3 años y, al eliminar una cuenta, se desvincula de la persona. Al cambiar `condiciones.html`, actualiza la fecha de `NV_CONDICIONES`; al cambiar `privacidad.html`, la de `NV_PRIVACIDAD`.
