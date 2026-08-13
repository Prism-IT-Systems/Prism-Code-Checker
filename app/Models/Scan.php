<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scan extends Model
{
    protected $fillable = [
        'project_id',
        'branch',
        'scan_type',
        'status',
        'files_scanned',
        'critical_count',
        'error_count',
        'warning_count',
        'notice_count',
        'info_count',
        'analyzer_summaries',
        'meta',
        'started_at',
        'completed_at',
        'duration',
    ];

    protected function casts(): array
    {
        return [
            'analyzer_summaries' => 'array',
            'meta' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration' => 'float',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(ScanIssue::class);
    }

    public function isBlocking(): bool
    {
        $blocking = config('codechecker.blocking_severities', ['critical', 'error']);

        $counts = [
            'critical' => $this->critical_count,
            'error' => $this->error_count,
            'warning' => $this->warning_count,
            'notice' => $this->notice_count,
            'info' => $this->info_count,
        ];

        foreach ($blocking as $severity) {
            if (($counts[$severity] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    public function resultLabel(): string
    {
        return $this->isBlocking() ? 'FIX REQUIRED' : 'READY TO PUSH';
    }
}
