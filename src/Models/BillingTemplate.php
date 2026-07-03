<?php

declare(strict_types=1);

namespace Aizuddinmanap\CashierChip\Models;

use Aizuddinmanap\CashierChip\Http\ChipApi;
use Aizuddinmanap\CashierChip\Subscription;
use Illuminate\Database\Eloquent\Model;

/**
 * A Chip Billing Template.
 *
 * A billing template describes an invoice (or, when is_subscription is true, a
 * recurring subscription). Chip performs all the recurring work server-side:
 * billing-cycle scheduling, auto-charging the tokenized card each cycle, trials,
 * dunning on failure, and emailing receipts. This class is a thin data container
 * (mirrors the Product/Purchase DTO style) plus helpers that call the Chip API.
 *
 * Field names track Chip's official PHP SDK (lib/Model/Billing/BillingTemplate).
 */
class BillingTemplate
{
    /** Chip billing template id (populated after create()/find()). */
    public ?string $id = null;

    /** Resource type, e.g. "billing_template" (read-only). */
    public ?string $type = null;

    /** Brand id. Defaults to config on create() when omitted. */
    public ?string $brand_id = null;

    /** Human-readable title, e.g. "Monthly Subscription". */
    public ?string $title = null;

    /** The invoice line items + currency (same shape as a purchase). */
    public ?PurchaseDetails $purchase = null;

    /** Whether this template is a recurring subscription. */
    public ?bool $is_subscription = null;

    /** How long the subscription runs before charging (with *_units). */
    public ?int $subscription_period = null;

    /** Units for subscription_period: day, week, month, year. */
    public ?string $subscription_period_units = null;

    /** Grace window before the invoice is due (with *_units). */
    public ?int $subscription_due_period = null;

    /** Units for subscription_due_period. */
    public ?string $subscription_due_period_units = null;

    /**
     * Charge at the end of the billing cycle rather than the start.
     *
     * CHIP's docs show `true`; the SDK types it int. Accept both and pass through.
     */
    public int|bool|null $subscription_charge_period_end = null;

    /** Number of free trial periods before paid billing begins. */
    public ?int $subscription_trial_periods = null;

    /** Total number of billing cycles (null = indefinite). */
    public ?int $number_of_billing_cycles = null;

    /** Force the payment to be tokenized/recurring. */
    public ?bool $force_recurring = null;

    /** Seconds until the invoice is issued. */
    public ?int $invoice_issued = null;

    /** Seconds until the invoice is due. */
    public ?int $invoice_due = null;

    /** Authorize only, capture later. */
    public ?bool $invoice_skip_capture = null;

    /** Email a receipt to the client on each charge. */
    public ?bool $invoice_send_receipt = null;

    /**
     * The scalar fields sent to Chip when creating/updating a template.
     *
     * @var string[]
     */
    protected static array $scalarFields = [
        'id',
        'brand_id',
        'title',
        'is_subscription',
        'subscription_period',
        'subscription_period_units',
        'subscription_due_period',
        'subscription_due_period_units',
        'subscription_charge_period_end',
        'subscription_trial_periods',
        'number_of_billing_cycles',
        'force_recurring',
        'invoice_issued',
        'invoice_due',
        'invoice_skip_capture',
        'invoice_send_receipt',
        'type',
    ];

    /**
     * Create a new BillingTemplate instance.
     */
    public function __construct(array $attributes = [])
    {
        foreach ($attributes as $key => $value) {
            if (! property_exists($this, $key)) {
                continue;
            }

            if ($key === 'purchase' && is_array($value)) {
                $this->purchase = new PurchaseDetails($value);
            } else {
                $this->{$key} = $value;
            }
        }
    }

    /**
     * Create the billing template on Chip and return the hydrated instance.
     */
    public static function create(array|self $attributes): self
    {
        $template = $attributes instanceof self ? $attributes : new self($attributes);

        $response = (new ChipApi())->createBillingTemplate($template->toArray());

        return $template->fillFromResponse($response);
    }

    /**
     * Fetch an existing billing template from Chip.
     */
    public static function find(string $templateId): self
    {
        return new self((new ChipApi())->getBillingTemplate($templateId));
    }

