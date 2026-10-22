<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ExaminerLearning;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LearningController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->query('status');
        $kind = $request->query('kind');
        $search = $request->query('search');

        $query = ExaminerLearning::query();

        if ($status) {
            $query->where('status', $status);
        }
        if ($kind) {
            $query->where('kind', $kind);
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('topic', 'like', "%{$search}%")
                    ->orWhere('instruction', 'like', "%{$search}%")
                    ->orWhere('rule_code', 'like', "%{$search}%");
            });
        }

        $learnings = $query->orderByDesc('hits')->orderByDesc('id')->paginate(30)->withQueryString();

        return Inertia::render('Learnings/Index', [
            'learnings' => $learnings,
            'filters' => [
                'status' => $status,
                'kind' => $kind,
                'search' => $search,
            ],
        ]);
    }

    public function update(Request $request, ExaminerLearning $learning): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'string', 'in:active,pending,disabled'],
            'instruction' => ['sometimes', 'string', 'max:2000'],
        ]);

        $learning->update($data);

        return back()->with('success', 'Pembelajaran AI berhasil diperbarui.');
    }
}
