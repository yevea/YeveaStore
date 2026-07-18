<?php
namespace FacturaScripts\Plugins\YeveaStore\Lib;

use FacturaScripts\Core\Model\Familia;
use FacturaScripts\Core\Model\Producto;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\YeveaStore\Model\YeveaStoreCartItem;
use FacturaScripts\Plugins\YeveaStore\Model\YeveaStoreOrder;
use FacturaScripts\Plugins\YeveaStore\Model\YeveaStoreOrderLine;

/**
 * Shared order-fulfilment logic, used by BOTH the customer-facing checkout
 * return (Presupuesto ?stripe=success) and the server-to-server Stripe
 * webhook (StripeWebhook). Whichever arrives first processes the order; the
 * other one finds it already processed and does nothing.
 *
 * The using class must also use LanguageTrait (translateProduct() is needed
 * to resolve product names).
 */
trait OrderFulfillmentTrait
{
    /**
     * Finalizes an order paid through Stripe Checkout, given the checkout
     * session data (must include metadata.order_id). Idempotent: an order
     * that is no longer in 'pending_payment' is returned untouched.
     *
     * @param array $session Stripe checkout session (decoded JSON)
     * @return YeveaStoreOrder|null the order, or null if it cannot be found
     */
    protected function finalizePaidOrder(array $session): ?YeveaStoreOrder
    {
        $orderId = (int) ($session['metadata']['order_id'] ?? 0);
        $order = new YeveaStoreOrder();
        if ($orderId <= 0 || false === $order->loadFromCode($orderId)) {
            return null;
        }

        // Idempotency guard. Note: the webhook and the browser return can race;
        // the reload narrows the window, and a duplicate would only affect the
        // native PedidoCliente (visible to the admin), never the payment.
        $order->loadFromCode($order->id);
        if ($order->status !== 'pending_payment') {
            return $order;
        }

        $order->status = 'pending';
        if (false === $order->save()) {
            Tools::log()->error('order-placement-failed');
            return $order;
        }

        $lineModel = new YeveaStoreOrderLine();
        $orderLines = $lineModel->all([Where::eq('order_id', $order->id)], [], 0, 0);

        // Integrate with FacturaScripts native client and order models
        $this->createNativeFsOrder($order, $orderLines);

        // Empty the cart that generated this order, even if the current PHP
        // session differs from the one used at checkout time.
        $cartSession = (string) ($session['metadata']['cart_session'] ?? '');
        if ($cartSession !== '') {
            $cartItem = new YeveaStoreCartItem();
            foreach ($cartItem->all([Where::eq('session_id', $cartSession)], [], 0, 0) as $item) {
                $item->delete();
            }
        }

        Tools::log()->notice('order-placed-successfully');
        return $order;
    }

