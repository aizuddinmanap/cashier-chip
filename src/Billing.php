<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip;

use Aizuddinmanap\CashierChip\Http\ChipApi;
use Aizuddinmanap\CashierChip\Models\BillingTemplate;

/**
 * Thin manager over Chip's Billing Template API.
 *
 * Reachable as Cashier::billing() or Cashier::client()->billing. The Cashier
 * idiom ($user->subscribeToTemplate(), BillingTemplate::create()) is preferred,
 * but this mirror lets you drive the API imperatively.
 */
class Billing
{
    /**
     * Create a billing template on Chip.
     */
    public function createTemplate(BillingTemplate|array $template): BillingTemplate
    {
        return BillingTemplate::create($template);
    }

    /**
     * Fetch a billing template from Chip.
     */
    public function getTemplate(string $templateId): BillingTemplate
    {
        return BillingTemplate::find($templateId);
    }

    /**
     * List billing templates.
     *
     * @return BillingTemplate[]
     */
    public function templates(array $query = []): array
    {
        return BillingTemplate::all($query);
    }

    /**
     * Update a billing template on Chip.
     */
    public function updateTemplate(string $templateId, array $data): array
    {
        return (new ChipApi())->updateBillingTemplate($templateId, $data);
    }

    /**
     * Delete a billing template on Chip.
     */
    public function deleteTemplate(string $templateId): array
    {
        return (new ChipApi())->deleteBillingTemplate($templateId);
    }

    /**
     * Add a subscriber to a billing template.
     *
     * Chip wants client_id (and whitelist / send_* flags) at the top level of the
     * body. Accepts a bare client_id, a flat body, or a legacy nested
     * {billing_template_client: {...}} which is flattened.
     */
    public function addSubscriber(string $templateId, string|array $client, array $options = []): array
    {
        if (is_string($client)) {
            $client = ['client_id' => $client];
        } elseif (isset($client['billing_template_client'])) {
            $client = $client['billing_template_client'];
        }

        $body = $client;

        if (! empty($options['purchase'])) {
            $body['purchase'] = $options['purchase'];
        }

        return (new ChipApi())->addSubscriber($templateId, $body);
    }

    /**
     * Send a one-off invoice for a (non-subscription) template to a client.
     */
    public function sendInvoice(string $templateId, array $data): array
    {
        return (new ChipApi())->sendBillingTemplateInvoice($templateId, $data);
    }
}
