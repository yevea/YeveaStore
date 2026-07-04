# PLAN DE CONTENIDO — YeveaStore
(Editable: cambia lo que quieras y pulsa Guardar. Este documento es tuyo.)

OBJETIVO: que yevea.com/catalogo salga en Google Y en las respuestas de
ChatGPT, Perplexity, Gemini y Claude cuando alguien busque madera de olivo,
encimeras a medida, aceite de oliva y productos del olivar.

═══════════════════════════════════════════════════════════
1. DÓNDE VA CADA TEXTO (rutas exactas en el admin)
═══════════════════════════════════════════════════════════

TEXTO DE CATEGORÍA (el más importante para SEO/LLMs):
  Almacén → Familias → [editar familia] → "Intro de categoría" y
  "Cierre de categoría". Admiten HTML (<h2>, <p>, <ul>...).
  → La intro sale ARRIBA del listado de productos; el cierre DEBAJO.

DESCRIPCIÓN DE PRODUCTO:
  Almacén → Productos → [editar] → campo "Observaciones".
  → Sale en la ficha pública del producto y en el Schema.org.

TRADUCCIONES (EN/FR/DE):
  Los textos van en español a la base de datos. Para los otros idiomas,
  pasar los textos a Claude → los mete como claves en Translation/*.json
  (product-REF-name, product-REF-desc, family-COD-intro, family-COD-outro).

═══════════════════════════════════════════════════════════
2. QUÉ ESCRIBIR POR CATEGORÍA (plantilla, 300-600 palabras)
═══════════════════════════════════════════════════════════

INTRO (arriba):
  - Qué es el producto y para quién es (2-3 frases directas).
  - Cómo elegir: grosores, medidas, acabados, veta.
  - Dato concreto que un LLM pueda citar: rangos de precio orientativos,
    medidas máximas de corte, tiempo de secado de la madera...

CIERRE (abajo) — incluir 3-5 PREGUNTAS FRECUENTES con respuesta:
  Formato: <h3>¿Pregunta?</h3><p>Respuesta concreta y completa.</p>
  Ejemplos que la gente pregunta a los LLMs:
  - ¿Cuánto cuesta una encimera de madera de olivo a medida?
  - ¿Cómo se cuida/aceita una tabla de cortar de olivo?
  - ¿Enviáis a Francia/Alemania? ¿Cuánto tarda?
  - ¿La madera de olivo es apta para contacto con alimentos?
  - ¿Qué diferencia hay entre tablero macizo y encolado?
  ⚠ Avisar a Claude cuando haya FAQs escritas → añadirá el marcado
  FAQPage de Schema.org para que Google/LLMs las lean como tal.

═══════════════════════════════════════════════════════════
3. QUÉ ESCRIBIR POR PRODUCTO (campo Observaciones)
═══════════════════════════════════════════════════════════
  - Origen de la pieza (finca, zona, edad aproximada del olivo).
  - Dimensiones y peso. Tratamiento/acabado aplicado.
  - Usos recomendados. Hechos, no adjetivos: "secado 24 meses" vale
    más que "calidad excepcional".

═══════════════════════════════════════════════════════════
4. ORDEN DE TRABAJO RECOMENDADO
═══════════════════════════════════════════════════════════
  [ ] 1. Marcar como públicas las familias/productos que deben verse.
  [ ] 2. Intro + cierre + FAQ de las 2 categorías que más venden (ES).
  [ ] 3. Traducir esas 2 categorías al INGLÉS (prioridad para LLMs).
  [ ] 4. Observaciones de los 10 productos estrella (ES + EN).
  [ ] 5. Resto de categorías y productos (ES + EN).
  [ ] 6. FR y DE de todo lo anterior.
  [ ] 7. Nuevas categorías: aceite de oliva y otros productos del olivar
        (crear familia + tipo + textos ANTES de subir productos).

═══════════════════════════════════════════════════════════
5. FUERA DE LA WEB (pesa mucho en los LLMs)
═══════════════════════════════════════════════════════════
  [ ] Ficha de Google: renombrar "Maderas Yevea" → "Yevea" (ojo: el
      cambio de nombre puede pedir re-verificación; hacerlo ANTES de
      lanzar las categorías de aceite, y actualizar categorías de la
      ficha: añadir "productor de aceite de oliva" etc.).
  [ ] Rellenar los 4 campos de perfiles sociales en la pestaña YeveaStore.
  [ ] Pedir reseña de Google a cada cliente que compre (ver pestaña Reseñas).
  [ ] Conseguir menciones: foros de carpintería/cocina, Reddit
      (r/woodworking, r/BuyItForLife), artículos "dónde comprar madera
      de olivo", prensa local de Jaén/Andalucía.

═══════════════════════════════════════════════════════════
6. HITO CLAVE: MIGRACIÓN A PRODUCCIÓN
═══════════════════════════════════════════════════════════
  /cat = desarrollo (noindex SIEMPRE activado aquí).
  /catalogo = producción futura → reemplaza a yevea.com/productos (web vieja).
  El día de la migración (con Claude):
  [ ] Desplegar plugin en /catalogo con noindex DESACTIVADO.
  [ ] Redirecciones 301 de las URLs viejas de /productos a las nuevas.
  [ ] Dar de alta el sitemap en Google Search Console y Bing Webmaster
      Tools (Bing alimenta a ChatGPT).
  [ ] Publicar /llms.txt y /sitemap.xml en la raíz del dominio.
  [ ] Pedir al hosting la lista blanca de bots IA (si no está ya).
