# YeveaStore Plugin for FacturaScripts

**Minimal version of FacturaScripts:** 2025.71  
**License:** LGPL v3  
**Web:** [facturascripts.com](https://facturascripts.com)

A YeveaStore / shopping cart plugin for FacturaScripts, built for a Spanish olive wood sawmill. The plugin manages a product catalogue of olive wood products—planks, custom-cut boards, rustic bathroom countertops, kitchen countertops, cutting boards and handcrafted olive wood items—with full support for customers across the European Union.

## Product Categories

| Category (ES) | Category (EN) | Category (FR) | Category (DE) | Family Type |
|---|---|---|---|---|
| Madera de Olivo | Olive Wood | Bois d'Olivier | Olivenholz | mercancia |
| Tablones de Madera | Wood Planks | Planches de Bois | Holzbohlen | tablones |
| Tableros de Madera de Olivo | Olive Wood Boards | Plateaux en Bois d'Olivier | Olivenholzplatten | tableros |
| Encimeras de Baño Rústicas | Rustic Bathroom Countertops | Plans de Toilette Rustiques | Rustikale Badezimmer-Waschtischplatten | tableros |
| Encimeras de Cocina | Kitchen Countertops | Plans de Travail de Cuisine | Küchenarbeitsplatten | tableros |
| Tablas de Cocina | Cutting Boards | Planches à Découper | Schneidebretter | artesania |
| Artesanía de Madera de Olivo | Olive Wood Crafts | Artisanat en Bois d'Olivier | Olivenholz-Kunsthandwerk | artesania |

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
- **Category Management** — Organise products into families with type-specific behaviour (mercancia, tablones, tableros, artesania)
- **Storefront** — Public-facing product catalogue with category filtering and Schema.org structured data
- **Shopping Cart** — Session-based cart with add, update quantity, and remove functionality
- **Custom Dimensions** — Tableros (boards/countertops) support customer-specified length × width with price per m²
- **Order Processing** — Checkout flow that converts cart items into orders with full customer details
- **Native FS Integration** — Automatically creates FacturaScripts `Cliente` and `PedidoCliente` records
- **Stripe Payments** — Integrated Stripe checkout for card payments
- **Translations** — English, Spanish, French and German language support
- **EU Shipping** — Designed for customers in Spain, France, Germany and the whole EU

## Plugin Structure

```
YeveaStore/
├── Assets/
│   └── JS/
│       └── EditFamilia.js           # Dynamic family-type UI
├── Controller/
│   ├── EditYeveaStoreOrder.php       # Edit order (admin)
│   ├── ListYeveaStoreOrder.php       # List orders (admin)
│   ├── Presupuesto.php              # Quote/checkout (frontend)
│   ├── ProductoDetalle.php          # Product detail (frontend)
│   ├── Tableros.php                 # Product catalogue (frontend)
│   ├── SettingsYeveaStore.php        # Stripe settings (admin)
│   ├── ShoppingCartView.php         # Shopping cart redirect
│   └── StoreFront.php               # Storefront catalogue (frontend)
├── Extension/
│   ├── Controller/
│   │   ├── EditFamilia.php          # Family type + dimension limits
│   │   └── EditProducto.php         # Product image fixes + nostock
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
│   ├── es_ES.json                   # Spanish translations
│   └── fr_FR.json                   # French translations
├── View/
│   ├── Presupuesto.html.twig        # Quote/checkout template
│   ├── ProductoDetalle.html.twig    # Product detail template (with Schema.org)
│   ├── Tableros.html.twig            # Product catalogue template (with Schema.org)
│   ├── ShoppingCartView.html.twig   # Cart redirect template
│   └── StoreFront.html.twig         # Storefront template (with Schema.org)
├── XMLView/
│   ├── EditYeveaStoreOrder.xml       # Order editor view
│   ├── EditYeveaStoreOrderLine.xml   # Order line editor view
│   ├── ListYeveaStoreOrder.xml       # Order list view
│   └── SettingsYeveaStore.xml        # Settings view
├── Init.php                         # Plugin initialisation
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
2. Navigate to **Admin → Settings** and click the **E-Commerce** tab  
   (direct URL: `/SettingsYeveaStore`).
3. Enter your **Stripe Secret Key** (`sk_live_…` or `sk_test_…` for testing) and optionally the **Stripe Public Key** (`pk_live_…` / `pk_test_…`).
4. Click **Save**.

You can obtain both keys from the [Stripe Dashboard → Developers → API keys](https://dashboard.stripe.com/apikeys).

> **Tip:** Use test keys (`sk_test_…` / `pk_test_…`) during development and switch to live keys for production.

#### Option B — phpMyAdmin / cPanel File Manager (no admin panel access needed)

If you prefer to configure the keys directly in the database (e.g. via **cPanel → phpMyAdmin**):

1. Open phpMyAdmin and select the FacturaScripts database.
2. Browse the `fs_settings` table.
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

### Storefront
- Access the storefront at `/StoreFront` or `/Tableros`
- Browse products, filter by category, add items to cart
- Access the quote/cart at `/Presupuesto`
- Complete checkout by entering customer details (name, NIF/CIF, email, phone, address, city, postal code, province, country) and clicking **Realizar Pedido**
- Stripe payment is processed; on success, a native FacturaScripts client and sales order are created automatically
