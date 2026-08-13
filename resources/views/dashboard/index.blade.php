@extends('layouts.app')

@section('title', 'Prism Code Checker')

@section('content')
    <div class="panel">
        <h1>Prism Code Checker</h1>
        <p>Analyze any local PHP or WordPress project before you push or deploy.</p>

        <form method="POST" action="{{ route('dashboard.detect') }}" style="margin-top: 18px;">
            @csrf
            <label for="path">Project Path</label>
            <input
                id="path"
                type="text"
                name="path"
                value="{{ old('path', $path ?? '') }}"
                placeholder="{{ $projectsRoot ? rtrim($projectsRoot, '/\\') . DIRECTORY_SEPARATOR . 'my-project' : 'D:\\path\\to\\project' }}"
                required
            >
            @if ($projectsRoot)
                <p style="margin-top: 8px; font-size: 0.9rem;">Allowed root: <code>{{ $projectsRoot }}</code></p>
            @endif
            <div class="actions">
                <button class="btn secondary" type="submit">Detect Project</button>
            </div>
        </form>

        @if (!empty($detected))
            <div class="meta-grid">
                <div class="meta-item">
                    <div class="label">Project</div>
                    <div class="value">{{ basename($detected->path) }}</div>
                </div>
                <div class="meta-item">
                    <div class="label">Type</div>
                    <div class="value">{{ ucfirst($detected->type) }}</div>
                </div>
                <div class="meta-item">
                    <div class="label">Git Branch</div>
                    <div class="value">{{ $detected->branch ?? 'n/a' }}</div>
                </div>
                <div class="meta-item">
                    <div class="label">PHP</div>
                    <div class="value">{{ $detected->phpVersion ?? 'n/a' }}</div>
                </div>
                <div class="meta-item">
                    <div class="label">Composer</div>
                    <div class="value">{{ $detected->composerAvailable ? 'Available' : 'Not found' }}</div>
                </div>
                <div class="meta-item">
                    <div class="label">PHP Files</div>
                    <div class="value">{{ count($detected->files) }}</div>
                </div>
            </div>

            <form method="POST" action="{{ route('scans.store') }}">
                @csrf
                <input type="hidden" name="path" value="{{ $detected->path }}">
                <div class="actions">
                    <button class="btn" type="submit" name="scan_type" value="changed">Scan Changed Files</button>
                    <button class="btn secondary" type="submit" name="scan_type" value="full">Run Full Scan</button>
                </div>
            </form>
        @endif
    </div>

    <div class="panel">
        <h2>Recent Scans</h2>
        @if ($recentScans->isEmpty())
            <div class="empty">No scans yet.</div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Type</th>
                        <th>Branch</th>
                        <th>Result</th>
                        <th>When</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentScans as $scan)
                        <tr>
                            <td><a href="{{ route('scans.show', $scan) }}">{{ $scan->project->name }}</a></td>
                            <td>{{ $scan->scan_type }}</td>
                            <td>{{ $scan->branch ?? 'n/a' }}</td>
                            <td>
                                <span class="status-pill {{ $scan->isBlocking() ? 'fix' : 'ready' }}">
                                    {{ $scan->resultLabel() }}
                                </span>
                            </td>
                            <td>{{ $scan->created_at?->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
