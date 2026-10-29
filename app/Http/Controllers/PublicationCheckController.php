<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\AdditionalCheckCoordinator;
use App\Models\FindingAssessment;
use App\Models\Publication;
use App\Models\PublicationCheck;
use App\Models\PublicationReference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicationCheckController extends Controller
{
    public function store(Request $request, Publication $publication, AdditionalCheckCoordinator $coordinator): RedirectResponse
    {
        Gate::authorize('update', $publication);

        $data = $request->validate([
            'scope' => ['required', 'array'],
            'scope.*' => ['string', 'in:volume,cover_title,print_layout'],
        ]);

        $coordinator->start($publication, $request->user(), $data['scope']);

        return back()->with('success', 'Pemeriksaan tambahan aturan terbaru berhasil dimulai.');
    }

    public function retry(Request $request, Publication $publication, PublicationCheck $check, AdditionalCheckCoordinator $coordinator): RedirectResponse
    {
        abort_unless($check->checkRun && $check->checkRun->publication_id === $publication->id, 404);
        Gate::authorize('update', $publication);

        $coordinator->retry($check, $request->user());

        return back()->with('success', 'Pemeriksaan '.$check->check_type.' dimasukkan ke antrean ulang.');
    }

    public function storeReference(Request $request, Publication $publication): RedirectResponse
    {
        Gate::authorize('update', $publication);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:20480'],
            'series' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'between:1990,2050'],
            'target_url' => ['nullable', 'url', 'max:1000'],
        ]);

        $file = $request->file('file');
        $path = $file->store('publications/'.$publication->id.'/references/manual', 'local');

        $run = $publication->checkRuns()->latest()->first();
        if (! $run) {
            $run = $publication->checkRuns()->create([
                'requested_by' => $request->user()->id,
                'mode' => 'supplemental',
                'baseline_rule_version' => $publication->rule_version,
                'target_rule_version' => config('audit.rules_version_v2', 'bps-2026.10.18-v2'),
                'prompt_version' => config('audit.prompt_version_v2', 'page-v2'),
                'pdf_sha256' => $publication->pdf_checksum,
                'rules_path' => 'audit-rules/pedoman-v2.md',
                'rules_hash' => hash('sha256', 'v2'),
                'scope' => ['volume'],
                'status' => 'pending',
                'input_revision' => $publication->revision,
            ]);
        }

        PublicationReference::create([
            'check_run_id' => $run->id,
            'series' => $data['series'] ?? $publication->kind,
            'jurisdiction' => $publication->region,
            'year' => $data['year'] ?? ($publication->year - 1),
            'source_url' => $data['target_url'] ?? null,
            'provenance' => 'user_upload',
            'access_status' => 'opened',
            'read_status' => 'readable',
            'artifact_manifest' => [
                [
                    'id' => 1,
                    'artifact_type' => $file->getClientOriginalExtension() === 'pdf' ? 'pdf_document' : 'cover_image',
                    'path' => $path,
                    'checksum' => hash_file('sha256', Storage::disk('local')->path($path)),
                ],
            ],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'Dokumen rujukan manual berhasil diunggah.');
    }

    public function verifyVolumeManually(Request $request, Publication $publication, AdditionalCheckCoordinator $coordinator): RedirectResponse
    {
        Gate::authorize('update', $publication);

        $data = $request->validate([
            'prior_volume' => ['required', 'integer', 'between:1,500'],
            'prior_year' => ['nullable', 'integer', 'between:1990,2050'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $priorYear = (int) ($data['prior_year'] ?? (($publication->year ?? 2026) - 1));
        $priorVolume = (int) $data['prior_volume'];

        $run = $publication->checkRuns()->latest()->first();
        if (! $run) {
            $run = $publication->checkRuns()->create([
                'requested_by' => $request->user()->id,
                'mode' => 'supplemental',
                'baseline_rule_version' => $publication->rule_version,
                'target_rule_version' => config('audit.target_rule_version', 'bps-2026.10.18-v2'),
                'prompt_version' => 'page-v2',
                'pdf_sha256' => $publication->pdf_checksum,
                'rules_path' => 'audit-rules/pedoman-v2.md',
                'rules_hash' => hash('sha256', 'v2'),
                'scope' => ['volume'],
                'status' => 'running',
                'started_at' => now(),
            ]);
        }

        $existingRef = $run->references()->latest('id')->first();
        $baseMeta = $existingRef?->metadata ?? [];
        $mergedMeta = array_merge($baseMeta, [
            'title' => $baseMeta['title'] ?? ('Kecamatan '.$publication->region.' Dalam Angka '.$priorYear),
            'year' => $priorYear,
            'volume' => $priorVolume,
            'volume_source' => 'manual_verified',
            'notes' => $data['notes'] ?? null,
        ]);

        PublicationReference::create([
            'check_run_id' => $run->id,
            'series' => $publication->kind,
            'jurisdiction' => $publication->region,
            'year' => $priorYear,
            'source_url' => $existingRef?->source_url,
            'metadata' => $mergedMeta,
            'provenance' => 'manual_verified',
            'access_status' => 'opened',
            'read_status' => 'readable',
            'scope' => 'cover_only',
            'artifact_manifest' => $existingRef?->artifact_manifest ?? [
                [
                    'id' => 1,
                    'kind' => 'reference_artifact',
                    'artifact_type' => 'manual_verification',
                    'provenance' => 'manual_verified',
                    'quote' => "Edisi {$priorYear} diverifikasi manual sebagai Volume {$priorVolume}",
                ],
            ],
            'fetched_at' => now(),
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $volumeCheck = $run->checks()->where('check_type', 'volume')->first();
        if (! $volumeCheck) {
            $volumeCheck = $run->checks()->create([
                'check_type' => 'volume',
                'input_hash' => hash('sha256', $run->id.'|volume'),
                'execution_status' => 'pending',
            ]);
        }

        $coordinator->executeVolume($run, $volumeCheck);

        return back()->with('success', "Volume edisi {$priorYear} (Volume {$priorVolume}) berhasil disimpan dan diverifikasi.");
    }

    public function artifact(Request $request, Publication $publication, PublicationReference $reference, string $artifact): BinaryFileResponse
    {
        abort_unless($reference->checkRun && $reference->checkRun->publication_id === $publication->id, 404);
        Gate::authorize('view', $publication);

        $manifest = $reference->artifact_manifest ?? [];
        $item = collect($manifest)->first(fn ($a) => (string) ($a['id'] ?? '') === $artifact || (string) ($a['artifact_type'] ?? '') === $artifact);

        abort_unless($item && ! empty($item['path']) && Storage::disk('local')->exists($item['path']), 404);

        return response()->file(Storage::disk('local')->path($item['path']), [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function updateAssessment(Request $request, Publication $publication, FindingAssessment $assessment): RedirectResponse
    {
        abort_unless($assessment->checkRun && $assessment->checkRun->publication_id === $publication->id, 404);
        Gate::authorize('review', $publication);

        $data = $request->validate([
            'decision' => ['required', 'string', 'in:accepted,rejected'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $assessment->update([
            'reviewer_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        if ($data['decision'] === 'accepted') {
            $finding = $assessment->finding;
            if ($assessment->proposed_decision === 'dismiss') {
                $finding->update([
                    'review_status' => 'rejected',
                    'review_note' => $data['reason'] ?? 'Usulan pembatalan disetujui: '.$assessment->reason,
                    'version' => $finding->version + 1,
                ]);
            }
        }

        return back()->with('success', 'Keputusan atas usulan sistem berhasil disimpan.');
    }
}
