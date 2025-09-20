<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TransactionImportLog;

class TransactionImportLogController extends Controller
{
    public function stats()
    {
        $successCount = TransactionImportLog::where('status','success')->count();
        $failedCount = TransactionImportLog::where('status','failed')->count();
        $recentFailed = TransactionImportLog::where('status','failed')
                            ->latest('processed_at')
                            ->take(50)
                            ->get();

        $avgProcessingTime = TransactionImportLog::where('status','success')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, published_at, processed_at)) as avg_seconds')
            ->first();

        return response()->json([
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'avg_processing_time_seconds' => $avgProcessingTime->avg_seconds ?? 0,
            'recent_failed' => $recentFailed,
        ]);
    }
}
