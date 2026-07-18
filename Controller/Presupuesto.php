<?php
namespace FacturaScripts\Plugins\YeveaStore\Controller;

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\YeveaStore\Lib\OrderFulfillmentTrait;
use FacturaScripts\Plugins\YeveaStore\Lib\StoreControllerBase;
use FacturaScripts\Plugins\YeveaStore\Model\YeveaStoreCartItem;
use FacturaScripts\Plugins\YeveaStore\Model\YeveaStoreOrder;
use FacturaScripts\Plugins\YeveaStore\Model\YeveaStoreOrderLine;

class Presupuesto extends StoreControllerBase
{
    use OrderFulfillmentTrait;

    private const CLIENTE_CLASS = 'FacturaScripts\\Dinamic\\Model\\Cliente';
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
                $this->redirectAfterPost();
                break;

            case 'remove-item':
                $this->removeItem();
                $this->redirectAfterPost();
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

        // Create the order (status 'pending_payment') BEFORE redirecting to Stripe,
        // and pass its id in the Stripe metadata. This way the order can always be
        // recovered on the success callback, even if the PHP session expired or the
        // customer returns from another browser/device.
        $order = $this->createPendingOrder($items, [
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
        ]);
        if ($order === null) {
            Tools::log()->error('order-placement-failed');
            return;
        }

        $checkoutUrl = $this->createStripeCheckoutSession($items, $secretKey, [
            'order_id' => (string) $order->id,
            'cart_session' => $sessionId,
        ]);
        if ($checkoutUrl) {
            header('Location: ' . $checkoutUrl, true, 302);
            exit;
        }

        // Stripe session could not be created: remove the pending order so a retry
        // does not leave duplicates behind.
        $this->deleteOrderWithLines($order);
        Tools::log()->error('stripe-session-failed');
    }

    /**
     * Creates a YeveaStoreOrder (status 'pending_payment') with its lines and totals
     * from the current cart items. Returns null if nothing could be saved.
     * Orders left in 'pending_payment' are abandoned checkouts.
     */
    private function createPendingOrder(array $items, array $data): ?YeveaStoreOrder
    {
        $order = new YeveaStoreOrder();
        $order->customer_name = $data['customer_name'];
        $order->customer_email = $data['customer_email'];
        $order->customer_phone = $data['customer_phone'];
        $order->customer_nif = $data['customer_nif'];
        $order->address = $data['address'];
        $order->customer_city = $data['customer_city'];
        $order->customer_zip = $data['customer_zip'];
        $order->customer_province = $data['customer_province'];
        $order->customer_country = $data['customer_country'];
        $order->notes = $data['notes'];
        $order->status = 'pending_payment';

        $total = 0;
        $orderLines = [];

        foreach ($items as $item) {
            $info = $this->resolveProductInfoByRef($item->product_referencia);
            if ($info === null) {
                continue;
            }

            $priceWithTax = $info->price * (1 + $info->tax_rate / 100);

            // For Tableros: area-based pricing
            $largoCm = $item->largo_cm ?? null;
            $anchoCm = $item->ancho_cm ?? null;
            $area = $this->calculateTablerosArea($largoCm, $anchoCm);
            $subtotal = ($area !== null)
                ? $priceWithTax * $area * $item->quantity
                : $priceWithTax * $item->quantity;
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

        if (empty($orderLines)) {
            return null;
        }

        $order->total = $total;
        if (false === $order->save()) {
            return null;
        }

        foreach ($orderLines as $line) {
            $line->order_id = $order->id;
            $line->save();
        }

        return $order;
    }

    private function deleteOrderWithLines(YeveaStoreOrder $order): void
    {
        $lineModel = new YeveaStoreOrderLine();
        foreach ($lineModel->all([Where::eq('order_id', $order->id)], [], 0, 0) as $line) {
            $line->delete();
        }
        $order->delete();
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

        $session = $this->getStripeSession($stripeSessionId, $secretKey);
        if ($session === null || ($session['payment_status'] ?? '') !== 'paid') {
            Tools::log()->error('stripe-session-failed');
            return;
        }

        // Shared with the StripeWebhook controller; idempotent, so it does not
        // matter whether the webhook or this browser return runs first.
        $order = $this->finalizePaidOrder($session);
        if ($order === null) {
            // Paid Stripe session without a recoverable order (e.g. checkout started
            // before this version was deployed). Show a generic success to the customer
            // and flag it in the log so the admin can reconcile it in Stripe.
            $this->orderSuccess = true;
            Tools::log()->error('order-not-found-for-paid-stripe-session ' . $stripeSessionId);
            return;
        }

        $this->orderSuccess = true;
        $this->orderCode = $order->code;
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

    private function createStripeCheckoutSession(array $items, string $secretKey, array $metadata = []): ?string
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
            'success_url' => $baseUrl . '/presupuesto?stripe=success&stripe_session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $baseUrl . '/presupuesto?stripe=cancel',
        ];

        // Metadata travels with the Stripe session and comes back on retrieval,
        // so the order can be recovered without relying on the PHP session.
        foreach ($metadata as $key => $value) {
            $params['metadata[' . $key . ']'] = $value;
        }

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

    /**
     * Retrieves a Stripe Checkout Session (payment status + metadata) from the API.
     */
    private function getStripeSession(string $stripeSessionId, string $secretKey): ?array
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
            return null;
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
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
                    'slug' => $info->slug ?? '',
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

}
