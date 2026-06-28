# YeveaStore — Contexto para Claude

## Qué es YeveaStore

Plugin de e-commerce para **FacturaScripts** (ERP/facturación español, PHP) construido para una serrería española de madera de olivo. Gestiona un catálogo de productos de madera de olivo (tablones, tableros a medida, encimeras de baño y cocina, artesanía) con ventas a toda la UE.

- **Versión:** 1.2
- **Requiere:** FacturaScripts ≥ 2025.71, PHP ≥ 8.1
- **Namespace PHP:** `FacturaScripts\Plugins\YeveaStore`
- **Licencia:** LGPL v3

El plugin vive en `Plugins/YeveaStore/` dentro de la instalación de FacturaScripts y se activa desde el panel de admin.

---

## Arquitectura general

### Patrón MVC de FacturaScripts

FacturaScripts usa un patrón MVC propio:
- **Controllers** extienden `FacturaScripts\Core\Template\Controller`
- **Models** extienden `FacturaScripts\Core\Template\ModelClass` + `ModelTrait`
- **Views** son plantillas Twig en `View/`
- Las tablas de BD se definen en XML (`Table/*.xml`) y FacturaScripts las crea/migra automáticamente

### Jerarquía de controllers (frontend público)

```
Controller
└── StoreFront        ← catálogo base; $requiresAuth = false
    ├── Tableros      ← extiende StoreFront, añade filtros de dimensión y slug ?cat=
    └── (Presupuesto es independiente, no extiende StoreFront)

Presupuesto           ← carrito + checkout + Stripe
ProductoDetalle       ← página de producto individual
SettingsYeveaStore     ← config admin (claves Stripe)
EditYeveaStoreOrder    ← edición de pedidos (admin)
ListYeveaStoreOrder    ← listado de pedidos (admin)
```

### Traits compartidos (Lib/)

- **`LanguageTrait`** — detección de idioma (`?lang=` → cookie → es_ES), `detectAndSetLanguage()`, `translateProduct()`, `translateCategory()`, `langSwitchUrl()`
- **`SlugTrait`** — generación de slugs URL: `generateSlug()` y `generateProductSlug()`

---

## Base de datos — Tablas propias del plugin

| Tabla | Archivo XML | Modelo PHP |
|---|---|---|
| `yeveastore_orders` | `Table/yeveastore_orders.xml` | `Model/YeveaStoreOrder.php` |
| `yeveastore_order_lines` | `Table/yeveastore_order_lines.xml` | `Model/YeveaStoreOrderLine.php` |
| `yeveastore_cart_items` | `Table/yeveastore_cart_items.xml` | `Model/YeveaStoreCartItem.php` |
| `productos_imagenes` | `Table/productos_imagenes.xml` | (usa `ProductoImagen` del core) |

### Extensiones a tablas nativas de FacturaScripts

| Tabla nativa | Columnas añadidas |
|---|---|
| `familias` | `publica` (bool), `tipofamilia` (varchar), `largo_min/max`, `ancho_min/max`, `category_custom_css`, `category_intro`, `category_outro` |
| `productos` | `largo`, `ancho`, `espesor` (dimensiones físicas del tablón) |
| `variantes` | (ver `Extension/Table/variantes.xml`) |

### Migración desde plugin anterior

`Init.php` incluye lógica idempotente para migrar datos del plugin antiguo `ecommerce` a `yeveastore` (tablas `ecommerce_*` → `yeveastore_*`, settings `ecommerce` → `yeveastore`).

---

## Tipos de familia (`tipofamilia`)

El campo `tipofamilia` en `familias` controla el comportamiento de producto en la tienda:

| Valor | Comportamiento |
|---|---|
| `mercancia` | Producto estándar. Cantidad ajustable. Default. |
| `tablones` | Tablón único (stock físico). Cantidad forzada a 1. Se muestra como "vendido" si stock ≤ 0. Las dimensiones del producto (`largo×ancho×espesor`) se muestran en el carrito y en el pedido nativo. |
| `tableros` | Tablero a medida. El cliente introduce `largo_cm` × `ancho_cm`. Precio calculado por m² (precio/m² × área). Cada combinación de dimensiones es un ítem de carrito separado. Cantidad forzada a 1. Los límites `largo_min/max`, `ancho_min/max` de la familia se usan para validación en frontend. |
| `artesania` | Pieza artesanal única. Cantidad forzada a 1. Se muestra como "vendido" si stock ≤ 0. |