    /**
     * List billing templates from Chip.
     *
     * @return self[]
     */
    public static function all(array $query = []): array
    {
        $data = (new ChipApi())->getBillingTemplates($query);

        $rows = $data['results'] ?? $data['data'] ?? (array_is_list($data) ? $data : []);

        return array_map(fn ($row) => new self($row), $rows);
    }

    /**
     * Attach the invoice line items / currency.
     */
    public function setPurchase(PurchaseDetails|array $purchase): self
    {
        $this->purchase = $purchase instanceof PurchaseDetails
            ? $purchase
            : new PurchaseDetails($purchase);

        return $this;
    }

    /**
     * Convenience: add a single product to the template's purchase.
     */
    public function addProduct(Product $product): self
    {
        if ($this->purchase === null) {
            $this->purchase = new PurchaseDetails();
        }

        $this->purchase->addProduct($product);

        return $this;
    }

    /**
     * Add a billable model as a subscriber and mirror a local subscription row.
     *
     * Resolves the billable to a Chip client_id (creating the Chip customer if
     * needed), enrolls it on this template, and records a local subscription so
     * the rest of the package ($user->subscriptions, ->subscribedToPlan, etc.)
     * keeps working. Chip drives the recurring charges from here on.
     */
    public function addSubscriber(Model $billable, array $options = []): Subscription
    {
        if (! $this->id) {
            throw new \InvalidArgumentException('The billing template must be created before adding subscribers.');
        }

        if (! $billable->hasChipId()) {
            $billable->createAsChipCustomer();
        }

        $client = array_merge(
            ['client_id' => $billable->chipId()],
            array_intersect_key($options, array_flip([
                'payment_method_whitelist',
                'send_invoice_on_charge_failure',
                'send_invoice_on_add_subscriber',
                'send_receipt',
            ]))
        );

        $body = ['billing_template_client' => $client];

        if (! empty($options['purchase'])) {
            $body['purchase'] = $options['purchase'] instanceof PurchaseDetails
                ? $options['purchase']->toArray()
                : $options['purchase'];
        }

        $response = (new ChipApi())->addSubscriber($this->id, $body);

        $onTrial = ($this->subscription_trial_periods ?? 0) > 0;

        $trialEndsAt = null;
        if ($onTrial && ! empty($response['subscription_billing_scheduled_on'])) {
            $trialEndsAt = \Carbon\Carbon::createFromTimestamp($response['subscription_billing_scheduled_on']);
        }

        return $billable->subscriptions()->create([
            'name' => $options['name'] ?? 'default',
            'chip_id' => $response['id'] ?? 'bts_' . uniqid(),
            'chip_billing_template_id' => $this->id,
            'chip_status' => $response['status'] ?? ($onTrial ? 'trialing' : 'active'),
            'chip_price_id' => null,
            'quantity' => 1,
            'trial_ends_at' => $trialEndsAt,
            'ends_at' => null,
        ]);
    }

    /**
     * Re-fetch this template from Chip and re-hydrate.
     */
    public function refresh(): self
    {
        if (! $this->id) {
            return $this;
        }

        return $this->fillFromResponse((new ChipApi())->getBillingTemplate($this->id));
    }

    /**
     * Delete this billing template on Chip.
     */
    public function delete(): array
    {
        if (! $this->id) {
            return [];
        }

        return (new ChipApi())->deleteBillingTemplate($this->id);
    }

    /**
     * Convert the template to the array Chip expects (nulls stripped).
     */
    public function toArray(): array
    {
        $data = [];

        foreach (static::$scalarFields as $field) {
            if ($this->{$field} !== null) {
                $data[$field] = $this->{$field};
            }
        }

        if ($this->purchase !== null) {
            $data['purchase'] = $this->purchase->toArray();
        }

        return $data;
    }

    /**
     * Hydrate scalar fields from an API response, preserving the purchase.
     */
    protected function fillFromResponse(array $response): self
    {
        foreach (static::$scalarFields as $field) {
            if (array_key_exists($field, $response)) {
                $this->{$field} = $response[$field];
            }
        }

        if (isset($response['purchase']) && is_array($response['purchase'])) {
            $this->purchase = new PurchaseDetails($response['purchase']);
        }

        return $this;
    }
}
