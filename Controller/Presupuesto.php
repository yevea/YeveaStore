<?php
namespace FacturaScripts\Plugins\YeveaStore\Controller;

use FacturaScripts\Core\Model\Familia;
use FacturaScripts\Core\Model\Producto;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\YeveaStore\Lib\StoreControllerBase;
use FacturaScripts\Plugins\YeveaStore\Model\YeveaStoreCartItem;
use FacturaScripts\Plugins\YeveaStore\Model\YeveaStoreOrder;
use FacturaScripts\Plugins\YeveaStore\Model\YeveaStoreOrderLine;

class Presupuesto extends StoreControllerBase
{
    private const CLIENTE_CLASS = 'FacturaScripts\\Dinamic\\Model\\Cliente';
    private const PEDIDO_CLASS = 'FacturaScripts\\Dinamic\\Model\\PedidoCliente';
    private const LINEA_CLASS = 'FacturaScripts\\Dinamic\\Model\\LineaPedidoCliente';
    private const PRESUPUESTO_CLASS = 'FacturaScripts\\Dinamic\\Model\\PresupuestoCliente';
    private const LINEA_PRESUPUESTO_CLASS = 'FacturaScripts\\Dinamic\\Model\\LineaPresupuestoCliente';

    /** @var array */
    public $cartItems = [];

    /** @var float */
    public $cartTotal = 0;

    /** @var float */
    public $cartNeto = 0;

    /** @var float */
    public $cartImpuestos = 0;

    /** @var bool */
    public $orderSuccess = false;

