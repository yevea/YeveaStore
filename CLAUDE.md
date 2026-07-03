# Reglas del proyecto Yevea — LEER SIEMPRE

## Regla nº1 — NUNCA tocar el core de FacturaScripts
- NUNCA edites, sobrescribas ni borres archivos del core de FacturaScripts.
- El core se actualiza periódicamente y cualquier cambio directo se PIERDE en cada actualización. Modificar el core es un error grave.
- Todo el trabajo se hace DENTRO de los plugins, en ~/public_html/madera/Plugins/.
- Si necesitas cambiar el comportamiento de una plantilla, controlador o recurso del core, la solución correcta es SOBRESCRIBIRLO desde el plugin (extender/override), nunca editar el original.
- Si una tarea parece exigir tocar el core, PÁRATE y pregunta antes de actuar. No improvises.

## Regla nº2 — Producción es intocable
- ~/public_html/catalogo es FacturaScripts en PRODUCCIÓN. NUNCA lo toques.
- ~/public_html/cat es DESARROLLO. Aquí se trabaja.
- Ante cualquier duda sobre en qué entorno estás, pregunta antes de escribir.

## Regla nº3 — SEO es la prioridad máxima
- yevea.com ocupa el primer puesto en buscadores de su sector. No se puede perder.
- Antes de cambiar URLs, estructura o renderizado, evalúa el impacto SEO.
- Nunca rompas URLs indexadas sin un plan de redirecciones 301.

## Regla nº4 — Planificar antes de ejecutar
- Antes de modificar archivos, muestra la lista de archivos afectados y espera aprobación.
- No hagas cambios grandes sin avisar primero.
