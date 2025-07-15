<?php

namespace Aizuddinmanap\CashierChip\Tests\Feature;

use Aizuddinmanap\CashierChip\Tests\TestCase;
use Aizuddinmanap\CashierChip\Tests\Fixtures\User;
use Aizuddinmanap\CashierChip\Cashier;
use Aizuddinmanap\CashierChip\Transaction;
use Aizuddinmanap\CashierChip\Invoice;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;

class InvoiceAlignmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test user
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password'
        ]);
    }

    #[Test]
    public function transactions_can_be_converted_to_invoices()
    {
        // Create a successful transaction (this is your billing data)
        $transaction = $this->user->transactions()->create([
            'id' => 'txn_test_123',
            'chip_id' => 'purchase_456',
            'type' => 'charge',
            'status' => 'success',
            'currency' => 'MYR',
            'total' => 2990, // RM 29.90
            'description' => 'Test Service',
            'processed_at' => now(),
        ]);

        // Get invoices (Laravel Cashier compatible)
        $invoices = $this->user->invoices();

        $this->assertInstanceOf(Collection::class, $invoices);
        $this->assertCount(1, $invoices);
        
        $invoice = $invoices->first();
        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertEquals('txn_test_123', $invoice->id());
        $this->assertEquals('RM 29.90', $invoice->total());
        $this->assertEquals('MYR', $invoice->currency());
        $this->assertEquals('paid', $invoice->status());
        $this->assertTrue($invoice->paid());
        $this->assertFalse($invoice->open());
    }

    #[Test]
    public function can_find_specific_invoice()
    {
        // Create transaction
        $this->user->transactions()->create([
            'id' => 'txn_findme_123',
            'chip_id' => 'purchase_789',
            'type' => 'charge',
            'status' => 'success',
            'currency' => 'MYR',
            'total' => 2990,
            'description' => 'Findable Service',
        ]);

        // Find invoice (Laravel Cashier compatible)
        $invoice = $this->user->findInvoice('txn_findme_123');

        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertEquals('txn_findme_123', $invoice->id());
        $this->assertEquals('Findable Service', $invoice->description());
    }

    #[Test]
    public function can_get_latest_invoice()
    {
        // Create multiple transactions
        $this->user->transactions()->create([
            'id' => 'txn_old_123',
            'chip_id' => 'purchase_old',
            'type' => 'charge',
            'status' => 'success',
            'currency' => 'MYR',
            'total' => 1000,
            'description' => 'Old Service',
            'created_at' => now()->subDays(2),
        ]);

        $this->user->transactions()->create([
            'id' => 'txn_latest_123',
            'chip_id' => 'purchase_latest',
            'type' => 'charge',
            'status' => 'success',
            'currency' => 'MYR',
            'total' => 2990,
            'description' => 'Latest Service',
            'created_at' => now(),
        ]);

        // Get latest invoice
        $latestInvoice = $this->user->latestInvoice();

        $this->assertInstanceOf(Invoice::class, $latestInvoice);
        $this->assertEquals('txn_latest_123', $latestInvoice->id());
        $this->assertEquals('Latest Service', $latestInvoice->description());
    }

    #[Test]
    public function can_create_invoice_for_specific_amount()
    {
        // Create invoice (Laravel Cashier compatible)
        $invoice = $this->user->invoiceFor('Premium Service', 2990);

        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertEquals('Premium Service', $invoice->description());
        $this->assertEquals(2990, $invoice->rawTotal());
        $this->assertEquals('RM 29.90', $invoice->total());
        $this->assertEquals('open', $invoice->status()); // Pending transactions = open invoices
        $this->assertTrue($invoice->open());
        $this->assertFalse($invoice->paid());
    }

    #[Test]
    public function can_get_upcoming_invoice()
    {
        // Create a pending transaction
        $this->user->transactions()->create([
            'id' => 'txn_upcoming_123',
            'chip_id' => 'purchase_upcoming',
            'type' => 'charge',
            'status' => 'pending',
            'currency' => 'MYR',
            'total' => 2990,
            'description' => 'Upcoming Service',
        ]);

        // Get upcoming invoice
        $upcomingInvoice = $this->user->upcomingInvoice();

        $this->assertInstanceOf(Invoice::class, $upcomingInvoice);
        $this->assertEquals('txn_upcoming_123', $upcomingInvoice->id());
        $this->assertEquals('Upcoming Service', $upcomingInvoice->description());
        $this->assertEquals('open', $upcomingInvoice->status());
    }

    #[Test]
    public function can_filter_invoices_by_period()
    {
        // Create transactions in different periods
        $this->user->transactions()->create([
            'id' => 'txn_old_period',
            'chip_id' => 'purchase_old_period',
            'type' => 'charge',
            'status' => 'success',
            'currency' => 'MYR',
            'total' => 1000,
            'created_at' => now()->subMonths(2),
        ]);

        $this->user->transactions()->create([
            'id' => 'txn_current_period',
            'chip_id' => 'purchase_current_period',
            'type' => 'charge',
            'status' => 'success',
            'currency' => 'MYR',
            'total' => 2990,
            'created_at' => now(),
        ]);

        // Get invoices for current month
        $startDate = now()->startOfMonth();
        $endDate = now()->endOfMonth();
        $monthlyInvoices = $this->user->invoicesForPeriod($startDate, $endDate);

        $this->assertCount(1, $monthlyInvoices);
        $this->assertEquals('txn_current_period', $monthlyInvoices->first()->id());
    }

    #[Test]
    public function invoice_status_mapping_works_correctly()
    {
        $testCases = [
            ['status' => 'success', 'expected' => 'paid'],
            ['status' => 'pending', 'expected' => 'open'],
            ['status' => 'failed', 'expected' => 'void'],
            ['status' => 'refunded', 'expected' => 'void'],
        ];

        foreach ($testCases as $case) {
            $transaction = $this->user->transactions()->create([
                'id' => 'txn_status_' . $case['status'],
                'chip_id' => 'purchase_' . $case['status'],
                'type' => 'charge',
                'status' => $case['status'],
                'currency' => 'MYR',
                'total' => 2990,
            ]);

            $invoice = $this->user->findInvoice($transaction->id);
            $this->assertEquals($case['expected'], $invoice->status());
        }
    }

    #[Test]
    public function invoice_has_proper_line_items()
    {
        // Create transaction with description
        $this->user->transactions()->create([
            'id' => 'txn_lines_123',
            'chip_id' => 'purchase_lines',
            'type' => 'charge',
            'status' => 'success',
            'currency' => 'MYR',
            'total' => 2990,
            'description' => 'Premium Service Package',
        ]);

        $invoice = $this->user->findInvoice('txn_lines_123');
        $lines = $invoice->lines();

        $this->assertInstanceOf(Collection::class, $lines);
        $this->assertCount(1, $lines);

        $line = $lines->first();
        $this->assertEquals('Premium Service Package', $line['description']);
        $this->assertEquals(2990, $line['amount']);
        $this->assertEquals('MYR', $line['currency']);
        $this->assertEquals(1, $line['quantity']);
    }

    #[Test]
    public function invoice_amounts_are_calculated_correctly()
    {
        // Create successful transaction
        $this->user->transactions()->create([
            'id' => 'txn_amounts_123',
            'chip_id' => 'purchase_amounts',
            'type' => 'charge',
            'status' => 'success',
            'currency' => 'MYR',
            'total' => 2990,
            'processed_at' => now(),
        ]);

        $invoice = $this->user->findInvoice('txn_amounts_123');

        // For paid invoices
        $this->assertEquals(2990, $invoice->rawAmountPaid());
        $this->assertEquals(0, $invoice->rawAmountDue());
        $this->assertEquals('RM 29.90', $invoice->amountPaid());
        $this->assertEquals('RM 0.00', $invoice->amountDue());

        // Create pending transaction
        $this->user->transactions()->create([
            'id' => 'txn_pending_123',
            'chip_id' => 'purchase_pending',
            'type' => 'charge',
            'status' => 'pending',
            'currency' => 'MYR',
            'total' => 5000,
        ]);

        $pendingInvoice = $this->user->findInvoice('txn_pending_123');

        // For pending invoices
        $this->assertEquals(0, $pendingInvoice->rawAmountPaid());
        $this->assertEquals(5000, $pendingInvoice->rawAmountDue());
        $this->assertEquals('RM 0.00', $pendingInvoice->amountPaid());
        $this->assertEquals('RM 50.00', $pendingInvoice->amountDue());
    }

    #[Test]
    public function can_handle_multiple_transactions_as_invoices()
    {
        // Simulate your 18 transactions worth MYR 29.90 each
        for ($i = 1; $i <= 18; $i++) {
            $this->user->transactions()->create([
                'id' => 'txn_multi_' . $i,
                'chip_id' => 'purchase_multi_' . $i,
                'type' => 'charge',
                'status' => 'success',
                'currency' => 'MYR',
                'total' => 2990, // RM 29.90
                'description' => 'Service Payment #' . $i,
                'created_at' => now()->subDays($i),
            ]);
        }

        // Get all invoices
        $invoices = $this->user->invoices();

        $this->assertCount(18, $invoices);

        // Calculate total
        $totalBilled = $invoices->sum(fn($invoice) => $invoice->rawTotal());
        $expectedTotal = 18 * 2990; // 18 × RM 29.90 = RM 538.20

        $this->assertEquals($expectedTotal, $totalBilled);
        $this->assertEquals('RM 538.20', Cashier::formatAmount($totalBilled));

        // Check each invoice
        foreach ($invoices as $invoice) {
            $this->assertEquals('RM 29.90', $invoice->total());
            $this->assertEquals('paid', $invoice->status());
            $this->assertTrue($invoice->paid());
        }
    }

    #[Test]
    public function invoice_pdf_generation_works()
    {
        // Create transaction
        $this->user->transactions()->create([
            'id' => 'txn_pdf_123',
            'chip_id' => 'purchase_pdf',
            'type' => 'charge',
            'status' => 'success',
            'currency' => 'MYR',
            'total' => 2990,
            'description' => 'PDF Test Service',
        ]);

        $invoice = $this->user->findInvoice('txn_pdf_123');

        // Test PDF generation methods exist
        $this->assertTrue(method_exists($invoice, 'downloadPDF'));
        $this->assertTrue(method_exists($invoice, 'viewPDF'));

        // Test that without dompdf, we get helpful error message
        if (!class_exists(\Dompdf\Dompdf::class)) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('PDF generation requires dompdf');
            $invoice->downloadPDF();
        }
    }

    #[Test]
    public function invoice_conversion_preserves_transaction_data()
    {
        $transactionData = [
            'id' => 'txn_preserve_123',
            'chip_id' => 'purchase_preserve',
            'type' => 'charge',
            'status' => 'success',
            'currency' => 'MYR',
            'total' => 2990,
            'description' => 'Data Preservation Test',
            'metadata' => json_encode(['test' => 'value']),
            'created_at' => now(),
            'processed_at' => now(),
        ];

        $transaction = $this->user->transactions()->create($transactionData);
        $invoice = $this->user->findInvoice($transaction->id);

        // Verify all data is preserved
        $this->assertEquals($transaction->id, $invoice->id());
        $this->assertEquals($transaction->chip_id, $invoice->chipId());
        $this->assertEquals($transaction->total, $invoice->rawTotal());
        $this->assertEquals($transaction->currency, $invoice->currency());
        $this->assertEquals($transaction->description, $invoice->description());
        $this->assertEquals(['test' => 'value'], $invoice->metadata());
        
        // Compare transaction properties instead of entire object to avoid Laravel state differences
        $invoiceTransaction = $invoice->transaction();
        $this->assertEquals($transaction->id, $invoiceTransaction->id);
        $this->assertEquals($transaction->total, $invoiceTransaction->total);
        $this->assertEquals($transaction->status, $invoiceTransaction->status);
    }

    #[Test]
    public function laravel_cashier_api_compatibility()
    {
        // Create some test transactions
        $this->user->transactions()->create([
            'id' => 'txn_compat_1',
            'chip_id' => 'purchase_compat_1',
            'type' => 'charge',
            'status' => 'success',
            'currency' => 'MYR',
            'total' => 2990,
        ]);

        $this->user->transactions()->create([
            'id' => 'txn_compat_2',
            'chip_id' => 'purchase_compat_2',
            'type' => 'charge',
            'status' => 'pending',
            'currency' => 'MYR',
            'total' => 5000,
        ]);

        // These should work exactly like Laravel Cashier Stripe/Paddle
        $invoices = $this->user->invoices();
        $this->assertInstanceOf(Collection::class, $invoices);

        $invoice = $this->user->findInvoice('txn_compat_1');
        $this->assertInstanceOf(Invoice::class, $invoice);

        $latestInvoice = $this->user->latestInvoice();
        $this->assertInstanceOf(Invoice::class, $latestInvoice);

        $upcomingInvoice = $this->user->upcomingInvoice();
        $this->assertInstanceOf(Invoice::class, $upcomingInvoice);

        // Create new invoice
        $newInvoice = $this->user->invoiceFor('Test Service', 1000);
        $this->assertInstanceOf(Invoice::class, $newInvoice);
        $this->assertEquals('Test Service', $newInvoice->description());
        $this->assertEquals(1000, $newInvoice->rawTotal());
    }

    #[Test]
    public function invoice_has_proper_timestamp_fields()
    {
        $createdTime = Carbon::parse('2024-01-15 10:30:00');
        $updatedTime = Carbon::parse('2024-01-15 11:45:00');
        
        // Create transaction with specific timestamps
        $transaction = $this->user->transactions()->create([
            'id' => 'txn_timestamp_test',
            'chip_id' => 'purchase_timestamp',
            'type' => 'charge',
            'status' => 'success',
            'currency' => 'MYR',
            'total' => 5990,
            'description' => 'Timestamp Test Service',
            'created_at' => $createdTime,
            'updated_at' => $updatedTime,
        ]);

        // Convert to invoice
        $invoice = $this->user->findInvoice('txn_timestamp_test');

        // Verify all timestamp fields are properly set
        $this->assertNotNull($invoice->date, 'Invoice date field should not be null');
        $this->assertNotNull($invoice->created_at, 'Invoice created_at field should not be null (BUG FIX)');
        $this->assertNotNull($invoice->updated_at, 'Invoice updated_at field should not be null (BUG FIX)');
        
        // Verify timestamps match the original transaction
        $this->assertEquals($createdTime->format('Y-m-d H:i:s'), $invoice->date->format('Y-m-d H:i:s'));
        $this->assertEquals($createdTime->format('Y-m-d H:i:s'), $invoice->created_at->format('Y-m-d H:i:s'));
        $this->assertEquals($updatedTime->format('Y-m-d H:i:s'), $invoice->updated_at->format('Y-m-d H:i:s'));
        
        // Verify Laravel view compatibility (the main issue this fixes)
        $this->assertInstanceOf(Carbon::class, $invoice->created_at, 'created_at should be Carbon instance for views');
        $this->assertInstanceOf(Carbon::class, $invoice->updated_at, 'updated_at should be Carbon instance for views');
        
        // Test common view methods that were failing before the fix
        $this->assertIsString($invoice->created_at->format('M d, Y'), 'Should format properly for views');
        $this->assertIsString($invoice->created_at->diffForHumans(), 'Should calculate relative time for views');
        $this->assertIsString($invoice->updated_at->toDateTimeString(), 'Should convert to string for views');
    }

    #[Test]
    public function pdf_generation_handles_null_paid_at_dates()
    {
        // Create transaction with null processed_at (which becomes paid_at in invoice)
        $transaction = $this->user->transactions()->create([
            'id' => 'txn_null_paid_at',
            'chip_id' => 'purchase_null_paid',
            'type' => 'charge',
            'status' => 'success', // Status is successful but no processed_at
            'currency' => 'MYR',
            'total' => 2990,
            'description' => 'PDF Date Test Service',
            'processed_at' => null, // This becomes paid_at in invoice conversion
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Convert to invoice
        $invoice = $this->user->findInvoice('txn_null_paid_at');

        // Verify the invoice is marked as paid but has null paid_at
        $this->assertTrue($invoice->paid(), 'Invoice should be marked as paid');
        $this->assertNull($invoice->paidAt(), 'Invoice paid_at should be null');

        // Test PDF HTML generation with null paid_at (this was crashing in v1.0.13)
        $reflection = new \ReflectionClass($invoice);
        $method = $reflection->getMethod('generateInvoiceHTML');
        $method->setAccessible(true);

        // This should not throw "Call to a member function format() on null" exception
        $html = $method->invoke($invoice, [
            'company_name' => 'Test Company',
            'company_address' => '123 Test Street',
            'company_phone' => '+60 3-1234 5678',
            'company_email' => 'test@example.com'
        ]);

        // Verify the HTML contains the N/A fallback for null paid_at
        $this->assertStringContainsString('Paid on:', $html, 'Should show payment section for paid invoice');
        $this->assertStringContainsString('N/A', $html, 'Should show N/A for null paid_at date');

        // Verify PDF generation methods exist and don't crash
        $this->assertTrue(method_exists($invoice, 'downloadPDF'), 'downloadPDF method should exist');
        $this->assertTrue(method_exists($invoice, 'viewPDF'), 'viewPDF method should exist');

        // Test that actual PDF generation doesn't crash (if dompdf is available)
        if (class_exists(\Dompdf\Dompdf::class)) {
            try {
                $response = $invoice->downloadPDF([
                    'company_name' => 'Test Company'
                ]);
                $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
            } catch (Exception $e) {
                $this->fail('PDF generation should not crash with null paid_at: ' . $e->getMessage());
            }
        }
    }
} 