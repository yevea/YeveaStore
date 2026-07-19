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

---

# Chuleta técnica (para Claude — denso a propósito, no re-derivar)

## Entornos
- PROD: `~/public_html/cat` = FS 2025 moderno, BD `shopcat_dev`, user BD `shopcat_contable`, creds en config.php (FS_DB_*). Plugin = clon git en `Plugins/YeveaStore` (remoto yevea/YeveaStore, main).
- Legacy NO tocar: `~/catalogo` (FS viejo, BD shopcat_cat), `~/productos` (WordPress→301 al lanzar), raíz = home estática. BD huérfana `shopcat_catalogo` fuera de cPanel: ignorar.
- SSH `shopcat@uk604.directrouter.com` (pwd: pedir a Martín, no guardar). Backups: `~/backups-migracion/`.

## Estructura objetivo del sitio (plan de Martín, 2026-07)
```
yevea.com/
├── cat/      Tienda dinámica (FacturaScripts) — EXISTE, es prod
├── madera/   Workspace de Claude (scripts/herramientas) — existe, casi vacío
└── doc/      KB ESTÁTICA por silos de idioma — existe VACÍA, por construir
    ├── es/  aceite/ olivas/ hierbas/ madera/{tablones,rodajas,troncos,
    │        tableros-mesa,estantes,encimeras-cocina,encimeras-bano,
    │        torneado,tablas-cocina,utensilios-cocina}  (cada hoja = index.html)
    ├── en/  URLs TRADUCIDAS para SEO: oil/ olives/ herbs/ wood/{planks,slices,
    │        logs,table-tops,shelves,kitchen-worktops,bathroom-worktops,
    │        turning,cutting-boards,kitchen-utensils}
    └── fr/  huile/ olives/ herbes/ bois/{planches,...}
```
- Rol del /doc: contenido citable (SEO+LLMs) que enlaza a las fichas de /cat; el catálogo vende, la KB posiciona. Las categorías del árbol anticipan las familias futuras de la tienda (aceite, olivas, hierbas).

## Deploy (tras push a main)
```
cd ~/public_html/cat/Plugins/YeveaStore && git pull origin main
cd ~/public_html/cat && php -r 'require "vendor/autoload.php"; const FS_FOLDER=__DIR__; require "config.php"; \FacturaScripts\Core\Kernel::init(); \FacturaScripts\Core\Plugins::deploy(true,true); \FacturaScripts\Core\Cache::clear();'
```
- Verificar web: `curl -sk --resolve yevea.com:443:$(hostname -I|awk '{print $1}') https://yevea.com/cat/...` (desde el server; curl externo pasa desde whitelist Rochen 2026-07).
- Ver HTML como admin: cookies `fsNick`/`fsLogkey` desde tabla `users` (admin=1).
- Menú/pages se cachean: Cache::clear + relogin. Tabla ajustes = `settings` (¡no fs_settings!), grupo 'yeveastore', JSON en `properties`.

