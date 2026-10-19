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

Edita las dos variables al principio de `contact.php`:

- `$destino`: el email donde quieres recibir los mensajes.
- `$remitente`: un email creado en tu dominio de Hostinger (p. ej. `no-reply@tudominio.com`).

Los mensajes llegan a `sales@thenovis.com`. Crea en Hostinger el buzón `no-reply@thenovis.com` (o cambia `$remitente` por uno que exista en tu dominio).

## Configuración privada (`gemini-config.php`, fuera de `public_html`)

```php
<?php
return [
  'key' => 'CLAVE_GEMINI',          // con facturación activada (obligatorio para usuarios de la UE)
  'panel_user' => 'USUARIO',
  'panel_password' => 'CONTRASEÑA', // mínimo 8 caracteres
];
```

## Cumplimiento legal

- Páginas: `aviso-legal.html`, `privacidad.html`, `cookies.html`, `accesibilidad.html`.
- Rellenar los datos marcados como `[PENDIENTE]` (titular, NIF, domicilio) en aviso legal y privacidad.
- Formulario con consentimiento expreso; las solicitudes se borran solas a los 2 años.
- Sin cookies de terceros ni Google Fonts externas: no hace falta banner de cookies.
- Chat identificado como IA (Reglamento UE de IA, art. 50).
