<?php

namespace Tests\Feature;

use App\Services\Invoicing\InvoiceNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PDO;
use Tests\TestCase;

class InvoiceNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_numbers_are_sequential_within_a_year_and_restart_each_year(): void
    {
        $generator = app(InvoiceNumberGenerator::class);

        $numbers = DB::transaction(fn (): array => [
            $generator->next(2026),
            $generator->next(2026),
            $generator->next(2027),
            $generator->next(2026),
        ]);

        $this->assertSame(['INV-2026-0001', 'INV-2026-0002', 'INV-2027-0001', 'INV-2026-0003'], $numbers);
    }

    public function test_two_concurrent_issues_get_distinct_numbers(): void
    {
        $database = storage_path('framework/testing/invoice-numbers-'.bin2hex(random_bytes(4)).'.sqlite');
        File::ensureDirectoryExists(dirname($database));
        File::put($database, '');
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_FOREIGN_KEYS' => 'true'];

        try {
            Process::env($environment)->path(base_path())->run(['php', 'artisan', 'migrate', '--force', '--no-interaction'])->throw();
            Process::env($environment)->path(base_path())->run([
                'php', 'artisan', 'tinker', '--execute',
                '$customer = App\Models\Customer::query()->create(["name" => "Concurrency customer"]);'
                .' foreach ([1, 2] as $i) { Illuminate\Support\Facades\DB::table("orders")->insert(["order_number" => "ORD-2026-000{$i}", "customer_id" => $customer->id, "status" => "dispatched", "order_date" => "2026-10-22", "created_at" => now(), "updated_at" => now()]); }',
            ])->throw();

            // Each worker reserves a number, holds its transaction open briefly, then inserts its invoice.
            $worker = fn (int $orderId): string => 'echo Illuminate\Support\Facades\DB::transaction(function () {'
                .' $number = app(App\Services\Invoicing\InvoiceNumberGenerator::class)->next(2026);'
                .' usleep(400000);'
                .' Illuminate\Support\Facades\DB::table("invoices")->insert(["invoice_number" => $number, "order_id" => '.$orderId.', "orders_invoice_key" => '.$orderId.', "customer_id" => 1, "status" => "issued", "vat_rate" => 16, "created_at" => now(), "updated_at" => now()]);'
                .' return $number;'
                .' });';

            $results = Process::pool(function (Pool $pool) use ($environment, $worker): void {
                foreach (['first' => 1, 'second' => 2] as $name => $orderId) {
                    $pool->as($name)->env($environment)->path(base_path())->timeout(120)->command(['php', 'artisan', 'tinker', '--execute', $worker($orderId)]);
                }
            })->start()->wait();

            $numbers = [];

            foreach (['first', 'second'] as $name) {
                $this->assertTrue($results[$name]->successful(), "{$name} worker failed: ".$results[$name]->errorOutput());
                preg_match('/INV-2026-\d{4}/', $results[$name]->output(), $match);
                $numbers[] = $match[0] ?? 'missing: '.$results[$name]->output();
            }

            sort($numbers);
            $this->assertSame(['INV-2026-0001', 'INV-2026-0002'], $numbers);

            $connection = new PDO('sqlite:'.$database);
            $this->assertSame(2, (int) $connection->query('SELECT COUNT(DISTINCT invoice_number) FROM invoices')->fetchColumn());
            $this->assertSame(2, (int) $connection->query('SELECT last_number FROM invoice_number_sequences WHERE year = 2026')->fetchColumn());
            $connection = null;
        } finally {
            File::delete($database);
        }
    }
}