---

## Flujo de compra

```
1. Cliente visita /StoreFront o /Tableros
2. Añade producto → POST action=add-to-cart → YeveaStoreCartItem (session_id)
3. Va a /Presupuesto → ve carrito + formulario de datos cliente
4. Puede descargar presupuesto PDF → action=print-presupuesto
   → Crea PresupuestoCliente nativo en FS → redirige a /EditPresupuestoCliente?action=export&option=PDF
5. Confirma pedido → action=place-order
   → Guarda datos en $_SESSION['pending_yeveastore_order']
   → Crea sesión Stripe → redirige a Stripe
6. Stripe redirige a /Presupuesto?stripe=success&stripe_session_id=...
   → Verifica pago con Stripe API
   → Crea YeveaStoreOrder + YeveaStoreOrderLines
   → Crea/busca Cliente nativo + PedidoCliente nativo (via Calculator::calculate)
   → Vacía carrito de sesión
```

### Integración con FacturaScripts nativo

Al completar el pago, `Presupuesto::createNativeFsOrder()`:
1. Busca `Cliente` existente por email; si no existe, lo crea
2. Crea `PedidoCliente` con `$pedido->save()` primero (para obtener `idpedido`)
3. Construye líneas con `$pedido->getNewLine()` (aplica IVA por defecto)
4. Llama a `Calculator::calculate($pedido, $lines, true)` para calcular totales y persistir
5. Guarda `codcliente` y `codpedido` en `YeveaStoreOrder` para navegación cruzada

Requiere el plugin **Ventas** de FacturaScripts. Si no está disponible, el pedido YeveaStore se guarda igualmente pero sin crear documentos nativos.

---

## Sistema multilingüe

### Idiomas soportados

| Código | Idioma | Archivo |
|---|---|---|
| `es_ES` | Español (idioma base/fallback) | `Translation/es_ES.json` |
| `en_EN` | Inglés | `Translation/en_EN.json` |
| `fr_FR` | Francés | `Translation/fr_FR.json` |
| `de_DE` | Alemán | `Translation/de_DE.json` |

### Detección de idioma (`LanguageTrait::detectAndSetLanguage`)

Prioridad: `?lang=` parámetro → cookie `yeveastore_lang` → fallback `es_ES`

La elección se persiste en cookie (1 año, `SameSite=Lax`). Se aplica llamando a `Tools::lang()->setLang()` antes de cualquier `trans()` o carga de datos.

### Traducción de contenido (productos y categorías)

Los datos de BD están en español. Las traducciones de contenido se gestionan mediante claves en los JSON:

**Productos** — claves derivadas de `referencia`:
```
product-{REFERENCIA}-name   → nombre del producto
product-{REFERENCIA}-desc   → descripción del producto
```

**Categorías** — claves derivadas de `codfamilia`:
```
family-{CODFAMILIA}-name    → nombre de la categoría
family-{CODFAMILIA}-intro   → HTML de introducción
family-{CODFAMILIA}-outro   → HTML de cierre
```

**Patrón de fallback:** Si `trans()` devuelve la clave (no hay traducción), se usa el valor en español de la BD. Esto se comprueba explícitamente: `$translated !== $key ? $translated : $dbValue`.

Los slugs de URL **siempre se generan desde el valor en español de la BD** (`descripcion`), nunca desde el nombre traducido, para mantener la estabilidad del routing.

---

## Stripe

- Integración vía `curl` a `https://api.stripe.com/v1/checkout/sessions` (sin SDK)
- Claves almacenadas en `fs_settings` (nombre `yeveastore`): `stripe_secret_key`, `stripe_public_key`
- Configurables en `/SettingsYeveaStore` o directamente en BD via phpMyAdmin
- Verificación del pago: GET a `/v1/checkout/sessions/{id}` y comprueba `payment_status === 'paid'`
- Precios enviados a Stripe en céntimos (entero): `round($price * 100)`
- Para tableros: `unit_amount` = precio total del ítem (precio/m² × área × cantidad), `quantity` = 1

---

## Extensiones al panel de admin

`Extension/Controller/EditFamilia.php` — extiende la pantalla nativa de familias:
- Carga `Assets/JS/EditFamilia.js` para mostrar/ocultar el bloque de dimensiones dinámicamente según `tipofamilia`
- Oculta server-side las columnas `largo_min/max`, `ancho_min/max` cuando `tipofamilia !== 'tableros'`

