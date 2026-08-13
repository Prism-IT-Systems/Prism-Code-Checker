<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = [
        'name',
        'path',
        'type',
    ];

    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }
}
