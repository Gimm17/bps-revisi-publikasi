<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class WorkerHeartbeat implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        AuditEvent::where('action', 'worker.heartbeat')->delete();
        AuditEvent::create(['action' => 'worker.heartbeat', 'details' => []]);

        try {
            $coordinator = app(\App\Audit\AuditCoordinator::class);
            $hasWorkJobs = \Illuminate\Support\Facades\DB::table('jobs')
                ->whereIn('queue', ['machine', 'ai', 'verify'])
                ->exists();

            if (! $hasWorkJobs) {
                $stuckPubs = \App\Models\Publication::whereIn('status', [
                    'processing',
                    'processing_machine',
                    'processing_ai',
                    'reconciling',
                ])->where('updated_at', '<=', now()->subMinutes(3))->get();

                foreach ($stuckPubs as $pub) {
                    $coordinator->afterPage($pub);
                }

                $coordinator->advanceBatchQueue();
            }
        } catch (\Throwable) {
            // Ignore watchdog errors so heartbeat always succeeds
        }
    }
}
