<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Http;

use Aizuddinmanap\CashierChip\Cashier;
use Aizuddinmanap\CashierChip\Exceptions\ChipApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChipApi
{
    /**
     * The Chip API base URL.
     */
    protected string $baseUrl;

    /**
     * The Chip API key.
     */
    protected string $apiKey;

    /**
     * The Chip brand ID.
     */
    protected string $brandId;

    /**
     * Create a new Chip API client instance.
     */
    public function __construct(?string $brandId = null, ?string $apiKey = null, ?string $endpoint = null)
    {
        $this->brandId = $brandId ?? config('cashier-chip.brand_id');
        $this->apiKey = $apiKey ?? Cashier::chipApiKey();
        $this->baseUrl = $endpoint ?? Cashier::chipApiUrl();
    }

    /**
     * Make a GET request to the Chip API.
     */
    public function get(string $endpoint, array $query = []): array
    {
        return $this->makeRequest('GET', $endpoint, [
            'query' => $query,
        ]);
    }

    /**
     * Make a POST request to the Chip API.
     */
    public function post(string $endpoint, array $data = []): array
    {
        return $this->makeRequest('POST', $endpoint, [
            'json' => $data,
        ]);
    }

    /**
     * Make a PUT request to the Chip API.
     */
    public function put(string $endpoint, array $data = []): array
    {
        return $this->makeRequest('PUT', $endpoint, [
            'json' => $data,
        ]);
    }

    /**
     * Make a DELETE request to the Chip API.
     */
    public function delete(string $endpoint): array
    {
        return $this->makeRequest('DELETE', $endpoint);
    }

    /**
     * Make a request to the Chip API.
     */
    protected function makeRequest(string $method, string $endpoint, array $options = []): array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout(30)
                ->retry(3, 1000)
                ->send($method, $this->buildUrl($endpoint), $options);

            $this->logRequest($method, $endpoint, $options, $response);

            if ($response->failed()) {
                throw new ChipApiException(
                    "Chip API request failed: {$response->status()} - {$response->body()}",
                    $response->status()
                );
            }

            return $response->json() ?? [];

        } catch (RequestException $e) {
            $this->logError($method, $endpoint, $e);
            
            throw new ChipApiException(
                "Failed to communicate with Chip API: {$e->getMessage()}",
                $e->getCode()
            );
        }
    }

    /**
     * Get the headers for API requests.
     */
    protected function getHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'Laravel-Cashier-Chip/' . Cashier::$version,
        ];
    }

    /**
     * Build the full URL for an endpoint.
     */
    protected function buildUrl(string $endpoint): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');
    }

    /**
     * Log API requests for debugging.
     */
    protected function logRequest(string $method, string $endpoint, array $options, Response $response): void
    {
        if (config('cashier-chip.logger')) {
            Log::channel(config('cashier-chip.logger'))->info('Chip API Request', [
                'method' => $method,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'options' => $this->sanitizeLogData($options),
            ]);
        }
    }

    /**
     * Log API errors.
     */
    protected function logError(string $method, string $endpoint, \Throwable $exception): void
    {
        if (config('cashier-chip.logger')) {
            Log::channel(config('cashier-chip.logger'))->error('Chip API Error', [
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Sanitize sensitive data from logs.
     */
    protected function sanitizeLogData(array $data): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'authorization'];
        
        array_walk_recursive($data, function (&$value, $key) use ($sensitiveKeys) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                $value = '***REDACTED***';
            }
        });

        return $data;
    }

    /**
     * Get payment methods available for the account.
     */
    public function getPaymentMethods(): array
    {
        return $this->get('payment_methods');
    }

    /**
     * Create a purchase (checkout) via Chip API.
     * 
     * @param array $purchaseData Purchase data following official Chip SDK structure
     */
    public function createPurchase(array $purchaseData): array
    {
        // Ensure brand_id is set
        if (!isset($purchaseData['brand_id'])) {
            $purchaseData['brand_id'] = $this->brandId;
        }

        return $this->post('purchases', $purchaseData);
    }

    /**
     * Get a purchase from Chip API by its ID.
     */
    public function getPurchase(string $purchaseId): array
    {
        return $this->get("purchases/{$purchaseId}");
    }

    /**
     * Create a client via Chip API.
     */
    public function createClient(array $data): array
    {
        return $this->post('clients', $data);
    }

    /**
     * Update a client via Chip API.
     */
    public function updateClient(string $clientId, array $data): array
    {
        return $this->put("clients/{$clientId}", $data);
    }

    /**
     * Get a client from Chip API.
     */
    public function getClient(string $clientId): array
    {
        return $this->get("clients/{$clientId}");
    }

    /**
     * Create a webhook via Chip API.
     */
    public function createWebhook(array $data): array
    {
        return $this->post('webhooks', $data);
    }

    /**
     * Get webhooks from Chip API.
     */
    public function getWebhooks(): array
    {
        return $this->get('webhooks');
    }

    /**
     * Delete a webhook via Chip API.
     */
    public function deleteWebhook(string $webhookId): array
    {
        return $this->delete("webhooks/{$webhookId}");
    }

    /**
     * Get public key from Chip API for webhook verification.
     */
    public function getPublicKey(): array
    {
        return $this->get('public_key');
    }

    /**
     * Verify webhook signature using the official Chip method.
     */
    public function verify(string $publicKey, string $signature, string $data): bool
    {
        // This would implement the official Chip webhook verification
        // The exact implementation depends on Chip's verification algorithm
        return openssl_verify($data, base64_decode($signature), $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * Refund a purchase via Chip API.
     */
    public function refundPurchase(string $purchaseId, array $data = []): array
    {
        return $this->post("purchases/{$purchaseId}/refund", $data);
    }

    /**
     * Charge a purchase using a token via Chip API.
     */
    public function chargePurchase(string $purchaseId, array $data = []): array
    {
        return $this->post("purchases/{$purchaseId}/charge", $data);
    }

    /**
     * Delete a recurring token for a purchase via Chip API.
     */
    public function deleteRecurringToken(string $purchaseId): array
    {
        return $this->delete("purchases/{$purchaseId}/delete_recurring_token");
    }

    /**
     * Search for clients by email via Chip API.
     */
    public function searchClientsByEmail(string $email): array
    {
        return $this->get('clients', ['q' => $email]);
    }

    /**
     * Get FPX B2C status from Chip API.
     */
    public function getFpxB2cStatus(): array
    {
        // Use the specific FPX endpoint mentioned in WooCommerce plugin
        $fpxBaseUrl = str_replace('/api/v1', '', $this->baseUrl);
        $response = Http::withHeaders($this->getHeaders())
            ->timeout(30)
            ->get($fpxBaseUrl . '/fpx_b2c');

        if ($response->failed()) {
            throw new ChipApiException(
                "FPX B2C status request failed: {$response->status()} - {$response->body()}",
                $response->status()
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Get FPX B2B1 status from Chip API.
     */
    public function getFpxB2b1Status(): array
    {
        // Use the specific FPX endpoint mentioned in WooCommerce plugin
        $fpxBaseUrl = str_replace('/api/v1', '', $this->baseUrl);
        $response = Http::withHeaders($this->getHeaders())
            ->timeout(30)
            ->get($fpxBaseUrl . '/fpx_b2b1');

        if ($response->failed()) {
            throw new ChipApiException(
                "FPX B2B1 status request failed: {$response->status()} - {$response->body()}",
                $response->status()
            );
        }

        return $response->json() ?? [];
    }
} 