`Extension/Controller/EditProducto.php` — extiende la pantalla nativa de productos:
- Fixes para relaciones de imágenes y campo `nostock`

Las vistas XML en `Extension/XMLView/` añaden columnas/widgets a las vistas nativas de familias, productos y variantes.

---

## SEO y datos estructurados

Las plantillas públicas incluyen:
- **JSON-LD Schema.org** en `<script type="application/ld+json">`: `Product` en páginas de detalle, `Store` + `OfferCatalog` en catálogos
- **Microdata** (`itemprop`) en las tarjetas de producto
- **HTML semántico**: `<article>`, `<nav>`, jerarquía `h1/h2`, `aria-label`
- **hreflang**: `View/Hreflang.html.twig` para las etiquetas `<link rel="alternate">`

El JSON-LD usa `{{ product.name }}` y `{{ product.description }}`, que ya contienen el texto traducido porque la traducción ocurre en el controller.

---

## Archivos y responsabilidades

```
Init.php                              Registro de extensiones + migración desde ecommerce
facturascripts.ini                    Metadatos del plugin (nombre, versión, min_version)
composer.json                         Autoload PSR-4

Controller/
  StoreFront.php                      Catálogo base. Gestiona carrito (add-to-cart),
                                      visibilidad de productos/familias, conteo de carrito.
  Tableros.php                        Extiende StoreFront. Filtros de dimensión (?largo_min/max
                                      etc.), resolución de slug ?cat= a codfamilia.
  ProductoDetalle.php                 Detalle de producto por slug URL.
  Presupuesto.php                     Carrito, checkout, integración Stripe, creación de
                                      pedidos nativos y presupuestos PDF.
  SettingsYeveaStore.php               Pantalla admin para claves Stripe.
  EditYeveaStoreOrder.php              Editor de pedidos YeveaStore (admin).
  ListYeveaStoreOrder.php              Listado de pedidos YeveaStore (admin).

Model/
  YeveaStoreOrder.php                  Pedido. Genera code='ORD-XXXXXXXX'. Guarda codcliente
                                      y codpedido para enlace con FS nativo.
  YeveaStoreOrderLine.php              Línea de pedido. Incluye largo_cm/ancho_cm para tableros.
  YeveaStoreCartItem.php               Ítem de carrito (sesión PHP). Incluye largo_cm/ancho_cm.
  YeveaStorePresupuesto.php            Modelo de presupuesto (stub, tabla yeveastore_presupuestos).
                                      NOTA: la generación de presupuestos PDF usa
                                      PresupuestoCliente nativo de FS, no este modelo.

Lib/
  LanguageTrait.php                   Detección de idioma, traducción de producto/categoría,
                                      langSwitchUrl().
  SlugTrait.php                       generateSlug() y generateProductSlug().

Extension/
  Controller/EditFamilia.php          Toggle JS + ocultar columnas de dimensión server-side.
  Controller/EditProducto.php         Fixes de imágenes y nostock.
  Table/familias.xml                  Añade publica, tipofamilia, dimensiones, CSS, intro/outro.
  Table/productos.xml                 Añade largo, ancho, espesor.
  Table/variantes.xml                 Extensiones a variantes.
  XMLView/                            Extensiones a vistas nativas de admin.

Table/
  yeveastore_orders.xml                Esquema de yeveastore_orders.
  yeveastore_order_lines.xml           Esquema de yeveastore_order_lines.
  yeveastore_cart_items.xml            Esquema de yeveastore_cart_items.
  productos_imagenes.xml              Esquema de imágenes de producto.

View/
  StoreFront.html.twig                Catálogo principal con Schema.org JSON-LD.
  Tableros.html.twig                  Catálogo de tableros con selector de dimensiones.
  ProductoDetalle.html.twig           Detalle de producto con microdata Schema.org.
  Presupuesto.html.twig               Carrito + formulario checkout.
  Header.html.twig                    Cabecera común (logo, nav, selector de idioma).
  Footer.html.twig                    Pie común.
  Hreflang.html.twig                  Etiquetas hreflang para SEO multilingüe.
  Tab/ProductoImagen.html.twig        Tab de imágenes en el admin de productos.

XMLView/
  EditYeveaStoreOrder.xml              Vista admin de edición de pedido.
  EditYeveaStoreOrderLine.xml          Vista admin de línea de pedido.
  ListYeveaStoreOrder.xml              Vista admin de listado de pedidos.
  SettingsYeveaStore.xml               Vista de configuración (claves Stripe).

Translation/
  es_ES.json, en_EN.json,
  fr_FR.json, de_DE.json             ~139 claves de UI + claves product-*/family-*.

Assets/
  CSS/yeveastore.css                   Estilos propios de la tienda.
  JS/EditFamilia.js                   Toggle dinámico del bloque de dimensiones en admin.
```

