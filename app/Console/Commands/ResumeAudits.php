<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\AuditCoordinator;
use App\Models\Publication;
use Illuminate\Console\Command;

class ResumeAudits extends Command
{
    protected $signature = 'audit:resume {publication? : UUID publikasi}';

    protected $description = 'Antrekan halaman yang belum selesai; halaman selesai tidak diulang.';

    public function handle(AuditCoordinator $coordinator): int
    {
        $query = Publication::whereNull('locked_at')->whereNotNull('ai_config_id')->whereIn('status', [
            'processing',
            'processing_machine',
            'processing_ai',
            'reconciling',
            'partial_failure',
            'cross_checking',
            'exporting',
        ]);
        if ($id = $this->argument('publication')) {
            $query->whereKey($id);
        }
        foreach ($query->get() as $pub) {
            $coordinator->start($pub);
            $coordinator->afterPage($pub);
            $this->info($pub->id.' dilanjutkan.');
        }

        return self::SUCCESS;
    }
}
