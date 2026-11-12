<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoDataCleaner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('demo:clear')]
#[Description('Remove the data loaded by demo:seed (local and testing only)')]
class ClearDemoData extends Command
{
    public function handle(DemoDataCleaner $cleaner): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('demo:clear only runs in the local and testing environments.');

            return self::FAILURE;
        }

        if (! $cleaner->hasDemoData()) {
            $this->info('There is no demo data to remove.');

            return self::SUCCESS;
        }

        $removed = $cleaner->clear();

        $this->table(['Table', 'Rows removed'], collect($removed)->map(fn (int $count, string $table): array => [$table, number_format($count)])->values()->all());
        $this->info('Demo data removed. Products, roles, production lines and holidays are kept.');

        return self::SUCCESS;
    }
}
