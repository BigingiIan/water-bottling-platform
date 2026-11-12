<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A promotion only sets the forecast's is_promotion flag. It never changes order prices.
 */
class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use HasFactory;

    public const TYPES = ['price_discount', 'bundle', 'display', 'other'];

    protected $fillable = [
        'name',
        'product_id',
        'start_date',
        'end_date',
        'type',
        'discount_percent',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'discount_percent' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Planned, active or ended, worked out from the dates rather than stored.
     */
    public function status(?CarbonInterface $today = null): string
    {
        $today = ($today ?? today())->copy()->startOfDay();

        return match (true) {
            $today->lessThan($this->start_date) => 'planned',
            $today->greaterThan($this->end_date) => 'ended',
            default => 'active',
        };
    }

    /**
     * Whether the promotion applies to this product on this day. A promotion without a product covers them all.
     */
    public function appliesTo(int $productId, CarbonInterface $day): bool
    {
        return ($this->product_id === null || $this->product_id === $productId)
            && $day->betweenIncluded($this->start_date->copy()->startOfDay(), $this->end_date->copy()->endOfDay());
    }
}
