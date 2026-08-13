@extends('layouts.app')

@section('title', 'Scan #'.$scan->id.' — Prism Code Checker')

@section('content')
    <div class="panel">
        <div class="row" style="align-items: flex-start;">
            <div>
                <h1>Project: {{ $scan->project->name }}</h1>
                <p style="margin: 0;">Path: <code>{{ $scan->project->path }}</code></p>
            </div>
            <div style="text-align: right;">
                <span class="status-pill {{ $scan->isBlocking() ? 'fix' : 'ready' }}">
                    {{ $scan->resultLabel() }}
                </span>
            </div>
        </div>

        <div class="meta-grid">
            <div class="meta-item">
                <div class="label">Branch</div>
                <div class="value">{{ $scan->branch ?? 'n/a' }}</div>
            </div>
            <div class="meta-item">
                <div class="label">Project Type</div>
                <div class="value">{{ ucfirst($scan->project->type) }}</div>
            </div>
            <div class="meta-item">
                <div class="label">Scan Type</div>
                <div class="value">{{ ucfirst($scan->scan_type) }}</div>
            </div>
            <div class="meta-item">
                <div class="label">Files Checked</div>
                <div class="value">{{ $scan->files_scanned }}</div>
            </div>
            <div class="meta-item">
                <div class="label">Scan Duration</div>
                <div class="value">{{ number_format((float) $scan->duration, 1) }} seconds</div>
            </div>
        </div>

        <div class="counts">
            <div class="count critical"><div class="label">Critical</div><div class="n">{{ $scan->critical_count }}</div></div>
            <div class="count error"><div class="label">Errors</div><div class="n">{{ $scan->error_count }}</div></div>
            <div class="count warning"><div class="label">Warnings</div><div class="n">{{ $scan->warning_count }}</div></div>
            <div class="count notice"><div class="label">Notices</div><div class="n">{{ $scan->notice_count }}</div></div>
        </div>

        @if (!empty($scan->analyzer_summaries))
            <h2>Analyzer Status</h2>
            <div class="summaries">
                @foreach ($scan->analyzer_summaries as $summary)
                    <div class="summary-row">
                        <div>
                            <strong>{{ $summary['tool'] ?? 'Unknown' }}</strong>
                            @if (!empty($summary['error_message']))
                                <div class="rule">{{ $summary['error_message'] }}</div>
                            @endif
                        </div>
                        <div>
                            {{ !empty($summary['success']) ? 'OK' : 'FAILED' }}
                            · {{ $summary['issue_count'] ?? 0 }} issues
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="panel">
        <h2>Issues</h2>
        <p style="margin-top: 0;">
            Showing {{ number_format($matchingCount) }} matching issue{{ $matchingCount === 1 ? '' : 's' }}
            across {{ number_format($filePage->total()) }} file{{ $filePage->total() === 1 ? '' : 's' }}.
            Files are paginated so large scans stay readable.
        </p>

        <form method="GET" action="{{ route('scans.show', $scan) }}" class="row" style="margin-bottom: 16px;">
            <div>
                <label for="q">Search</label>
                <input id="q" type="text" name="q" value="{{ $filters['q'] }}" placeholder="filename, message, or rule">
            </div>
            <div>
                <label for="severity">Severity</label>
                <select id="severity" name="severity">
                    @foreach (['all','critical','error','warning','notice','info'] as $severity)
                        <option value="{{ $severity }}" @selected($filters['severity'] === $severity)>{{ ucfirst($severity) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="tool">Tool</label>
                <select id="tool" name="tool">
                    <option value="all" @selected($filters['tool'] === 'all')>All</option>
                    @foreach ($tools as $tool)
                        <option value="{{ $tool }}" @selected($filters['tool'] === $tool)>{{ $tool }}</option>
                    @endforeach
                </select>
            </div>
            <div style="display:flex; align-items:flex-end;">
                <button class="btn secondary" type="submit">Apply Filters</button>
            </div>
        </form>

        <div class="filters">
            @foreach (['all','critical','error','warning','notice'] as $severity)
                <a
                    class="{{ $filters['severity'] === $severity ? 'active' : '' }}"
                    href="{{ route('scans.show', ['scan' => $scan, 'severity' => $severity, 'tool' => $filters['tool'], 'q' => $filters['q']]) }}"
                >{{ ucfirst($severity) }}</a>
            @endforeach
        </div>

        @if ($grouped->isEmpty())
            <div class="empty">No issues matched these filters.</div>
        @else
            @foreach ($grouped as $file => $fileIssues)
                @php $totalForFile = $fileTotals[$file] ?? $fileIssues->count(); @endphp
                <details class="file-group">
                    <summary>{{ $file }} <span style="color: var(--muted); font-weight: 500;">({{ $totalForFile }})</span></summary>
                    @foreach ($fileIssues as $issue)
                        <div class="issue">
                            <div class="issue-top">
                                <span class="badge {{ $issue->severity }}">{{ strtoupper($issue->severity) }}</span>
                                <span class="badge info">{{ $issue->tool }}</span>
                                <span class="location">{{ $issue->location() }}</span>
                            </div>
                            <div class="message">{{ $issue->message }}</div>
                            @if ($issue->rule)
                                <div class="rule">Rule: {{ $issue->rule }}</div>
                            @endif
                        </div>
                    @endforeach
                    @if ($totalForFile > $fileIssues->count())
                        <div class="issue">
                            <div class="rule">Showing first {{ $fileIssues->count() }} of {{ $totalForFile }} issues in this file. Use search or severity filters to narrow the list.</div>
                        </div>
                    @endif
                </details>
            @endforeach

            <div class="pager">
                <a class="btn secondary" href="{{ $filePage->previousPageUrl() ?: '#' }}" aria-disabled="{{ $filePage->onFirstPage() ? 'true' : 'false' }}">Previous files</a>
                <span>Page {{ $filePage->currentPage() }} of {{ $filePage->lastPage() }}</span>
                <a class="btn secondary" href="{{ $filePage->nextPageUrl() ?: '#' }}" aria-disabled="{{ $filePage->hasMorePages() ? 'false' : 'true' }}">Next files</a>
            </div>
        @endif
    </div>
@endsection
