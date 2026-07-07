# Reglas del proyecto Yevea — LEER SIEMPRE

## Regla nº1 — NUNCA tocar el core de FacturaScripts
- NUNCA edites, sobrescribas ni borres archivos del core de FacturaScripts.
- El core se actualiza periódicamente y cualquier cambio directo se PIERDE en cada actualización. Modificar el core es un error grave.
- Todo el trabajo se hace DENTRO del plugin: ~/public_html/cat/Plugins/YeveaStore/ (clon git de yevea/YeveaStore).
- Si necesitas cambiar el comportamiento de una plantilla, controlador o recurso del core, la solución correcta es SOBRESCRIBIRLO desde el plugin (extender/override), nunca editar el original.
- Si una tarea parece exigir tocar el core, PÁRATE y pregunta antes de actuar. No improvises.

## Regla nº2 — ~/public_html/cat ES PRODUCCIÓN
- yevea.com/cat es la tienda EN PRODUCCIÓN (decisión de 2026-07: se descartó migrar a /catalogo para simplificar).
- Los cambios de código se verifican (lint/revisión) ANTES de hacer pull en el servidor, siempre con commit en git para poder revertir.
- Tras cada despliegue: verificar la web pública y el admin antes de dar por terminado.
- Carpetas legacy que NO se tocan: ~/public_html/catalogo (FacturaScripts antiguo) y ~/public_html/productos (WordPress viejo — solo recibirá redirecciones 301 en el lanzamiento).

## Regla nº3 — SEO es la prioridad máxima
- yevea.com ocupa el primer puesto en buscadores de su sector. No se puede perder.
- Antes de cambiar URLs, estructura o renderizado, evalúa el impacto SEO.
- Nunca rompas URLs indexadas sin un plan de redirecciones 301.
- La tienda tiene noindex activado hasta que Martín dé la orden de lanzamiento (contenido listo).

## Regla nº4 — Planificar antes de ejecutar
- Antes de modificar archivos, muestra la lista de archivos afectados y espera aprobación.
- No hagas cambios grandes sin avisar primero.
