<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Http\Middleware;

use Aizuddinmanap\CashierChip\Cashier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
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
     * Verify the webhook signature.
     */
    protected function verify(Request $request): bool
    {
        $secret = Cashier::chipWebhookSecret();

        if (! $secret) {
            // If no secret is configured, skip verification
            return true;
        }

        $signature = $request->header('X-Signature');
        $timestamp = $request->header('X-Timestamp');

        if (! $signature || ! $timestamp) {
            return false;
        }

        // Check if the timestamp is within the tolerance
        $tolerance = config('cashier.webhook.tolerance', 300);
        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        // Get the request body
        $payload = $request->getContent();

        // Create the expected signature
        $expectedSignature = $this->calculateSignature($payload, $timestamp, $secret);

        // Compare signatures using a constant time comparison
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Calculate the expected signature.
     */
    protected function calculateSignature(string $payload, string $timestamp, string $secret): string
    {
        // This follows Chip's webhook signature calculation
        // The exact implementation may vary based on Chip's documentation
        $signatureString = $timestamp . '.' . $payload;
        
        return hash_hmac('sha256', $signatureString, $secret);
    }
} 