---

## Reglas importantes

### Slugs de URL
Los slugs **siempre se generan desde `descripcion` en español de la BD**, nunca desde el nombre traducido. `Tableros::preResolveSlugToCategory()` y `ProductoDetalle::loadProductBySlug()` dependen de esto. Cambiar este comportamiento rompería el routing.

### Visibilidad de productos
Un producto aparece en la tienda si:
- `producto.publico = true`, **o**
- pertenece a una familia con `familia.publica = true`

Cuando la familia es pública, se muestran **todos** sus productos (independientemente de `publico`). Cuando no, solo los que tengan `publico = true`.

### Carrito basado en sesión PHP
El `session_id()` de PHP identifica el carrito. No hay autenticación de cliente en el frontend. El carrito se vacía tras completar el pago con éxito.

### Precios e IVA
- Los precios en BD (`precio`/`pvpunitario`) son **precios netos** (sin IVA)
- El IVA se resuelve vía `codimpuesto` del producto o el impuesto por defecto de la empresa (`Tools::settings('default', 'codimpuesto', '')`)
- Los precios mostrados al cliente en la tienda incluyen IVA: `precio × (1 + tasa/100)`
- Para tableros: precio total = precio/m² × (largo_cm × ancho_cm / 10000)

### Pedidos nativos de FacturaScripts
- Requiere `Calculator::calculate()` para que los totales del `PedidoCliente` sean correctos
- El `PedidoCliente` debe guardarse **antes** de crear líneas (para tener `idpedido`)
- Para tableros en el pedido nativo: `pvpunitario = precio/m² × área` y se añade `(largo×ancho cm)` a la descripción
- Para tablones: se añaden las dimensiones físicas `(largo×ancho×espesor cm)` a la descripción

### IVA en Stripe
Stripe recibe el precio **con IVA incluido** en céntimos. No se configura IVA separado en Stripe.

### YeveaStorePresupuesto.php
El modelo `Model/YeveaStorePresupuesto.php` es un **stub incompleto** (namespace incorrecto: `Model\` en lugar de `FacturaScripts\Plugins\YeveaStore\Model\`, no usa `ModelTrait`, no tiene tabla definida en XML). La funcionalidad real de presupuestos PDF usa `PresupuestoCliente` nativo de FacturaScripts.

---

## Estado actual del desarrollo

### Implementado y funcional
- Catálogo de productos con filtrado por categoría
- Cuatro tipos de familia con comportamiento diferenciado (mercancia, tablones, tableros, artesania)
- Carrito de sesión con add/update/remove
- Checkout con formulario de datos de cliente
- Pago con Stripe (sesiones de checkout, verificación de pago)
- Creación automática de `Cliente` y `PedidoCliente` nativos en FacturaScripts
- Generación de presupuestos PDF via `PresupuestoCliente` nativo
- Sistema multilingüe completo para cadenas de UI (es/en/fr/de)
- Traducción de contenido de productos y categorías via claves JSON (`LanguageTrait`)
- Selector de idioma en el header con persistencia en cookie
- Migración idempotente desde el plugin anterior `ecommerce`
- Schema.org JSON-LD y microdata en todas las páginas públicas
- Filtros de dimensión en la vista Tableros (largo/ancho/espesor min/max)
- Slugs de URL para categorías (`?cat=slug`) y productos (`?url=slug`)

### Pendiente / conocido
- `Model/YeveaStorePresupuesto.php` está incompleto (stub sin tabla XML ni ModelTrait correcto); si se necesita una tabla `yeveastore_presupuestos` propia, hay que reescribirlo correctamente
- Los slugs de URL son siempre en español (los slugs multilingüe son una mejora futura documentada en `MULTILINGUAL.md`)
- Las traducciones de contenido de producto/categoría en JSON deben mantenerse manualmente al añadir o cambiar referencias (`referencia`) o códigos de familia (`codfamilia`)
- No hay tests automatizados
