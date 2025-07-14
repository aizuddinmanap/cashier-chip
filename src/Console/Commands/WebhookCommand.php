<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Console\Commands;

use Aizuddinmanap\CashierChip\Cashier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class WebhookCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cashier:webhook 
                            {action : The action to perform (list, create, delete)}
                            {--url= : The webhook URL for create action}
                            {--events=* : The events to listen for}
                            {--id= : The webhook ID for delete action}';

    /**
     * The console command description.
     */
    protected $description = 'Manage Chip webhooks';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'list' => $this->listWebhooks(),
            'create' => $this->createWebhook(),
            'delete' => $this->deleteWebhook(),
            default => $this->error("Invalid action: {$action}. Use list, create, or delete."),
        };
    }

    /**
     * List all webhooks.
     */
    protected function listWebhooks(): int
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . Cashier::chipApiKey(),
                'Content-Type' => 'application/json',
            ])->get(Cashier::chipApiUrl() . '/webhooks');

            if ($response->failed()) {
                $this->error('Failed to fetch webhooks: ' . $response->body());
                return 1;
            }

            $webhooks = $response->json('data', []);

            if (empty($webhooks)) {
                $this->info('No webhooks found.');
                return 0;
            }

            $this->table(
                ['ID', 'URL', 'Events', 'Status', 'Created'],
                collect($webhooks)->map(function ($webhook) {
                    return [
                        $webhook['id'] ?? 'N/A',
                        $webhook['url'] ?? 'N/A',
                        implode(', ', $webhook['events'] ?? []),
                        $webhook['status'] ?? 'N/A',
                        $webhook['created_at'] ?? 'N/A',
                    ];
                })->toArray()
            );

            return 0;
        } catch (\Exception $e) {
            $this->error('Error listing webhooks: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Create a new webhook.
     */
    protected function createWebhook(): int
    {
        $url = $this->option('url');
        
        if (!$url) {
            $url = $this->ask('Enter the webhook URL');
        }

        if (!$url) {
            $this->error('Webhook URL is required.');
            return 1;
        }

        $events = $this->option('events');
        
        if (empty($events)) {
            $defaultEvents = [
                'purchase.completed',
                'purchase.failed',
                'subscription.created',
                'subscription.updated',
                'subscription.cancelled',
                'subscription.expired',
            ];
            
            $events = $this->choice(
                'Select events to listen for (comma-separated for multiple)',
                $defaultEvents,
                0,
                null,
                true
            );
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . Cashier::chipApiKey(),
                'Content-Type' => 'application/json',
            ])->post(Cashier::chipApiUrl() . '/webhooks', [
                'url' => $url,
                'events' => $events,
            ]);

            if ($response->failed()) {
                $this->error('Failed to create webhook: ' . $response->body());
                return 1;
            }

            $webhook = $response->json();
            
            $this->info("Webhook created successfully!");
            $this->line("ID: {$webhook['id']}");
            $this->line("URL: {$webhook['url']}");
            $this->line("Events: " . implode(', ', $webhook['events']));
            
            if (isset($webhook['secret'])) {
                $this->warn("Webhook Secret: {$webhook['secret']}");
                $this->warn("Make sure to add this to your .env file as CHIP_WEBHOOK_SECRET");
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Error creating webhook: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Delete a webhook.
     */
    protected function deleteWebhook(): int
    {
        $id = $this->option('id');
        
        if (!$id) {
            $id = $this->ask('Enter the webhook ID to delete');
        }

        if (!$id) {
            $this->error('Webhook ID is required.');
            return 1;
        }

        if (!$this->confirm("Are you sure you want to delete webhook {$id}?")) {
            $this->info('Operation cancelled.');
            return 0;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . Cashier::chipApiKey(),
                'Content-Type' => 'application/json',
            ])->delete(Cashier::chipApiUrl() . "/webhooks/{$id}");

            if ($response->failed()) {
                $this->error('Failed to delete webhook: ' . $response->body());
                return 1;
            }

            $this->info("Webhook {$id} deleted successfully!");
            return 0;
        } catch (\Exception $e) {
            $this->error('Error deleting webhook: ' . $e->getMessage());
            return 1;
        }
    }
} 