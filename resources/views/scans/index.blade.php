@extends('layouts.app')

@section('title', 'Scan History — Prism Code Checker')

@section('content')
    <div class="panel">
        <h1>Scan History</h1>
        @if ($scans->isEmpty())
            <div class="empty">No scans yet.</div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Project</th>
                        <th>Scan</th>
                        <th>Branch</th>
                        <th>Issues</th>
                        <th>Result</th>
                        <th>When</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($scans as $scan)
                        <tr>
                            <td><a href="{{ route('scans.show', $scan) }}">#{{ $scan->id }}</a></td>
                            <td>{{ $scan->project->name }}</td>
                            <td>{{ $scan->scan_type }}</td>
                            <td>{{ $scan->branch ?? 'n/a' }}</td>
                            <td>
                                C{{ $scan->critical_count }}
                                / E{{ $scan->error_count }}
                                / W{{ $scan->warning_count }}
                            </td>
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
            <div style="margin-top: 16px;">{{ $scans->links() }}</div>
        @endif
    </div>
@endsection
