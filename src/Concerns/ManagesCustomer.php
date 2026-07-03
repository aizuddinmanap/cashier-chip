<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Concerns;

use Aizuddinmanap\CashierChip\Cashier;
use Aizuddinmanap\CashierChip\Customer;

trait ManagesCustomer
{
    /**
     * Create a Chip customer for the given model.
     */
    public function createAsChipCustomer(array $options = []): Customer
    {
        if ($this->hasChipId()) {
            throw new \Exception('Billable model already has a Chip customer ID.');
        }

        $api = new \Aizuddinmanap\CashierChip\Http\ChipApi();

        // Prepare client data for Chip API
        $clientData = [
            'email' => $this->email ?? $options['email'] ?? null,
            'full_name' => $this->name ?? $options['name'] ?? null,
            'legal_name' => $options['legal_name'] ?? null,
        ];

        // Remove null values
        $clientData = array_filter($clientData, function ($value) {
            return $value !== null;
        });

        // Create the client (reusing an existing one if the email already
        // exists). Any other failure propagates — we must NOT persist a local
        // placeholder id: that makes hasChipId() true, so the client is never
        // re-created and Chip rejects every later add_subscriber / charge.
        $response = $this->createOrReuseChipClient($api, $clientData);

        if (empty($response['id'])) {
            throw new \Aizuddinmanap\CashierChip\Exceptions\ChipApiException(
                'Chip did not return a client id when creating the customer.'
            );
        }

        $customer = new Customer([
            'chip_id' => $response['id'],
            'email' => $response['email'] ?? $clientData['email'] ?? null,
            'name' => $response['full_name'] ?? $clientData['full_name'] ?? null,
        ]);

        // Store the Chip customer ID locally
        $this->chip_id = $customer->chip_id;
        $this->save();

        return $customer;
    }

    /**
     * Create the Chip client, or reuse an existing one on a duplicate email.
     *
     * Chip rejects a second client with the same email (clients_unique_email).
     * Rather than failing, look the existing client up and reuse its id. Any
     * other API failure propagates (we never fabricate a local id).
     */
    protected function createOrReuseChipClient(\Aizuddinmanap\CashierChip\Http\ChipApi $api, array $clientData): array
    {
        try {
            return $api->createClient($clientData);
        } catch (\Aizuddinmanap\CashierChip\Exceptions\ChipApiException $e) {
            $email = $clientData['email'] ?? null;

            if (! $email || ! str_contains($e->getMessage(), 'clients_unique_email')) {
                throw $e;
            }

            $existing = $this->findChipClientByEmail($api, $email);

            if ($existing === null || empty($existing['id'])) {
                throw $e;
            }

            return $existing;
        }
    }

    /**
     * Find an existing Chip client by exact email match.
     */
    protected function findChipClientByEmail(\Aizuddinmanap\CashierChip\Http\ChipApi $api, string $email): ?array
    {
        $results = $api->searchClientsByEmail($email);

        // Chip returns a paginated list; accept results/data/plain-list shapes.
        $rows = $results['results'] ?? $results['data'] ?? (array_is_list($results) ? $results : []);

        foreach ($rows as $row) {
            if (! empty($row['id']) && strcasecmp((string) ($row['email'] ?? ''), $email) === 0) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Update the underlying Chip customer information for the model.
     */
    public function updateChipCustomer(array $options = []): Customer
    {
        if (! $this->hasChipId()) {
            return $this->createAsChipCustomer($options);
        }

        $api = new \Aizuddinmanap\CashierChip\Http\ChipApi();

        // Prepare client data for Chip API
        $clientData = [
            'email' => $this->email ?? $options['email'] ?? null,
            'full_name' => $this->name ?? $options['name'] ?? null,
            'legal_name' => $options['legal_name'] ?? null,
        ];

        // Remove null values
        $clientData = array_filter($clientData, function ($value) {
            return $value !== null;
        });

        try {
            // Update client via Chip API
            $response = $api->updateClient($this->chip_id, $clientData);

            return new Customer([
                'chip_id' => $this->chip_id,
                'email' => $response['email'] ?? $clientData['email'],
                'name' => $response['full_name'] ?? $clientData['full_name'],
            ]);

        } catch (\Exception $e) {
            // Fallback to local customer data
            return new Customer([
                'chip_id' => $this->chip_id,
                'email' => $this->email ?? $options['email'] ?? null,
                'name' => $this->name ?? $options['name'] ?? null,
            ]);
        }
    }

    /**
     * Get the Chip customer instance for the current user and email.
     */
    public function asChipCustomer(): Customer
    {
        if (! $this->hasChipId()) {
            return $this->createAsChipCustomer();
        }

        return new Customer([
            'chip_id' => $this->chip_id,
            'email' => $this->email,
            'name' => $this->name,
        ]);
    }

    /**
     * Determine if the entity has a Chip customer ID.
     */
    public function hasChipId(): bool
    {
        return ! is_null($this->chip_id);
    }

    /**
     * Get the Chip customer ID.
     */
    public function chipId(): ?string
    {
        return $this->chip_id;
    }
} 