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

        try {
            // Create client via Chip API
            $response = $api->createClient($clientData);

            $customer = new Customer([
                'chip_id' => $response['id'],
                'email' => $response['email'] ?? $clientData['email'],
                'name' => $response['full_name'] ?? $clientData['full_name'],
            ]);

            // Store the Chip customer ID locally
            $this->chip_id = $customer->chip_id;
            $this->save();

            return $customer;

        } catch (\Exception $e) {
            // Fallback to local customer creation if API fails
            $customer = new Customer([
                'chip_id' => 'cust_' . uniqid(),
                'email' => $this->email ?? $options['email'] ?? null,
                'name' => $this->name ?? $options['name'] ?? null,
            ]);

            $this->chip_id = $customer->chip_id;
            $this->save();

            return $customer;
        }
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