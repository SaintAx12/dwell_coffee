<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StockItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'unit',
        'quantity',
        'min_level',
        'unit_cost',
        'expiry_date',
        'expiry_warning_days',
        'shelf_life_days',
    ];

    protected $casts = [
        'quantity'            => 'float',
        'min_level'           => 'float',
        'unit_cost'           => 'decimal:2',
        'expiry_warning_days' => 'integer',
        'shelf_life_days'     => 'integer',
    ];

    protected $appends = [
        'is_low',
        'is_expiring',
        'earliest_expiry',
    ];

    public function counts()
    {
        return $this->hasMany(StockCount::class)->latest();
    }

    public function batches()
    {
        return $this->hasMany(StockBatch::class);
    }

    public function movements()
    {
        return $this->hasMany(StockMovement::class)->latest();
    }

    public function getIsLowAttribute(): bool
    {
        return (float) $this->quantity <= (float) $this->min_level;
    }

    public function getEarliestExpiryAttribute(): ?string
    {
        if ((float) $this->quantity <= 0) {
            return null;
        }

        $batchExpiry = null;

        if (Schema::hasTable('stock_batches')) {
            $batchExpiry = DB::table('stock_batches')
                ->where('stock_item_id', $this->id)
                ->where('remaining_qty', '>', 0)
                ->whereNotNull('expiry_date')
                ->orderBy('expiry_date', 'asc')
                ->value('expiry_date');
        }

        $date = $batchExpiry ?? $this->expiry_date;

        return $date ? Carbon::parse($date)->toDateString() : null;
    }

    public function getIsExpiringAttribute(): bool
    {
        if ((float) $this->quantity <= 0 || !$this->earliest_expiry) {
            return false;
        }

        $warnDays = (int) ($this->expiry_warning_days ?? 2);

        return Carbon::parse($this->earliest_expiry)
            ->startOfDay()
            ->lte(now()->startOfDay()->addDays($warnDays));
    }
}