    /**
     * Creates a native FacturaScripts Cliente and PedidoCliente from the YeveaStore order.
     * Gracefully skips if the required FS models are not available.
     */
    protected function createNativeFsOrder(YeveaStoreOrder $order, array $orderLines): void
    {
        $pedidoClass = 'FacturaScripts\\Dinamic\\Model\\PedidoCliente';
        $lineaClass = 'FacturaScripts\\Dinamic\\Model\\LineaPedidoCliente';

        if (!class_exists('FacturaScripts\\Dinamic\\Model\\Cliente') || !class_exists($pedidoClass) || !class_exists($lineaClass)) {
            return;
        }

        if (!class_exists('\FacturaScripts\Core\Lib\Calculator')) {
            return;
        }

        try {
            // Find or create a Cliente
            $cliente = $this->findOrCreateCliente($order);
            if (null === $cliente) {
                return;
            }

            $order->codcliente = $cliente->codcliente;

            // Create a PedidoCliente
            /** @var \FacturaScripts\Dinamic\Model\PedidoCliente $pedido */
            $pedido = new $pedidoClass();
            $pedido->codcliente = $cliente->codcliente;
            $pedido->nombrecliente = $order->customer_name;
            $pedido->cifnif = $order->customer_nif ?? '';
            $pedido->email = $order->customer_email ?? '';
            $pedido->telefono1 = $order->customer_phone ?? '';
            $pedido->direccion = $order->address ?? '';
            $pedido->codpostal = $order->customer_zip ?? '';
            $pedido->ciudad = $order->customer_city ?? '';
            $pedido->provincia = $order->customer_province ?? '';
            $pedido->codpais = $order->customer_country ?: 'ES';
            $pedido->observaciones = $order->notes ?? '';
            $pedido->fecha = Tools::date();
            $pedido->hora = Tools::hour();

            // Save the pedido first so its primary key (idpedido) is available when
            // getNewLine() creates line objects – otherwise idpedido would be null and
            // inserting into lineaspedidoscli would fail with a NOT NULL constraint.
            if (false === $pedido->save()) {
                Tools::log()->error('pedido-creation-failed');
                return;
            }

            // Build lines using getNewLine() so tax defaults are applied correctly,
            // then use Calculator::calculate() to compute proper totals and persist everything.
            $lines = [];
            foreach ($orderLines as $yeveastoreLine) {
                $info = $this->resolveProductInfoByRef($yeveastoreLine->product_referencia);
                if ($info === null) {
                    Tools::log()->warning('product-not-found', ['referencia' => $yeveastoreLine->product_referencia]);
                    continue;
                }

                $linea = $pedido->getNewLine();
                $linea->referencia = $yeveastoreLine->product_referencia;
                $linea->descripcion = $yeveastoreLine->product_name;
                $linea->pvpunitario = $info->price;

                // For Tableros: adjust price by area
                $largoCm = $yeveastoreLine->largo_cm ?? null;
                $anchoCm = $yeveastoreLine->ancho_cm ?? null;
                $area = $this->calculateTablerosArea($largoCm, $anchoCm);
                if ($area !== null) {
                    $linea->pvpunitario = $info->price * $area;
                    $linea->descripcion .= ' (' . $largoCm . 'x' . $anchoCm . ' cm)';
                }

                // For Tablones: append product dimensions to description
                $linea->descripcion = $this->appendTablonesDimensions($linea->descripcion, $info);

                $linea->cantidad = $yeveastoreLine->quantity;
                $lines[] = $linea;
            }

            if (\FacturaScripts\Core\Lib\Calculator::calculate($pedido, $lines, true)) {
                $order->codpedido = $pedido->codigo;
                $order->save();
            }
        } catch (\Exception $e) {
            Tools::log()->error($e->getMessage());
        }
    }

    /**
     * Finds an existing Cliente by email or creates a new one from the order data.
     *
     * @return object|null
     */
    protected function findOrCreateCliente(YeveaStoreOrder $order): ?object
    {
        $clienteClass = 'FacturaScripts\\Dinamic\\Model\\Cliente';
        $email = $order->customer_email ?? '';

        if (!empty($email)) {
            /** @var \FacturaScripts\Dinamic\Model\Cliente $existing */
            $existing = new $clienteClass();
            $where = [Where::eq('email', $email)];
            if ($existing->loadWhere($where)) {
                return $existing;
            }
        }

        /** @var \FacturaScripts\Dinamic\Model\Cliente $cliente */
        $cliente = new $clienteClass();
        $cliente->nombre = $order->customer_name;
        $cliente->cifnif = $order->customer_nif ?? '';
        $cliente->email = $email;
        $cliente->telefono1 = $order->customer_phone ?? '';
        $cliente->direccion = $order->address ?? '';
        $cliente->codpostal = $order->customer_zip ?? '';
        $cliente->ciudad = $order->customer_city ?? '';
        $cliente->provincia = $order->customer_province ?? '';
        $cliente->codpais = $order->customer_country ?: 'ES';

        if ($cliente->save()) {
            return $cliente;
        }

        return null;
    }

