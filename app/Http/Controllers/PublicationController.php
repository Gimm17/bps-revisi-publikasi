<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\AuditCoordinator;
use App\Audit\RuleRegistry;
use App\Http\Requests\PartialExportRequest;
use App\Http\Requests\PublicationRequest;
use App\Models\AiProviderConfig;
use App\Models\Export;
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

        return Inertia::render($request->routeIs('dashboard') ? 'Dashboard' : 'Publications/Index', ['publications' => $publications, 'filters' => $request->only('q', 'status'), 'aiReady' => AiProviderConfig::latest('id')->first()?->vision_tested_at !== null, 'metrics' => ['total' => Publication::visibleTo($request->user())->count(), 'active' => Publication::visibleTo($request->user())->whereIn('status', ['processing', 'cross_checking', 'exporting'])->count(), 'ready' => Publication::visibleTo($request->user())->whereIn('status', ['review_ready', 'final'])->count()]]);
    }

    public function create(): Response
    {
        return Inertia::render('Publications/Create');
    }

    public function store(PublicationRequest $request, CreatePublication $service): RedirectResponse
    {
        $publication = $service->execute($request->user(), $request->file('pdf'), $request->file('template'), $request->safe()->except(['pdf', 'template']));

        return redirect('/publications/'.$publication->id)->with('success', 'Dokumen berhasil divalidasi. '.$publication->page_count.' halaman siap diperiksa.');
    }

    public function show(Request $request, Publication $publication): Response
    {
        Gate::authorize('view', $publication);
        $page = max(1, min($publication->page_count, $request->integer('page', 1)));

        return Inertia::render('Publications/Show', [
            'publication' => $publication->only('id', 'title', 'region', 'year', 'kind', 'notes', 'status', 'page_count', 'manifest', 'pdf_name', 'template_name', 'rule_version', 'started_at', 'completed_at', 'locked_at', 'revision', 'error'),
            'pages' => fn () => $publication->pages()->get(['id', 'physical_page', 'status', 'page_type', 'printed_page', 'image_path', 'attempts', 'error'])->map(fn ($p) => [...$p->only('physical_page', 'status', 'page_type', 'printed_page', 'attempts', 'error'), 'has_image' => (bool) $p->image_path]),
            'currentPage' => fn () => $publication->pages()->where('physical_page', $page)->first()?->only('physical_page', 'status', 'page_type', 'printed_page', 'image_path', 'result', 'error'),
            'findings' => fn () => $publication->findings()->orderBy('id')->get(),
            'exports' => fn () => $publication->exports()->get()->map(fn ($e) => $e->only('id', 'version', 'publication_revision', 'status', 'checksum', 'finding_count', 'validation', 'error', 'created_at')),
            'rules' => app(RuleRegistry::class)->all(), 'canEdit' => $request->user()->can('update', $publication), 'canReview' => $request->user()->can('review', $publication),
            'assignedUsers' => $request->user()->isAdmin() ? $publication->assignees()->pluck('users.id') : [],
            'employees' => $request->user()->isAdmin() ? User::where('is_active', true)->get(['id', 'name', 'role']) : [],
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
        $coordinator->export($publication, $request->user()->id);

        return back()->with('success', 'Versi Excel baru sedang dibuat.');
    }

    public function download(Publication $publication, Export $export): BinaryFileResponse
    {
        Gate::authorize('view', $publication);
        abort_unless($export->publication_id === $publication->id && $export->status === 'complete', 404);
        $label = ($export->validation['mode'] ?? 'full') === 'partial' ? 'SEMENTARA BELUM LENGKAP' : 'Pemeriksaan';
        $filename = preg_replace('/[^\pL\pN\- ]/u', '', $publication->title).' - '.$label.' v'.$export->version.'.xlsx';

        return response()->download(Storage::disk('local')->path($export->path), $filename, ['Cache-Control' => 'private, no-store']);
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
}
