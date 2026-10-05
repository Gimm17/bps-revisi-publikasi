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
    }
}
