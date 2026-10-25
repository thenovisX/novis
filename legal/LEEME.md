# Textos legales de The Novis

Esta carpeta **no se publica** en la web (el `.htaccess` la bloquea). Sirve de referencia para mantener las páginas legales.

## Plantillas base

`plantillas/general-legal/` contiene una copia de las plantillas de
[General-Legal/legal-templates](https://github.com/General-Legal/legal-templates)
(commit `6d68054`, 22/09/2026), redactadas por los abogados de General Legal, PC:

| Plantilla | Para qué la usamos |
|---|---|
| `privacy-policy-gdpr` | Estructura de `privacidad.html`: origen de los datos, bases legales, destinatarios, transferencias, conservación, derechos, menores, cambios. |
| `cookie-notice` | Estructura de `cookies.html`: categorías, tus opciones, cambios. |
| `terms-of-use` | Cláusulas de uso del sitio en `aviso-legal.html`. |
| `dpa-global` | Base para el contrato de encargado del tratamiento (art. 28 RGPD) del Plan Restaurante. |

**Licencia:** CC0 1.0 (dominio público): se pueden usar, modificar y distribuir sin pedir permiso ni citar.
Aun así, General Legal pide mantener su nota de crédito, así que la conservamos en las plantillas copiadas
y en un comentario HTML de cada página legal. Las plantillas están pensadas para EE. UU. y en inglés: los textos
de la web son una **adaptación** al Derecho español y europeo (RGPD, LOPDGDD, LSSI-CE, Ley General para la
Defensa de los Consumidores y Usuarios), no una traducción.

> Las plantillas y estas adaptaciones no son asesoramiento jurídico. Conviene que un abogado revise los textos
> antes de publicarlos como definitivos.

## Qué se puede cambiar desde el panel (sin tocar código)

- **Ajustes → Tu negocio y contacto:** titular, NIF, domicilio, correo y teléfono.
- **Legal:** inscripción en el Registro Mercantil, delegado de protección de datos, juzgados competentes,
  ID de Google Analytics y la lista de **servicios externos** (proveedores) que sale en la política de privacidad.

Las páginas llevan marcas `<!--d:clave-->texto por defecto<!--/d-->` que `pagina.php` cambia por esos datos.

## Añadir un servicio nuevo (p. ej. Mailchimp, Meta, Calendly)

1. Panel → **Legal → Servicios externos**: añade una fila (nombre, para qué, dónde trata los datos y enlace a su privacidad).
2. Si el servicio **instala cookies que no son necesarias**, añádelo en `js/consent.js` (lista `CATEGORIAS`) para que
   solo se cargue con consentimiento, y añade sus cookies a la tabla de `cookies.html`.
3. Si se usa para enviar publicidad, solo a quien tenga la casilla de publicidad marcada (panel → Cuentas → «Publicidad»).
4. Cambia la fecha de «Última actualización» de las páginas afectadas.
