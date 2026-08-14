<?php

use App\CodeAnalysis\Services\IssueClassifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scan_issues', function (Blueprint $table) {
            $table->string('category')->default(IssueClassifier::BUG)->after('tool');
            $table->index(['scan_id', 'category']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('scan_issues', function (Blueprint $table) {
            $table->dropIndex(['scan_id', 'category']);
            $table->dropColumn('category');
        });
    }

    /**
     * Re-classify historic issues so old scans use the same buckets as new ones.
     */
    private function backfill(): void
    {
        $classifier = new IssueClassifier;

        $combinations = DB::table('scan_issues')
            ->select('tool', 'rule')
            ->distinct()
            ->get();

        foreach ($combinations as $combination) {
            $tool = (string) $combination->tool;
            $rule = $combination->rule;

            // Older scans stored formatting findings under a synthetic tool name.
            $category = $tool === 'Formatting'
                ? $classifier->categorize('PHPCS', $rule)
                : $classifier->categorize($tool, $rule);

            $matching = fn () => tap(
                DB::table('scan_issues')->where('tool', $tool),
                fn ($query) => $rule === null ? $query->whereNull('rule') : $query->where('rule', $rule)
            );

            if ($category === IssueClassifier::PRACTICE) {
                $matching()->whereIn('severity', ['critical', 'error'])->update(['severity' => 'warning']);
            }

            $update = [
                'category' => $category,
                'tool' => $tool === 'Formatting' ? 'PHPCS' : $tool,
            ];

            if ($category === IssueClassifier::STYLE) {
                $update['severity'] = 'notice';
            }

            $matching()->update($update);
        }

        $this->recountScans();
    }

    private function recountScans(): void
    {
        $counts = DB::table('scan_issues')
            ->select('scan_id', 'severity', DB::raw('COUNT(*) as total'))
            ->groupBy('scan_id', 'severity')
            ->get()
            ->groupBy('scan_id');

        foreach ($counts as $scanId => $rows) {
            $bySeverity = $rows->pluck('total', 'severity');

            DB::table('scans')->where('id', $scanId)->update([
                'critical_count' => (int) $bySeverity->get('critical', 0),
                'error_count' => (int) $bySeverity->get('error', 0),
                'warning_count' => (int) $bySeverity->get('warning', 0),
                'notice_count' => (int) $bySeverity->get('notice', 0),
                'info_count' => (int) $bySeverity->get('info', 0),
            ]);
        }
    }
};
