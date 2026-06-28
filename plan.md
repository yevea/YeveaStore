# Plan de cambios: WoodStore → YeveaStore

> Estado: pendiente de confirmación. No tocar código hasta aprobación.

---

## 1. Archivos a RENOMBRAR (y modificar internamente)

| Archivo actual | Archivo nuevo |
|---|---|
| `Controller/EditWoodstoreOrder.php` | `Controller/EditYeveaStoreOrder.php` |
| `Controller/ListWoodstoreOrder.php` | `Controller/ListYeveaStoreOrder.php` |
| `Controller/SettingsWoodstore.php` | `Controller/SettingsYeveaStore.php` |
| `Model/WoodstoreCartItem.php` | `Model/YeveaStoreCartItem.php` |
| `Model/WoodstoreOrder.php` | `Model/YeveaStoreOrder.php` |
| `Model/WoodstoreOrderLine.php` | `Model/YeveaStoreOrderLine.php` |
| `Model/WoodstorePresupuesto.php` | `Model/YeveaStorePresupuesto.php` |
| `Table/woodstore_cart_items.xml` | `Table/yeveastore_cart_items.xml` |
| `Table/woodstore_orders.xml` | `Table/yeveastore_orders.xml` |
| `Table/woodstore_order_lines.xml` | `Table/yeveastore_order_lines.xml` |
| `XMLView/EditWoodstoreOrder.xml` | `XMLView/EditYeveaStoreOrder.xml` |
| `XMLView/EditWoodstoreOrderLine.xml` | `XMLView/EditYeveaStoreOrderLine.xml` |
| `XMLView/ListWoodstoreOrder.xml` | `XMLView/ListYeveaStoreOrder.xml` |
| `XMLView/SettingsWoodstore.xml` | `XMLView/SettingsYeveaStore.xml` |
| `Assets/CSS/woodstore.css` | `Assets/CSS/yeveastore.css` |

---

## 2. Archivos a modificar SOLO internamente (sin renombrar)

### Metadatos del plugin
- `facturascripts.ini` — `name`, `description`
- `composer.json` — nombre del paquete, namespace PSR-4 (`WoodStore` → `YeveaStore`)

### Controllers
- `Init.php` — namespace, clave de settings (`'woodstore'` → `'yeveastore'`), referencias de migración DB
- `Controller/StoreFront.php` — namespace, `use` statements, ruta CSS, clave `settings('woodstore', ...)`
- `Controller/Tableros.php` — namespace
- `Controller/ProductoDetalle.php` — namespace
- `Controller/Presupuesto.php` — namespace, `use` statements, ruta CSS, claves de session y settings
- `Extension/Controller/EditFamilia.php` — namespace, ruta del asset JS
- `Extension/Controller/EditProducto.php` — namespace

### Lib
- `Lib/LanguageTrait.php` — namespace, cookie `woodstore_lang` → `yeveastore_lang`
- `Lib/SlugTrait.php` — namespace

### Vistas
- `View/Header.html.twig` — IDs CSS: `woodstore-header` → `yeveastore-header`
- `View/Footer.html.twig` — IDs CSS: `woodstore-footer` → `yeveastore-footer`

### Traducciones (4 archivos)
- `Translation/es_ES.json` — claves `"woodstore"` y `"settings-woodstore"` → `"yeveastore"` / `"settings-yeveastore"`
- `Translation/en_EN.json` — ídem
- `Translation/fr_FR.json` — ídem
- `Translation/de_DE.json` — ídem

### Assets
- `Assets/JS/EditFamilia.js` — cabecera de comentario

---

## 3. Archivos NUEVOS a crear

| Archivo | Descripción |
|---|---|
| `Controller/Productos.php` | Landing page del plugin. Carga familias públicas y las pasa a la vista. No extiende StoreFront. |
| `View/Productos.html.twig` | Vista de landing con tarjetas de categoría. Madera de Olivo destacada. Data-driven: cualquier familia pública aparece automáticamente. |

---

## 4. Archivos que NO cambian

- `Extension/Table/familias.xml`, `productos.xml`, `variantes.xml` — no tienen referencias WoodStore
- `Extension/XMLView/*.xml` — no tienen referencias WoodStore
- `Table/productos_imagenes.xml` — no tiene referencias WoodStore
- `View/Presupuesto.html.twig`, `View/ProductoDetalle.html.twig`, `View/StoreFront.html.twig`, `View/Tableros.html.twig`, `View/Hreflang.html.twig`, `View/Tab/ProductoImagen.html.twig` — solo referencias de estructura, no de nombre del plugin

---

## 5. Decisión pendiente: tablas de BD

Los XMLs de tabla se renombrarán a `yeveastore_*`. En dev, FacturaScripts creará las nuevas tablas vacías al activar el plugin. Los datos existentes en `woodstore_*` quedarán sin migrar.

**Opciones:**
- A) Renombrar tablas (`yeveastore_*`) y asumir tablas vacías en dev — más limpio a largo plazo
- B) Mantener nombres `woodstore_*` en BD internamente — conserva datos de dev pero deja inconsistencia nombre/namespace

---

## Resumen de cambios por tipo

| Tipo | Cantidad |
|---|---|
| Archivos renombrados + modificados | 15 |
| Archivos modificados internamente | 17 |
| Archivos nuevos | 2 |
| **Total archivos afectados** | **34** |
