# YeveaStore Plugin for FacturaScripts

**Minimal version of FacturaScripts:** 2025.71  
**License:** LGPL v3  
**Web:** [facturascripts.com](https://facturascripts.com)

A YeveaStore / shopping cart plugin for FacturaScripts, built for a Spanish olive grove products. The plugin manages a product catalogue of olive oil, olives and olive wood products—planks, custom-cut boards, rustic bathroom countertops, kitchen countertops, cutting boards and handcrafted olive wood items—with full support for customers across the European Union.

## Product Categories

| Category (ES) | Category (EN) | Category (FR) | Category (DE) |
|---|---|---|---|---|
| Tablones de Madera | Wood Planks | Planches de Bois | Holzbohlen | etc. to be updated

## Target Markets

The plugin targets the European Union market, with full translations for:

- **Spanish** (es_ES) — primary language
- **English** (en_EN) — international
- **French** (fr_FR) — France market
- **German** (de_DE) — Germany market

FacturaScripts automatically selects the translation matching the user's language preference.

## SEO & AI Agent Optimisation

The storefront and product detail pages include:

- **Schema.org JSON-LD structured data** — each product page outputs a `Product` schema with name, description, SKU, price, currency, availability, material, category, brand, manufacturer, variants and shipping area. Catalogue pages output a `Store` schema with an `OfferCatalog` listing all products.
- **Schema.org microdata attributes** — product cards embed `itemprop` attributes (`name`, `description`, `image`, `sku`, `price`, `priceCurrency`, `availability`, `material`) so search-engine crawlers and AI agents can parse the data directly from the HTML.
- **Semantic HTML** — `<article>`, `<nav>`, `<h1>`/`<h2>` hierarchy, `aria-label` attributes, and breadcrumb markup.
- **Multi-language translation keys** — product-category descriptions (`olive-wood-desc`, `wood-planks-desc`, `olive-wood-boards-desc`, `rustic-bathroom-countertops-desc`, `kitchen-countertops-desc`, `cutting-boards-desc`, `olive-wood-crafts-desc`) are available in all four languages so AI agents can present product information in the user's language.

## Features

- **Product Management** — Create and manage products with name, reference, description, price, stock, and images
- **Category Management** — Organise products into families; two family types: **standard product** and **unique piece** (qty 1, "Sold" when gone, dimensions on the product, default stock 1)
- **Storefront** — Public-facing product catalogue with category filtering and Schema.org structured data
- **Shopping Cart** — Session-based cart with add, update quantity, and remove functionality
- **Measurement Price Calculator** — per-family "Tabla de Precios" tab (None / Area L×W at €/m² / Volume L×W×H at €/m³): customer enters dimensions on the product page (free input within family limits, or a fixed dropdown of allowed values), price is computed live and re-validated server-side; configurable labels/units, overage % and calculated weight. Pricing by measurements is configured ONLY here — family types no longer carry pricing behaviour
- **Warehouse Capture PWA** — installable mobile app at `/capturar` (no login — the selected warehouse identifies the operator): camera multi-photo capture, auto SKU (`FAMILYNAME-NNNN`), dimensions/weight/pile, stock 1 (unique pieces), price auto-calculated from the family's thickness-rate table. Works offline (IndexedDB queue + Background Sync, deduped server-side by capture id). Captured products stay hidden everywhere until approved in Admin → YeveaStore → YeveaCaptura
- **Order Processing** — Checkout flow that converts cart items into orders with full customer details
- **Native FS Integration** — Automatically creates FacturaScripts `Cliente` and `PedidoCliente` records
- **Stripe Payments** — Integrated Stripe checkout for card payments
- **Translations** — English, Spanish, French and German language support, with a public language switcher (`?lang=`, persisted in a cookie) and `hreflang` alternate-language tags
- **EU Shipping** — Designed for customers in Spain, France, Germany and the whole EU

## Plugin Structure

```
YeveaStore/
├── Assets/
│   ├── CSS/
│   │   ├── yeveacaptura.css         # Capture PWA styles (mobile-first)
│   │   └── yeveastore.css           # Public storefront styles
│   ├── Images/                      # Capture PWA icons (SVG + 192/512 PNG)
│   └── JS/
│       ├── SettingsScrollTop.js     # Admin settings scroll fix
│       ├── YeveaCaptura.js          # Capture PWA app (camera, offline queue, install)
│       ├── YeveaCapturaQueue.js     # Shared IndexedDB queue (page + service worker)
│       ├── YeveaCapturaSW.js        # Service worker (shell cache + Background Sync)
│       └── YeveaFamiliaTarifa.js    # Price-table tab editor UI
├── Controller/
│   ├── EditYeveaStoreOrder.php       # Edit order (admin)
│   ├── ListYeveaStoreOrder.php       # List orders (admin)
│   ├── LlmsTxt.php                  # /llms.txt (AI-agent discovery)
│   ├── Presupuesto.php              # Quote/checkout (frontend)
│   ├── ProductoDetalle.php          # Product detail (frontend)
│   ├── Productos.php                # Product catalogue (frontend)
│   ├── SettingsYeveaStore.php        # Admin settings (5 tabs: Dashboard/Ajustes/Plan/Reseñas/Captura)
│   ├── Sitemap.php                  # /sitemap.xml
│   ├── StripeWebhook.php            # Stripe webhook (checkout.session.completed)
│   └── YeveaCaptura.php             # /capturar — warehouse capture PWA (no login; captures await admin approval)
├── Extension/
│   ├── Controller/
│   │   ├── EditFamilia.php          # Family type + dimension limits + "Tabla de Precios" tab
│   │   ├── EditProducto.php         # Product image fixes + nostock
│   │   └── EditSettings.php         # Registers YeveaStore settings tab
│   ├── Table/
│   │   ├── familias.xml             # Family table extensions
│   │   ├── productos.xml            # Product table extensions
│   │   └── variantes.xml            # Variant table extensions
│   └── XMLView/
│       ├── EditFamilia.xml          # Family editor extensions
│       ├── EditProducto.xml         # Product editor extensions
│       ├── EditVariante.xml         # Variant editor extensions
│       ├── ListFamilia.xml          # Family list extensions
│       └── ListProducto.xml         # Product list extensions
├── Lib/
│   ├── LanguageTrait.php            # Visitor language detection (?lang=/cookie) + content translation
│   ├── OrderFulfillmentTrait.php    # Shared order-completion logic (Presupuesto + StripeWebhook)
│   ├── SlugTrait.php                # Product/category slug generation (always from Spanish)
│   ├── StoreControllerBase.php      # Abstract base for public controllers (Productos, ProductoDetalle, Presupuesto)
│   └── YeveaMeasure.php             # Measurement calculator: calc_config parsing, area/volume factor, capture rates
├── Model/
│   ├── YeveaStoreCartItem.php        # Cart item model
│   ├── YeveaStoreOrder.php           # Order model
│   └── YeveaStoreOrderLine.php       # Order line model
├── Table/
│   ├── yeveastore_cart_items.xml     # Cart items table
│   ├── yeveastore_order_lines.xml    # Order lines table
│   ├── yeveastore_orders.xml         # Orders table
│   └── productos_imagenes.xml       # Product images table
├── Translation/
│   ├── de_DE.json                   # German translations
│   ├── en_EN.json                   # English translations
│   ├── es_ES.json                   # Spanish translations (canonical fallback)
│   └── fr_FR.json                   # French translations
├── View/
│   ├── Footer.html.twig             # Shared public footer
│   ├── Header.html.twig             # Shared public header (nav + language switcher)
│   ├── Hreflang.html.twig           # hreflang alternate-language <link> tags
│   ├── Presupuesto.html.twig        # Quote/checkout template
│   ├── ProductoDetalle.html.twig    # Product detail template (with Schema.org)
│   ├── Productos.html.twig          # Product catalogue template (with Schema.org)
│   ├── YeveaCaptura.html.twig       # Warehouse capture PWA app shell (standalone, mobile-first)
│   ├── YeveaStoreCaptura.html.twig  # Admin: YeveaCaptura tab (launcher + install help)
│   ├── YeveaStoreDashboard.html.twig # Admin: AI-bot traffic dashboard tab
│   ├── YeveaStorePlan.html.twig     # Admin: content plan tab
│   ├── YeveaStoreResenas.html.twig  # Admin: reviews tab
│   └── Tab/
│       ├── ProductoImagen.html.twig  # Product images tab override (observations field)
│       └── YeveaFamiliaTarifa.html.twig # "Tabla de Precios" tab (calculator config + capture rates)
├── XMLView/
│   ├── EditYeveaStoreOrder.xml       # Order editor view
│   ├── EditYeveaStoreOrderLine.xml   # Order line editor view
│   ├── ListYeveaStoreOrder.xml       # Order list view
│   └── YeveaStoreAjustes.xml        # Settings view (Admin > Settings scans Settings*.xml)
├── Init.php                         # Plugin initialisation (routes, migrations, slug backfill)
├── composer.json                    # PHP dependencies
├── facturascripts.ini               # Plugin metadata
├── LICENSE
└── README.md
```

## Installation

1. Copy the `YeveaStore` folder into your FacturaScripts `Plugins/` directory
2. Go to the FacturaScripts admin panel
3. Navigate to **Admin > Plugins** and enable the **YeveaStore** plugin
4. The plugin will create the necessary database tables automatically

## Configuration

### Stripe Payment Gateway

Stripe is the payment gateway used during checkout.  You need a **Stripe Secret Key** (`sk_…`) to accept payments.

#### Option A — Admin panel (recommended)

1. Log in to the FacturaScripts admin panel.
2. Navigate to **Admin → YeveaStore** and open the **Settings** tab  
   (direct URL: `/SettingsYeveaStore`).
3. Enter your **Stripe Secret Key** (`sk_live_…` or `sk_test_…` for testing) and optionally the **Stripe Public Key** (`pk_live_…` / `pk_test_…`).
4. Click **Save**.

You can obtain both keys from the [Stripe Dashboard → Developers → API keys](https://dashboard.stripe.com/apikeys).

> **Tip:** Use test keys (`sk_test_…` / `pk_test_…`) during development and switch to live keys for production.

#### Webhook (recommended)

The webhook guarantees that a paid order is registered even if the customer never
returns to the site after paying (closed tab, lost connection, expired session).

1. Go to [Stripe Dashboard → Developers → Webhooks](https://dashboard.stripe.com/webhooks) and click **Add endpoint**.
2. Endpoint URL: `https://your-domain.com/StripeWebhook`
3. Select the event **`checkout.session.completed`** and save.
4. Copy the **Signing secret** (`whsec_…`) shown for the endpoint.
5. Paste it into **Admin → Settings → E-Commerce → Stripe Webhook Signing Secret** and save.

Events are verified with an HMAC-SHA256 signature check (5-minute replay tolerance).
Order fulfilment is idempotent: the webhook and the customer's return page can both
fire without creating duplicates.

#### Option B — phpMyAdmin / cPanel File Manager (no admin panel access needed)

If you prefer to configure the keys directly in the database (e.g. via **cPanel → phpMyAdmin**):

1. Open phpMyAdmin and select the FacturaScripts database.
2. Browse the `settings` table (named `fs_settings` in older FacturaScripts versions).
3. Look for a row where `name = 'yeveastore'`.  
   • If it exists, open the row for editing.  
   • If it does not exist yet, insert a new row with `name = 'yeveastore'`.
4. In the `properties` column (a JSON string), add or update the Stripe keys:

   ```json
   {"stripe_secret_key":"sk_test_YOUR_KEY_HERE","stripe_public_key":"pk_test_YOUR_KEY_HERE"}
   ```

   If the column already contains other properties, merge them — for example:

   ```json
   {"other_setting":"value","stripe_secret_key":"sk_test_YOUR_KEY_HERE","stripe_public_key":"pk_test_YOUR_KEY_HERE"}
   ```

5. Save the row.  No restart is needed; the plugin reads settings on every request.

### Native FacturaScripts Order Integration

When a customer completes a payment via Stripe, the plugin automatically:

1. **Finds or creates a `Cliente`** — searches for an existing client by email address; if none is found, a new client is created with all the submitted contact details.
2. **Creates a `PedidoCliente`** — a native FacturaScripts sales order is created and linked to the client. The order appears in **Ventas > Pedidos** like any manually entered order.
3. **Links back to the YeveaStore order** — the `YeveaStoreOrder` record stores the `codcliente` and `codpedido` values so you can navigate directly to the native records from **Ventas > Pedidos (YeveaStore) > Edit**.

> This integration requires the FacturaScripts **Ventas** (Facturación) plugin to be installed. The plugin gracefully skips the native order creation if the required models are not available.

## Usage

### Admin Panel
- Access the **YeveaStore** menu in the admin panel to manage categories, products, and orders
- Create categories first, then add products assigned to those categories
- Orders are created automatically when customers complete the checkout process

### Price Table (per family)
- Edit any familia → **Tabla de Precios** tab: pick a calculator mode (None / Area L×W / Volume L×W×H)
- Per dimension: label, unit and optional allowed values (`60-99` ranges and/or `35; 40; 45` lists → rendered as a dropdown; empty = free input within the family min/max limits)
- Pricing label/unit, "show price per unit", overage % and calculated weight (product weight × measure)
- **Capture rates**: thickness ranges → €/m², used by the capture PWA to auto-price slabs
- The product's price (or its variant's) is the per-unit price (€/m² or €/m³); the checkout recalculates and validates everything server-side

### Warehouse Capture (PWA)
- Open `/capturar` on the warehouse phone (no login needed) and install it from the browser prompt or ⋮ menu
- Capture flow: photos (camera or gallery) → family → name → warehouse → pile → weight → L×W×T → save
- Auto SKU `FAMILYNAME-NNNN`, stock 1, photos stored as product images named `family-product-N.jpg`, price from the family's capture rates
- Offline: captures queue locally and auto-send when coverage returns (Background Sync on Android; on reopen elsewhere)
- Every capture lands in **Admin → YeveaStore → YeveaCaptura** awaiting approval (invisible in catalogue, product page, sitemap, llms.txt and cart until approved); review price/photos via **Editar**, then **Aprobar**

### Storefront
- Public routes are lowercase for SEO: `/productos` (catalogue), `/producto` (product detail), `/presupuesto` (quote/cart), `/sitemap.xml`, `/llms.txt`, plus `/capturar` (warehouse capture PWA — no login; captured products stay hidden until approved in Admin → YeveaStore → Captura)
- The CamelCase controller routes (`/Productos`, `/Presupuesto`, `/ProductoDetalle`…) 301-redirect to their lowercase equivalent on GET requests
- Browse products, filter by category, add items to cart
- Switch language with the header selector (`?lang=es_ES|en_EN|fr_FR|de_DE`); the choice is remembered in a cookie and reflected in `hreflang` tags
- Complete checkout by entering customer details (name, NIF/CIF, email, phone, address, city, postal code, province, country) and clicking **Realizar Pedido**
- Stripe payment is processed; on success, a native FacturaScripts client and sales order are created automatically
