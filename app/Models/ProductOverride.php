<?php

namespace App\Models;

use Database\Factories\ProductOverrideFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductOverride extends Model
{
    /** @use HasFactory<ProductOverrideFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_hidden' => 'boolean', 'profit_percentage' => 'float', 'sale_price' => 'integer'];
    }
}
