<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * Shared Delhivery helpers: credentials from .env, payload clean-up,
 * readable error messages and request logging.
 *
 * .env keys:
 *   DELHIVERY_API_TOKEN=<live token from one.delhivery.com > Settings > API Setup>
 *   DELHIVERY_BASE_URL=https://track.delhivery.com   (staging: https://staging-express.delhivery.com)
 */
class DelhiveryHelper
{
    public static function token(): string
    {
        return trim((string) config('services.delhivery.token'));
    }

    public static function baseUrl(): string
    {
        return rtrim((string) config('services.delhivery.base_url', 'https://track.delhivery.com'), '/');
    }

    public static function isConfigured(): bool
    {
        return self::token() !== '';
    }

    /**
     * Clean up a stored order payload before it is sent to Delhivery's
     * /api/cmu/create.json endpoint.
     */
    public static function normalizePayload(array $payload, ?Order $order = null): array
    {
        $shipments = $payload['shipments'] ?? [];

        foreach ($shipments as $i => $s) {
            // Payment mode must be "COD" or "Prepaid"
            $isCod = strtolower((string) ($s['payment_mode'] ?? ($order->payment_type ?? ''))) === 'cod';
            $s['payment_mode'] = $isCod ? 'COD' : 'Prepaid';
            if (!$isCod) {
                $s['cod_amount'] = '0';
            }

            if ($order) {
                // Always use our internal order number so the order can be matched back
                $s['order'] = (string) $order->order_number;

                // Package dimensions (cm) and weight (grams) from the saved order
                if (empty($s['shipment_length']) && !empty($order->package_length)) {
                    $s['shipment_length'] = $order->package_length;
                }
                if (empty($s['shipment_width']) && !empty($order->package_breadth)) {
                    $s['shipment_width'] = $order->package_breadth;
                }
                if (empty($s['shipment_height']) && !empty($order->package_height)) {
                    $s['shipment_height'] = $order->package_height;
                }
                if (empty($s['weight']) && !empty($order->package_weight)) {
                    $s['weight'] = $order->package_weight;
                }
            }

            // seller_inv was being filled with the "unique order number" yes/no flag
            if (!isset($s['seller_inv']) || in_array(strtolower((string) $s['seller_inv']), ['', 'yes', 'no'], true)) {
                $s['seller_inv'] = (string) ($s['order'] ?? '');
            }

            // 10-digit phone numbers only
            if (!empty($s['phone'])) {
                $digits = preg_replace('/\D+/', '', (string) $s['phone']);
                $s['phone'] = substr($digits, -10);
            }

            if (isset($s['add'])) {
                $s['add'] = trim(preg_replace('/\s*,\s*$/', '', (string) $s['add']));
            }

            foreach (['shipment_length', 'shipment_width', 'shipment_height', 'weight'] as $k) {
                if (isset($s[$k]) && $s[$k] !== '') {
                    $s[$k] = (float) $s[$k];
                }
            }

            $shipments[$i] = $s;
        }

        $payload['shipments'] = $shipments;

        if (!empty($payload['pickup_location']['phone'])) {
            $digits = preg_replace('/\D+/', '', (string) $payload['pickup_location']['phone']);
            $payload['pickup_location']['phone'] = substr($digits, -10);
        }

        return $payload;
    }

    /**
     * Turn a Delhivery error response into a readable message.
     */
    public static function errorMessage($response): string
    {
        if (!is_array($response)) {
            return 'No response from Delhivery';
        }

        $parts = [];
        if (!empty($response['rmk'])) {
            $parts[] = is_array($response['rmk']) ? implode('; ', $response['rmk']) : $response['rmk'];
        }
        foreach ($response['packages'] ?? [] as $pkg) {
            foreach ((array) ($pkg['remarks'] ?? []) as $r) {
                if ($r !== '' && $r !== null) {
                    $parts[] = $r;
                }
            }
        }
        if (!empty($response['error'])) {
            $parts[] = is_array($response['error']) ? implode('; ', $response['error']) : $response['error'];
        }
        if (!self::isConfigured()) {
            $parts[] = 'DELHIVERY_API_TOKEN is not set in .env';
        }

        $parts = array_unique(array_filter(array_map('strval', $parts)));

        return $parts ? implode(' | ', $parts) : 'Unknown error from Delhivery';
    }

    /**
     * Log a Delhivery API call (to storage/logs/laravel.log).
     */
    public static function log(string $action, $request, $response): void
    {
        try {
            $status = method_exists($response, 'status') ? $response->status() : null;
            $body = method_exists($response, 'json') ? ($response->json() ?? $response->body()) : $response;

            Log::info('[Delhivery] ' . $action, [
                'http_status' => $status,
                'request' => $request,
                'response' => $body,
            ]);
        } catch (\Throwable $e) {
            // never break order flow because of logging
        }
    }
}