## Arquitectura plugin
- Rutas públicas MINÚSCULAS vía MyFiles/routes.json (Init::update → registerLowercaseRoutes): /productos /producto /presupuesto /sitemap.xml /llms.txt /capturar. CamelCase legacy (/Productos…) → 301 solo en GET (enforceLowercasePath en base). publicPath() da la ruta minúscula de cada controller.
- Público: `Lib/StoreControllerBase` (abstracto, sin ruta; getPageData default menu=yeveastore+oculto) ← Productos, ProductoDetalle, Presupuesto.
- `Lib/OrderFulfillmentTrait` = finalización de pedido compartida Presupuesto+StripeWebhook; idempotente vía status `pending_payment`→`pending`; Stripe metadata: order_id, cart_session. `pending_payment` huérfanos = checkouts abandonados.
- tipofamilia (2 valores): estandar | pieza_unica (qty=1, sin duplicar en carrito, 'Vendido' si stock≤0, dims en producto, stock 1 al crear). Precio por medidas SOLO vía calc_config; migración legacy en Init (mercancia/tableros→estandar, tablones/artesania→pieza_unica). Filtro de dimensiones del catálogo: visible si la lista tiene productos con dims.
- Tabla de Precios: familias.calc_config JSON parseado por `Lib/YeveaMeasure` (mode none|area €/m²|volume €/m³; etiquetas/unidades por dimensión; opciones "60-99"/"35;40" → desplegable, vacío=libre con min/max familia; overage %; peso calculado; capture_rates rangos espesor→€/m²). Pestaña 'Tabla de Precios' en EditFamilia (extensión createViews + acción save-yevea-calc-config). Checkout/Stripe/PDF recalculan con YeveaMeasure::factorFor (valida opciones server-side); alto_cm en cart_items y order_lines.
- YeveaCaptura (/capturar): PWA SIN login (el almacén identifica al operario); manifest y SW servidos vía ?file= (scope correcto); SKU = NOMBREFAMILIA-NNNN (nombre, no codfamilia que puede ser "1"); crea Producto+Variante+Stock 1 (pila→stocks.ubicacion) con captura_pendiente=true → invisible en catálogo/ficha/sitemap/llms/carrito hasta Aprobar (tab YeveaCaptura de SettingsYeveaStore, muestra tarifa 0€ en rojo). Fotos: AttachedFile renombrado familia-producto-N.jpg + ProductoImagen (lo ÚNICO que renderiza la tienda) + AttachedFileRelation. Offline: cola IndexedDB compartida página/SW (YeveaCapturaQueue.js) + Background Sync; idempotencia por capture_id (MyFiles/yeveacaptura-captures.json con flock); CSRF = same-origin check (formToken no sirve para replays diferidos). Precio: YeveaMeasure::capturePrice (capture_rates). Meta cacheada en localStorage; solo familias publica=true en selector y POST.
- Visibilidad: producto.publico OR familia.publica. Slug: columna `productos.slug` (backfill en Init::update, lazy en ProductoDetalle).
- Precios BD = netos; IVA por codimpuesto→fallback default; se muestra/cobra con IVA.
- Traducciones contenido: claves `product-REF-name/-desc`, `family-COD-name/-intro/-outro` en Translation/*.json; BD solo español; slugs SIEMPRE desde español.
- Admin: SettingsYeveaStore = 5 tabs (Dashboard bots / Ajustes / Plan contenido / Reseñas / YeveaCaptura=cola aprobación). Docs editables en `MyFiles/yeveastore-*.md` (seed desde `Docs/`). Informe bots: `Scripts/ai-bot-report.sh`, cron diario 08:00, escribe `MyFiles/yeveastore-reports/`, email texto plano vía sendmail (mail = adjunto, no usar).

## Trampas FS conocidas (costaron sesiones enteras)
- Todo archivo en `Controller/` registra RUTA PÚBLICA + página; getPageData sin menu/showonmenu → default menu='new' VISIBLE (bug del "Nuevo").
- PanelController deshabilita TODAS las tabs si la vista principal no marca hasData (HtmlView nunca lo marca → forzar `$this->hasData=true`).
- Core EditSettings escanea TODOS los `XMLView/Settings*.xml` (por eso el nuestro se llama `YeveaStoreAjustes.xml`).
- Copiar instalación FS: cambiar FS_DB_NAME + FS_ROUTE (config.php) **y** RewriteBase (.htaccess).
- `(bool)'false' === true` → usar filter_var(...FILTER_VALIDATE_BOOLEAN) para settings checkbox.
- Plantillas públicas: bloque `{% block meta %}`; fsc.t() SOLO en controllers del plugin (LanguageTrait), en admin usar trans().
- Inyección navbar sin tocar core: `Extension/View/MenuTemplate_MenuIconBefore_*.html.twig`.
- Trait constants requieren PHP 8.2; min del proyecto 8.1 → strings locales.
- Template\Controller NO auto-renderiza: run() debe terminar en `$this->view('X.html.twig')` (o echo+exit para raw). Sin ello → 200 con 0 bytes.
- `php -l` no detecta imports `use` ausentes → clase de otro namespace peta solo en runtime (AdminPlugins). Revisar extends/new vs use al crear controllers.
- Core\Request y Core\UploadedFile de FS NO son Symfony: no hay getRequestUri() (usar $_SERVER); files->get() da null para inputs array (usar files->getArray('x')); UploadedFile::move() devuelve bool, no lanza.
- `formToken()` en twig emite el `<input hidden>` COMPLETO: usar `{{ formToken() }}` suelto, jamás dentro de value="…" (token mutilado → "Petición no válida"). Valor raw: formToken(false).

## Estado / pendientes → memoria [[project-migration]]
- noindex ON hasta orden de lanzamiento. Productos aún sin marcar públicos (catálogo/sitemap/llms vacíos hasta entonces).
- Lanzamiento: noindex OFF + robots raíz (Sitemap /cat/sitemap.xml, pedir OK) + llms.txt raíz + 301 WP + GSC/Bing + ficha Google (renombrar a "Yevea").
- Futuro: plugin YeveaReviews (diseño en tab Reseñas) cuando ~5 reseñas; FAQPage schema cuando Martín escriba FAQs.
