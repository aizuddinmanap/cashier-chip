<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Models;

class ClientDetails
{
    /**
     * Client ID.
     */
    public ?string $id = null;

    /**
     * Client email address.
     */
    public ?string $email = null;

    /**
     * Client full name.
     */
    public ?string $full_name = null;

    /**
     * Client legal name.
     */
    public ?string $legal_name = null;

    /**
     * Client phone number.
     */
    public ?string $phone = null;

    /**
     * Client street address.
     */
    public ?string $street_address = null;

    /**
     * Client country.
     */
    public ?string $country = null;

    /**
     * Client city.
     */
    public ?string $city = null;

    /**
     * Client zip code.
     */
    public ?string $zip_code = null;

    /**
     * Client state.
     */
    public ?string $state = null;

    /**
     * Create a new ClientDetails instance.
     */
    public function __construct(array $attributes = [])
    {
        foreach ($attributes as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }

    /**
     * Convert the client details to an array.
     */
    public function toArray(): array
    {
        $data = [];
        
        foreach (get_object_vars($this) as $key => $value) {
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        return $data;
    }
} 