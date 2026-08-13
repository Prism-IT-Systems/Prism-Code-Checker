<?php

namespace App\Http\Controllers;

use App\CodeAnalysis\Services\ScanService;
use App\Models\Scan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ScanService $scanService,
    ) {}

    public function index(): View
    {
        $recentScans = Scan::query()
            ->with('project')
            ->latest()
            ->limit(10)
            ->get();

        return view('dashboard.index', [
            'recentScans' => $recentScans,
            'projectsRoot' => config('codechecker.projects_root'),
        ]);
    }

    public function detect(Request $request): View|RedirectResponse
    {
        $validated = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
        ]);

        try {
            $context = $this->scanService->detect($validated['path']);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return back()->withInput()->withErrors(['path' => $e->getMessage()]);
        }

        return view('dashboard.index', [
            'recentScans' => Scan::query()->with('project')->latest()->limit(10)->get(),
            'projectsRoot' => config('codechecker.projects_root'),
            'detected' => $context,
            'path' => $context->path,
        ]);
    }
}
