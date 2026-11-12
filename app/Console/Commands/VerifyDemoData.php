<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoDataVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('demo:verify')]
#[Description('Check the stock ledger, order, invoice and payment totals, forecast history and page data')]
class VerifyDemoData extends Command
{
    public function handle(DemoDataVerifier $verifier): int
    {
        $checks = $verifier->checks();

        $this->table(['Check', 'Result', 'Detail'], array_map(fn (array $check): array => [
            $check['check'],
            $check['passed'] ? '<fg=green>pass</>' : '<fg=red>FAIL</>',
            $check['detail'],
        ], $checks));
        $this->table(['Table', 'Rows'], collect($verifier->tableCounts())->map(fn (int $count, string $table): array => [$table, number_format($count)])->values()->all());

        $failed = array_filter($checks, fn (array $check): bool => ! $check['passed']);

        if ($failed !== []) {
            $this->error(count($failed).' of '.count($checks).' checks failed.');

            return self::FAILURE;
        }

        $this->info('All '.count($checks).' checks passed.');

        return self::SUCCESS;
    }
}
