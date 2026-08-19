<?php

$db = new PDO('sqlite:' . __DIR__ . '/../database/database.sqlite');
$rows = $db->query('SELECT scans.id, scans.duration, scans.analyzer_summaries, projects.path FROM scans LEFT JOIN projects ON projects.id = scans.project_id WHERE scans.id >= 50 ORDER BY scans.id')->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $r) {
    $s = json_decode($r['analyzer_summaries'], true) ?? [];
    printf("Scan %d  total=%.1fs  project=%s\n", $r['id'], (float) $r['duration'], basename($r['path'] ?? '?'));
    foreach ($s as $key => $info) {
        $tool = $info['tool'] ?? $key;
        printf("  %-12s duration=%.1fs  issues=%d  err=%s\n",
            $tool,
            (float) ($info['duration'] ?? 0),
            (int) ($info['total'] ?? $info['count'] ?? 0),
            substr($info['error'] ?? '-', 0, 80)
        );
    }
}
