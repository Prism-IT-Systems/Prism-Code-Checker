<?php

namespace App\Http\Controllers;

use App\CodeAnalysis\Services\ScanService;
use App\Models\Scan;
use App\Models\ScanIssue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ScanController extends Controller
{
    public function __construct(
        private readonly ScanService $scanService,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
            'scan_type' => ['required', 'in:changed,full'],
        ]);

        try {
            $scan = $this->scanService->run($validated['path'], $validated['scan_type']);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return back()->withInput()->withErrors(['path' => $e->getMessage()]);
        }

        return redirect()->route('scans.show', $scan);
    }

    public function show(Request $request, Scan $scan): View
    {
        $scan->load('project');

        $severity = $request->string('severity')->toString();
        $tool = $request->string('tool')->toString();
        $q = $request->string('q')->toString();
        $filesPerPage = max(1, (int) config('codechecker.dashboard_files_per_page', 12));
        $issuesPerFile = max(1, (int) config('codechecker.dashboard_issues_per_file', 80));

        $filePage = $this->filteredIssues($scan, $severity, $tool, $q)
            ->select('file')
            ->selectRaw('COUNT(*) as issue_count')
            ->groupBy('file')
            ->orderBy('file')
            ->paginate($filesPerPage)
            ->withQueryString();

        $fileNames = $filePage->getCollection()->pluck('file')->all();
        $grouped = collect();
        $fileTotals = [];

        if ($fileNames !== []) {
            $issues = $this->filteredIssues($scan, $severity, $tool, $q)
                ->whereIn('file', $fileNames)
                ->orderBy('file')
                ->orderBy('line')
                ->get()
                ->groupBy('file');

            foreach ($filePage as $row) {
                $fileTotals[$row->file] = (int) $row->issue_count;
                $grouped->put(
                    $row->file,
                    $issues->get($row->file, collect())->take($issuesPerFile)
                );
            }

            unset($issues);
        }

        $tools = $scan->issues()
            ->select('tool')
            ->distinct()
            ->orderBy('tool')
            ->pluck('tool');

        $matchingCount = $this->filteredIssues($scan, $severity, $tool, $q)->count();

        return view('scans.show', [
            'scan' => $scan,
            'grouped' => $grouped,
            'filePage' => $filePage,
            'fileTotals' => $fileTotals,
            'issuesPerFile' => $issuesPerFile,
            'matchingCount' => $matchingCount,
            'tools' => $tools,
            'filters' => [
                'severity' => $severity !== '' ? $severity : 'all',
                'tool' => $tool !== '' ? $tool : 'all',
                'q' => $q,
            ],
        ]);
    }

    public function index(): View
    {
        $scans = Scan::query()
            ->with('project')
            ->latest()
            ->paginate(20);

        return view('scans.index', [
            'scans' => $scans,
        ]);
    }

    private function filteredIssues(Scan $scan, string $severity, string $tool, string $q): Builder
    {
        $query = ScanIssue::query()->where('scan_id', $scan->id);

        if ($severity !== '' && $severity !== 'all') {
            $query->where('severity', $severity);
        }

        if ($tool !== '' && $tool !== 'all') {
            $query->where('tool', $tool);
        }

        if ($q !== '') {
            $query->where(function ($inner) use ($q) {
                $inner->where('file', 'like', "%{$q}%")
                    ->orWhere('message', 'like', "%{$q}%")
                    ->orWhere('rule', 'like', "%{$q}%");
            });
        }

        return $query;
    }
}
