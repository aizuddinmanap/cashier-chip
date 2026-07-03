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
     * Accepts a bare Chip client_id, a billing_template_client array, or an
     * already-assembled request body (with billing_template_client / purchase).
     */
    public function addSubscriber(string $templateId, string|array $client, array $options = []): array
    {
        if (is_array($client) && (isset($client['billing_template_client']) || isset($client['purchase']))) {
            return (new ChipApi())->addSubscriber($templateId, $client);
        }

        if (is_string($client)) {
            $client = ['client_id' => $client];
        }

        $body = ['billing_template_client' => $client];

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
