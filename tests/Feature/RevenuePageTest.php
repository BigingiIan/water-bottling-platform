<?php

namespace Tests\Feature;

use App\Livewire\RevenuePage;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Module;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Invoicing\InvoiceService;
use App\Services\Invoicing\PaymentService;
use App\Services\Orders\OrderStatus;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class RevenuePageTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-23 09:00:00');
        $this->seed([RoleSeeder::class, ModuleSeeder::class, RoleModulePermissionSeeder::class]);
        $this->administrator = $this->userWithRole('administrator');
    }

    public function test_roles_without_revenue_access_are_forbidden(): void
    {
        $this->actingAs($this->userWithRole('inventory-staff'));

        $this->get(route('revenue.index'))->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertDontSee(route('revenue.index'));
    }

    public function test_view_access_sees_the_page_and_can_export(): void
    {
        DB::table('role_module_permissions')
            ->where('role_id', Role::query()->where('slug', 'production-manager')->valueOrFail('id'))
            ->where('module_id', Module::query()->where('slug', 'revenue')->valueOrFail('id'))
            ->update(['access_level' => 'view']);
        $this->actingAs($this->userWithRole('production-manager'));

        $this->get(route('revenue.index'))->assertOk()->assertSeeText('Revenue');
        Livewire::test(RevenuePage::class)->call('exportCsv')->assertFileDownloaded('revenue-2026-10-01_2026-10-23.csv');
    }

    public function test_full_page_shows_every_section_with_live_figures(): void
    {
        $this->issuedAndPartlyPaid();
        $this->actingAs($this->administrator);

        $this->get(route('revenue.index'))
            ->assertOk()
            ->assertSeeText('KES 8,800.00')
            ->assertSeeText('Gross KES 10,208.00 incl. VAT')
            ->assertSeeText('KES 3,000.00')
            ->assertSeeText('KES 7,208.00')
            ->assertSeeText('Invoiced vs collected')
            ->assertSeeText('Revenue by product')
            ->assertSeeText('Top customers')
            ->assertSeeText('Receivables aging')
            ->assertSeeText('Expected revenue, next 7 days')
            ->assertSeeText('Estimate from the demand forecast, not booked revenue')
            ->assertSeeText('No forecast')
            ->assertSeeText('How these are calculated')
            ->assertSeeText('Reversed payments are excluded.');
    }

    public function test_presets_granularity_and_custom_range_validation(): void
    {
        $this->issuedAndPartlyPaid();
        $this->actingAs($this->administrator);

        Livewire::test(RevenuePage::class)
            ->set('granularity', 'week')
            ->assertSeeText('Week of')
            ->set('granularity', 'month')
            ->assertSeeText('Oct 2026')
            ->set('period', 'last_month')
            ->assertSeeText('No invoices were issued in this period.')
            ->set('period', 'custom')
            ->assertSet('from', '2026-10-01')
            ->assertSet('to', '2026-10-23')
            ->set('to', '2026-09-01')
            ->assertHasErrors(['to'])
            ->assertSeeText('The end date must be on or after the start date. Showing this month instead.')
            ->assertSeeText('KES 8,800.00')
            ->set('to', '2026-10-10')
            ->assertHasNoErrors();
    }

    public function test_csv_export_contains_each_section(): void
    {
        $this->issuedAndPartlyPaid();
        $this->actingAs($this->administrator);

        $component = Livewire::test(RevenuePage::class)->call('exportCsv')->assertFileDownloaded('revenue-2026-10-01_2026-10-23.csv');
        $csv = base64_decode((string) data_get($component->effects, 'download.content'));

        $this->assertStringStartsWith("section,label,metric,value\n", $csv);
        $this->assertStringContainsString("kpi,invoicedNet,current,8800.00\n", $csv);
        $this->assertStringContainsString("kpi,collected,current,3000.00\n", $csv);
        $this->assertStringContainsString("kpi,outstanding,current,7208.00\n", $csv);
        $this->assertStringContainsString('aging,"Retail Hub",current,7208.00', $csv);
        $this->assertStringContainsString('customer,"Retail Hub",outstanding,7208.00', $csv);
        $this->assertStringContainsString(',expected_revenue,"no forecast"', $csv);
    }

    private function issuedAndPartlyPaid(): Invoice
    {
        $order = Order::factory()->create([
            'customer_id' => Customer::factory()->create(['name' => 'Retail Hub'])->id,
            'status' => OrderStatus::Dispatched,
            'dispatched_at' => now(),
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => Product::factory()->create(['name' => 'Water Jar 10L', 'unit_price_kes' => '220.00'])->id,
            'quantity' => 40,
            'unit_price' => '220.00',
            'line_total' => '8800.00',
        ]);
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->issue($invoices->createDraftFromOrder($order, $this->administrator), $this->administrator);
        app(PaymentService::class)->record($invoice, '3000.00', 'mpesa', 'SJK4H7Q2LP', now(), null, $this->administrator);

        return $invoice;
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('slug', $slug)->valueOrFail('id')]);
    }
}
