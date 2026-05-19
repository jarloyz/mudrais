<?php

use Illuminate\Support\Facades\DB;

$stats = DB::table('provider_logs')
    ->select(
        'model',
        'status',
        DB::raw('COUNT(*) as total_requests'),
        DB::raw('ROUND(AVG(total_tokens)::numeric, 2) as avg_tokens'),
        DB::raw('ROUND(AVG(latency_ms)::numeric, 2) as avg_latency_ms')
    )
    ->groupBy('model', 'status')
    ->orderBy('model')
    ->orderBy('status')
    ->get();

echo "\n-------------------------------------------------------------------------------------------------\n";
printf("%-45s | %-8s | %-10s | %-10s | %-15s\n", 'Model', 'Status', 'Requests', 'Avg Tokens', 'Avg Latency (ms)');
echo "-------------------------------------------------------------------------------------------------\n";

foreach ($stats as $row) {
    printf(
        "%-45s | %-8s | %-10d | %-10.2f | %-15.2f\n",
        substr($row->model ?? 'Unknown', 0, 45),
        $row->status ?? 'N/A',
        $row->total_requests,
        $row->avg_tokens ?? 0,
        $row->avg_latency_ms ?? 0
    );
}
echo "-------------------------------------------------------------------------------------------------\n\n";
