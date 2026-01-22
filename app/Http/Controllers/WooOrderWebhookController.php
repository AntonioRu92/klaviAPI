<?php

namespace App\Http\Controllers;

use App\Services\KlaviyoService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class WooOrderWebhookController extends Controller
{
    public function __construct(
        private KlaviyoService $klaviyoService
    ) {}

    public function handle(Request $request): JsonResponse
    {
        // Log the raw payload
        Log::info('WooCommerce Order Created Webhook Received', [
            'payload' => $request->all(),
            'headers' => $request->headers->all(),
        ]);

        // Validate the payload
        $validator = Validator::make($request->all(), [
            'billing.email' => 'required|email',
            'id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            Log::warning('Invalid WooCommerce Webhook Payload', [
                'errors' => $validator->errors(),
                'payload' => $request->all(),
            ]);
            return response()->json(['status' => 'invalid_payload'], 400);
        }

        // Extract and normalize order data
        $orderData = $this->normalizeOrderData($request->all());

        try {
            // Upsert profile
            $this->klaviyoService->upsertProfile($orderData['profile']);

            // Track order event
            $this->klaviyoService->trackOrderEvent($orderData['event']);
        } catch (\Exception $e) {
            Log::error('Error processing Klaviyo integration', [
                'order_id' => $request->input('id'),
                'error' => $e->getMessage(),
            ]);
            // Still return 200 to WooCommerce as per requirements
        }

        return response()->json(['status' => 'processed'], 200);
    }

    private function normalizeOrderData(array $payload): array
    {
        $billing = $payload['billing'] ?? [];
        $order = $payload;

        return [
            'profile' => [
                'email' => $billing['email'],
                'first_name' => $billing['first_name'] ?? '',
                'last_name' => $billing['last_name'] ?? '',
                'woocommerce_id' => $payload['customer_id'] ?? null,
            ],
            'event' => [
                'order_id' => $order['id'],
                'total' => $order['total'] ?? 0,
                'currency' => $order['currency'] ?? 'USD',
                'payment_method' => $order['payment_method'] ?? '',
                'coupons' => $order['coupon_lines'] ?? [],
                'items' => $order['line_items'] ?? [],
                'time' => strtotime($order['date_created'] ?? now()->toDateTimeString()),
                'properties' => $order, // Include all extra fields
            ],
        ];
    }
}