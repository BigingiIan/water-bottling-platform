<?php

namespace App\Services\Demo;

use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\Invoicing\InvoiceCalculator;
use App\Services\Leakage\AnomalyAssessment;
use App\Services\Leakage\LeakageDetector;
use App\Services\Leakage\ShrinkageAssessment;
use App\Services\Requests\RequestContext;
use App\Services\SettingsService;
use App\Services\UserPreferenceService;
use Carbon\CarbonImmutable;
use Faker\Factory as FakerFactory;
use Faker\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use SplFileObject;
use Throwable;

/**
 * Builds a business that has been trading for a while: the demand CSV replayed as orders, the production,
 * stock, invoices and payments behind them, and the maintenance, requests and notifications around them.
 *
 * Everything is generated in memory with a fixed Faker seed and bulk inserted, so the same day produces the
 * same data. The ledger rules the services enforce (stock never negative, level_after chaining per stock
 * line, invoice totals from InvoiceCalculator, leakage grading from LeakageDetector) are kept here too.
 */
class DemoDataBuilder
{
    public const SEED = 20261028;

    public const MIN_DAYS = 14;

    /**
     * The SKUs left low and critical at the end, so every stock status shows.
     */
    public const LOW_SKU = '5L';

    public const CRITICAL_SKU = '10L';

    /**
     * Share of the period at which a batch trips each leakage threshold, with the SKU it happens on.
     *
     * @var array<string, list<array{0: float, 1: string}>>
     */
    private const LEAKAGE_EVENTS = [
        'review' => [[0.12, '500ml'], [0.3, '5L'], [0.48, '20L'], [0.66, '500ml'], [0.84, '10L']],
        'investigate' => [[0.38, '20L'], [0.92, '5L']],
    ];

    /**
     * The SKU whose loss creeps up over the last days of the period, until the anomaly check flags it.
     */
    private const DRIFT_SKU = '1L';

    /**
     * Loss % beyond normal for each of the drifting batches. Normal batches sit between about -1% and 0%,
     * and none of these reaches the 2% review threshold, so only the anomaly check notices.
     *
     * @var list<float>
     */
    private const DRIFT_LOSS_PERCENTS = [-0.4, -0.2, 0.0, 0.2, 0.45, 0.7, 1.0, 1.3, 1.6, 1.9];

    /**
     * The count that loses enough stock to trip the review threshold, by share of the period.
     */
    private const SHRINKAGE_EVENT = [0.6, '20L'];

    private const FLUSH_EVERY_DAYS = 30;

    private Generator $faker;

    private DemoDataset $data;

    private string $timezone;

    private CarbonImmutable $today;

    /**
     * @var list<CarbonImmutable>
     */
    private array $days = [];

    /**
     * @var array<string, list<array{units: int, price: string}>>
     */
    private array $demand = [];

    /**
     * @var array<string, array{id: int, name: string, volume: string, price: string, threshold: int}>
     */
    private array $products = [];

    /**
     * @var array<string, int>
     */
    private array $lineIds = [];

    /**
     * @var array<string, int>
     */
    private array $roleIds = [];

    /**
     * @var array<string, list<int>>
     */
    private array $usersByRole = [];

    /**
     * @var list<array{id: int, name: string, type: string, terms: int, skus: list<string>, every: int, offset: int, weight: float, methods: array<string, int>}>
     */
    private array $customers = [];

    /**
     * Stock lines per SKU, oldest first, with their running level.
     *
     * @var array<string, list<int>>
     */
    private array $stockLines = [];

    /**
     * @var array<int, int>
     */
    private array $lineLevels = [];

    /**
     * @var array<int, string>
     */
    private array $lineLastUpdated = [];

    /**
     * @var array<string, int> "sku|Y-m" => stock line id
     */
    private array $monthLines = [];

    /**
     * Movements since each SKU's last count, for the count's expectation.
     *
     * @var array<string, array{in: int, out: int, adjustments: int}>
     */
    private array $sinceCount = [];

    /**
     * @var array<string, array{id: int, counted: int}>
     */
    private array $lastCount = [];

    /**
     * @var array<string, int>
     */
    private array $lastMovementId = [];

    /**
     * Loss % of earlier batches, newest first, for the anomaly baseline.
     *
     * @var array<string, list<float>>
     */
    private array $lossHistory = [];

    /**
     * @var array<string, array<int, int>> sequence table => year => last number
     */
    private array $sequences = [];

    /**
     * @var array<string, true>
     */
    private array $paymentReferences = [];

    /**
     * @var list<array{sku: string, date: CarbonImmutable, id: int, severity: string, title: string}>
     */
    private array $flaggedReports = [];

    /**
     * @var list<array{id: int, number: string, customer: int, due: CarbonImmutable, balance: string}>
     */
    private array $overdueInvoices = [];

    /**
     * @var array<string, int>
     */
    private array $equipmentIds = [];

    private ?int $breakdownWorkOrderId = null;

    private bool $shrinkageEventDone = false;

    public function __construct(
        private InvoiceCalculator $calculator,
        private LeakageDetector $detector,
        private SettingsService $settings,
    ) {}

    /**
     * Writes the history: the given number of days ending yesterday, plus today as a finished trading day.
     *
     * @return array<string, int> rows inserted per table
     */
    public function build(int $days, int $customerCount): array
    {
        if ($days < self::MIN_DAYS) {
            throw new RuntimeException('The demo needs at least '.self::MIN_DAYS.' days of history.');
        }

        $this->faker = FakerFactory::create('en_US');
        $this->faker->seed(self::SEED);
        $this->data = new DemoDataset;
        $this->timezone = (string) $this->settings->get('organisation.timezone', config('app.timezone'));
        // The app's today, as a day in the operations timezone, so it matches what forecasting asks for.
        $this->today = CarbonImmutable::parse(today()->toDateString(), $this->timezone);
        $this->loadDemand($days + 1);
        $this->loadReferenceRows();

        DB::transaction(function () use ($customerCount): void {
            $this->createUsers();
            $this->createCustomers($customerCount);
            $this->createCalendar();

            foreach ($this->days as $index => $day) {
                $this->simulateDay($index, $day);

                // A year of rows does not fit in memory at once; nothing written so far is changed later.
                if ($index % self::FLUSH_EVERY_DAYS === self::FLUSH_EVERY_DAYS - 1) {
                    $this->data->flush();
                }
            }

            $this->finishStockLines();
            $this->createMaintenance();
            $this->createErrorEvents();
            $this->createNotifications();
            $this->data->flush();
            $this->createRequests();
            $this->data->flush();
            $this->saveSequences();
            DemoDataset::recordDates('daily_sku_features', $this->days[0]->toDateString(), $this->today->toDateString());
        });

        return $this->data->insertedCounts();
    }

    /**
     * Orders taken after today's close and tomorrow's queued runs. Added once the day is closed, because an
     * open order dated today would keep today out of the forecast history.
     *
     * @return array<string, int>
     */
    public function addEveningActivity(): array
    {
        if ($this->customers === []) {
            throw new RuntimeException('Build the history before adding the evening activity.');
        }

        DB::transaction(function (): void {
            $this->createEveningOrders();
            $this->createUpcomingRuns();
            $this->data->flush();
            $this->saveSequences();
        });

        return $this->data->insertedCounts();
    }

    /**
     * @return array<string, int> rows inserted per table so far
     */
    public function insertedCounts(): array
    {
        return $this->data->insertedCounts();
    }

    private function loadDemand(int $dayCount): void
    {
        $path = base_path('data/kenya_water_demand_daily.csv');

        if (! is_readable($path)) {
            throw new RuntimeException("Demand CSV is missing or unreadable: {$path}");
        }

        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);
        $columns = array_flip((array) $file->fgetcsv());
        $byDate = [];

        foreach ($file as $values) {
            if (! is_array($values) || ! isset($values[$columns['units_sold']]) || $values[$columns['date']] === 'date') {
                continue;
            }

            $byDate[$values[$columns['date']]][$values[$columns['product_sku']]] = [
                'units' => (int) round((float) $values[$columns['units_sold']]),
                'price' => bcadd((string) $values[$columns['unit_price_kes']], '0', 2),
            ];
        }

        ksort($byDate);

        if (count($byDate) < $dayCount) {
            throw new RuntimeException('The demand CSV has '.count($byDate)." days; {$dayCount} are needed.");
        }

        // The CSV's last day lands on today, so the requested history ends yesterday.
        foreach (array_values(array_slice($byDate, -$dayCount)) as $offset => $skus) {
            $this->days[] = $this->today->subDays($dayCount - 1 - $offset);

            foreach ($skus as $sku => $row) {
                $this->demand[$sku][] = $row;
            }
        }