    /**
     * Resolves product name and price by referencia.
     * Prefers Variante lookup so the full name (parent + attribute description) is always
     * returned for variant products. Falls back to a direct Producto lookup for single-variant
     * products or when the Variante model is unavailable.
     *
     * @param string $referencia
     * @return object|null with properties: name, price, referencia, tax_rate, largo, ancho, espesor, familyType
     */
    protected function resolveProductInfoByRef(string $referencia): ?object
    {
        $varianteClass = '\FacturaScripts\Core\Model\Variante';

        // Prefer variant lookup so we can always build the full name with attributes
        if (class_exists($varianteClass)) {
            $variante = new $varianteClass();
            $varWhere = [Where::eq('referencia', $referencia)];
            if ($variante->loadWhere($varWhere)) {
                $parent = new Producto();
                if ($parent->loadFromCode($variante->idproducto)) {
                    $attrDesc = method_exists($variante, 'description') ? $variante->description(true) : '';
                    $translated = $this->translateProduct($parent->referencia, $parent->descripcion, '');
                    $name = empty($attrDesc)
                        ? $translated['name']
                        : $translated['name'] . ' – ' . $attrDesc;
                    return (object) [
                        'name' => $name,
                        'price' => $variante->precio,
                        'referencia' => $parent->referencia,
                        'slug' => (string) ($parent->slug ?? ''),
                        'tax_rate' => $this->getTaxRate($parent->codimpuesto ?? ''),
                        'largo' => $parent->largo ?? null,
                        'ancho' => $parent->ancho ?? null,
                        'espesor' => $parent->espesor ?? null,
                        'familyType' => $this->getFamilyType($parent->codfamilia),
                    ];
                }
            }
        }

        // Fall back to direct Producto lookup (e.g. single-variant products or when Variante model unavailable)
        $product = new Producto();
        $where = [Where::eq('referencia', $referencia)];
        if ($product->loadWhere($where)) {
            $translated = $this->translateProduct($product->referencia, $product->descripcion, '');
            return (object) [
                'name' => $translated['name'],
                'price' => $product->precio,
                'referencia' => $product->referencia,
                'slug' => (string) ($product->slug ?? ''),
                'tax_rate' => $this->getTaxRate($product->codimpuesto ?? ''),
                'largo' => $product->largo ?? null,
                'ancho' => $product->ancho ?? null,
                'espesor' => $product->espesor ?? null,
                'familyType' => $this->getFamilyType($product->codfamilia),
            ];
        }

        return null;
    }

    protected function getTaxRate(string $codimpuesto): float
    {
        // Fall back to the company default tax when the product has no codimpuesto,
        // matching the behaviour of Calculator::calculate() used in printPresupuesto().
        if (empty($codimpuesto)) {
            $codimpuesto = Tools::settings('default', 'codimpuesto', '');
        }

        if (empty($codimpuesto)) {
            return 0.0;
        }

        $impuestoClass = null;
        foreach (['\FacturaScripts\Dinamic\Model\Impuesto', '\FacturaScripts\Core\Model\Impuesto'] as $class) {
            if (class_exists($class)) {
                $impuestoClass = $class;
                break;
            }
        }

        if ($impuestoClass === null) {
            return 0.0;
        }

        $impuesto = new $impuestoClass();
        return $impuesto->loadFromCode($codimpuesto) ? (float) $impuesto->iva : 0.0;
    }

    /**
     * Calculates the area in m² for Tableros items.
     * Returns null if this is not a Tableros item (no valid dimensions).
     */
    protected function calculateTablerosArea(?float $largoCm, ?float $anchoCm): ?float
    {
        if ($largoCm !== null && $anchoCm !== null && $largoCm > 0 && $anchoCm > 0) {
            return $largoCm * $anchoCm / 10000;
        }
        return null;
    }

    protected function getFamilyType(?string $codfamilia): string
    {
        if (empty($codfamilia)) {
            return 'mercancia';
        }

        $familia = new Familia();
        if ($familia->loadFromCode($codfamilia)) {
            return $familia->tipofamilia ?? 'mercancia';
        }

        return 'mercancia';
    }

    /**
     * Formats product dimensions (largo, ancho, espesor) into a display string.
     * Returns empty string if no dimensions are available.
     */
    protected function formatProductDimensions(?float $largo, ?float $ancho, ?float $espesor): string
    {
        $parts = [];
        if ($largo !== null) {
            $parts[] = $largo;
        }
        if ($ancho !== null) {
            $parts[] = $ancho;
        }
        if ($espesor !== null) {
            $parts[] = $espesor;
        }
        return empty($parts) ? '' : implode('x', $parts) . ' cm';
    }

    /**
     * Appends tablones product dimensions to a description string.
     */
    protected function appendTablonesDimensions(string $descripcion, object $info): string
    {
        if ($info->familyType === 'tablones') {
            $dims = $this->formatProductDimensions($info->largo, $info->ancho, $info->espesor);
            if ($dims !== '') {
                $descripcion .= ' (' . $dims . ')';
            }
        }
        return $descripcion;
    }
}
