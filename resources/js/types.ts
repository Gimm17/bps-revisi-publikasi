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
    cross_verify_mode?: 'off' | 'high_risk' | 'all_pages';
    locked_at: string | null;
    error: string | null;
    rule_version: string;
    pdf_name: string;
    template_name: string;
}
export interface Evidence {
    kind?: 'publication_page' | 'reference_artifact';
    physical_page?: number;
    printed_page?: string | null;
    quote?: string;
    bbox?: [number, number, number, number] | number[] | null;
    reference_id?: string;
    artifact_id?: string;
    artifact_page?: number | null;
    source_url?: string | null;
    provenance?: 'official_browser' | 'user_upload';
}

export interface PublicationEvidence extends Evidence {
    kind?: 'publication_page';
    physical_page: number;
}

export interface ReferenceEvidence extends Evidence {
    kind: 'reference_artifact';
    reference_id: string;
    artifact_id: string;
    source_url: string | null;
    provenance: 'official_browser' | 'user_upload';
}

export type AuditEvidence = PublicationEvidence | ReferenceEvidence;

export interface CheckRunRecord {
    id: string;
    publication_id: string;
    requested_by: number;
    mode: 'new' | 'supplemental';
    baseline_rule_version: string;
    target_rule_version: string;
    prompt_version: string;
    status: 'pending' | 'running' | 'completed' | 'failed' | 'cancelled';
    scope: string[];
    summary: Record<string, unknown> | null;
    error: Record<string, unknown> | null;
    started_at: string | null;
    completed_at: string | null;
    created_at: string;
}

export interface CheckItemRecord {
    id: number;
    check_run_id: string;
    check_type: string;
    execution_status: 'pending' | 'running' | 'completed' | 'retry_wait' | 'failed' | 'cancelled';
    decision: 'conform' | 'nonconform' | 'needs_verification' | 'not_applicable' | null;
    result: Record<string, unknown> | null;
    evidences: Evidence[] | null;
    attempts: number;
    error_code: string | null;
    error_message: string | null;
}

export interface PublicationReferenceRecord {
    id: number;
    check_run_id: string;
    series: string | null;
    jurisdiction: string | null;
    year: number | null;
    source_url: string | null;
    provenance: 'official_browser' | 'user_upload';
    access_status: 'pending' | 'opened' | 'blocked';
    read_status: 'pending' | 'readable' | 'unreadable';
    artifact_manifest: {
        id?: number;
        artifact_type: string;
        path: string;
        checksum?: string;
    }[] | null;
}

export interface FindingAssessmentRecord {
    id: number;
    finding_id: number;
    check_run_id: string;
    proposed_decision: string;
    reason: string;
    evidences: Evidence[] | null;
    reviewer_id: number | null;
    reviewed_at: string | null;
    reviewer?: { id: number; name: string } | null;
}

export interface CoverageSummary {
    page_coverage: {
        checked_pages: number;
        total_pages: number;
        complete: boolean;
    };
    base_crosscheck: {
        complete: boolean;
        status: string;
    };
    additional_checks: {
        id: number;
        check_type: string;
        execution_status: string;
        decision: string | null;
        error_message: string | null;
    }[];
    limitations: string[];
    report_mode: 'partial' | 'complete_with_limitations' | 'complete';
    can_export: boolean;
    can_lock: boolean;
}
export interface AiAgentItem {
    id: number | null;
    slot: number;
    name: string;
    base_url: string;
    model: string;
    enabled: boolean;
    status: 'untested' | 'testing' | 'ready' | 'failed';
    test_message: string | null;
    vision_tested_at: string | null;
    has_api_key: boolean;
    max_concurrency: number;
    updated_at: string | null;
}

export interface PageVerificationRecord {
    id?: number;
    publication_page_id?: number;
    ai_agent_id?: number | null;
    status: 'pending' | 'running' | 'completed' | 'skipped' | 'failed';
    agreement?: {
        confirmed_count: number;
        single_agent_count: number;
        table_alignment_checked?: boolean;
    } | null;
    error?: string | null;
    agent?: {
        id: number;
        slot: number;
        name: string;
    } | null;
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
    agreement?: 'confirmed' | 'single_agent' | null;
    agent_ids?: number[] | null;
}

export interface PageRecord {
    id?: number;
    physical_page: number;
    status: string;
    page_type: string | null;
    printed_page: string | null;
    has_image: boolean;
    error: string | null;
    attempts?: number;
    primary_agent_slot?: number | null;
    verifier_agent_slot?: number | null;
    verification_status?: string | null;
    primary_agent?: { id: number; slot: number; name: string } | null;
    verification?: PageVerificationRecord | null;
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
        mode?: "partial" | "complete_with_limitations" | "complete" | "full";
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

export interface RuleGuideItem {
    code: string;
    title: string;
    category: string;
    severity: 'wajib' | 'saran' | 'verifikasi';
    mode: 'deterministic' | 'ai_page' | 'ai_pair' | 'external_evidence' | 'manual';
    spec_ref: string;
    summary: string;
    how_it_works: string;
    logic: string;
    criteria_pass: string;
    criteria_fail: string;
    example_good: string;
    example_bad: string;
    recommendation: string;
}

export interface RuleCategory {
    id: string;
    label: string;
    icon: string;
    description: string;
}

export interface WorkflowStage {
    step: number;
    id: string;
    title: string;
    badge: string;
    description: string;
    logic: string;
    icon: string;
}

export interface RuleGuideSummary {
    total_rules: number;
    mandatory_count: number;
    advisory_count: number;
    verification_count: number;
    categories_count: number;
}
