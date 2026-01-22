<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KlaviyoService
{
    private string $apiKey;
    private string $baseUrl = 'https://a.klaviyo.com/api';
    private string $revision = '2023-10-15';

    public function __construct()
    {
        $this->apiKey = config('services.klaviyo.private_api_key');
    }

    public function upsertProfile(array $profileData): void
    {
        $payload = [
            'data' => [
                'type' => 'profile',
                'attributes' => [
                    'email' => $profileData['email'],
                    'first_name' => $profileData['first_name'],
                    'last_name' => $profileData['last_name'],
                    'properties' => [
                        'woocommerce_id' => $profileData['woocommerce_id'],
                    ],
                ],
            ],
        ];

        $this->makeApiCall('POST', '/profiles/', $payload);
    }

    public function trackOrderEvent(array $orderData): void
    {
        $cacheKey = "klaviyo_order_{$orderData['order_id']}";

        // Idempotency check
        if (Cache::has($cacheKey)) {
            Log::info("Order event already tracked for order ID: {$orderData['order_id']}");
            return;
        }

        $payload = [
            'data' => [
                'type' => 'event',
                'attributes' => [
                    'metric' => [
                        'data' => [
                            'type' => 'metric',
                            'attributes' => [
                                'name' => 'Placed Order',
                            ],
                        ],
                    ],
                    'profile' => [
                        'data' => [
                            'type' => 'profile',
                            'attributes' => [
                                'email' => $orderData['properties']['billing']['email'] ?? '',
                            ],
                        ],
                    ],
                    'value' => $orderData['total'],
                    'time' => date('c', $orderData['time']), // ISO 8601
                    'properties' => [
                        'order_id' => $orderData['order_id'],
                        'total' => $orderData['total'],
                        'currency' => $orderData['currency'],
                        'payment_method' => $orderData['payment_method'],
                        'coupons' => $orderData['coupons'],
                        'items' => $orderData['items'],
                        // Include all extra fields from the original payload
                        ...$orderData['properties'],
                    ],
                ],
            ],
        ];

        $this->makeApiCall('POST', '/events/', $payload);

        // Mark as processed
        Cache::put($cacheKey, true, now()->addDays(30)); // Cache for 30 days
    }

    private function makeApiCall(string $method, string $endpoint, array $payload): void
    {
        try {
            $response = Http::timeout(30)
                ->retry(3, 100)
                ->withHeaders([
                    'Authorization' => "Klaviyo-API-Key {$this->apiKey}",
                    'Revision' => $this->revision,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->send($method, $this->baseUrl . $endpoint, [
                    'json' => $payload,
                ]);

            if (!$response->successful()) {
                Log::error("Klaviyo API call failed", [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'payload' => $payload,
                ]);
                throw new \Exception("Klaviyo API error: {$response->status()}");
            }

            Log::info("Klaviyo API call successful", [
                'endpoint' => $endpoint,
                'status' => $response->status(),
            ]);
        } catch (\Exception $e) {
            Log::error("Exception during Klaviyo API call", [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}