    /** @var string */
    public $orderCode = '';

    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['menu'] = 'yeveastore';
        $pageData['title'] = 'presupuesto';
        $pageData['icon'] = 'fa-solid fa-file-invoice';
        $pageData['showonmenu'] = false;
        return $pageData;
    }

    public function run(): void
    {
        parent::run();

        $stripeCallback = $this->request()->query->get('stripe', '');
        if ($stripeCallback === 'success') {
            $this->handleStripeSuccess();
        } elseif ($stripeCallback === 'cancel') {
            $this->handleStripeCancel();
        }

        $action = $this->request()->request->get('action', '');
        switch ($action) {
            case 'update-quantity':
                $this->updateQuantity();
                break;

            case 'remove-item':
                $this->removeItem();
                break;

            case 'place-order':
                $this->placeOrder();
                break;

            case 'print-presupuesto':
                $this->printPresupuesto();
                break;
        }

        $this->loadCartItems();
        $this->loadCategories();

        $this->view('Presupuesto.html.twig');
    }

    private function updateQuantity(): void
    {
        $cartItemId = (int) $this->request()->request->get('cart_item_id', 0);
        $quantity = (int) $this->request()->request->get('quantity', 1);

        $cartItem = new YeveaStoreCartItem();
        if ($cartItem->loadFromCode($cartItemId)) {
            if ($cartItem->session_id === $this->getSessionId()) {
                $cartItem->quantity = max(1, $quantity);
                $cartItem->save();
            }
        }
    }

    private function removeItem(): void
    {
        $cartItemId = (int) $this->request()->request->get('cart_item_id', 0);

        $cartItem = new YeveaStoreCartItem();
        if ($cartItem->loadFromCode($cartItemId)) {
            if ($cartItem->session_id === $this->getSessionId()) {
                $cartItem->delete();
            }
        }
    }

    private function placeOrder(): void
    {
        $sessionId = $this->getSessionId();

        $cartItem = new YeveaStoreCartItem();
        $where = [Where::eq('session_id', $sessionId)];
        $items = $cartItem->all($where);

        if (empty($items)) {
            Tools::log()->warning('cart-empty');
            return;
        }

        $customerName = trim($this->request()->request->get('customer_name', ''));
        $customerEmail = trim($this->request()->request->get('customer_email', ''));
        $customerPhone = trim($this->request()->request->get('customer_phone', ''));
        $customerNif = trim($this->request()->request->get('customer_nif', ''));
        $address = trim($this->request()->request->get('address', ''));
        $customerCity = trim($this->request()->request->get('customer_city', ''));
        $customerZip = trim($this->request()->request->get('customer_zip', ''));
        $customerProvince = trim($this->request()->request->get('customer_province', ''));
        $customerCountry = trim($this->request()->request->get('customer_country', 'ES'));
        $notes = trim($this->request()->request->get('notes', ''));

        if (empty($customerName)) {
            Tools::log()->warning('customer-name-required');
            return;
        }

        if (!empty($customerEmail) && false === filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            Tools::log()->warning('invalid-email');
            return;
        }

        $secretKey = Tools::settings('yeveastore', 'stripe_secret_key', '');
        if (empty($secretKey)) {
            Tools::log()->error('stripe-not-configured');
            return;
        }

        // Store pending order data in session to retrieve after Stripe callback
        $_SESSION['pending_yeveastore_order'] = [
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'customer_phone' => $customerPhone,
            'customer_nif' => $customerNif,
            'address' => $address,
            'customer_city' => $customerCity,
            'customer_zip' => $customerZip,
            'customer_province' => $customerProvince,
            'customer_country' => $customerCountry ?: 'ES',
            'notes' => $notes,
        ];

        $checkoutUrl = $this->createStripeCheckoutSession($items, $secretKey);
        if ($checkoutUrl) {
            header('Location: ' . $checkoutUrl, true, 302);
            exit;
        }

        Tools::log()->error('stripe-session-failed');
    }

    private function handleStripeSuccess(): void
    {
        $stripeSessionId = $this->request()->query->get('stripe_session_id', '');
        if (empty($stripeSessionId)) {
            return;
        }

        $secretKey = Tools::settings('yeveastore', 'stripe_secret_key', '');
        if (empty($secretKey)) {
            return;
        }

        if (!$this->verifyStripePayment($stripeSessionId, $secretKey)) {
            Tools::log()->error('stripe-session-failed');
            return;
        }

        $sessionId = $this->getSessionId();
        $pendingOrder = $_SESSION['pending_yeveastore_order'] ?? null;
        if (empty($pendingOrder)) {
            // Session expired or order was already processed; show a generic success page
            $this->orderSuccess = true;
            Tools::log()->notice('order-placed-successfully');
            return;
        }

        $cartItem = new YeveaStoreCartItem();
        $where = [Where::eq('session_id', $sessionId)];
        $items = $cartItem->all($where);

        $order = new YeveaStoreOrder();
        $order->customer_name = $pendingOrder['customer_name'];
        $order->customer_email = $pendingOrder['customer_email'];
        $order->customer_phone = $pendingOrder['customer_phone'] ?? '';
        $order->customer_nif = $pendingOrder['customer_nif'] ?? '';
        $order->address = $pendingOrder['address'];
        $order->customer_city = $pendingOrder['customer_city'] ?? '';
        $order->customer_zip = $pendingOrder['customer_zip'] ?? '';
        $order->customer_province = $pendingOrder['customer_province'] ?? '';
        $order->customer_country = $pendingOrder['customer_country'] ?? 'ES';
        $order->notes = $pendingOrder['notes'];
        $order->status = 'pending';

        $total = 0;
        $orderLines = [];

        foreach ($items as $item) {
            $info = $this->resolveProductInfoByRef($item->product_referencia);
            if ($info !== null) {
                $priceWithTax = $info->price * (1 + $info->tax_rate / 100);

                // For Tableros: area-based pricing
                $largoCm = $item->largo_cm ?? null;
                $anchoCm = $item->ancho_cm ?? null;
                $area = $this->calculateTablerosArea($largoCm, $anchoCm);
                if ($area !== null) {
                    $subtotal = $priceWithTax * $area * $item->quantity;
                } else {
                    $subtotal = $priceWithTax * $item->quantity;
                }
                $total += $subtotal;

                $line = new YeveaStoreOrderLine();
                $line->product_referencia = $info->referencia;
                $line->product_name = $info->name;
                $line->quantity = $item->quantity;
                $line->price = $priceWithTax;
                $line->subtotal = $subtotal;
                $line->largo_cm = $largoCm;
                $line->ancho_cm = $anchoCm;
                $orderLines[] = $line;
            }
        }

        $order->total = $total;

        if ($order->save()) {
            foreach ($orderLines as $line) {
                $line->order_id = $order->id;
                $line->save();
            }

            // Integrate with FacturaScripts native client and order models
            $this->createNativeFsOrder($order, $orderLines, $pendingOrder);

            foreach ($items as $item) {
                $item->delete();
            }

            unset($_SESSION['pending_yeveastore_order']);
            $this->orderSuccess = true;
            $this->orderCode = $order->code;
        } else {
            Tools::log()->error('order-placement-failed');
        }
    }

    /**
     * Creates a native FacturaScripts Cliente and PedidoCliente from the YeveaStore order.
     * Gracefully skips if the required FS models are not available.
     */
    private function createNativeFsOrder(YeveaStoreOrder $order, array $orderLines, array $pendingOrder): void
    {
        if (!class_exists(self::CLIENTE_CLASS) || !class_exists(self::PEDIDO_CLASS) || !class_exists(self::LINEA_CLASS)) {
            return;
        }

        if (!class_exists('\FacturaScripts\Core\Lib\Calculator')) {
            return;
        }

        try {
            // Find or create a Cliente
            $cliente = $this->findOrCreateCliente($pendingOrder);
            if (null === $cliente) {
                return;
            }

            $order->codcliente = $cliente->codcliente;

            // Create a PedidoCliente
            /** @var \FacturaScripts\Dinamic\Model\PedidoCliente $pedido */
            $pedido = new (self::PEDIDO_CLASS)();
            $pedido->codcliente = $cliente->codcliente;
            $pedido->nombrecliente = $pendingOrder['customer_name'];
            $pedido->cifnif = $pendingOrder['customer_nif'] ?? '';
            $pedido->email = $pendingOrder['customer_email'] ?? '';
            $pedido->telefono1 = $pendingOrder['customer_phone'] ?? '';
            $pedido->direccion = $pendingOrder['address'] ?? '';
            $pedido->codpostal = $pendingOrder['customer_zip'] ?? '';
            $pedido->ciudad = $pendingOrder['customer_city'] ?? '';
            $pedido->provincia = $pendingOrder['customer_province'] ?? '';
            $pedido->codpais = $pendingOrder['customer_country'] ?: 'ES';
            $pedido->observaciones = $pendingOrder['notes'] ?? '';
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
     * Finds an existing Cliente by email or creates a new one.
     *
     * @param array $pendingOrder
     * @return object|null
     */
    private function findOrCreateCliente(array $pendingOrder): ?object
    {
        $email = $pendingOrder['customer_email'] ?? '';

        if (!empty($email)) {
            /** @var \FacturaScripts\Dinamic\Model\Cliente $existing */
            $existing = new (self::CLIENTE_CLASS)();
            $where = [Where::eq('email', $email)];
            if ($existing->loadWhere($where)) {
                return $existing;
            }
        }

        /** @var \FacturaScripts\Dinamic\Model\Cliente $cliente */
        $cliente = new (self::CLIENTE_CLASS)();
        $cliente->nombre = $pendingOrder['customer_name'];
        $cliente->cifnif = $pendingOrder['customer_nif'] ?? '';
        $cliente->email = $email;
        $cliente->telefono1 = $pendingOrder['customer_phone'] ?? '';
        $cliente->direccion = $pendingOrder['address'] ?? '';
        $cliente->codpostal = $pendingOrder['customer_zip'] ?? '';
        $cliente->ciudad = $pendingOrder['customer_city'] ?? '';
        $cliente->provincia = $pendingOrder['customer_province'] ?? '';
        $cliente->codpais = $pendingOrder['customer_country'] ?: 'ES';

        if ($cliente->save()) {
            return $cliente;
        }

        return null;
    }

    /**
     * Creates a native FacturaScripts PresupuestoCliente from the current cart items
     * and redirects to the FS native PDF export so the user gets the standard presupuesto PDF.
     * Falls back to window.print() if the required FS models are not available.
     */
    private function printPresupuesto(): void
    {
        if (!class_exists(self::PRESUPUESTO_CLASS) || !class_exists(self::LINEA_PRESUPUESTO_CLASS)) {
            // Ventas plugin not available — front-end falls back to window.print()
            return;
        }

        $sessionId = $this->getSessionId();
        $cartItem = new YeveaStoreCartItem();
        $where = [Where::eq('session_id', $sessionId)];
        $items = $cartItem->all($where);

        if (empty($items)) {
            Tools::log()->warning('cart-empty');
            return;
        }

        try {
            /** @var \FacturaScripts\Dinamic\Model\PresupuestoCliente $presupuesto */
            $presupuesto = new (self::PRESUPUESTO_CLASS)();
            $presupuesto->nombrecliente = trim($this->request()->request->get('customer_name', '')) ?: 'Cliente';
            $presupuesto->cifnif = trim($this->request()->request->get('customer_nif', ''));
            $presupuesto->email = trim($this->request()->request->get('customer_email', ''));
            $presupuesto->telefono1 = trim($this->request()->request->get('customer_phone', ''));
            $presupuesto->direccion = trim($this->request()->request->get('address', ''));
            $presupuesto->codpostal = trim($this->request()->request->get('customer_zip', ''));
            $presupuesto->ciudad = trim($this->request()->request->get('customer_city', ''));
            $presupuesto->provincia = trim($this->request()->request->get('customer_province', ''));
            $presupuesto->codpais = trim($this->request()->request->get('customer_country', 'ES')) ?: 'ES';
            $presupuesto->observaciones = trim($this->request()->request->get('notes', ''));
            $presupuesto->fecha = Tools::date();
            $presupuesto->hora = Tools::hour();

            // Link to existing cliente if email matches
            if (!empty($presupuesto->email) && class_exists(self::CLIENTE_CLASS)) {
                /** @var \FacturaScripts\Dinamic\Model\Cliente $existing */
                $existing = new (self::CLIENTE_CLASS)();
                $clienteWhere = [Where::eq('email', $presupuesto->email)];
                if ($existing->loadWhere($clienteWhere)) {
                    $presupuesto->codcliente = $existing->codcliente;
                }
            }

            if (!$presupuesto->save()) {
                Tools::log()->error('presupuesto-creation-failed');
                return;
            }

            // Build lines using getNewLine() so tax defaults are applied, then
            // use Calculator::calculate() to compute proper line and document totals.
            $lines = [];
            foreach ($items as $item) {
                $info = $this->resolveProductInfoByRef($item->product_referencia);
                if ($info === null) {
                    continue;
                }

                $linea = $presupuesto->getNewLine();
                $linea->referencia = $item->product_referencia;
                $linea->descripcion = $info->name;
                $linea->pvpunitario = $info->price;

                // For Tableros: adjust price by area
                $largoCm = $item->largo_cm ?? null;
                $anchoCm = $item->ancho_cm ?? null;
                $area = $this->calculateTablerosArea($largoCm, $anchoCm);
                if ($area !== null) {
                    $linea->pvpunitario = $info->price * $area;
                    $linea->descripcion .= ' (' . $largoCm . 'x' . $anchoCm . ' cm)';
                }

                // For Tablones: append product dimensions to description
                $linea->descripcion = $this->appendTablonesDimensions($linea->descripcion, $info);

                $linea->cantidad = $item->quantity;
                $lines[] = $linea;
            }

            \FacturaScripts\Core\Lib\Calculator::calculate($presupuesto, $lines, true);

            // Use the numeric primary key in the URL so EditPresupuestoCliente can find the record.
            $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
            $url = $scriptDir . '/EditPresupuestoCliente?action=export&option=PDF&code=' . urlencode($presupuesto->idpresupuesto);
            header('Location: ' . $url, true, 302);
            exit;
        } catch (\Exception $e) {
            Tools::log()->error($e->getMessage());
        }
    }

    private function handleStripeCancel(): void
    {
        Tools::log()->notice('order-payment-cancelled');
    }

    private function createStripeCheckoutSession(array $items, string $secretKey): ?string
    {
        $baseUrl = $this->baseUrl();

        $lineItems = [];
        foreach ($items as $item) {
            $info = $this->resolveProductInfoByRef($item->product_referencia);
            if ($info !== null) {
                $unitAmountWithTax = $info->price * (1 + $info->tax_rate / 100);

                // For Tableros: area-based pricing
                $largoCm = $item->largo_cm ?? null;
                $anchoCm = $item->ancho_cm ?? null;
                $itemName = $info->name;
                $area = $this->calculateTablerosArea($largoCm, $anchoCm);
                if ($area !== null) {
                    $totalAmount = (int) round($unitAmountWithTax * $area * 100);
                    $itemName .= ' (' . $largoCm . 'x' . $anchoCm . ' cm)';
                    $lineItems[] = [
                        'price_data' => [
                            'currency' => 'eur',
                            'product_data' => ['name' => $itemName],
                            'unit_amount' => $totalAmount,
                        ],
                        'quantity' => $item->quantity,
                    ];
                } else {
                    // For Tablones: append product dimensions to name
                    $itemName = $this->appendTablonesDimensions($itemName, $info);
                    $lineItems[] = [
                        'price_data' => [
                            'currency' => 'eur',
                            'product_data' => ['name' => $itemName],
                            'unit_amount' => (int) round($unitAmountWithTax * 100),
                        ],
                        'quantity' => $item->quantity,
                    ];
                }
            }
        }

        if (empty($lineItems)) {
            return null;
        }

        $params = [
            'payment_method_types' => ['card'],
            'line_items' => $lineItems,
            'mode' => 'payment',
            'success_url' => $baseUrl . '/Presupuesto?stripe=success&stripe_session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $baseUrl . '/Presupuesto?stripe=cancel',
        ];

        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_USERPWD, $secretKey . ':');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!$response || $httpCode !== 200) {
            if ($curlError) {
                Tools::log()->error($curlError);
            }
            return null;
        }

        $data = json_decode($response, true);
        return $data['url'] ?? null;
    }

    private function verifyStripePayment(string $stripeSessionId, string $secretKey): bool
    {
        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . urlencode($stripeSessionId));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $secretKey . ':');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!$response || $httpCode !== 200) {
            if ($curlError) {
                Tools::log()->error($curlError);
            }
            return false;
        }

        $data = json_decode($response, true);
        return isset($data['payment_status']) && $data['payment_status'] === 'paid';
    }

    private function loadCartItems(): void
    {
        $sessionId = $this->getSessionId();
        $this->cartItems = [];
        $this->cartTotal = 0;
        $this->cartNeto = 0;
        $this->cartImpuestos = 0;

        $cartItem = new YeveaStoreCartItem();
        $where = [Where::eq('session_id', $sessionId)];
        $items = $cartItem->all($where);

        foreach ($items as $item) {
            $info = $this->resolveProductInfoByRef($item->product_referencia);
            if ($info !== null) {
                $netPrice = $info->price;
                $taxRate = $info->tax_rate;

                // For Tableros: price is per m², calculate based on area
                $largoCm = $item->largo_cm ?? null;
                $anchoCm = $item->ancho_cm ?? null;
                $isTableros = false;
                $area = $this->calculateTablerosArea($largoCm, $anchoCm);
                if ($area !== null) {
                    $neto = $netPrice * $area * $item->quantity;
                    $isTableros = true;
                } else {
                    $neto = $netPrice * $item->quantity;
                }

                $taxAmount = round($neto * $taxRate / 100, 2);
                $subtotal = $neto + $taxAmount;
                $this->cartItems[] = (object) [
                    'id' => $item->id,
                    'referencia' => $info->referencia,
                    'product_name' => $info->name,
                    'net_price' => $netPrice,
                    'tax_rate' => $taxRate,
                    'quantity' => $item->quantity,
                    'neto' => $neto,
                    'tax_amount' => $taxAmount,
                    'subtotal' => $subtotal,
                    'product_price' => $netPrice * (1 + $taxRate / 100),
                    'largo_cm' => $largoCm,
                    'ancho_cm' => $anchoCm,
                    'isTableros' => $isTableros,
                    'isTablones' => $info->familyType === 'tablones',
                    'largo' => $info->largo,
                    'ancho' => $info->ancho,
                    'espesor' => $info->espesor,
                ];
                $this->cartNeto += $neto;
                $this->cartTotal += $subtotal;
            }
        }

        $this->cartImpuestos = round($this->cartTotal - $this->cartNeto, 2);
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
    private function resolveProductInfoByRef(string $referencia): ?object
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
                'tax_rate' => $this->getTaxRate($product->codimpuesto ?? ''),
                'largo' => $product->largo ?? null,
                'ancho' => $product->ancho ?? null,
                'espesor' => $product->espesor ?? null,
                'familyType' => $this->getFamilyType($product->codfamilia),
            ];
        }

        return null;
    }

    private function getTaxRate(string $codimpuesto): float
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
    private function calculateTablerosArea(?float $largoCm, ?float $anchoCm): ?float
    {
        if ($largoCm !== null && $anchoCm !== null && $largoCm > 0 && $anchoCm > 0) {
            return $largoCm * $anchoCm / 10000;
        }
        return null;
    }

    private function getFamilyType(?string $codfamilia): string
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
    private function formatProductDimensions(?float $largo, ?float $ancho, ?float $espesor): string
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
    private function appendTablonesDimensions(string $descripcion, object $info): string
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
