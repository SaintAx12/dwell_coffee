<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecoveryPack extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'photos_omitted' => 'boolean',
        ];
    }
}