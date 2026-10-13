<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AnalyzePage;
use App\Jobs\RenderPage;
use App\Models\AiUsageRecord;
use App\Models\PublicationPage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepareParallelAudit extends Command
{
    protected $signature = 'audit:prepare-parallel';

    protected $description = 'Sesuaikan batas antrean halaman lama untuk worker paralel; tidak mengulang hasil selesai.';

    public function handle(): int
    {
        $updated = 0;
        foreach (DB::table('jobs')->whereNull('reserved_at')->pluck('id') as $id) {
            $updated += DB::transaction(function () use ($id): int {
                $row = DB::table('jobs')->where('id', $id)->whereNull('reserved_at')->lockForUpdate()->first();
                if (! $row) {
                    return 0;
                }
                $payload = json_decode($row->payload, true, 64, JSON_THROW_ON_ERROR);
                if (! in_array($payload['displayName'] ?? '', [AnalyzePage::class, RenderPage::class], true) || ($payload['retryUntil'] ?? null) !== null) {
                    return 0;
                }
                $job = unserialize($payload['data']['command'], ['allowed_classes' => [AnalyzePage::class, RenderPage::class]]);
                if (! $job instanceof AnalyzePage && ! $job instanceof RenderPage) {
                    return 0;
                }
                if ($job instanceof AnalyzePage) {
                    $job->attemptBaseline ??= (int) PublicationPage::whereKey($job->pageId)->value('attempts');
                    $job->usageBaseline ??= (int) AiUsageRecord::where('page_id', $job->pageId)->max('id');
                }
                $job->tries = 0;
                $job->maxExceptions = 3;
                $job->uniqueFor = 15000;
                $payload['maxTries'] = 0;
                $payload['maxExceptions'] = 3;
                $payload['retryUntil'] = now()->addHours(4)->timestamp;
                $payload['data']['command'] = serialize($job);
                DB::table('jobs')->where('id', $id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);

                return 1;
            });
        }
        $this->info($updated.' tugas antrean lama disesuaikan. Hasil halaman tidak diubah.');

        return self::SUCCESS;
    }
}
