<?php

declare(strict_types=1);
use App\Jobs\RenderPage;
use App\Models\Publication;
use App\Models\User;
use App\Services\CreatePublication;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PublicationFixtures;

// Isolated browser-test database. Never connects to employee/production data.
$base = dirname(__DIR__, 2);
$database = $base.'/storage/framework/testing/browser.sqlite';
if (! is_dir(dirname($database))) {
    mkdir(dirname($database), 0770, true);
}if (! file_exists($database)) {
    touch($database);
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'file', 'QUEUE_CONNECTION' => 'database', 'HASH_DRIVER' => 'bcrypt'] as $key => $value) {
    $_ENV[$key] = $_SERVER[$key] = $value;
    putenv($key.'='.$value);
}
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Artisan::call('migrate', ['--force' => true]);
if (in_array('--work', $argv, true)) {
    Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1, '--timeout' => 230]);
    exit;
}
Queue::fake();
$user = User::updateOrCreate(['email' => 'browser@example.test'], ['name' => 'Pemeriksa Uji', 'password' => 'BrowserTestPassword2026!', 'role' => 'admin', 'is_active' => true]);
$fixture = new class
{
    use PublicationFixtures;

    public function file()
    {
        return $this->pdfFile(3);
    }
};
$file = $fixture->file();
copy($file->getPathname(), $base.'/storage/framework/testing/browser.pdf');
$pub = Publication::where('title', 'Publikasi Pengujian Browser')->first();
if (! $pub) {
    $pub = app(CreatePublication::class)->execute($user, $file, null, ['title' => 'Publikasi Pengujian Browser', 'region' => 'Daerah Uji', 'year' => 2026, 'kind' => 'kcda']);
    foreach ($pub->pages as $page) {
        app()->call([new RenderPage($page->id), 'handle']);
    }
    foreach ($pub->pages as $page) {
        $page->update(['status' => 'complete', 'completed_at' => now(), 'page_type' => 'narrative', 'result' => ['physical_page' => $page->physical_page, 'printed_page' => (string) $page->physical_page, 'readability' => 'readable', 'page_type' => 'narrative', 'text' => 'PUBLIKASI TEST HALAMAN '.$page->physical_page, 'inventory' => ['headings' => [], 'tables' => [], 'figures' => [], 'header' => '', 'footer' => ''], 'identity' => [], 'contents' => [], 'findings' => []]]);
    }
    $pub->update(['status' => 'review_ready']);
    $pub->findings()->create(['fingerprint' => hash('sha256', 'browser-finding'), 'rule_code' => 'MANUAL.REVIEW', 'section' => 'Pengujian', 'severity' => 'verifikasi', 'source' => 'manual', 'confidence' => 1, 'observed' => 'Temuan pengujian antarmuka', 'expected' => 'Catatan pemeriksa dapat disimpan', 'recommendation' => 'Perbaikan uji awal', 'evidences' => [['physical_page' => 1, 'quote' => 'PUBLIKASI TEST HALAMAN 1', 'bbox' => [.1, .08, .8, .14]]]]);
}
foreach ($pub->pages as $page) {
    if (! $page->result) {
        $page->update(['completed_at' => now(), 'result' => ['physical_page' => $page->physical_page, 'text' => 'PUBLIKASI TEST HALAMAN '.$page->physical_page]]);
    }
}
$partial = Publication::where('title', 'Publikasi Pemeriksaan Belum Lengkap')->first();
if (! $partial) {
    $partial = app(CreatePublication::class)->execute($user, $fixture->file(), null, ['title' => 'Publikasi Pemeriksaan Belum Lengkap', 'region' => 'Daerah Parsial', 'year' => 2026, 'kind' => 'kcda']);
    foreach ($partial->pages as $page) {
        app()->call([new RenderPage($page->id), 'handle']);
        $page->update($page->physical_page < 3 ? ['status' => 'complete', 'completed_at' => now(), 'result' => ['physical_page' => $page->physical_page, 'text' => 'PUBLIKASI TEST', 'page_type' => 'narrative', 'inventory' => [], 'identity' => [], 'contents' => []]] : ['status' => 'failed', 'error' => 'Respons AI belum valid pada kolom contents.0.target_page.']);
    }
    $partial->update(['status' => 'partial_failure']);
    $partial->findings()->create(['fingerprint' => 'partial-browser-finding', 'rule_code' => 'MANUAL.REVIEW', 'section' => 'Pengujian', 'severity' => 'verifikasi', 'source' => 'manual', 'confidence' => 1, 'observed' => 'Temuan parsial tetap tersimpan', 'expected' => 'Hasil tidak hilang', 'recommendation' => 'Pertahankan hasil', 'evidences' => [['physical_page' => 1, 'quote' => 'PUBLIKASI TEST']]]);
    $run = $partial->checkRuns()->create([
        'requested_by' => $user->id,
        'mode' => 'supplemental',
        'baseline_rule_version' => $partial->rule_version,
        'target_rule_version' => 'bps-2026.10.18-v2',
        'prompt_version' => 'page-v2',
        'pdf_sha256' => $partial->pdf_checksum,
        'rules_path' => 'audit-rules/pedoman-v2.md',
        'rules_hash' => hash('sha256', 'v2'),
        'scope' => ['volume', 'cover_title', 'print_layout'],
        'status' => 'completed',
        'input_revision' => $partial->revision,
    ]);
    $run->checks()->create([
        'check_type' => 'volume',
        'input_hash' => hash('sha256', 'vol-check'),
        'execution_status' => 'completed',
        'decision' => 'needs_verification',
        'error_message' => 'Volume edisi sebelumnya belum terbaca dari rujukan resmi',
    ]);
}
echo "Browser fixtures ready; isolated SQLite testing database.\n";
