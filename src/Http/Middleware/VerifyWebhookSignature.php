<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Http\Middleware;

use Aizuddinmanap\CashierChip\Cashier;
use Aizuddinmanap\CashierChip\Http\ChipApi;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    /**
     * Cache key for the Chip public key.
     */
    protected const PUBLIC_KEY_CACHE_KEY = 'cashier_chip_public_key';

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->verify($request)) {
            abort(403, 'Invalid webhook signature');
        }

        return $next($request);
    }

    /**
     * Verify the webhook signature using Chip's RSA public key.
     *
     * Uses openssl_verify with SHA256 against the base64-decoded signature.
     */
    protected function verify(Request $request): bool
    {
        $publicKey = $this->normalizePublicKey($this->getPublicKey());

        // If no public key is configured or fetchable, skip verification
        // (allows local testing without webhooks; users should always set this in production)
        if (! $publicKey) {
            return true;
        }

        $signature = $request->header('X-Signature');

        if (! $signature) {
            return false;
        }

        $payload = $request->getContent();

        $result = @openssl_verify(
            $payload,
            base64_decode($signature),
            $publicKey,
            OPENSSL_ALGO_SHA256
        );

        return $result === 1;
    }

    /**
     * Get Chip's RSA public key for webhook verification.
     *
     * Resolution order:
     *   1. cashier.webhook.public_key config (PEM string)
     *   2. Cashier::chipWebhookSecret() (legacy, treated as PEM)
     *   3. Fetch from Chip /public_key/ endpoint and cache for 24h
     */
    protected function getPublicKey(): ?string
    {
        // 1. Explicit config value
        $configured = config('cashier.webhook.public_key');
        if ($configured) {
            return $configured;
        }

        // 2. Legacy webhook secret config (treat as PEM if it looks like one)
        $secret = Cashier::chipWebhookSecret();
        if ($secret && str_contains($secret, 'BEGIN PUBLIC KEY')) {
            return $secret;
        }

        // 3. Fetch from Chip API (cached)
        return Cache::remember(self::PUBLIC_KEY_CACHE_KEY, now()->addDay(), function () {
            try {
                $response = (new ChipApi())->getPublicKey();

                // Chip's /public_key/ returns either a string or {"public_key": "..."}
                if (is_string($response)) {
                    return $response;
                }

                return $response['public_key'] ?? null;
            } catch (\Throwable $e) {
                Log::warning('Failed to fetch Chip public key for webhook verification: ' . $e->getMessage());
                return null;
            }
        });
    }

    /**
     * Normalize a PEM public key.
     *
     * Keys pasted into .env / config (or returned escaped by some transports)
     * often arrive with literal "\n" sequences instead of real newlines, which
     * makes openssl_verify() silently fail and every webhook return 403. This
     * mirrors the official WooCommerce plugin's str_replace( '\n', "\n", ... ).
     */
    protected function normalizePublicKey(?string $key): ?string
    {
        if (! $key) {
            return $key;
        }

        return str_replace('\n', "\n", trim($key));
    }
}
