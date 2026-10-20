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
  'panel_password' => 'CONTRASEÑA', // mínimo 8 caracteres
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

## Cumplimiento legal

- Páginas: `aviso-legal.html`, `privacidad.html`, `cookies.html`, `accesibilidad.html`.
- Rellenar los datos marcados como `[PENDIENTE]` (titular, NIF, domicilio) en aviso legal y privacidad.
- Formulario con consentimiento expreso; las solicitudes se borran solas a los 2 años.
- Sin cookies de terceros ni Google Fonts externas: no hace falta banner de cookies.
- Chat identificado como IA (Reglamento UE de IA, art. 50).
