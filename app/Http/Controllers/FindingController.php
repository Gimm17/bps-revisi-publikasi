<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\RuleRegistry;
use App\Http\Requests\FindingRequest;
use App\Models\Finding;
use App\Models\Publication;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class FindingController extends Controller
{
    public function store(FindingRequest $request, Publication $publication): RedirectResponse
    {
        $data = $request->validated();
        $this->validateRule($data['rule_code']);
        if (empty($data['page']) || empty(trim($data['quote'] ?? ''))) {
            throw ValidationException::withMessages(['quote' => 'Temuan manual memerlukan halaman dan kutipan bukti.']);
        }
        DB::transaction(function () use ($publication, $data, $request) {
            $pub = Publication::whereKey($publication->id)->lockForUpdate()->firstOrFail();
            abort_if($pub->locked_at, 403);
            $f = $pub->findings()->create([...collect($data)->except(['version', 'page', 'quote'])->all(), 'fingerprint' => hash('sha256', random_bytes(32)), 'section' => 'Temuan manual', 'source' => 'manual', 'confidence' => 1, 'evidences' => [['physical_page' => $data['page'], 'quote' => $data['quote'], 'bbox' => null]], 'version' => 1]);
            $f->revisions()->create(['user_id' => $request->user()->id, 'before' => [], 'after' => $f->toArray(), 'reason' => $data['review_note'] ?? 'Temuan manual ditambahkan']);
            $pub->increment('revision');
        });
        AuditLogger::record('finding.created', $publication);

        return back()->with('success', 'Temuan manual disimpan. Buat ulang Excel untuk memuat perubahan.');
    }

    public function update(FindingRequest $request, Publication $publication, Finding $finding): RedirectResponse
    {
        abort_unless($finding->publication_id === $publication->id, 404);
        $data = $request->validated();
        $this->validateRule($data['rule_code']);
        if (($data['review_status'] === 'rejected' || ($data['deleted'] ?? false)) && empty(trim($data['review_note'] ?? ''))) {
            throw ValidationException::withMessages(['review_note' => 'Tuliskan alasan penolakan atau penghapusan.']);
        }
        DB::transaction(function () use ($publication, $finding, $data, $request) {
            $pub = Publication::whereKey($publication->id)->lockForUpdate()->firstOrFail();
            abort_if($pub->locked_at, 403);
            $f = Finding::whereKey($finding->id)->lockForUpdate()->firstOrFail();
            if ($f->version !== $data['version']) {
                if ($request->header('X-Inertia')) {
                    throw ValidationException::withMessages(['version' => 'Temuan telah diubah pengguna lain. Perubahan Anda belum dibuang. Muat ulang temuan sebelum menyimpan.']);
                }
                abort(409, 'Temuan telah diubah pengguna lain. Muat ulang sebelum menyimpan.');
            }
            $before = $f->toArray();
            $f->update([...collect($data)->except(['version', 'page', 'quote'])->all(), 'version' => $f->version + 1]);
            $f->revisions()->create(['user_id' => $request->user()->id, 'before' => $before, 'after' => $f->toArray(), 'reason' => $data['review_note'] ?? 'Penyuntingan temuan']);
            $pub->increment('revision');
        });
        AuditLogger::record('finding.updated', $publication, ['finding_id' => $finding->id]);

        return back()->with('success', 'Perubahan tersimpan. Excel lama tetap tersedia; buat versi baru untuk perubahan ini.');
    }

    public function history(Publication $publication, Finding $finding): JsonResponse
    {
        Gate::authorize('view', $publication);
        abort_unless($finding->publication_id === $publication->id, 404);

        return response()->json($finding->revisions()->get());
    }

    private function validateRule(string $code): void
    {
        if (! array_key_exists($code, app(RuleRegistry::class)->all())) {
            throw ValidationException::withMessages(['rule_code' => 'Kode aturan tidak dikenal.']);
        }
    }
}
