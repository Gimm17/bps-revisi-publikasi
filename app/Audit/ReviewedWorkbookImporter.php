<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\Finding;
use App\Models\Publication;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ReviewedWorkbookImporter
{
    public function __construct(protected ExaminerLearningService $learningService = new ExaminerLearningService) {}

    /**
     * Import a reviewed Excel workbook and synchronize findings (handling deleted and noted findings).
     *
     * @return array{total_in_workbook: int, kept: int, deleted: int, deleted_ids: array<int>, updated_notes: int}
     */
    public function import(string $filePath, Publication $publication, ?User $user = null): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestRow();

        $presentFindingIds = [];
        $notesByFindingId = [];
        $rejectionsByFindingId = [];

        // Scan rows in column M starting at row 7
        for ($r = 7; $r <= $highestRow; $r++) {
            $cellVal = (string) $sheet->getCell([13, $r])->getValue(); // Column M is 13
            if (trim($cellVal) === '') {
                continue;
            }

            // Match Ref: #F123 or #F123,#F124
            if (preg_match_all('/#F(\d+)/i', $cellVal, $matches)) {
                $ids = array_map('intval', $matches[1]);
                foreach ($ids as $id) {
                    $presentFindingIds[$id] = true;

                    // Check for custom rejection note or reason in cell
                    if (preg_match('/(?:Alasan|Catatan Penolakan Pemeriksa|Catatan):\s*([^\n\r]+)/iu', $cellVal, $mNote)) {
                        $notesByFindingId[$id] = trim($mNote[1]);
                    }

                    if (str_contains($cellVal, '[DITOLAK') || str_contains($cellVal, 'Ditolak:')) {
                        $rejectionsByFindingId[$id] = true;
                    }
                }
            }
        }

        $spreadsheet->disconnectWorksheets();

        // Get all active findings for this publication in DB
        $dbFindings = $publication->findings()->where('deleted', false)->get();
        $dbFindingMap = $dbFindings->keyBy('id');

        $deletedIds = [];
        $keptCount = 0;
        $updatedNotesCount = 0;

        foreach ($dbFindings as $finding) {
            $fId = $finding->id;

            // If finding was present in DB but removed from Excel -> EXCEL DELETION!
            if (! isset($presentFindingIds[$fId])) {
                $deletedIds[] = $fId;
                $finding->update([
                    'review_status' => 'rejected',
                    'review_note' => 'Dihapus dari Excel oleh pemeriksa',
                ]);
                $this->learningService->recordExcelDeletion($finding, null, $user);
            } else {
                $keptCount++;

                // If explicit rejection or note was recorded in Excel
                $customNote = $notesByFindingId[$fId] ?? null;
                $isRejected = isset($rejectionsByFindingId[$fId]);

                if ($isRejected && $finding->review_status !== 'rejected') {
                    $reason = $customNote ?: 'Ditolak oleh pemeriksa pada Excel';
                    $finding->update([
                        'review_status' => 'rejected',
                        'review_note' => $reason,
                    ]);
                    $this->learningService->recordRejection($finding, $reason, $user);
                    $updatedNotesCount++;
                } elseif ($customNote !== null && $finding->review_note !== $customNote) {
                    $finding->update(['review_note' => $customNote]);
                    $updatedNotesCount++;
                }
            }
        }

        return [
            'total_in_workbook' => count($presentFindingIds),
            'kept' => $keptCount,
            'deleted' => count($deletedIds),
            'deleted_ids' => $deletedIds,
            'updated_notes' => $updatedNotesCount,
        ];
    }
}
