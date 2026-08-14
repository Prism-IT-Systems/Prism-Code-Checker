<?php

namespace App\Models;

use App\CodeAnalysis\Services\IssueClassifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanIssue extends Model
{
    protected $fillable = [
        'scan_id',
        'file',
        'line',
        'column',
        'severity',
        'tool',
        'category',
        'rule',
        'message',
        'fixable',
    ];

    protected function casts(): array
    {
        return [
            'fixable' => 'boolean',
            'line' => 'integer',
            'column' => 'integer',
        ];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    public function categoryLabel(): string
    {
        return app(IssueClassifier::class)->label((string) $this->category);
    }

    public function location(): string
    {
        if ($this->line === null) {
            return $this->file;
        }

        return $this->file.':'.$this->line;
    }
}
