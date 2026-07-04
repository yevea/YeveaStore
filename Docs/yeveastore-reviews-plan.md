# RESEÑAS — seguimiento y plan del sistema
(Editable: usa la tabla de abajo para apuntar reseñas hasta que exista el plugin.)

═══════════════════════════════════════════════════════════
POR QUÉ IMPORTAN
═══════════════════════════════════════════════════════════
Los LLMs y Google recomiendan tiendas con reseñas visibles y marcadas con
Schema.org (aggregateRating). Es la señal de confianza nº1.

═══════════════════════════════════════════════════════════
FASE ACTUAL (manual, empezar YA)
═══════════════════════════════════════════════════════════
1. Tras cada pedido entregado, enviar al cliente el enlace de reseña de
   Google (sacarlo de la ficha: Google Business → "Pedir reseñas").
   Plantilla de email:
   ────────────────────────────────────────────
   Asunto: ¿Qué te ha parecido tu [producto]?
   Hola [nombre], soy Martín, de Yevea. Espero que tu [producto] haya
   llegado bien. Si tienes un minuto, una reseña nos ayuda muchísimo:
   [ENLACE]. Cualquier problema, respóndeme a este correo y lo arreglo.
   ────────────────────────────────────────────
2. Apuntar cada reseña en la tabla de abajo.
3. Con ~5 reseñas → avisar a Claude para implementar la FASE PLUGIN.

REGISTRO DE RESEÑAS (rellenar a mano):
| Fecha      | Plataforma | Autor   | Nota | Producto        | Respondida |
|------------|------------|---------|------|-----------------|------------|
| (ejemplo)  | Google     | M.G.    | 5    | Encimera olivo  | Sí         |
|            |            |         |      |                 |            |

═══════════════════════════════════════════════════════════
FASE PLUGIN: "YeveaReviews" (diseño completo, para implementar)
═══════════════════════════════════════════════════════════
Plugin independiente de FacturaScripts (no mezclar con YeveaStore, para
poder activarlo/desactivarlo y reutilizarlo).

TABLAS:
  yeveareviews_reviews:
    id, platform (google|web|email|etsy|otro), external_id,
    author_name, rating (1-5), title, body, product_referencia (nullable),
    review_date, response_text, response_date,
    status (pending|approved|hidden), source_url, creation_date
  yeveareviews_requests (solicitudes enviadas):
    id, order_code, customer_email, sent_date, reminder_date, reviewed (bool)

FUNCIONES:
  1. ADMIN: pestaña "Reseñas" con listado + alta manual (copiar las de
     Google), respuesta, y estado aprobada/oculta.
  2. SOLICITUDES AUTOMÁTICAS: X días después de marcar un pedido como
     "completed", email automático al cliente con el enlace de reseña
     (cron diario; plantilla editable; máx. 1 recordatorio).
  3. ESCAPARATE: bloque de reseñas aprobadas en la ficha de producto
     (las ligadas a su referencia) y en el catálogo (las generales).
  4. SCHEMA.ORG: aggregateRating + review en el JSON-LD de producto,
     calculado solo con reseñas aprobadas. ⚠ Regla de Google: el marcado
     debe salir de reseñas recogidas en TU web, no copiadas de Google →
     por eso el formulario propio de la función 5.
  5. FORMULARIO PROPIO: página /Resena?pedido=CODE accesible solo desde
     el email post-compra (token), sin login. Las reseñas entran como
     "pending" y se aprueban a mano (cero spam).
  6. IMPORTACIÓN GOOGLE (opcional, v2): API de Google Business Profile
     para sincronizar reseñas de la ficha (se muestran, pero sin marcado
     Schema.org — regla de Google).

ESTIMACIÓN: v1 (funciones 1-4) en una sesión de trabajo con Claude;
v2 (5-6) en otra. Pedir cuando haya ~5 reseñas reales que mostrar.

═══════════════════════════════════════════════════════════
NOTA SOBRE LA FICHA DE GOOGLE
═══════════════════════════════════════════════════════════
Renombrar "Maderas Yevea" → "Yevea" (visión: madera + aceite + olivar).
Al renombrar, Google puede pedir re-verificación de la ficha: hacerlo en
un momento tranquilo, tener a mano fotos/facturas que acrediten el nombre.
Actualizar también: categorías de la ficha, web (→ /catalogo cuando se
migre), horario, y fotos de productos del olivar.