        ksort($this->demand);
    }

    private function loadReferenceRows(): void
    {
        $this->roleIds = DB::table('roles')->pluck('id', 'slug')->map(fn (mixed $id): int => (int) $id)->all();
        $this->lineIds = DB::table('production_lines')->pluck('id', 'name')->map(fn (mixed $id): int => (int) $id)->all();
        $products = DB::table('products')->get()->keyBy('unit_type');

        foreach (array_keys($this->demand) as $sku) {
            $product = $products->get($sku) ?? throw new RuntimeException("Product {$sku} is missing. Run the product catalogue seeder first.");
            $line = DemoCatalog::SKU_PRODUCTION[$sku]['line'] ?? throw new RuntimeException("No production line is set for {$sku}.");

            if (! isset($this->lineIds[$line])) {
                throw new RuntimeException("Production line {$line} is missing. Run the production line seeder first.");
            }

            $meanDemand = array_sum(array_column($this->demand[$sku], 'units')) / count($this->demand[$sku]);
            $threshold = max(10, (int) (round($meanDemand * 0.8 / 10) * 10));
            $this->products[$sku] = [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'volume' => (string) ($product->volume_litres ?? throw new RuntimeException("Product {$sku} has no volume.")),
                'price' => (string) $product->unit_price_kes,
                'threshold' => $threshold,
            ];
            $this->stockLines[$sku] = [];
            $this->sinceCount[$sku] = ['in' => 0, 'out' => 0, 'adjustments' => 0];
            $this->lossHistory[$sku] = [];

            DB::table('products')->where('id', $product->id)->update(['reorder_threshold' => $threshold]);
        }

        $this->loadSequences();
    }

    private function loadSequences(): void
    {
        foreach (['order_number_sequences', 'invoice_number_sequences', 'work_order_number_sequences', 'request_number_sequences'] as $table) {
            $this->sequences[$table] = DB::table($table)->pluck('last_number', 'year')->map(fn (mixed $number): int => (int) $number)->all();
        }
    }

    private function saveSequences(): void
    {
        foreach ($this->sequences as $table => $years) {
            foreach ($years as $year => $number) {
                DB::table($table)->upsert([['year' => $year, 'last_number' => $number]], ['year'], ['last_number']);
            }
        }
    }

    private function nextNumber(string $table, string $prefix, CarbonImmutable $date): string
    {
        $number = ($this->sequences[$table][$date->year] ?? 0) + 1;
        $this->sequences[$table][$date->year] = $number;

        return sprintf('%s-%d-%04d', $prefix, $date->year, $number);
    }

    private function createUsers(): void
    {
        $password = Hash::make(DemoCatalog::PASSWORD);
        $joined = $this->stamp($this->days[0]->subDays(45), '09:00');

        foreach (DemoCatalog::USERS as $index => $user) {
            $id = $this->data->add('users', [
                'name' => $user['name'],
                'email' => $user['email'].'@'.DemoCatalog::EMAIL_DOMAIN,
                'password_hash' => $password,
                'role_id' => $this->roleIds[$user['role']] ?? throw new RuntimeException("Role {$user['role']} is missing. Seed the roles first."),
                'status' => 'active',
                'must_change_password' => false,
                'last_active_at' => $this->stamp($this->today, sprintf('%02d:%02d', 7 + $index % 9, $this->faker->numberBetween(0, 59))),
                'created_at' => $joined,
                'updated_at' => $joined,
            ]);
            $this->usersByRole[$user['role']][] = $id;

            // The welcome tour is for real first logins; demo accounts open straight onto their dashboard.
            $this->data->add('user_preferences', [
                'user_id' => $id,
                'key' => UserPreferenceService::TOUR_KEY,
                'value' => $this->today->toIso8601String(),
                'created_at' => $joined,
                'updated_at' => $joined,
            ]);
            $this->audit($this->usersByRole['administrator'][0], 'user.created', 'users', null, ['user_id' => $id, 'name' => $user['name'], 'role' => $user['role']], $joined, $id);
        }
    }

    private function createCustomers(int $count): void
    {
        $definitions = DemoCatalog::CUSTOMERS;

        for ($index = count($definitions); $index < $count; $index++) {
            $base = $definitions[$index % count($definitions)];
            $definitions[] = ['name' => $base['area'].' '.ucfirst($base['type']).' '.($index + 1), 'type' => $base['type'], 'area' => $base['area']];
        }

        foreach (array_slice($definitions, 0, max(1, $count)) as $definition) {
            $profile = DemoCatalog::CUSTOMER_PROFILES[$definition['type']];
            $terms = $this->faker->randomElement($profile['terms']);
            $id = $this->data->add('customers', [
                'name' => $definition['name'],
                'contact_number' => '07'.$this->faker->numerify('########'),
                'address' => $definition['area'],
                'customer_type' => $definition['type'],
                'payment_terms_days' => $terms,
            ]);
            $every = $this->faker->randomElement($profile['every']);
            $this->customers[] = [
                'id' => $id,
                'name' => $definition['name'],
                'type' => $definition['type'],
                'terms' => $terms,
                'skus' => $profile['skus'],
                'every' => $every,
                'offset' => $this->faker->numberBetween(0, $every - 1),
                'weight' => $this->faker->randomFloat(2, $profile['weight'][0], $profile['weight'][1]),
                'methods' => $profile['methods'],
            ];
        }
    }

    private function createCalendar(): void
    {
        $administrator = $this->usersByRole['administrator'][0];
        $existingTerms = DB::table('school_terms')->get(['year', 'term'])->map(fn (object $term): string => "{$term->year}-{$term->term}")->all();

        // Approximate Kenyan school terms, enough for the term flag and the school customers' buying pattern.
        foreach (range($this->days[0]->year, $this->today->addMonths(2)->year) as $year) {
            foreach ([1 => ['01-06', '04-04'], 2 => ['04-28', '08-01'], 3 => ['08-25', '10-31']] as $term => [$start, $end]) {
                if (in_array("{$year}-{$term}", $existingTerms, true)) {
                    continue;
                }

                $this->data->add('school_terms', [
                    'year' => $year,
                    'term' => $term,
                    'start_date' => "{$year}-{$start}",
                    'end_date' => "{$year}-{$end}",
                    'created_at' => $this->stamp($this->days[0], '08:00'),
                    'updated_at' => $this->stamp($this->days[0], '08:00'),
                ]);
            }
        }

        $dayCount = count($this->days);

        foreach (DemoCatalog::PROMOTIONS as $promotion) {
            $start = $this->days[0]->addDays((int) round($promotion['at'] * $dayCount));
            $length = min($promotion['days'], max(3, intdiv($dayCount, 12)));
            $createdOn = $start->subDays(10)->max($this->days[0]);
            $this->data->add('promotions', [
                'name' => $promotion['name'],
                'product_id' => $promotion['sku'] === null ? null : $this->products[$promotion['sku']]['id'],
                'start_date' => $start->toDateString(),
                'end_date' => $start->addDays($length - 1)->toDateString(),
                'type' => $promotion['type'],
                'discount_percent' => $promotion['discount'],
                'notes' => $promotion['type'] === 'display' ? 'Display stands agreed with the top retailers.' : null,
                'created_by' => $administrator,
                'created_at' => $this->stamp($createdOn, '11:00'),
                'updated_at' => $this->stamp($createdOn, '11:00'),
            ]);
        }
    }

    /**
     * Morning production, the day's orders delivered and invoiced, and the weekly count on Saturdays.
     */
    private function simulateDay(int $index, CarbonImmutable $day): void
    {
        foreach ($this->demand as $sku => $rows) {
            $this->produce($index, $day, $sku, $rows[$index]['units']);
        }

        foreach ($this->ordersFor($index, $day) as $order) {
            $this->deliverOrder($index, $day, $order);
        }

        if ($day->isSaturday() && $index < count($this->days) - 2) {
            foreach (array_keys($this->demand) as $sku) {
                $this->countStock($index, $day, $sku);
            }
        }
    }

    /**
     * One run per SKU, sized so the day's sales are covered and a buffer is left; the low and critical SKUs
     * are run down over the last two days.
     */
    private function produce(int $index, CarbonImmutable $day, string $sku, int $sales): void
    {
        $threshold = $this->products[$sku]['threshold'];
        $daysLeft = count($this->days) - 1 - $index;
        $target = match (true) {
            $sku === self::LOW_SKU && $daysLeft === 0 => (int) ceil($threshold * 0.75),
            $sku === self::CRITICAL_SKU && $daysLeft === 0 => max(1, (int) floor($threshold * 0.3)),
            in_array($sku, [self::LOW_SKU, self::CRITICAL_SKU], true) && $daysLeft === 1 => (int) floor($threshold * 0.4),
            default => (int) ceil($threshold * 1.4),
        };
        $units = max(0, $sales + $target - $this->levelOf($sku));

        if ($units === 0) {
            return;
        }

        $setup = DemoCatalog::SKU_PRODUCTION[$sku];
        $lineId = $this->lineIds[$setup['line']];
        $start = CarbonImmutable::parse($day->toDateString().' '.$setup['time'], $this->timezone);
        $finish = $start->addMinutes(50 + $this->faker->numberBetween(0, 40));
        $manager = $this->faker->randomElement($this->usersByRole['production-manager']);
        $runId = $this->data->add('production_schedules', [
            'product_sku' => $sku,
            'production_line_id' => $lineId,
            'scheduled_date' => $day->toDateString(),
            'scheduled_time' => $setup['time'].':00',
            'target_units' => max(50, (int) (round($units / 50) * 50)),
            'status' => 'complete',
            'created_at' => $this->utc($start->subDay()->setTime(15, 0)),
            'updated_at' => $this->utc($finish),
        ]);

        $parts = $units >= 300 ? 3 : ($units >= 60 ? 2 : 1);
        $remaining = $units;

        for ($part = 1; $part <= $parts; $part++) {
            $logged = $part === $parts ? $remaining : intdiv($units, $parts);
            $remaining -= $logged;
            $flagged = $this->faker->boolean(2);
            $this->data->add('production_logs', [
                'production_schedule_id' => $runId,
                'operator_id' => $this->faker->randomElement($this->usersByRole['production-staff']),
                'units_logged' => $logged,
                'logged_at' => $this->utc($start->addMinutes((int) (($finish->diffInMinutes($start, true)) * $part / $parts))),
                'quality_status' => $flagged ? 'flagged' : 'passed',
                'notes' => $flagged ? 'A few caps not seated; rechecked and released.' : null,
                'created_at' => $this->utc($finish),
                'updated_at' => $this->utc($finish),
            ]);
        }

        $batchId = $this->data->peekNextId('production_batches');
        $reference = 'B-'.str_pad((string) $batchId, 5, '0', STR_PAD_LEFT);
        $volume = $this->products[$sku]['volume'];
        $loggedLitres = (float) bcmul((string) $units, $volume, 3);
        $lossPercent = $this->plannedLossPercent($index, $sku);
        // Total loss from intake to bottles: normal process loss plus any loss beyond it.
        $totalLoss = $lossPercent === null
            ? $this->faker->randomFloat(3, 2.0, 3.0) / 100
            : 1 - (1 - (float) $this->detector->normalLossPercent() / 100) * (1 - $lossPercent / 100);
        $raw = number_format($loggedLitres / (1 - $totalLoss), 2, '.', '');
        $metered = number_format($loggedLitres * (1 + $this->faker->randomFloat(4, -0.004, 0.004)), 2, '.', '');
        $assessment = $this->detector->assessProcess(
            batchReference: $reference,
            sku: $sku,
            lineName: $setup['line'],
            rawWaterVolume: $raw,
            meteredBottledVolume: $metered,
            loggedUnits: $units,
            volumeLitres: $volume,
            anomaly: fn (float $percent): AnomalyAssessment => $this->detector->anomalyAgainst($this->lossHistory[$sku], $this->lossHistory[$sku], $percent),
        );
        $this->data->add('production_batches', [
            'user_id' => $manager,
            'production_schedule_id' => $runId,
            'batch_date' => $day->toDateString(),
            'raw_water_volume' => $raw,
            'bottled_volume' => $metered,
            'logged_volume' => $assessment->loggedLitres,
            'process_loss_percent' => $assessment->lossPercent,
            'status' => 'completed',
            'completed_at' => $this->utc($finish),
            'notes' => "Production run {$sku} on ".$day->toDateString(),
            'created_at' => $this->utc($finish),
        ]);
        // Each SKU runs on one line only, so the product and product-and-line baselines are the same.
        array_unshift($this->lossHistory[$sku], (float) $assessment->lossPercent);
        $this->lossHistory[$sku] = array_slice($this->lossHistory[$sku], 0, AnomalyAssessment::BASELINE_BATCHES);

        $this->audit($manager, 'production.run_completed', 'production_schedules', null, [
            'production_schedule_id' => $runId,
            'batch_id' => $batchId,
            'raw_water_volume' => $raw,
            'bottled_volume' => $metered,
        ], $this->utc($finish));

        if ($assessment->reportsLoss()) {
            $this->leakageReport($day, $sku, [
                'batch_id' => $batchId,
                'production_line_id' => $lineId,
                'source' => 'process',
                'finding' => 'process_loss',
                'detected_by' => $assessment->lossDetectedBy(),
                'severity' => $assessment->lossSeverity ?? 'review',
                'expected_volume' => $assessment->expectedLitres,
                'actual_volume' => $assessment->loggedLitres,
                'discrepancy' => bcsub($assessment->loggedLitres, $assessment->expectedLitres, 3),
                'loss_percent' => $assessment->lossPercent,
                'z_score' => $assessment->anomaly->zScore,
                'explanation' => $assessment->lossExplanation(),
                'detection_key' => "process:{$batchId}:process_loss",
            ], $finish, "Process loss flagged on {$reference}");
        }

        $this->receive($day, $sku, $units, $batchId, $reference, $finish);
    }

    /**
     * Loss % beyond normal for the batches chosen to trip a threshold or to drift; null for a normal batch.
     */
    private function plannedLossPercent(int $index, string $sku): ?float
    {
        $dayCount = count($this->days);

        foreach (self::LEAKAGE_EVENTS as $severity => $events) {
            foreach ($events as [$share, $eventSku]) {
                if ($eventSku === $sku && (int) floor($share * $dayCount) === $index) {
                    return $severity === 'review'
                        ? $this->faker->randomFloat(2, 2.6, 4.4)
                        : $this->faker->randomFloat(2, 6.0, 8.5);
                }
            }
        }

        $driftStart = $dayCount - count(self::DRIFT_LOSS_PERCENTS);

        if ($sku === self::DRIFT_SKU && $index >= $driftStart && $driftStart >= AnomalyAssessment::MINIMUM_BATCHES) {
            return self::DRIFT_LOSS_PERCENTS[$index - $driftStart];
        }

        return null;
    }

    /**
     * Puts a batch's bottles on the SKU's stock line for the month.
     */
    private function receive(CarbonImmutable $day, string $sku, int $units, int $batchId, string $reference, CarbonImmutable $at): void
    {
        $monthKey = $sku.'|'.$day->format('Y-m');

        if (! isset($this->monthLines[$monthKey])) {
            $lineId = $this->data->add('warehouse_stock', [
                'batch_id' => $batchId,
                'product_id' => $this->products[$sku]['id'],
                'current_level' => 0,
                'warehouse_location' => DemoCatalog::SKU_PRODUCTION[$sku]['location'],
                'minimum_threshold' => null,
                'last_updated' => $this->utc($at),
            ]);
            $this->monthLines[$monthKey] = $lineId;
            $this->stockLines[$sku][] = $lineId;
            $this->lineLevels[$lineId] = 0;
        }

        $this->move($sku, $this->monthLines[$monthKey], 'production', $units, $reference, "Production run {$sku} on ".$day->toDateString(), null, $at);
        $this->sinceCount[$sku]['in'] += $units;
    }

    /**
     * Appends one ledger entry; level_after is the line's level after the change, as StockService writes it.
     */
    private function move(string $sku, int $lineId, string $type, int $change, ?string $reference, ?string $reason, ?int $userId, CarbonImmutable $at): int
    {
        $level = $this->lineLevels[$lineId] + $change;

        if ($level < 0) {
            throw new RuntimeException("Demo stock for {$sku} would go negative on line {$lineId}.");
        }

        $this->lineLevels[$lineId] = $level;
        $this->lineLastUpdated[$lineId] = $this->utc($at);
        $id = $this->data->add('stock_movements', [
            'warehouse_stock_id' => $lineId,
            'product_id' => $this->products[$sku]['id'],
            'type' => $type,
            'quantity_change' => $change,
            'level_after' => $level,
            'reference' => $reference,
            'reason' => $reason,
            'user_id' => $userId,
            'created_at' => $this->utc($at),
        ]);
        $this->lastMovementId[$sku] = $id;

        return $id;
    }

    private function levelOf(string $sku): int
    {
        return array_sum(array_map(fn (int $lineId): int => $this->lineLevels[$lineId], $this->stockLines[$sku]));
    }

    /**
     * Splits each SKU's units for the day across the customers who order that day.
     *
     * @return list<array{customer: array<string, mixed>, lines: array<string, int>, created: CarbonImmutable, dispatched: CarbonImmutable}>
     */
    private function ordersFor(int $index, CarbonImmutable $day): array
    {
        $isTerm = $this->isSchoolTerm($day);
        $ordering = array_values(array_filter($this->customers, function (array $customer) use ($index, $day, $isTerm): bool {
            if ($customer['type'] === 'school' && (! $isTerm || $day->isWeekend())) {
                return false;
            }

            if ($customer['type'] === 'office' && $day->isWeekend()) {
                return false;
            }

            return ($index + $customer['offset']) % $customer['every'] === 0;
        }));
        $distributors = array_values(array_filter($this->customers, fn (array $customer): bool => $customer['type'] === 'distributor'));
        $fallback = $distributors[$index % max(1, count($distributors))] ?? $this->customers[0];
        $lines = [];

        foreach ($this->demand as $sku => $rows) {
            $buyers = array_values(array_filter($ordering, fn (array $customer): bool => in_array($sku, $customer['skus'], true)));
            $buyers = $buyers === [] ? [$fallback] : $buyers;

            foreach ($this->split($rows[$index]['units'], array_column($buyers, 'weight')) as $position => $quantity) {
                if ($quantity > 0) {
                    $lines[$buyers[$position]['id']][$sku] = $quantity;
                }
            }
        }

        $customersById = array_column($this->customers, null, 'id');
        $orders = [];

        foreach ($lines as $customerId => $skuLines) {
            $created = CarbonImmutable::parse($day->toDateString().' 07:00', $this->timezone)->addMinutes($this->faker->numberBetween(0, 120));
            $orders[] = [
                'customer' => $customersById[$customerId],
                'lines' => $skuLines,
                'created' => $created,
                'dispatched' => CarbonImmutable::parse($day->toDateString().' 09:30', $this->timezone)->addMinutes($this->faker->numberBetween(0, 210)),
            ];
        }

        usort($orders, fn (array $a, array $b): int => $a['dispatched'] <=> $b['dispatched']);

        return $orders;
    }

    /**
     * Whole units in proportion to the weights, with the remainder going to the largest fractions.
     *
     * @param  list<float>  $weights
     * @return list<int>
     */
    private function split(int $units, array $weights): array
    {
        $total = array_sum($weights);
        $exact = array_map(fn (float $weight): float => $units * $weight / $total, $weights);
        $quantities = array_map(fn (float $share): int => (int) floor($share), $exact);
        $fractions = array_map(fn (float $share, int $whole): float => $share - $whole, $exact, $quantities);
        arsort($fractions);

        foreach (array_slice(array_keys($fractions), 0, $units - array_sum($quantities)) as $position) {
            $quantities[$position]++;
        }

        return $quantities;
    }

    private function isSchoolTerm(CarbonImmutable $day): bool
    {
        $date = $day->format('m-d');

        return ($date >= '01-06' && $date <= '04-04') || ($date >= '04-28' && $date <= '08-01') || ($date >= '08-25' && $date <= '10-31');
    }

    /**
     * Confirmed, dispatched oldest stock first and delivered the same day, then invoiced and paid.
     *
     * @param  array{customer: array<string, mixed>, lines: array<string, int>, created: CarbonImmutable, dispatched: CarbonImmutable}  $order
     */
    private function deliverOrder(int $index, CarbonImmutable $day, array $order): void
    {
        $customer = $order['customer'];
        $clerk = $this->faker->randomElement($this->usersByRole['inventory-staff']);
        $confirmed = $order['created']->addMinutes($this->faker->numberBetween(10, 45));
        $delivered = $order['dispatched']->addMinutes($this->faker->numberBetween(60, 240));
        $number = $this->nextNumber('order_number_sequences', 'ORD', $day);
        $orderId = $this->data->add('orders', [
            'order_number' => $number,
            'customer_id' => $customer['id'],
            'status' => 'delivered',
            'order_date' => $day->toDateString(),
            'requested_date' => $day->toDateString(),
            'confirmed_at' => $this->utc($confirmed),
            'dispatched_at' => $this->utc($order['dispatched']),
            'delivered_at' => $this->utc($delivered),
            'cancelled_at' => null,
            'notes' => null,
            'created_by' => $clerk,
            'created_at' => $this->utc($order['created']),
            'updated_at' => $this->utc($delivered),
        ]);
        $lineTotals = [];
        $invoiceLines = [];

        foreach ($order['lines'] as $sku => $quantity) {
            $price = $this->demand[$sku][$index]['price'];
            $lineTotal = bcmul((string) $quantity, $price, 2);
            $lineTotals[] = $lineTotal;
            $invoiceLines[] = ['sku' => $sku, 'quantity' => $quantity, 'price' => $price, 'total' => $lineTotal];
            $this->data->add('order_items', [
                'order_id' => $orderId,
                'product_id' => $this->products[$sku]['id'],
                'quantity' => $quantity,
                'unit_price' => $price,
                'line_total' => $lineTotal,
                'created_at' => $this->utc($order['created']),
                'updated_at' => $this->utc($confirmed),
            ]);
            $this->dispatchLine($sku, $quantity, $price, $customer, $number, $clerk, $order['dispatched']);
        }

        $this->audit($clerk, 'orders.status_changed', 'orders', ['order_id' => $orderId, 'order_number' => $number, 'status' => 'dispatched'], [
            'order_id' => $orderId,
            'order_number' => $number,
            'status' => 'delivered',
        ], $this->utc($delivered));

        $daysAgo = count($this->days) - 1 - $index;

        if ($this->faker->boolean($daysAgo < 2 ? 50 : 92)) {
            $this->invoice($day, $orderId, $customer, $invoiceLines, $lineTotals, $delivered);
        }
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function dispatchLine(string $sku, int $quantity, string $price, array $customer, string $orderNumber, int $clerk, CarbonImmutable $at): void
    {
        $remaining = $quantity;

        foreach ($this->stockLines[$sku] as $lineId) {
            if ($remaining === 0) {
                break;
            }

            $take = min($remaining, $this->lineLevels[$lineId]);

            if ($take === 0) {
                continue;
            }

            $this->move($sku, $lineId, 'sale', -$take, $orderNumber, 'Order dispatch to '.$customer['name'], $clerk, $at);
            $this->data->add('sales_transactions', [
                'customer_id' => $customer['id'],
                'stock_id' => $lineId,
                'quantity' => $take,
                'unit_price' => $price,
                'total_value' => bcmul((string) $take, $price, 2),
                'transaction_date' => $this->utc($at),
            ]);
            $remaining -= $take;
        }

        if ($remaining > 0) {
            throw new RuntimeException("Demo production did not cover {$sku} sales on ".$at->toDateString().'.');
        }

        $this->sinceCount[$sku]['out'] += $quantity;
    }

    /**
     * Issued on delivery with the VAT settings, then paid, part paid or left owing.
     *
     * @param  array<string, mixed>  $customer
     * @param  list<array{sku: string, quantity: int, price: string, total: string}>  $lines
     * @param  list<string>  $lineTotals
     */
    private function invoice(CarbonImmutable $day, int $orderId, array $customer, array $lines, array $lineTotals, CarbonImmutable $delivered): void
    {
        $administrator = $this->faker->randomElement($this->usersByRole['administrator']);
        $vatRate = bcadd((string) $this->settings->get('invoice.vat_rate', 16.0), '0', 2);
        $pricesIncludeVat = (bool) $this->settings->get('invoice.prices_include_vat', false);
        $totals = $this->calculator->totals($lineTotals, $vatRate, $pricesIncludeVat);
        $issuedAt = $delivered->addMinutes($this->faker->numberBetween(15, 90));
        $due = $day->addDays($customer['terms']);
        $number = $this->nextNumber('invoice_number_sequences', 'INV', $day);
        $invoiceId = $this->data->add('invoices', [
            'invoice_number' => $number,
            'order_id' => $orderId,
            'orders_invoice_key' => $orderId,
            'customer_id' => $customer['id'],
            'status' => 'issued',
            'issue_date' => $day->toDateString(),
            'due_date' => $due->toDateString(),
            'prices_include_vat' => $pricesIncludeVat,
            'vat_rate' => $vatRate,
            'subtotal' => $totals['subtotal'],
            'vat_amount' => $totals['vatAmount'],
            'total' => $totals['total'],
            'amount_paid' => '0.00',
            'issued_at' => $this->utc($issuedAt),
            'issued_by' => $administrator,
            'created_by' => $administrator,
            'created_at' => $this->utc($issuedAt),
            'updated_at' => $this->utc($issuedAt),
        ]);

        foreach ($lines as $line) {
            $this->data->add('invoice_items', [
                'invoice_id' => $invoiceId,
                'product_id' => $this->products[$line['sku']]['id'],
                'description' => "{$this->products[$line['sku']]['name']} ({$line['sku']})",
                'quantity' => $line['quantity'],
                'unit_price' => $line['price'],
                'line_total' => $line['total'],
                'created_at' => $this->utc($issuedAt),
                'updated_at' => $this->utc($issuedAt),
            ]);
        }

        $this->audit($administrator, 'invoices.issued', 'invoices', ['invoice_id' => $invoiceId, 'status' => 'draft'], [
            'invoice_id' => $invoiceId,
            'invoice_number' => $number,
            'status' => 'issued',
            'total' => $totals['total'],
        ], $this->utc($issuedAt));

        $this->settle($invoiceId, $number, $customer, $day, $due, $totals['total']);
    }

    /**
     * Most invoices are paid within terms; a share of recent ones are left overdue, some of them part paid.
     *
     * @param  array<string, mixed>  $customer
     */
    private function settle(int $invoiceId, string $number, array $customer, CarbonImmutable $issued, CarbonImmutable $due, string $total): void
    {
        $age = (int) $issued->diffInDays($this->today, true);
        $isPastDue = $due->lessThan($this->today);
        $overdueChance = match (true) {
            $age <= 90 => 15,
            $age <= 180 => 4,
            default => 1,
        };
        $payments = [];

        if ($isPastDue && $this->faker->boolean($overdueChance)) {
            if ($this->faker->boolean(40)) {
                $payments[] = [bcmul($total, (string) $this->faker->randomFloat(2, 0.3, 0.7), 2), $issued->addDays($this->faker->numberBetween(0, (int) $issued->diffInDays($this->today, true)))];
            }
        } else {
            $payDay = $issued->addDays($this->faker->boolean(85)
                ? $this->faker->numberBetween(0, $customer['terms'])
                : $customer['terms'] + $this->faker->numberBetween(1, 10));

            if ($payDay->lessThanOrEqualTo($this->today)) {
                if ($this->faker->boolean(15)) {
                    $first = bcmul($total, (string) $this->faker->randomFloat(2, 0.3, 0.6), 2);
                    $firstDay = $issued->addDays((int) floor($issued->diffInDays($payDay, true) / 2));
                    $payments[] = [$first, $firstDay];
                    $payments[] = [bcsub($total, $first, 2), $payDay];
                } else {
                    $payments[] = [$total, $payDay];
                }
            } elseif ($this->faker->boolean(10)) {
                $payments[] = [bcmul($total, (string) $this->faker->randomFloat(2, 0.3, 0.6), 2), $issued->addDays($this->faker->numberBetween(0, $age))];
            }
        }

        $paid = '0.00';
        $lastPaidAt = null;

        foreach ($payments as [$amount, $date]) {
            if (bccomp($amount, '0', 2) <= 0) {
                continue;
            }

            $method = $this->weightedPick($customer['methods']);
            $reference = $this->paymentReference($method, $date);
            $recorder = $this->faker->randomElement($this->usersByRole['administrator']);
            $paidAt = CarbonImmutable::parse($date->toDateString().' 09:00', $this->timezone)->addMinutes($this->faker->numberBetween(0, 480));
            $paymentId = $this->data->add('payments', [
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
                'active_reference' => $reference,
                'paid_at' => $this->utc($paidAt),
                'recorded_by' => $recorder,
                'notes' => null,
                'created_at' => $this->utc($paidAt),
                'updated_at' => $this->utc($paidAt),
            ]);
            $paid = bcadd($paid, $amount, 2);
            $lastPaidAt = $paidAt;
            $this->audit($recorder, 'payments.recorded', 'payments', ['invoice_id' => $invoiceId], [
                'invoice_id' => $invoiceId,
                'payment_id' => $paymentId,
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
            ], $this->utc($paidAt));
        }

        $status = match (true) {
            bccomp($paid, $total, 2) >= 0 => 'paid',
            bccomp($paid, '0', 2) > 0 => 'partially_paid',
            default => 'issued',
        };
        $this->data->update('invoices', $invoiceId, [
            'status' => $status,
            'amount_paid' => $paid,
            'updated_at' => $lastPaidAt === null ? $this->data->get('invoices', $invoiceId)['updated_at'] : $this->utc($lastPaidAt),
        ]);

        if ($status !== 'paid' && $isPastDue) {
            $this->overdueInvoices[] = ['id' => $invoiceId, 'number' => $number, 'customer' => $customer['id'], 'due' => $due, 'balance' => bcsub($total, $paid, 2)];
        }
    }

    /**
     * Realistic-looking but invented: M-Pesa style codes, bank transfer references and cash receipt numbers.
     */
    private function paymentReference(string $method, CarbonImmutable $date): string
    {
        do {
            $reference = match ($method) {
                'mpesa' => chr(ord('R') + max(0, min(8, $date->year - 2023))).strtoupper($this->faker->bothify('?#??#?#??')),
                'bank_transfer' => 'FT'.$date->format('y').str_pad((string) $date->dayOfYear, 3, '0', STR_PAD_LEFT).strtoupper($this->faker->bothify('??##')),
                default => 'RCT-'.$this->faker->numerify('######'),
            };
        } while (isset($this->paymentReferences[$method.$reference]));

        $this->paymentReferences[$method.$reference] = true;

        return $reference;
    }

    /**
     * Saturday evening count of a SKU's location: usually exact, sometimes a few bottles short, and once a
     * loss big enough to trip the review threshold.
     */
    private function countStock(int $index, CarbonImmutable $day, string $sku): void
    {
        $system = $this->levelOf($sku);
        $previous = $this->lastCount[$sku] ?? null;
        $since = $this->sinceCount[$sku];
        $opening = $previous['counted'] ?? 0;
        $expected = $opening + $since['in'] - $since['out'] + $since['adjustments'];
        $isEvent = $sku === self::SHRINKAGE_EVENT[1] && ! $this->shrinkageEventDone && $index >= (int) floor(self::SHRINKAGE_EVENT[0] * count($this->days));
        $shrinkage = match (true) {
            $isEvent => (int) ceil($expected * $this->faker->randomFloat(3, 0.025, 0.04)),
            // Small losses stay under half a percent, well below the review threshold.
            $expected >= 250 && $this->faker->boolean(15) => $this->faker->numberBetween(1, (int) floor($expected * 0.004)),
            default => 0,
        };
        $shrinkage = min($shrinkage, $system);
        $counted = $system - $shrinkage;
        $user = $this->faker->randomElement($this->usersByRole['inventory-staff']);
        $at = CarbonImmutable::parse($day->toDateString().' 17:30', $this->timezone);
        $location = DemoCatalog::SKU_PRODUCTION[$sku]['location'];
        $note = $shrinkage === 0 ? null : ($isEvent ? 'Damaged pallet found behind the racking.' : 'Damaged bottles found on the shelf.');
        $countId = $this->data->add('stock_counts', [
            'product_id' => $this->products[$sku]['id'],
            'warehouse_location' => $location,
            'counted_units' => $counted,
            'opening_units' => $opening,
            'production_in' => $since['in'],
            'sales_out' => $since['out'],
            'adjustments_net' => $since['adjustments'],
            'expected_units' => $expected,
            'system_units' => $system,
            'shrinkage_units' => $expected - $counted,
            'previous_count_id' => $previous['id'] ?? null,
            'last_movement_id' => null,
            'user_id' => $user,
            'note' => $note,
            'created_at' => $this->utc($at),
        ]);

        // Losses come out of the oldest stock first, as StockCountService corrects them.
        $remaining = $shrinkage;

        foreach ($this->stockLines[$sku] as $lineId) {
            $take = min($remaining, $this->lineLevels[$lineId]);

            if ($take > 0) {
                $movementId = $this->move($sku, $lineId, 'adjustment', -$take, LeakageDetector::COUNT_REFERENCE_PREFIX.$countId, 'Stock count'.($note ? ": {$note}" : ''), $user, $at);
                $this->audit($user, 'inventory.stock_movement', 'warehouse_stock', ['current_level' => $this->lineLevels[$lineId] + $take], [
                    'current_level' => $this->lineLevels[$lineId],
                    'stock_movement_id' => $movementId,
                    'type' => 'adjustment',
                    'quantity_change' => -$take,
                ], $this->utc($at));
                $remaining -= $take;
            }

            if ($remaining === 0) {
                break;
            }
        }

        $this->data->update('stock_counts', $countId, ['last_movement_id' => $this->lastMovementId[$sku] ?? null]);
        $this->lastCount[$sku] = ['id' => $countId, 'counted' => $counted];
        $this->sinceCount[$sku] = ['in' => 0, 'out' => 0, 'adjustments' => 0];

        $shrinkagePercent = $expected > 0 ? bcdiv(bcmul((string) ($expected - $counted), '100', 6), (string) $expected, 3) : null;
        $severity = $this->detector->severityFor($shrinkagePercent);

        if ($isEvent) {
            $this->shrinkageEventDone = true;
        }

        if ($severity === null) {
            return;
        }

        $assessment = new ShrinkageAssessment(
            sku: $sku,
            location: $location,
            openingUnits: $opening,
            openingFromCount: $previous !== null,
            productionIn: $since['in'],
            salesOut: $since['out'],
            adjustmentsNet: $since['adjustments'],
            expectedUnits: $expected,
            systemUnits: $system,
            countedUnits: $counted,
            shrinkageUnits: $expected - $counted,
            shrinkagePercent: $shrinkagePercent,
            severity: $severity,
        );
        $litres = fn (int $units): string => bcmul((string) $units, $this->products[$sku]['volume'], 3);
        $this->leakageReport($day, $sku, [
            'warehouse_location' => $location,
            'stock_count_id' => $countId,
            'source' => 'stock',
            'finding' => 'stock_shrinkage',
            'detected_by' => 'rule',
            'severity' => $severity,
            'expected_volume' => $litres($expected),
            'actual_volume' => $litres($counted),
            'discrepancy' => $litres($counted - $expected),
            'loss_percent' => $shrinkagePercent,
            'z_score' => null,
            'explanation' => $assessment->explanation(),
            'detection_key' => "stock:{$countId}",
        ], $at, "Stock shrinkage flagged on {$sku} at {$location}");
    }

    /**
     * Older findings have been worked through; the last week's are still open.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function leakageReport(CarbonImmutable $day, string $sku, array $attributes, CarbonImmutable $at, string $title): void
    {
        $age = (int) $day->diffInDays($this->today, true);
        $reviewer = $this->faker->randomElement($this->usersByRole['inventory-staff']);
        $status = match (true) {
            $age > 21 => 'resolved',
            $age > 7 => 'acknowledged',
            default => 'open',
        };
        $id = $this->data->add('leakage_reports', [
            'batch_id' => null,
            'product_id' => $this->products[$sku]['id'],
            'production_line_id' => null,
            'warehouse_location' => null,
            'stock_count_id' => null,
            ...$attributes,
            'report_date' => $day->toDateString(),
            'status' => $status,
            'acknowledged_by' => $status === 'open' ? null : $reviewer,
            'acknowledged_at' => $status === 'open' ? null : $this->utc($at->addDay()),
            'resolution_note' => $status === 'resolved' ? $this->faker->randomElement([
                'Valve seal on the filler replaced; loss back to normal on the next batch.',
                'Meter recalibrated and the batch rechecked.',
                'Damaged stock written off and the racking repaired.',
                'Spillage during a changeover; operators re-briefed.',
            ]) : null,
            'resolved_by' => $status === 'resolved' ? $reviewer : null,
            'resolved_at' => $status === 'resolved' ? $this->utc($at->addDays(3)) : null,
            'created_at' => $this->utc($at),
            'updated_at' => $this->utc($at),
        ]);
        $this->flaggedReports[] = ['sku' => $sku, 'date' => $day, 'id' => $id, 'severity' => $attributes['severity'], 'title' => $title];
    }

    /**
     * Writes the final levels onto the stock lines.
     */
    private function finishStockLines(): void
    {
        $this->data->flush();

        foreach ($this->lineLevels as $lineId => $level) {
            DB::table('warehouse_stock')->where('id', $lineId)->update([
                'current_level' => $level,
                'last_updated' => $this->lineLastUpdated[$lineId],
            ]);
        }

        // Production aims the low and critical SKUs at their bands, but a quiet last day can leave more
        // stock than planned; the reorder threshold is then set from the final level so the band still shows.
        $criticalShare = (float) $this->settings->get('inventory.critical_percent', 50) / 100;

        foreach ([self::LOW_SKU => [$criticalShare, 1.0], self::CRITICAL_SKU => [0.0, $criticalShare]] as $sku => [$from, $to]) {
            if (! isset($this->products[$sku])) {
                continue;
            }

            $level = $this->levelOf($sku);
            $threshold = $this->products[$sku]['threshold'];

            if ($level >= $threshold * $from && $level < $threshold * $to) {
                continue;
            }

            $threshold = (int) ceil($level / (($from + $to) / 2));
            $this->products[$sku]['threshold'] = $threshold;
            DB::table('products')->where('id', $this->products[$sku]['id'])->update(['reorder_threshold' => $threshold]);
        }
    }

    /**
     * Fifteen machines, their preventive plans worked through the period, breakdowns with downtime, one
     * machine out of service now and one plan overdue.
     */
    private function createMaintenance(): void
    {
        $maintenanceRole = $this->roleIds['maintenance-staff'];
        $technicians = $this->usersByRole['maintenance-staff'];
        $start = $this->days[0];
        $workOrders = [];

        foreach (DemoCatalog::EQUIPMENT as $machine) {
            $this->equipmentIds[$machine['code']] = $this->data->add('equipment', [
                'code' => $machine['code'],
                'name' => $machine['name'],
                'type' => $machine['type'],
                'production_line_id' => $machine['line'] === null ? null : $this->lineIds[$machine['line']],
                'location' => $machine['location'],
                'manufacturer' => $machine['manufacturer'],
                'model' => $machine['model'],
                'serial_number' => strtoupper($this->faker->bothify('??-####-###')),
                'installed_on' => $start->subYears(2)->subDays($this->faker->numberBetween(0, 400))->toDateString(),
                'status' => match ($machine['code']) {
                    'L2-LAB-01' => 'out_of_service',
                    'PL-CMP-01' => 'needs_attention',
                    default => 'operational',
                },
                'notes' => null,
                'created_at' => $this->stamp($start->subDays(40), '10:00'),
                'updated_at' => $this->stamp($this->today, '08:00'),
            ]);
        }

        foreach (DemoCatalog::PLANS as $planIndex => $plan) {
            $every = $plan['every'];
            $isOverdue = $plan['task'] === DemoCatalog::OVERDUE_PLAN_TASK;
            $firstDue = $isOverdue
                ? $this->today->subDays(3)->subDays($every * intdiv(max(0, (int) $start->diffInDays($this->today->subDays(3), true)), $every))
                : $start->addDays($planIndex % $every);
            $lineEquipment = str_starts_with($plan['equipment'], 'L');
            $lastDone = null;
            $nextDue = $firstDue;
            $planId = $this->data->peekNextId('maintenance_plans');

            while ($nextDue->lessThanOrEqualTo($this->today->addDays(2))) {
                $due = $nextDue;
                $generated = CarbonImmutable::parse($due->subDays(2)->toDateString().' 06:00', $this->timezone);
                $leaveOpen = $due->greaterThanOrEqualTo($this->today) || ($isOverdue && $due->greaterThanOrEqualTo($this->today->subDays(3)));

                if ($leaveOpen) {
                    $workOrders[] = [
                        'equipment_id' => $this->equipmentIds[$plan['equipment']],
                        'maintenance_plan_id' => $planId,
                        'due_on' => $due->toDateString(),
                        'type' => 'preventive',
                        'source' => 'plan',
                        'priority' => $due->lessThan($this->today) ? 'urgent' : 'normal',
                        'status' => 'open',
                        'assigned_to' => null,
                        'description' => $plan['task'],
                        'created' => $generated,
                    ];

                    break;
                }

                $doneOn = $due->addDays($this->faker->boolean(80) ? 0 : 1)->min($this->today->subDay());
                $startedAt = CarbonImmutable::parse($doneOn->toDateString().' 13:00', $this->timezone)->addMinutes($this->faker->numberBetween(0, 120));
                $downtime = $lineEquipment ? $this->faker->numberBetween(20, 90) : $this->faker->numberBetween(0, 30);
                $workOrders[] = [
                    'equipment_id' => $this->equipmentIds[$plan['equipment']],
                    'maintenance_plan_id' => $planId,
                    'due_on' => $due->toDateString(),
                    'type' => 'preventive',
                    'source' => 'plan',
                    'priority' => 'normal',
                    'status' => 'done',
                    'assigned_to' => $this->faker->randomElement($technicians),
                    'description' => $plan['task'],
                    'created' => $generated,
                    'started' => $startedAt,
                    'completed' => $startedAt->addMinutes(max(20, $downtime)),
                    'downtime' => $downtime,
                    'parts' => $every >= 60 ? $this->faker->randomElement(DemoCatalog::PARTS) : null,
                    'cost' => $every >= 60 ? $this->faker->numberBetween(30, 180) * 100 : null,
                    'checklist' => array_fill(0, count($plan['checklist']), true),
                ];
                $lastDone = $doneOn;
                $nextDue = $doneOn->addDays($every);
            }

            $this->data->add('maintenance_plans', [
                'equipment_id' => $this->equipmentIds[$plan['equipment']],
                'task' => $plan['task'],
                'every_days' => $every,
                'first_due_on' => $firstDue->toDateString(),
                'last_done_on' => $lastDone?->toDateString(),
                'next_due_on' => $nextDue->toDateString(),
                'assigned_role_id' => $maintenanceRole,
                'checklist' => json_encode($plan['checklist']),
                'active' => true,
                'created_at' => $this->stamp($start->subDays(7), '10:00'),
                'updated_at' => $this->stamp(($lastDone ?? $start), '16:00'),
            ]);
        }

        $lineMachines = array_values(array_filter(DemoCatalog::EQUIPMENT, fn (array $machine): bool => $machine['line'] !== null || in_array($machine['type'], ['ro_unit', 'compressor'], true)));
        $breakdowns = max(3, intdiv(count($this->days), 15));

        for ($number = 0; $number < $breakdowns; $number++) {
            $day = $this->days[min(count($this->days) - 3, (int) floor(($number + 0.5) * count($this->days) / $breakdowns))];
            $machine = $this->faker->randomElement($lineMachines);
            $reported = CarbonImmutable::parse($day->toDateString().' 10:00', $this->timezone)->addMinutes($this->faker->numberBetween(0, 300));
            $downtime = $this->faker->numberBetween(45, 480);
            $workOrders[] = [
                'equipment_id' => $this->equipmentIds[$machine['code']],
                'type' => 'corrective',
                'source' => 'breakdown',
                'priority' => $this->faker->boolean(60) ? 'urgent' : 'normal',
                'status' => 'done',
                'reported_by' => $this->faker->randomElement($this->usersByRole['production-staff']),
                'assigned_to' => $this->faker->randomElement($technicians),
                'description' => $this->faker->randomElement(DemoCatalog::BREAKDOWNS),
                'created' => $reported,
                'started' => $reported->addMinutes($this->faker->numberBetween(15, 60)),
                'completed' => $reported->addMinutes($downtime),
                'downtime' => $downtime,
                'parts' => $this->faker->randomElement(DemoCatalog::PARTS),
                'cost' => $this->faker->numberBetween(15, 250) * 100,
                'notes' => 'Fault found and fixed; machine tested and handed back to production.',
            ];
        }

        // Now: the Line 02 labeller is down and being worked on.
        $brokeAt = CarbonImmutable::parse($this->today->subDay()->toDateString().' 14:20', $this->timezone);
        $workOrders[] = [
            'equipment_id' => $this->equipmentIds['L2-LAB-01'],
            'type' => 'corrective',
            'source' => 'breakdown',
            'priority' => 'urgent',
            'status' => 'in_progress',
            'reported_by' => $this->usersByRole['production-staff'][0],
            'assigned_to' => $technicians[0],
            'description' => 'Sleeve labeller heat tunnel not reaching temperature; sleeves not shrinking',
            'created' => $brokeAt,
            'started' => $brokeAt->addMinutes(40),
        ];

        usort($workOrders, fn (array $a, array $b): int => $a['created'] <=> $b['created']);

        foreach ($workOrders as $order) {
            $id = $this->addWorkOrder($order);

            if ($order['status'] === 'in_progress') {
                $this->breakdownWorkOrderId = $id;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function addWorkOrder(array $order): int
    {
        $reference = $this->nextNumber('work_order_number_sequences', 'WO', $order['created']->setTimezone($this->timezone));
        $updated = $order['completed'] ?? $order['started'] ?? $order['created'];
        $id = $this->data->add('work_orders', [
            'reference' => $reference,
            'equipment_id' => $order['equipment_id'],
            'maintenance_plan_id' => $order['maintenance_plan_id'] ?? null,
            'due_on' => $order['due_on'] ?? null,
            'request_id' => $order['request_id'] ?? null,
            'type' => $order['type'],
            'source' => $order['source'],
            'priority' => $order['priority'],
            'status' => $order['status'],
            'reported_by' => $order['reported_by'] ?? null,
            'assigned_to' => $order['assigned_to'] ?? null,
            'description' => $order['description'],
            'started_at' => isset($order['started']) ? $this->utc($order['started']) : null,
            'completed_at' => isset($order['completed']) ? $this->utc($order['completed']) : null,
            'downtime_minutes' => $order['downtime'] ?? null,
            'parts_used' => $order['parts'] ?? null,
            'cost_kes' => $order['cost'] ?? null,
            'checklist_results' => isset($order['checklist']) ? json_encode($order['checklist']) : null,
            'completion_notes' => $order['notes'] ?? null,
            'created_at' => $this->utc($order['created']),
            'updated_at' => $this->utc($updated),
        ]);

        if ($order['status'] === 'done') {
            $this->audit($order['assigned_to'], 'maintenance.work_order_completed', 'work_orders', ['reference' => $reference, 'status' => 'in_progress'], [
                'reference' => $reference,
                'status' => 'done',
                'downtime_minutes' => $order['downtime'] ?? null,
            ], $this->utc($order['completed']));
        }

        return $id;
    }

    /**
     * Requests between departments in every status. Written after the first flush so their snapshots can
     * be read from the stored records, the way the app builds them.
     */
    private function createRequests(): void
    {
        $context = app(RequestContext::class);
        $user = fn (string $role, int $index = 0): int => $this->usersByRole[$role][$index];
        $equipment = $this->equipmentIds;
        $latestOrder = (int) DB::table('orders')->whereIn('customer_id', array_column($this->customers, 'id'))->max('id');
        $investigation = collect($this->flaggedReports)->firstWhere('severity', 'investigate');
        $definitions = [
            ['from' => $user('production-staff'), 'to' => 'inventory-staff', 'type' => 'stock_check', 'subject' => 'Enough 20L for the school deliveries on Friday?', 'body' => 'Three schools have asked for 20L refills on Friday. Can you confirm we have the stock, or should we add a run?', 'priority' => 'normal', 'status' => 'open', 'ago' => 1, 'due' => 1],
            ['from' => $user('inventory-staff'), 'to' => 'production-manager', 'type' => 'production_request', 'subject' => 'Extra 5L run needed before the weekend', 'body' => '5L is below its reorder level and two distributors have large orders coming. Please add a 5L run.', 'priority' => 'urgent', 'status' => 'open', 'ago' => 4, 'due' => -2, 'related' => ['product', $this->products[self::LOW_SKU]['id']]],
            ['from' => $user('production-manager'), 'to' => 'maintenance-staff', 'type' => 'general', 'subject' => 'Compressor cycling more often than usual', 'body' => 'The air compressor is starting and stopping every couple of minutes. Could someone check it before it fails?', 'priority' => 'normal', 'status' => 'in_progress', 'ago' => 3, 'due' => 2, 'related' => ['equipment', (int) $equipment['PL-CMP-01']], 'work_order' => true],
            ['from' => $user('production-staff', 1), 'to' => 'maintenance-staff', 'type' => 'general', 'subject' => 'Line 02 labeller down again', 'body' => 'Sleeves are not shrinking on the 5L bottles. We have stopped the labeller.', 'priority' => 'urgent', 'status' => 'acknowledged', 'ago' => 1, 'due' => 0, 'related' => ['equipment', (int) $equipment['L2-LAB-01']], 'comments' => [[$user('maintenance-staff'), 'Seen. The heater element looks burnt out; a work order is open and we are on it.']]],
            ['from' => $user('inventory-staff', 1), 'to' => 'administrator', 'type' => 'data_correction', 'subject' => 'Customer phone number entered wrongly', 'body' => 'The contact number on the latest order belongs to a different customer. Please correct it.', 'priority' => 'low', 'status' => 'done', 'ago' => 9, 'due' => -6, 'related' => ['order', $latestOrder], 'resolution' => 'Corrected the contact number on the customer record.'],
            ['from' => $user('production-manager', 1), 'to' => 'inventory-staff', 'type' => 'leakage_review', 'subject' => 'Please review the batch flagged for investigation', 'body' => 'The loss on this batch is well above normal. Can you check the count and the write-off?', 'priority' => 'urgent', 'status' => 'done', 'ago' => 20, 'due' => -18, 'related' => $investigation === null ? null : ['leakage_report', $investigation['id']], 'resolution' => 'Checked: a split pallet during loading. Written off and the loading bay racking repaired.'],
            ['from' => $user('administrator'), 'to' => 'production-manager', 'type' => 'general', 'subject' => 'Plan for the school term rush', 'body' => 'School orders pick up in the first two weeks of term. Please plan extra 500ml and 20L runs.', 'priority' => 'normal', 'status' => 'acknowledged', 'ago' => 6, 'due' => 5],
            ['from' => $user('inventory-staff'), 'to' => 'production-manager', 'type' => 'production_request', 'subject' => 'Bring the 500ml run forward to 05:30', 'body' => 'A distributor truck arrives at 07:00. Could the 500ml run start earlier?', 'priority' => 'normal', 'status' => 'declined', 'ago' => 15, 'due' => -13, 'resolution' => 'Not possible this week: the water treatment cycle finishes at 05:45. We will load from yesterday\'s stock instead.'],
            ['from' => $user('maintenance-staff', 1), 'to' => 'production-manager', 'type' => 'general', 'subject' => 'Need Line 01 for two hours on Saturday', 'body' => 'We need to replace the filler valve seals. Two hours on Saturday afternoon would do it.', 'priority' => 'normal', 'status' => 'done', 'ago' => 30, 'due' => -27, 'resolution' => 'Line 01 released from 14:00 to 16:00 on Saturday.'],
            ['from' => $user('production-staff'), 'to' => 'inventory-staff', 'type' => 'stock_check', 'subject' => 'Labels for the 1L promotion', 'body' => 'Do we still have the promotion labels for the 1L display stands?', 'priority' => 'low', 'status' => 'cancelled', 'ago' => 12, 'due' => -10, 'comments' => [[$user('production-staff'), 'Found them in the store room, cancelling.']]],
            ['from' => $user('production-manager'), 'to' => 'inventory-staff', 'type' => 'stock_check', 'subject' => 'Confirm the 10L count after the weekend', 'body' => '10L looks critically low. Please recount before we plan tomorrow\'s runs.', 'priority' => 'urgent', 'status' => 'in_progress', 'ago' => 2, 'due' => 0, 'related' => ['product', $this->products[self::CRITICAL_SKU]['id']], 'comments' => [[$user('inventory-staff', 1), 'Counting now; will update by the end of the shift.']]],
            ['from' => $user('maintenance-staff'), 'to' => 'inventory-staff', 'type' => 'general', 'subject' => 'Space for spare parts in the store', 'body' => 'We have a delivery of conveyor parts next week. Can we have one shelf in the main store?', 'priority' => 'low', 'status' => 'done', 'ago' => 45, 'due' => -40, 'resolution' => 'Shelf 4 in the main store is yours.'],
            ['from' => $user('inventory-staff', 1), 'to' => 'production-manager', 'type' => 'production_request', 'subject' => 'Festive season stock build-up', 'body' => 'Distributors expect higher volumes in December. Can we build 500ml stock over the next three weeks?', 'priority' => 'normal', 'status' => 'done', 'ago' => 60, 'due' => -50, 'resolution' => 'Extra Saturday runs added for three weeks.'],
        ];
        $earliest = $this->days[0];

        foreach ($definitions as $definition) {
            $created = CarbonImmutable::parse($this->today->subDays($definition['ago'])->max($earliest)->toDateString().' 09:15', $this->timezone)->addMinutes($this->faker->numberBetween(0, 300));
            $sender = User::query()->findOrFail($definition['from']);
            $snapshot = null;

            if (isset($definition['related']) && $definition['related'][1] > 0) {
                try {
                    $snapshot = [...$context->snapshotFor($sender, $definition['related'][0], $definition['related'][1]), 'captured_at' => $created->toIso8601String()];
                } catch (Throwable) {
                    $snapshot = null;
                }
            }

            $resolved = in_array($definition['status'], ['done', 'declined', 'cancelled'], true) ? $created->addDays(1)->addHours(3) : null;
            $requestId = $this->data->add('requests', [
                'reference' => $this->nextNumber('request_number_sequences', 'REQ', $created),
                'type' => $definition['type'],
                'subject' => $definition['subject'],
                'body' => $definition['body'],
                'priority' => $definition['priority'],
                'status' => $definition['status'],
                'from_user_id' => $definition['from'],
                'to_role_id' => $this->roleIds[$definition['to']],
                'assigned_to_user_id' => in_array($definition['status'], ['in_progress', 'done'], true) ? $this->usersByRole[$definition['to']][0] : null,
                'due_date' => $this->today->addDays($definition['due'])->toDateString(),
                'related_type' => $snapshot === null ? null : $definition['related'][0],
                'related_id' => $snapshot === null ? null : $definition['related'][1],
                'context_snapshot' => $snapshot === null ? null : json_encode($snapshot),
                'resolved_at' => $resolved === null ? null : $this->utc($resolved),
                'resolution_note' => $definition['resolution'] ?? null,
                'reopened_at' => null,
                'created_at' => $this->utc($created),
                'updated_at' => $this->utc($resolved ?? $created->addHours(2)),
            ]);
            $this->audit($definition['from'], 'requests.created', 'requests', null, ['request_id' => $requestId, 'type' => $definition['type'], 'to_role' => $definition['to']], $this->utc($created));

            foreach ($definition['comments'] ?? [] as $position => [$author, $body]) {
                $this->data->add('request_comments', [
                    'request_id' => $requestId,
                    'user_id' => $author,
                    'body' => $body,
                    'created_at' => $this->utc($created->addHours(2 + $position)),
                ]);
            }

            foreach ($this->usersByRole[$definition['to']] as $recipient) {
                $this->notify($recipient, $created, [
                    'type' => 'request.created',
                    'title' => "New request: {$definition['subject']}",
                    'body' => 'From '.DB::table('users')->where('id', $definition['from'])->value('name').'.',
                    'url' => route('requests.show', $requestId, absolute: false),
                    'severity' => $definition['priority'] === 'urgent' ? 'warning' : 'info',
                    'key' => "request:{$requestId}:created",
                ]);
            }

            if (isset($definition['work_order'])) {
                $this->addWorkOrder([
                    'equipment_id' => $definition['related'][1],
                    'request_id' => $requestId,
                    'type' => 'inspection',
                    'source' => 'request',
                    'priority' => 'normal',
                    'status' => 'open',
                    'reported_by' => $definition['from'],
                    'assigned_to' => $this->usersByRole['maintenance-staff'][1],
                    'description' => $definition['subject'],
                    'created' => $created->addHours(1),
                ]);
            }
        }
    }

    /**
     * A few access denials and one recurring server error, as the error log would have them.
     */
    private function createErrorEvents(): void
    {
        $events = [
            ['user' => $this->usersByRole['production-staff'][0], 'role' => 'production-staff', 'status' => 403, 'module' => 'invoicing', 'path' => '/invoices', 'ago' => 40, 'exception' => 'Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException', 'message' => 'no_access:Invoicing'],
            ['user' => $this->usersByRole['inventory-staff'][1], 'role' => 'inventory-staff', 'status' => 403, 'module' => 'production', 'path' => '/production/schedule', 'ago' => 12, 'exception' => 'Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException', 'message' => 'no_access:Production'],
            ['user' => $this->usersByRole['maintenance-staff'][0], 'role' => 'maintenance-staff', 'status' => 403, 'module' => 'settings', 'path' => '/settings', 'ago' => 3, 'exception' => 'Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException', 'message' => 'no_access:Settings'],
            ['user' => $this->usersByRole['inventory-staff'][0], 'role' => 'inventory-staff', 'status' => 500, 'module' => 'orders', 'path' => '/orders', 'ago' => 25, 'exception' => 'Illuminate\Database\QueryException', 'message' => 'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction', 'resolved' => 'Long-running report query moved off the order tables; no repeats since.'],
            ['user' => $this->usersByRole['inventory-staff'][1], 'role' => 'inventory-staff', 'status' => 500, 'module' => 'orders', 'path' => '/orders', 'ago' => 24, 'exception' => 'Illuminate\Database\QueryException', 'message' => 'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction', 'resolved' => 'Same cause as the previous lock timeout.'],
            ['user' => $this->usersByRole['production-manager'][0], 'role' => 'production-manager', 'status' => 500, 'module' => 'forecasting', 'path' => '/forecasting', 'ago' => 2, 'exception' => 'Illuminate\Http\Client\ConnectionException', 'message' => 'cURL error 7: Failed to connect to 127.0.0.1 port 8001'],
        ];

        foreach ($events as $event) {
            $at = CarbonImmutable::parse($this->today->subDays(min($event['ago'], count($this->days) - 1))->toDateString().' 10:00', $this->timezone)->addMinutes($this->faker->numberBetween(0, 400));
            $file = $event['status'] === 403 ? 'app/Http/Middleware/EnsureModuleAccess.php' : ($event['module'] === 'forecasting' ? 'app/Services/MLForecastClient.php' : 'vendor/laravel/framework/src/Illuminate/Database/Connection.php');
            $line = $event['status'] === 403 ? 41 : ($event['module'] === 'forecasting' ? 88 : 825);
            $this->data->add('error_events', [
                'reference' => strtoupper($this->faker->bothify('?#?#-?#?#')),
                'user_id' => $event['user'],
                'status_code' => $event['status'],
                'module_slug' => $event['module'],
                'path' => $event['path'],
                'method' => 'GET',
                'exception_class' => $event['exception'],
                'message' => $event['status'] === 403 ? 'Access denied.' : 'An unexpected server error occurred.',
                'exception_message' => $event['message'],
                'exception_file' => $file,
                'exception_line' => $line,
                'trace' => json_encode([['file' => $file, 'line' => $line, 'function' => 'handle']]),
                'fingerprint' => hash('sha256', $event['exception']."\0".$file."\0".$line),
                'role_slug' => $event['role'],
                'ip_address' => '10.0.0.'.$this->faker->numberBetween(10, 60),
                'resolved_at' => isset($event['resolved']) ? $this->utc($at->addDays(2)) : null,
                'resolution_note' => $event['resolved'] ?? null,
                'created_at' => $this->utc($at),
            ]);
        }
    }

    /**
     * What each role would have been told about: flagged leakage, overdue invoices, stock running low, the
     * machine that broke down, and maintenance assigned to them.
     */
    private function createNotifications(): void
    {
        $inventoryAndProduction = [...$this->usersByRole['administrator'], ...$this->usersByRole['production-manager'], ...$this->usersByRole['production-staff'], ...$this->usersByRole['inventory-staff']];

        foreach (array_slice($this->flaggedReports, -8) as $report) {
            foreach ($inventoryAndProduction as $userId) {
                $this->notify($userId, $report['date']->setTime(12, 0), [
                    'type' => 'leakage.flagged',
                    'title' => $report['title'].' ('.ucfirst($report['severity']).')',
                    'body' => 'Open the leakage tab to see how it was worked out.',
                    'url' => route('inventory.overview', ['tab' => 'leakage'], absolute: false),
                    'severity' => $report['severity'] === 'investigate' ? 'critical' : 'warning',
                    'key' => "leakage:{$report['id']}",
                ]);
            }
        }

        usort($this->overdueInvoices, fn (array $a, array $b): int => $b['due'] <=> $a['due']);

        foreach (array_slice($this->overdueInvoices, 0, 6) as $invoice) {
            foreach ($this->usersByRole['administrator'] as $userId) {
                $this->notify($userId, $invoice['due']->addDay(), [
                    'type' => 'invoice.overdue',
                    'title' => "Invoice {$invoice['number']} is overdue",
                    'body' => 'KES '.number_format((float) $invoice['balance'], 2).' is still owing.',
                    'url' => route('invoices.show', $invoice['id'], absolute: false),
                    'severity' => 'warning',
                    'key' => "invoice:{$invoice['id']}:overdue",
                ]);
            }
        }

        foreach ([self::LOW_SKU => 'low', self::CRITICAL_SKU => 'critical'] as $sku => $status) {
            foreach ([...$this->usersByRole['administrator'], ...$this->usersByRole['production-manager'], ...$this->usersByRole['inventory-staff']] as $userId) {
                $this->notify($userId, $this->today, [
                    'type' => "stock.{$status}",
                    'title' => "{$this->products[$sku]['name']} is ".($status === 'low' ? 'running low' : 'critically low'),
                    'body' => "Stock dropped below the {$status} threshold (reorder at ".number_format($this->products[$sku]['threshold']).').',
                    'url' => route('inventory.overview', absolute: false),
                    'severity' => $status === 'low' ? 'warning' : 'critical',
                    'key' => "stock:{$this->products[$sku]['id']}:{$status}",
                ]);
            }
        }

        $equipmentId = $this->equipmentIds['L2-LAB-01'];

        foreach ([...$this->usersByRole['production-manager'], ...$this->usersByRole['production-staff']] as $userId) {
            $this->notify($userId, $this->today->subDay(), [
                'type' => 'equipment.out_of_service',
                'title' => 'Sleeve labeller (L2-LAB-01) is out of service',
                'body' => 'Line 02 runs may be affected until it is repaired.',
                'url' => route('maintenance.equipment.show', $equipmentId, absolute: false),
                'severity' => 'critical',
                'key' => "equipment:{$equipmentId}:out_of_service",
            ]);
        }

        $this->notify($this->usersByRole['maintenance-staff'][0], $this->today->subDay(), [
            'type' => 'work_order.assigned',
            'title' => 'Work order assigned to you: Sleeve labeller (L2-LAB-01)',
            'body' => 'Urgent breakdown on Line 02.',
            'url' => route('maintenance.work-orders.index', absolute: false),
            'severity' => 'warning',
            'key' => "work-order:{$this->breakdownWorkOrderId}:assigned:".$this->usersByRole['maintenance-staff'][0],
        ]);
    }

    /**
     * @param  array{type: string, title: string, body: string, url: string, severity: string, key: string}  $payload
     */
    private function notify(int $userId, CarbonImmutable $day, array $payload): void
    {
        $at = CarbonImmutable::parse($day->toDateString().' 08:00', $this->timezone)->addMinutes($this->faker->numberBetween(0, 540));
        $isRecent = $day->greaterThanOrEqualTo($this->today->subDays(2));
        $this->data->addKeyed('notifications', [
            'id' => $this->faker->uuid(),
            'type' => SystemNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $userId,
            'data' => json_encode($payload),
            'read_at' => $isRecent || $this->faker->boolean(20) ? null : $this->utc($at->addHours(3)),
            'created_at' => $this->utc($at),
            'updated_at' => $this->utc($at),
        ]);
    }

    /**
     * Evening orders for tomorrow: drafts still being put together and confirmed ones waiting for dispatch.
     */
    private function createEveningOrders(): void
    {
        $customers = array_slice(array_values(array_filter($this->customers, fn (array $customer): bool => in_array($customer['type'], ['distributor', 'retailer'], true))), 0, 5);
        $clerk = $this->usersByRole['inventory-staff'][0];

        foreach ($customers as $position => $customer) {
            $isConfirmed = $position >= 2;
            $created = CarbonImmutable::parse($this->today->toDateString().' 18:10', $this->timezone)->addMinutes($position * 12);
            $orderId = $this->data->add('orders', [
                'order_number' => $this->nextNumber('order_number_sequences', 'ORD', $this->today),
                'customer_id' => $customer['id'],
                'status' => $isConfirmed ? 'confirmed' : 'draft',
                'order_date' => $this->today->toDateString(),
                'requested_date' => $this->today->addDay()->toDateString(),
                'confirmed_at' => $isConfirmed ? $this->utc($created->addMinutes(8)) : null,
                'notes' => $position === 0 ? 'Customer will confirm quantities in the morning.' : null,
                'created_by' => $clerk,
                'created_at' => $this->utc($created),
                'updated_at' => $this->utc($created->addMinutes(8)),
            ]);

            foreach (['500ml' => 120 + 40 * $position, '1L' => 60 + 20 * $position, '20L' => 10 + 5 * $position] as $sku => $quantity) {
                $price = $this->products[$sku]['price'];
                $this->data->add('order_items', [
                    'order_id' => $orderId,
                    'product_id' => $this->products[$sku]['id'],
                    'quantity' => $quantity,
                    'unit_price' => $isConfirmed ? $price : null,
                    'line_total' => $isConfirmed ? bcmul((string) $quantity, $price, 2) : null,
                    'created_at' => $this->utc($created),
                    'updated_at' => $this->utc($created),
                ]);
            }
        }
    }

    /**
     * Tomorrow's runs, queued, so the schedule shows what is coming.
     */
    private function createUpcomingRuns(): void
    {
        foreach ($this->products as $sku => $product) {
            $setup = DemoCatalog::SKU_PRODUCTION[$sku];
            $threshold = $product['threshold'];
            $this->data->add('production_schedules', [
                'product_sku' => $sku,
                'production_line_id' => $this->lineIds[$setup['line']],
                'scheduled_date' => $this->today->addDay()->toDateString(),
                'scheduled_time' => $setup['time'].':00',
                'target_units' => max(50, (int) (round($threshold * 1.5 / 50) * 50)),
                'status' => 'queued',
                'created_at' => $this->utc(CarbonImmutable::parse($this->today->toDateString().' 16:00', $this->timezone)),
                'updated_at' => $this->utc(CarbonImmutable::parse($this->today->toDateString().' 16:00', $this->timezone)),
            ]);
        }
    }

    /**
     * @param  array<string, int>  $weights
     */
    private function weightedPick(array $weights): string
    {
        $roll = $this->faker->numberBetween(1, array_sum($weights));

        foreach ($weights as $choice => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $choice;
            }
        }

        return array_key_first($weights);
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function audit(?int $userId, string $action, string $table, ?array $old, ?array $new, string $at, ?int $targetUserId = null): void
    {
        $this->data->add('audit_log', [
            'user_id' => $userId,
            'target_user_id' => $targetUserId,
            'action' => $action,
            'table_name' => $table,
            'old_values' => $old === null ? null : json_encode($old),
            'new_values' => $new === null ? null : json_encode($new),
            'timestamp' => $at,
        ]);
    }

    /**
     * A local wall-clock time on the day, stored in the app timezone.
     */
    private function stamp(CarbonImmutable $day, string $time): string
    {
        return $this->utc(CarbonImmutable::parse($day->toDateString().' '.$time, $this->timezone));
    }

    private function utc(CarbonImmutable $moment): string
    {
        return $moment->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
