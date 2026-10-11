export interface Employee {
    id: number;
    name: string;
    email: string;
    role: string;
    is_active: boolean;
}
export interface Shared {
    auth: { user: Employee };
    flash: { success?: string; error?: string };
    errors: Record<string, string>;
    [key: string]: unknown;
}
export interface Publication {
    id: string;
    title: string;
    region: string;
    year: number;
    kind: string;
    status: string;
    page_count: number;
    checked_pages_count?: number;
    findings_count?: number;
    manifest: Record<string, unknown>;
    revision: number;
    locked_at: string | null;
    error: string | null;
    rule_version: string;
    pdf_name: string;
    template_name: string;
}
export interface Evidence {
    physical_page: number;
    printed_page?: string | null;
    quote?: string;
    bbox?: number[] | null;
}
export interface Finding {
    id: number;
    rule_code: string;
    section: string;
    severity: string;
    observed: string;
    expected: string;
    recommendation: string;
    evidences: Evidence[];
    review_status: string;
    review_note: string | null;
    version: number;
    deleted: boolean;
    source: string;
    confidence: number;
}
export interface PageRecord {
    physical_page: number;
    status: string;
    page_type: string | null;
    printed_page: string | null;
    has_image: boolean;
    error: string | null;
    result?: { text?: string; inventory?: Record<string, unknown> };
    image_path?: string | null;
}
export interface ExportRecord {
    id: number;
    version: number;
    publication_revision: number;
    status: string;
    finding_count: number;
    error: string | null;
    created_at: string;
    checksum: string | null;
    validation: {
        mode?: "partial" | "full";
        checked_pages?: number;
        total_pages?: number;
        unchecked_pages?: {
            physical_page: number;
            status: string;
            error: string | null;
        }[];
    } | null;
}
export interface Paginated<T> {
    data: T[];
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}
export const labels: Record<string, string> = {
    draft: "Siap diperiksa",
    pending: "Menunggu",
    rendering: "Menyiapkan gambar",
    ready: "Gambar siap",
    analyzing: "Sedang dibaca",
    processing: "Sedang diperiksa",
    complete: "Sudah diperiksa",
    needs_verification: "Perlu verifikasi",
    failed: "Gagal",
    partial_failure: "Perlu dilanjutkan",
    cross_checking: "Pemeriksaan silang",
    exporting: "Membuat Excel",
    review_ready: "Siap ditinjau",
    final: "Final terkunci",
    unreviewed: "Belum ditinjau",
    accepted: "Diterima",
    rejected: "Ditolak",
};
export const label = (status: string) => labels[status] ?? status;
