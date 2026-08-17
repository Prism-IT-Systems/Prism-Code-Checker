<?php

/**
 * Project persistence model.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A detected source project that can have multiple scans.
 */
class Project extends Model
{
    protected $fillable = [
        'name',
        'path',
        'type',
    ];

    /**
     * Return a human-readable framework name.
     */
    public function typeLabel(): string
    {
        return match ($this->type) {
            'codeigniter3' => 'CodeIgniter 3',
            'codeigniter4' => 'CodeIgniter 4',
            default => ucfirst((string) $this->type),
        };
    }

    /**
     * Get scans performed for this project.
     */
    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }
}
