# Novis

Web de Novis — diseño y desarrollo web para empresas.

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
3. Paga en la página segura de Stripe. Stripe avisa a `pagos/webhook.php` y el pago queda como **Pagado** en el panel; cliente y Novis reciben un email.

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

- Pestañas: **Inicio** (resumen y pendientes), **Servicios** (contratados y catálogo para contratar mantenimiento o alojamiento sin código), **Mensajes** (conversaciones con Novis), **Pagos** (código e historial) y **Cuenta** (descargar datos, eliminar cuenta y datos).
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

## Cumplimiento legal

- Páginas: `aviso-legal.html`, `privacidad.html`, `cookies.html`, `accesibilidad.html`.
- Rellenar los datos marcados como `[PENDIENTE]` (titular, NIF, domicilio) en aviso legal y privacidad.
- Formulario con consentimiento expreso; las solicitudes se borran solas a los 2 años.
- Sin cookies de terceros ni Google Fonts externas: no hace falta banner de cookies.
- Chat identificado como IA (Reglamento UE de IA, art. 50).
