<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Orders\OrderService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PDO;
use Tests\TestCase;

class OrderNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_numbers_are_sequential_within_a_year_and_restart_each_year(): void
    {
        $this->travelTo('2026-12-31 15:00:00');
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create(['role_id' => Role::query()->where('slug', 'administrator')->valueOrFail('id')]);
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $orders = app(OrderService::class);

        $numbers = collect(range(1, 3))->map(fn (): string => $orders->saveDraft(null, $customer, [$product->id => 1], null, null, $user)->order_number);
        $this->travelTo('2027-01-01 08:00:00');
        $numbers->push($orders->saveDraft(null, $customer, [$product->id => 1], null, null, $user)->order_number);

        $this->assertSame(['ORD-2026-0001', 'ORD-2026-0002', 'ORD-2026-0003', 'ORD-2027-0001'], $numbers->all());
    }

    public function test_two_concurrent_creates_get_distinct_numbers(): void
    {
        $database = storage_path('framework/testing/order-numbers-'.bin2hex(random_bytes(4)).'.sqlite');
        File::ensureDirectoryExists(dirname($database));
        File::put($database, '');
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_FOREIGN_KEYS' => 'true'];

        try {
            Process::env($environment)->path(base_path())->run(['php', 'artisan', 'migrate', '--force', '--no-interaction'])->throw();
            Process::env($environment)->path(base_path())->run([
                'php', 'artisan', 'tinker', '--execute',
                'App\Models\Customer::query()->create(["name" => "Concurrency customer"]);',
            ])->throw();

            // Each worker reserves a number, holds its transaction open briefly, then inserts the order.
            $worker = 'echo Illuminate\Support\Facades\DB::transaction(function () {'
                .' $number = app(App\Services\Orders\OrderNumberGenerator::class)->next(2026);'
                .' usleep(400000);'
                .' Illuminate\Support\Facades\DB::table("orders")->insert(["order_number" => $number, "customer_id" => 1, "status" => "draft", "order_date" => "2026-10-22", "created_at" => now(), "updated_at" => now()]);'
                .' return $number;'
                .' });';

            $results = Process::pool(function (Pool $pool) use ($environment, $worker): void {
                foreach (['first', 'second'] as $name) {
                    $pool->as($name)->env($environment)->path(base_path())->timeout(120)->command(['php', 'artisan', 'tinker', '--execute', $worker]);
                }
            })->start()->wait();

            $numbers = [];

            foreach (['first', 'second'] as $name) {
                $this->assertTrue($results[$name]->successful(), "{$name} worker failed: ".$results[$name]->errorOutput());
                preg_match('/ORD-2026-\d{4}/', $results[$name]->output(), $match);
                $numbers[] = $match[0] ?? 'missing';
            }

            sort($numbers);
            $this->assertSame(['ORD-2026-0001', 'ORD-2026-0002'], $numbers);

            $connection = new PDO('sqlite:'.$database);
            $this->assertSame(2, (int) $connection->query('SELECT COUNT(DISTINCT order_number) FROM orders')->fetchColumn());
            $this->assertSame(2, (int) $connection->query('SELECT last_number FROM order_number_sequences WHERE year = 2026')->fetchColumn());
            $connection = null;
        } finally {
            File::delete($database);
        }
    }
}
