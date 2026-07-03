<?php
namespace FacturaScripts\Plugins\YeveaStore\Controller;

use FacturaScripts\Core\Template\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\YeveaStore\Lib\LanguageTrait;
use FacturaScripts\Plugins\YeveaStore\Lib\OrderFulfillmentTrait;

/**
 * Server-to-server Stripe webhook endpoint (checkout.session.completed).
 *
 * Guarantees the order is fulfilled even if the customer never returns to the
 * site after paying (closed tab, lost connection, expired session…). Shares
 * the idempotent fulfilment logic with the Presupuesto success return, so
 * whichever arrives first wins and the other is a no-op.
 *
 * Setup (Stripe Dashboard → Developers → Webhooks):
 *   - Endpoint URL: https://<domain>/StripeWebhook
 *   - Event: checkout.session.completed
 *   - Copy the signing secret (whsec_…) into Admin → Settings → E-Commerce.
 */
class StripeWebhook extends Controller
{
    use LanguageTrait;
    use OrderFulfillmentTrait;

    /** Max accepted age (seconds) of a signed event, to block replay attacks */
    private const SIGNATURE_TOLERANCE = 300;

    protected $requiresAuth = false;

    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData['menu'] = 'yeveastore';
        $pageData['title'] = 'stripe-webhook';
        $pageData['icon'] = 'fa-brands fa-stripe';
        $pageData['showonmenu'] = false;
        return $pageData;
    }

    public function run(): void
    {
        parent::run();

        $webhookSecret = Tools::settings('yeveastore', 'stripe_webhook_secret', '');
        if (empty($webhookSecret)) {
            // Never process unverifiable events
            $this->respond(500, 'webhook-not-configured');
        }

        $payload = (string) file_get_contents('php://input');
        $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        if ($payload === '' || false === $this->verifySignature($payload, $signature, $webhookSecret)) {
            $this->respond(400, 'invalid-signature');
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            $this->respond(400, 'invalid-payload');
        }

        // Acknowledge any event type we don't handle so Stripe stops retrying it
        if (($event['type'] ?? '') !== 'checkout.session.completed') {
            $this->respond(200, 'ignored');
        }

        $session = $event['data']['object'] ?? [];
        if (!is_array($session) || ($session['payment_status'] ?? '') !== 'paid') {
            // e.g. async payment methods complete before the charge settles
            $this->respond(200, 'not-paid-yet');
        }

        $order = $this->finalizePaidOrder($session);
        if ($order === null) {
            // Retrying will not help if the order does not exist: acknowledge
            // and leave a trace for manual reconciliation in Stripe.
            Tools::log()->error('order-not-found-for-paid-stripe-session ' . ($session['id'] ?? ''));
            $this->respond(200, 'order-not-found');
        }

        $this->respond(200, 'ok');
    }

    /**
     * Verifies the Stripe-Signature header: HMAC-SHA256 of "{timestamp}.{payload}"
     * with the endpoint signing secret, plus a timestamp tolerance check.
     * https://docs.stripe.com/webhooks/signature
     */
    private function verifySignature(string $payload, string $header, string $secret): bool
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            if ($pair[0] === 't') {
                $timestamp = (int) $pair[1];
            } elseif ($pair[0] === 'v1') {
                $signatures[] = $pair[1];
            }
        }

        if (empty($timestamp) || empty($signatures)) {
            return false;
        }

        if (abs(time() - $timestamp) > self::SIGNATURE_TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sends a JSON response with the given HTTP status code and terminates.
     */
    private function respond(int $httpCode, string $status): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode(['status' => $status]);
        exit;
    }
}
