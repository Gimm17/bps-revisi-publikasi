<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\AuditCoordinator;
use App\Audit\CoverageService;
use App\Audit\RuleRegistry;
use App\Http\Requests\PartialExportRequest;
use App\Http\Requests\PublicationRequest;
use App\Models\AiAgent;
use App\Models\AiProviderConfig;
use App\Models\Export;
use App\Models\FindingAssessment;
use App\Models\Publication;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CreatePublication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicationController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Publication::visibleTo($request->user());
        if ($request->filled('q')) {
            $query->where('title', 'like', '%'.$request->string('q').'%');
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        $publications = $query->withCount(['pages as checked_pages_count' => fn ($q) => $q->where('status', 'complete'), 'findings' => fn ($q) => $q->where('deleted', false)->where('review_status', '!=', 'rejected')])->latest()->paginate(20)->withQueryString();

        $activeCount = Publication::visibleTo($request->user())->whereIn('status', ['processing', 'processing_machine', 'processing_ai', 'reconciling', 'cross_checking', 'exporting'])->count();
        $batchQueuedCount = Publication::visibleTo($request->user())->where('status', 'batch_queued')->count();

        if ($batchQueuedCount > 0 && $activeCount === 0) {
            app(AuditCoordinator::class)->advanceBatchQueue();
        }

        return Inertia::render($request->routeIs('dashboard') ? 'Dashboard' : 'Publications/Index', [
            'publications' => $publications,
            'filters' => $request->only('q', 'status'),
            'aiReady' => AiAgent::ready()->exists() || AiProviderConfig::latest('id')->first()?->vision_tested_at !== null,
            'metrics' => [
                'total' => Publication::visibleTo($request->user())->count(),
                'active' => $activeCount + $batchQueuedCount,
                'ready' => Publication::visibleTo($request->user())->whereIn('status', ['review_ready', 'final'])->count(),
                'batch_queued' => $batchQueuedCount,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Publications/Create');
    }

    public function store(PublicationRequest $request, CreatePublication $service): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $publication = $service->execute($request->user(), $request->file('pdf'), $request->file('template'), $request->safe()->except(['pdf', 'template']));

        if ($request->boolean('batch_item') || $request->wantsJson()) {
            return response()->json([
                'id' => $publication->id,
                'title' => $publication->title,
                'page_count' => $publication->page_count,
                'status' => $publication->status,
            ]);
        }

        return redirect('/publications/'.$publication->id)->with('success', 'Dokumen berhasil divalidasi. '.$publication->page_count.' halaman siap diperiksa.');
    }

    public function batchStart(Request $request, AuditCoordinator $coordinator): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'publication_ids' => ['required', 'array', 'min:1', 'max:15'],
            'publication_ids.*' => ['required', 'string', 'exists:publications,id'],
            'audit_mode' => ['nullable', 'string', 'in:ai,machine,fusion'],
        ]);

        if (($data['audit_mode'] ?? null) === 'fusion' && $request->user()?->role !== 'admin') {
            throw ValidationException::withMessages(['audit_mode' => 'Mode Fusion Dual-Engine hanya dapat dijalankan oleh Administrator.']);
        }

        foreach ($data['publication_ids'] as $pubId) {
            $pub = Publication::findOrFail($pubId);
            Gate::authorize('update', $pub);
        }

        $res = $coordinator->enqueueBatch($data['publication_ids'], $data['audit_mode'] ?? null);

        $msg = $res['queued_count'].' publikasi dimasukkan ke antrean batch. Pemeriksaan dikerjakan otomatis satu per satu di server (aman ditinggal atau tutup browser).';

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $msg,
                ...$res,
            ]);
        }

        return redirect('/publications')->with('success', $msg);
    }

    public function batchCancel(Publication $publication, AuditCoordinator $coordinator): RedirectResponse
    {
        Gate::authorize('update', $publication);
        $coordinator->cancelBatchQueue($publication);
        $coordinator->advanceBatchQueue();

        return back()->with('success', 'Publikasi dikeluarkan dari antrean batch.');
    }

    public function show(Request $request, Publication $publication): Response
    {
        Gate::authorize('view', $publication);
        $page = max(1, min($publication->page_count, $request->integer('page', 1)));

        return Inertia::render('Publications/Show', [
            'publication' => $publication->only('id', 'title', 'region', 'year', 'kind', 'notes', 'status', 'page_count', 'manifest', 'pdf_name', 'template_name', 'rule_version', 'started_at', 'completed_at', 'locked_at', 'revision', 'error', 'cross_verify_mode', 'audit_mode', 'fusion_summary'),
            'publicationAgents' => fn () => AiAgent::ready()->where('slot', '<=', AiAgent::MAX_PRIMARY_SLOTS)->orderBy('slot')->get(['id', 'slot', 'name', 'model']),
            'readyAgentsCount' => fn () => AiAgent::ready()->where('slot', '<=', AiAgent::MAX_PRIMARY_SLOTS)->count(),
            'pages' => fn () => $publication->pages()->with(['aiAgent', 'latestVerification.agent'])->get()->map(fn ($p) => [
                ...$p->only('id', 'physical_page', 'status', 'page_type', 'printed_page', 'attempts', 'error', 'machine_status', 'fusion_status'),
                'has_image' => (bool) $p->image_path,
                'primary_agent_slot' => $p->aiAgent?->slot,
                'verifier_agent_slot' => $p->latestVerification?->agent?->slot,
                'verification_status' => $p->latestVerification?->status,
            ]),
            'currentPage' => function () use ($publication, $page) {
                $p = $publication->pages()->where('physical_page', $page)->with(['aiAgent', 'latestVerification.agent'])->first();
                if (! $p) {
                    return null;
                }

                return [
                    ...$p->only('id', 'physical_page', 'status', 'page_type', 'printed_page', 'image_path', 'result', 'error', 'ai_agent_id', 'machine_status', 'machine_result', 'fusion_status', 'text_layer'),
                    'primary_agent' => $p->aiAgent ? ['id' => $p->aiAgent->id, 'slot' => $p->aiAgent->slot, 'name' => $p->aiAgent->name] : null,
                    'verification' => $p->latestVerification ? [
                        'status' => $p->latestVerification->status,
                        'agent' => $p->latestVerification->agent ? ['id' => $p->latestVerification->agent->id, 'slot' => $p->latestVerification->agent->slot, 'name' => $p->latestVerification->agent->name] : null,
                        'agreement' => $p->latestVerification->agreement,
                        'error' => $p->latestVerification->error,
                    ] : null,
                ];
            },
            'findings' => fn () => $publication->findings()->orderBy('id')->get(),
            'exports' => fn () => $publication->exports()->get()->map(fn ($e) => $e->only('id', 'version', 'publication_revision', 'status', 'checksum', 'finding_count', 'validation', 'error', 'created_at')),
            'rules' => app(RuleRegistry::class)->all(), 'canEdit' => $request->user()->can('update', $publication), 'canReview' => $request->user()->can('review', $publication),
            'assignedUsers' => $request->user()->isAdmin() ? $publication->assignees()->pluck('users.id') : [],
            'employees' => $request->user()->isAdmin() ? User::where('is_active', true)->get(['id', 'name', 'role']) : [],
            'coverage' => fn () => app(CoverageService::class)->summarize($publication),
            'checkRuns' => fn () => $publication->checkRuns()->with(['checks', 'references'])->latest()->get()->map(function ($run) {
                return [
                    'id' => $run->id,
                    'mode' => $run->mode,
                    'baseline_rule_version' => $run->baseline_rule_version,
                    'target_rule_version' => $run->target_rule_version,
                    'scope' => $run->scope,
                    'status' => $run->status,
                    'started_at' => $run->started_at,
                    'completed_at' => $run->completed_at,
                    'summary' => $run->summary,
                    'checks' => $run->checks->map(fn ($c) => [
                        'id' => $c->id,
                        'check_type' => $c->check_type,
                        'execution_status' => $c->execution_status,
                        'decision' => $c->decision,
                        'error_message' => $c->error_message,
                        'attempts' => $c->attempts,
                        'result' => $c->result,
                    ]),
                    'references' => $run->references->map(fn ($r) => [
                        'id' => $r->id,
                        'series' => $r->series,
                        'jurisdiction' => $r->jurisdiction,
                        'year' => $r->year,
                        'source_url' => $r->source_url,
                        'provenance' => $r->provenance,
                        'access_status' => $r->access_status,
                        'read_status' => $r->read_status,
                        'metadata' => $r->metadata,
                        'artifact_manifest' => $r->artifact_manifest,
                    ]),
                ];
            }),
            'assessments' => fn () => FindingAssessment::whereIn('finding_id', $publication->findings()->pluck('id'))
                ->with('reviewer:id,name')
                ->latest()
                ->get(),
        ]);
    }

    public function start(Publication $publication, AuditCoordinator $coordinator): RedirectResponse
    {
        Gate::authorize('update', $publication);
        $coordinator->start($publication);
        $coordinator->afterPage($publication);

        return back()->with('success', 'Pemeriksaan dimasukkan ke antrean.');
    }

    public function retry(Publication $publication, int $page, AuditCoordinator $coordinator): RedirectResponse
    {
        Gate::authorize('update', $publication);
        $coordinator->retry($publication, $page);

        return back()->with('success', 'Halaman '.$page.' dimasukkan ke antrean ulang.');
    }

    public function rebindAi(Request $request, Publication $publication, AuditCoordinator $coordinator): RedirectResponse
    {
        Gate::authorize('update', $publication);
        $request->validate(['confirm' => ['required', 'accepted']]);
        $coordinator->rebindAi($publication);

        return back()->with('success', 'Koneksi AI diganti dengan persetujuan Anda. Halaman selesai dipertahankan; halaman tersisa dilanjutkan.');
    }

    public function updateCrossVerifyMode(Request $request, Publication $publication): RedirectResponse
    {
        Gate::authorize('update', $publication);
        if (in_array($publication->status, ['review_ready', 'final'], true) || $publication->locked_at) {
            throw ValidationException::withMessages(['cross_verify_mode' => 'Mode verifikasi tidak dapat diubah setelah pemeriksaan selesai/dikunci.']);
        }
        $data = $request->validate([
            'cross_verify_mode' => ['required', 'string', 'in:off,high_risk,all_pages'],
        ]);
        $publication->update(['cross_verify_mode' => $data['cross_verify_mode']]);

        return back()->with('success', 'Mode verifikasi silang diperbarui.');
    }

    public function pdf(Publication $publication): BinaryFileResponse
    {
        Gate::authorize('view', $publication);

        return response()->file(Storage::disk('local')->path($publication->pdf_path), ['Cache-Control' => 'private, no-store']);
    }

    public function image(Request $request, Publication $publication, int $page): BinaryFileResponse
    {
        Gate::authorize('view', $publication);
        $record = $publication->pages()->where('physical_page', $page)->firstOrFail();
        $path = $request->boolean('thumbnail') ? $record->thumbnail_path : $record->image_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=300']);
    }

    public function export(Request $request, Publication $publication, AuditCoordinator $coordinator): RedirectResponse
    {
        Gate::authorize('view', $publication);
        $format = $request->input('format', 'standard');
        if (! in_array($format, ['standard', 'internal'], true)) {
            $format = 'standard';
        }
        $coordinator->export($publication, $request->user()->id, $format);

        return back()->with('success', 'Versi Excel baru ('.($format === 'internal' ? 'Format Audit Internal' : 'Format Standar Resmi').') sedang diproses.');
    }

    public function download(Request $request, Publication $publication, Export $export, \App\Audit\WorkbookWriter $writer): BinaryFileResponse
    {
        Gate::authorize('view', $publication);
        $format = $request->query('format', $export->validation['export_format'] ?? 'standard');
        $includeEngineSource = ($format === 'internal');

        $targetPath = $export->path;
        if ($includeEngineSource) {
            $internalPath = str_replace('.xlsx', '_internal.xlsx', $export->path);
            if (! Storage::disk('local')->exists($internalPath)) {
                $writer->write($publication, $internalPath, $export->validation, ['include_engine_source' => true]);
            }
            $targetPath = $internalPath;
        }

        $mode = $export->validation['mode'] ?? ($export->validation['report_mode'] ?? 'full');
        $label = match ($mode) {
            'partial' => 'SEMENTARA BELUM LENGKAP',
            'complete_with_limitations' => 'DENGAN KETERBATASAN',
            default => 'Pemeriksaan',
        };
        $suffix = $includeEngineSource ? ' (Internal - Asal Deteksi)' : '';
        $filename = preg_replace('/[^\pL\pN\- ]/u', '', $publication->title).' - '.$label.' v'.$export->version.$suffix.'.xlsx';

        return response()->download(Storage::disk('local')->path($targetPath), $filename, ['Cache-Control' => 'private, no-store']);
    }

    public function exportPartial(PartialExportRequest $request, Publication $publication, AuditCoordinator $coordinator): RedirectResponse
    {
        $coordinator->exportPartial($publication, $request->user()->id);

        return back()->with('success', 'Excel sementara siap diunduh. Hasil yang sudah tersimpan dipertahankan; pemeriksaan belum lengkap.');
    }

    public function assign(Request $request, Publication $publication): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['users' => ['present', 'array'], 'users.*' => ['integer', 'exists:users,id']]);
        $publication->assignees()->sync($data['users']);
        AuditLogger::record('publication.assigned', $publication, $data);

        return back()->with('success', 'Penugasan diperbarui.');
    }

    public function lock(Publication $publication): RedirectResponse
    {
        Gate::authorize('review', $publication);
        DB::transaction(function () use ($publication): void {
            $publication = Publication::whereKey($publication->id)->lockForUpdate()->firstOrFail();
            if (! app(AuditCoordinator::class)->canComplete($publication) || ! in_array($publication->status, ['review_ready', 'final']) || $publication->findings()->where('deleted', false)->whereIn('review_status', ['unreviewed', 'needs_verification'])->exists() || ! $publication->exports()->where('status', 'complete')->where('publication_revision', $publication->revision)->where(fn ($q) => $q->whereNull('validation->mode')->orWhere('validation->mode', '!=', 'partial'))->exists()) {
                throw ValidationException::withMessages(['lock' => 'Tinjau semua temuan dan buat Excel untuk revisi terbaru sebelum mengunci hasil.']);
            }
            $publication->update(['status' => 'final', 'locked_at' => now()]);
            AuditLogger::record('publication.locked', $publication);
        });

        return back()->with('success', 'Hasil final dikunci.');
    }

    public function unlock(Publication $publication): RedirectResponse
    {
        Gate::authorize('review', $publication);
        DB::transaction(function () use ($publication): void {
            $pub = Publication::whereKey($publication->id)->lockForUpdate()->firstOrFail();
            if ($pub->status !== 'final' || ! $pub->locked_at || ! app(AuditCoordinator::class)->canComplete($pub)) {
                throw ValidationException::withMessages(['unlock' => 'Hanya hasil final terkunci dengan seluruh halaman selesai yang dapat dibuka kembali.']);
            }
            $pub->update(['status' => 'review_ready', 'locked_at' => null]);
            AuditLogger::record('publication.unlocked', $pub);
        });

        return back()->with('success', 'Hasil dibuka untuk penyuntingan.');
    }

    public function uploadReviewedExport(\Illuminate\Http\Request $request, Publication $publication, \App\Models\PublicationExport $export, \App\Audit\ReviewedWorkbookImporter $importer): RedirectResponse
    {
        Gate::authorize('review', $publication);
        abort_unless($export->publication_id === $publication->id, 404);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:51200'],
        ]);

        $file = $request->file('file');
        $result = $importer->import($file->getRealPath(), $publication, $request->user());

        AuditLogger::record('publication.reviewed_workbook_imported', $publication, $result);

        $msg = "Excel pemeriksa berhasil disinkronkan: {$result['deleted']} temuan dihapus pemeriksa (dicatat sebagai bahan belajar AI), {$result['kept']} temuan dipertahankan.";

        return back()->with('success', $msg);
    }
}
