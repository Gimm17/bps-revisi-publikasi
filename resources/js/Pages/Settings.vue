<script setup lang="ts">
import { Head, useForm, router } from "@inertiajs/vue3";
import { ref, computed, onUnmounted, watch } from "vue";
import {
    Plus,
    Save,
    FlaskConical,
    ShieldCheck,
    Server,
    Copy,
    Check,
    AlertCircle,
    RefreshCw,
    Activity,
    Zap,
    Coins,
    CheckCircle2,
    Clock,
    Bot,
    TrendingUp,
    BarChart2,
    Filter,
    Layers,
    Cpu,
    ArrowUpRight,
    Bookmark,
    BookmarkCheck,
    Trash2,
    X,
} from "lucide-vue-next";
import AppLayout from "../Layouts/AppLayout.vue";
import type { Employee, AiAgentItem, AiModelPreset, AiTelemetryData, AiTelemetryTimelinePoint } from "../types";

const props = defineProps<{
    users: Employee[];
    ai_agents?: AiAgentItem[];
    ai_model_presets?: AiModelPreset[];
    ai: {
        id: number;
        base_url: string;
        model: string;
        vision_tested_at: string | null;
    } | null;
    reference_provider?: {
        id: number;
        provider: string;
        endpoint: string;
        is_ready: boolean;
        tested_at: string | null;
        updated_at: string;
    } | null;
    events: {
        id: number;
        action: string;
        created_at: string;
        details: unknown;
    }[];
    telemetry?: AiTelemetryData;
    health: {
        queue: number;
        failed: number;
        usage: number;
        driver: string;
        disk_free_mb: number;
    };
}>();

const tab = ref(
    new URLSearchParams(window.location.search).get("tab") ?? "users",
);
const editing = ref<number | null>(null);
const userOpen = ref(false);

const employee = useForm({
    name: "",
    email: "",
    password: "",
    role: "examiner",
    is_active: true,
});

function edit(u?: Employee) {
    editing.value = u?.id ?? null;
    employee.defaults({
        name: u?.name ?? "",
        email: u?.email ?? "",
        password: "",
        role: u?.role ?? "examiner",
        is_active: u?.is_active ?? true,
    });
    employee.reset();
    employee.clearErrors();
    userOpen.value = true;
}

function saveUser() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            userOpen.value = false;
            employee.reset("password");
        },
    };
    editing.value
        ? employee.put(`/settings/users/${editing.value}`, options)
        : employee.post("/settings/users", options);
}

// Multi-Agent State
const activeSlot = ref(1);
const testingAll = ref(false);
const testingSlot = ref<number | null>(null);
const pollTimer = ref<number | null>(null);
const copySuccess = ref(false);

const localAgents = ref<AiAgentItem[]>(
    props.ai_agents && props.ai_agents.length > 0
        ? JSON.parse(JSON.stringify(props.ai_agents))
        : [
            { slot: 1, name: 'Agent 1 (Utama)', base_url: props.ai?.base_url ?? 'https://api.9router.com/v1', model: props.ai?.model ?? 'gemini-2.5-flash', enabled: true, status: props.ai?.vision_tested_at ? 'ready' : 'untested', test_message: null, vision_tested_at: props.ai?.vision_tested_at ?? null, has_api_key: false, max_concurrency: 1, updated_at: null, id: null },
            { slot: 2, name: 'Agent 2', base_url: 'https://api.9router.com/v1', model: 'gemini-2.5-flash', enabled: false, status: 'untested', test_message: null, vision_tested_at: null, has_api_key: false, max_concurrency: 1, updated_at: null, id: null },
            { slot: 3, name: 'Agent 3', base_url: 'https://api.9router.com/v1', model: 'gemini-2.5-flash', enabled: false, status: 'untested', test_message: null, vision_tested_at: null, has_api_key: false, max_concurrency: 1, updated_at: null, id: null },
            { slot: 4, name: 'Agent 4', base_url: 'https://api.9router.com/v1', model: 'gemini-2.5-flash', enabled: false, status: 'untested', test_message: null, vision_tested_at: null, has_api_key: false, max_concurrency: 1, updated_at: null, id: null },
            { slot: 5, name: 'Agent 5', base_url: 'https://api.9router.com/v1', model: 'gemini-2.5-flash', enabled: false, status: 'untested', test_message: null, vision_tested_at: null, has_api_key: false, max_concurrency: 1, updated_at: null, id: null },
            { slot: 6, name: 'Agent Cadangan (Standby)', base_url: props.ai?.base_url ?? 'https://api.9router.com/v1', model: 'claude-sonnet-4-6', enabled: true, status: 'untested', test_message: null, vision_tested_at: null, has_api_key: false, max_concurrency: 1, updated_at: null, id: null, is_backup: true },
        ]
);

watch(() => props.ai_agents, (newAgents) => {
    if (newAgents && newAgents.length > 0) {
        localAgents.value = JSON.parse(JSON.stringify(newAgents));
        const ag = localAgents.value.find(a => a.slot === activeSlot.value);
        if (ag) {
            currentForm.value.name = ag.name;
            currentForm.value.base_url = ag.base_url;
            currentForm.value.model = ag.model;
            currentForm.value.enabled = ag.enabled;
            currentForm.value.max_concurrency = ag.max_concurrency || 1;
        }
    }
}, { deep: true });

const primaryAgents = computed(() => {
    return localAgents.value.filter(a => a.slot <= 5);
});

const backupAgentItem = computed(() => {
    return localAgents.value.find(a => a.slot === 6);
});

const currentAgent = computed(() => {
    return localAgents.value.find(a => a.slot === activeSlot.value) ?? localAgents.value[0];
});

const currentForm = ref({
    name: currentAgent.value.name,
    base_url: currentAgent.value.base_url,
    model: currentAgent.value.model,
    api_key: '',
    enabled: currentAgent.value.enabled,
    max_concurrency: currentAgent.value.max_concurrency || 1,
    saving: false,
    errorMessage: '',
});

watch(activeSlot, (newSlot) => {
    const ag = localAgents.value.find(a => a.slot === newSlot);
    if (ag) {
        currentForm.value = {
            name: ag.name,
            base_url: ag.base_url,
            model: ag.model,
            api_key: '',
            enabled: ag.enabled,
            max_concurrency: ag.max_concurrency || 1,
            saving: false,
            errorMessage: '',
        };
    }
});

const readyCount = computed(() => {
    return primaryAgents.value.filter(a => a.enabled && a.status === 'ready').length;
});

const activeCount = computed(() => {
    return primaryAgents.value.filter(a => a.enabled).length;
});

async function pollStatus() {
    try {
        const res = await fetch('/settings/ai-agents/status', {
            headers: { 'Accept': 'application/json' }
        });
        if (res.ok) {
            const data = await res.json();
            if (data.agents) {
                data.agents.forEach((srv: any) => {
                    const local = localAgents.value.find(a => a.slot === srv.slot);
                    if (local) {
                        local.status = srv.status;
                        local.test_message = srv.test_message;
                        local.vision_tested_at = srv.vision_tested_at;
                        local.enabled = srv.enabled;
                        local.name = srv.name;
                        local.model = srv.model;
                        if (srv.id) local.id = srv.id;
                    }
                });
            }
            if (!data.any_testing) {
                stopPolling();
                testingAll.value = false;
                testingSlot.value = null;
            }
        }
    } catch {
        // ignore network hiccup during poll
    }
}

function startPolling() {
    if (pollTimer.value) clearInterval(pollTimer.value);
    pollTimer.value = window.setInterval(pollStatus, 2500);
}

function stopPolling() {
    if (pollTimer.value) {
        clearInterval(pollTimer.value);
        pollTimer.value = null;
    }
}

onUnmounted(() => {
    stopPolling();
});

function saveActiveAgent() {
    currentForm.value.saving = true;
    currentForm.value.errorMessage = '';
    router.post(`/settings/ai-agents/${activeSlot.value}`, {
        name: currentForm.value.name,
        base_url: currentForm.value.base_url,
        model: currentForm.value.model,
        api_key: currentForm.value.api_key || undefined,
        enabled: currentForm.value.enabled,
        max_concurrency: currentForm.value.max_concurrency,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            currentForm.value.saving = false;
            currentForm.value.api_key = '';
            router.reload({ only: ['ai_agents', 'ai'] });
        },
        onError: (errs) => {
            currentForm.value.saving = false;
            currentForm.value.errorMessage = (Object.values(errs)[0] as string) || 'Gagal menyimpan konfigurasi agen.';
        }
    });
}

async function testSingleAgent() {
    const ag = currentAgent.value;
    if (!ag || !ag.id) {
        alert('Simpan konfigurasi agen terlebih dahulu sebelum melakukan uji pembacaan gambar.');
        return;
    }
    testingSlot.value = ag.slot;
    ag.status = 'testing';
    ag.test_message = 'Sedang menguji koneksi dan pembacaan gambar...';

    const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';
    try {
        await fetch(`/settings/ai-agents/${ag.id}/test`, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
            }
        });
        startPolling();
    } catch {
        testingSlot.value = null;
    }
}

async function testAllAgents() {
    testingAll.value = true;
    localAgents.value.forEach(a => {
        if (a.enabled && (a.has_api_key || a.id)) {
            a.status = 'testing';
            a.test_message = 'Sedang menguji koneksi dan pembacaan gambar...';
        }
    });
    const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';
    try {
        await fetch('/settings/ai-agents/test-all', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
            }
        });
        startPolling();
    } catch {
        testingAll.value = false;
    }
}

function copyFromSlot1() {
    router.post('/settings/ai-agents/copy', {
        from_slot: 1,
        to_slot: activeSlot.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            copySuccess.value = true;
            setTimeout(() => (copySuccess.value = false), 2500);
            router.reload({ only: ['ai_agents'] });
        }
    });
}

// AI Model Presets State
const localPresets = ref<AiModelPreset[]>(
    props.ai_model_presets ? JSON.parse(JSON.stringify(props.ai_model_presets)) : []
);
watch(() => props.ai_model_presets, (newPresets) => {
    if (newPresets) {
        localPresets.value = JSON.parse(JSON.stringify(newPresets));
    }
}, { deep: true });

const showSavePresetModal = ref(false);
const showAddCustomPresetModal = ref(false);
const presetBusy = ref(false);

const presetForm = ref({
    name: '',
    base_url: 'https://api.9router.com/v1',
    model: 'gemini-2.5-flash',
    api_key: '',
    notes: '',
    from_slot: null as number | null,
    saving: false,
    errorMessage: '',
});

function openSaveCurrentSlotModal() {
    const ag = currentAgent.value;
    let host = 'API';
    try {
        host = new URL(ag.base_url).hostname || ag.base_url;
    } catch {
        host = ag.base_url;
    }
    presetForm.value = {
        name: `${ag.model} (${host})`,
        base_url: ag.base_url,
        model: ag.model,
        api_key: '',
        notes: `Disimpan dari Slot ${activeSlot.value} (${ag.name})`,
        from_slot: activeSlot.value,
        saving: false,
        errorMessage: '',
    };
    showSavePresetModal.value = true;
}

function openAddCustomPresetModal() {
    presetForm.value = {
        name: '',
        base_url: 'https://api.9router.com/v1',
        model: 'gemini-2.5-flash',
        api_key: '',
        notes: '',
        from_slot: null,
        saving: false,
        errorMessage: '',
    };
    showAddCustomPresetModal.value = true;
}

function submitSavePreset() {
    presetForm.value.saving = true;
    presetForm.value.errorMessage = '';
    const payload: Record<string, any> = {
        name: presetForm.value.name,
        notes: presetForm.value.notes,
    };
    if (presetForm.value.from_slot) {
        payload.from_slot = presetForm.value.from_slot;
    } else {
        payload.base_url = presetForm.value.base_url;
        payload.model = presetForm.value.model;
        payload.api_key = presetForm.value.api_key;
    }

    router.post('/settings/ai-model-presets', payload, {
        preserveScroll: true,
        onSuccess: () => {
            presetForm.value.saving = false;
            showSavePresetModal.value = false;
            showAddCustomPresetModal.value = false;
            router.reload({ only: ['ai_model_presets'] });
        },
        onError: (errs) => {
            presetForm.value.saving = false;
            presetForm.value.errorMessage = (Object.values(errs)[0] as string) || 'Gagal menyimpan profil preset.';
        }
    });
}

function applyPreset(presetId: number, targetSlots: number[]) {
    presetBusy.value = true;
    const preset = localPresets.value.find(p => p.id === presetId);
    if (preset && targetSlots.includes(activeSlot.value)) {
        currentForm.value.base_url = preset.base_url;
        currentForm.value.model = preset.model;
        currentForm.value.api_key = '';
    }

    router.post(`/settings/ai-model-presets/${presetId}/apply`, {
        slots: targetSlots
    }, {
        preserveScroll: true,
        onSuccess: () => {
            presetBusy.value = false;
            router.reload({
                only: ['ai_agents', 'ai', 'ai_model_presets'],
                onSuccess: (page) => {
                    const freshAgents = (page.props.ai_agents as AiAgentItem[]) || [];
                    const activeAgent = freshAgents.find(a => a.slot === activeSlot.value);
                    if (activeAgent) {
                        currentForm.value.name = activeAgent.name;
                        currentForm.value.base_url = activeAgent.base_url;
                        currentForm.value.model = activeAgent.model;
                        currentForm.value.enabled = activeAgent.enabled;
                        currentForm.value.max_concurrency = activeAgent.max_concurrency || 1;
                        currentForm.value.api_key = '';
                    }
                }
            });
        },
        onFinish: () => {
            presetBusy.value = false;
        }
    });
}

function onSelectPresetToCurrentSlot(event: Event) {
    const target = event.target as HTMLSelectElement;
    const presetId = Number(target.value);
    if (!presetId) return;
    const preset = localPresets.value.find(p => p.id === presetId);
    if (preset) {
        currentForm.value.base_url = preset.base_url;
        currentForm.value.model = preset.model;
        currentForm.value.api_key = '';
    }
    applyPreset(presetId, [activeSlot.value]);
    target.value = '';
}

function onApplyToCustomSlot(presetId: number, event: Event) {
    const target = event.target as HTMLSelectElement;
    const slot = Number(target.value);
    if (!slot) return;
    applyPreset(presetId, [slot]);
    target.value = '';
}

function deletePreset(preset: AiModelPreset) {
    if (!confirm(`Hapus profil model "${preset.name}"?`)) return;
    router.delete(`/settings/ai-model-presets/${preset.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            router.reload({ only: ['ai_model_presets'] });
        }
    });
}

function formatDiskSpace(mb: number): string {
    if (!mb && mb !== 0) return '0 MB';
    if (mb >= 1000) {
        const gb = mb / 1024;
        return `${gb.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} GB`;
    }
    return `${mb.toLocaleString('id-ID')} MB`;
}

// Telemetry & Cost Monitoring State
const currentTelemetry = ref<AiTelemetryData | null>(props.telemetry ?? null);
const activeRange = ref<'24h' | '3d' | '7d' | '30d' | 'all'>(props.telemetry?.range ?? '7d');
const activeMetric = ref<'requests' | 'tokens' | 'cost' | 'latency' | 'failures'>('requests');
const loadingTelemetry = ref(false);
const hoveredPointIndex = ref<number | null>(null);

async function fetchTelemetry(range: '24h' | '3d' | '7d' | '30d' | 'all') {
    activeRange.value = range;
    loadingTelemetry.value = true;
    hoveredPointIndex.value = null;
    try {
        const res = await fetch(`/settings/ai-telemetry?range=${range}`, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        if (res.ok) {
            currentTelemetry.value = await res.json();
        }
    } catch (e) {
        console.error('Gagal memuat telemetri AI', e);
    } finally {
        loadingTelemetry.value = false;
    }
}

// Chart configuration
const chartMetrics: Record<string, {
    label: string;
    unit: string;
    color: string;
    fillColor: string;
    getValue: (p: AiTelemetryTimelinePoint) => number;
    formatValue: (v: number) => string;
}> = {
    requests: {
        label: 'Permintaan AI',
        unit: 'req',
        color: '#009B77',
        fillColor: 'rgba(0, 155, 119, 0.16)',
        getValue: (p) => p.requests,
        formatValue: (v) => `${formatNumber(v)} req`,
    },
    tokens: {
        label: 'Konsumsi Token',
        unit: 'token',
        color: '#4f46e5',
        fillColor: 'rgba(79, 70, 229, 0.14)',
        getValue: (p) => p.tokens,
        formatValue: (v) => formatTokens(v),
    },
    cost: {
        label: 'Perkiraan Biaya (IDR)',
        unit: 'IDR',
        color: '#d97706',
        fillColor: 'rgba(217, 119, 6, 0.14)',
        getValue: (p) => p.cost_idr,
        formatValue: (v) => formatIdr(v),
    },
    latency: {
        label: 'Rata-rata Latensi',
        unit: 'dtk',
        color: '#0284c7',
        fillColor: 'rgba(2, 132, 199, 0.14)',
        getValue: (p) => (p.avg_duration_ms / 1000),
        formatValue: (v) => `${v.toFixed(1)} dtk`,
    },
    failures: {
        label: 'Permintaan Gagal',
        unit: 'gagal',
        color: '#e11d48',
        fillColor: 'rgba(225, 29, 72, 0.14)',
        getValue: (p) => p.failures,
        formatValue: (v) => `${formatNumber(v)} gagal`,
    },
};

const currentMetricConfig = computed(() => chartMetrics[activeMetric.value]);

// SVG dimensions
const svgWidth = 840;
const svgHeight = 260;
const chartPad = { top: 25, right: 35, bottom: 40, left: 60 };
const chartW = svgWidth - chartPad.left - chartPad.right;
const chartH = svgHeight - chartPad.top - chartPad.bottom;

const chartPoints = computed(() => {
    const timeline = currentTelemetry.value?.timeline ?? [];
    if (!timeline.length) return [];

    const getter = currentMetricConfig.value.getValue;
    const values = timeline.map(getter);
    const maxVal = Math.max(...values, activeMetric.value === 'latency' ? 5 : (activeMetric.value === 'failures' ? 3 : 1));
    const minVal = 0;
    const range = maxVal - minVal || 1;

    return timeline.map((pt, i) => {
        const val = getter(pt);
        const x = chartPad.left + (timeline.length === 1 ? chartW / 2 : (i / (timeline.length - 1)) * chartW);
        const y = chartPad.top + chartH - ((val - minVal) / range) * chartH;
        return {
            x: Math.round(x * 10) / 10,
            y: Math.round(y * 10) / 10,
            val,
            point: pt,
            index: i,
        };
    });
});

const chartMaxY = computed(() => {
    const timeline = currentTelemetry.value?.timeline ?? [];
    if (!timeline.length) return 1;
    const getter = currentMetricConfig.value.getValue;
    const values = timeline.map(getter);
    return Math.max(...values, activeMetric.value === 'latency' ? 5 : (activeMetric.value === 'failures' ? 3 : 1));
});

const chartYLabels = computed(() => {
    const max = chartMaxY.value;
    const steps = 4;
    const labels = [];
    for (let i = 0; i <= steps; i++) {
        const val = (max / steps) * i;
        const y = chartPad.top + chartH - (i / steps) * chartH;
        let formatted = '';
        if (activeMetric.value === 'cost') {
            formatted = val >= 1000 ? `${Math.round(val / 1000)}k` : `${Math.round(val)}`;
        } else if (activeMetric.value === 'tokens') {
            formatted = val >= 1_000_000 ? `${(val / 1_000_000).toFixed(1)}M` : (val >= 1000 ? `${Math.round(val / 1000)}k` : `${Math.round(val)}`);
        } else if (activeMetric.value === 'latency') {
            formatted = `${val.toFixed(1)}s`;
        } else {
            formatted = val >= 1000 ? `${(val / 1000).toFixed(1)}k` : `${Math.round(val)}`;
        }
        labels.push({ val, y, formatted });
    }
    return labels;
});

const chartPaths = computed(() => {
    const pts = chartPoints.value;
    if (!pts.length) return { line: '', area: '' };
    if (pts.length === 1) {
        const p = pts[0];
        const line = `M ${p.x - 30} ${p.y} L ${p.x + 30} ${p.y}`;
        const area = `M ${p.x - 30} ${chartPad.top + chartH} L ${p.x - 30} ${p.y} L ${p.x + 30} ${p.y} L ${p.x + 30} ${chartPad.top + chartH} Z`;
        return { line, area };
    }

    let line = `M ${pts[0].x} ${pts[0].y}`;
    for (let i = 1; i < pts.length; i++) {
        const prev = pts[i - 1];
        const curr = pts[i];
        const cx1 = prev.x + (curr.x - prev.x) / 2;
        const cy1 = prev.y;
        const cx2 = prev.x + (curr.x - prev.x) / 2;
        const cy2 = curr.y;
        line += ` C ${cx1.toFixed(1)} ${cy1.toFixed(1)}, ${cx2.toFixed(1)} ${cy2.toFixed(1)}, ${curr.x.toFixed(1)} ${curr.y.toFixed(1)}`;
    }

    const first = pts[0];
    const last = pts[pts.length - 1];
    const baselineY = chartPad.top + chartH;
    const area = `${line} L ${last.x} ${baselineY} L ${first.x} ${baselineY} Z`;

    return { line, area };
});

const activeHoveredPoint = computed(() => {
    if (hoveredPointIndex.value === null) return null;
    return chartPoints.value[hoveredPointIndex.value] ?? null;
});

function formatNumber(num: number | undefined | null): string {
    return (num ?? 0).toLocaleString('id-ID');
}

function formatTokens(tokens: number | undefined | null): string {
    if (!tokens) return '0 Token';
    if (tokens >= 1_000_000) {
        return (tokens / 1_000_000).toFixed(2) + ' Jt';
    }
    if (tokens >= 1_000) {
        return (tokens / 1_000).toFixed(1) + ' Rb';
    }
    return tokens.toLocaleString('id-ID');
}

function formatIdr(idr: number | undefined | null): string {
    return 'Rp ' + Math.round(idr ?? 0).toLocaleString('id-ID');
}

function formatUsd(usd: number | undefined | null): string {
    return '$' + (usd ?? 0).toFixed(4);
}

function formatDuration(ms: number | undefined | null): string {
    if (!ms) return '0 dtk';
    if (ms < 1000) return `${ms} ms`;
    return `${(ms / 1000).toFixed(1)} dtk`;
}
</script>
<template>
    <Head title="Pengaturan" /><AppLayout
        ><div class="page-heading">
            <h1>Pengaturan ruang kerja</h1>
            <p>Akun pegawai, koneksi AI, dan kesehatan pemeriksaan.</p>
        </div>
        <nav aria-label="Bagian pengaturan">
            <!-- Desktop Tabs -->
            <div class="settings-tabs-desktop hidden sm:flex">
                <button
                    v-for="(text, key) in {
                        users: 'Pegawai',
                        ai: 'Koneksi AI',
                        telemetry: 'Pemantauan & Biaya AI',
                        system: 'Sistem',
                    }"
                    :key="key"
                    :class="['settings-tab-btn', { selected: tab === key }]"
                    @click="tab = key"
                >
                    {{ text }}
                </button>
            </div>

            <!-- Mobile 2x2 Segmented Grid -->
            <div class="grid grid-cols-2 gap-2 sm:hidden mb-6">
                <button
                    type="button"
                    class="mobile-tab-btn"
                    :class="{ active: tab === 'users' }"
                    @click="tab = 'users'"
                >
                    <span class="mobile-tab-icon">👥</span>
                    <span class="mobile-tab-text">Pegawai</span>
                    <span class="mobile-tab-badge" v-if="users?.length">{{ users.length }}</span>
                </button>
                <button
                    type="button"
                    class="mobile-tab-btn"
                    :class="{ active: tab === 'ai' }"
                    @click="tab = 'ai'"
                >
                    <span class="mobile-tab-icon">🤖</span>
                    <span class="mobile-tab-text">Koneksi AI</span>
                    <span class="mobile-tab-badge">6 Slot</span>
                </button>
                <button
                    type="button"
                    class="mobile-tab-btn"
                    :class="{ active: tab === 'telemetry' }"
                    @click="tab = 'telemetry'"
                >
                    <span class="mobile-tab-icon">📊</span>
                    <span class="mobile-tab-text">Telemetri</span>
                    <span class="mobile-tab-badge" style="background: #badfdb; color: #1e3a36;">Live</span>
                </button>
                <button
                    type="button"
                    class="mobile-tab-btn"
                    :class="{ active: tab === 'system' }"
                    @click="tab = 'system'"
                >
                    <span class="mobile-tab-icon">⚙️</span>
                    <span class="mobile-tab-text">Sistem</span>
                    <span class="mobile-tab-badge" :style="health?.failed > 0 ? 'background: #fed7aa; color: #9a3412;' : 'background: #e2e8f0; color: #334155;'">OK</span>
                </button>
            </div>
        </nav>
        <section v-if="tab === 'users'" class="settings-panel">
            <div class="section-heading">
                <div>
                    <h2>Akun pegawai</h2>
                    <p>
                        Admin membuat dan mengelola akun; tidak ada pendaftaran
                        mandiri.
                    </p>
                </div>
                <button class="button primary" @click="edit()">
                    <Plus :size="18" />Tambah pegawai
                </button>
            </div>
            <form
                v-if="userOpen"
                class="employee-form form-stack"
                @submit.prevent="saveUser"
            >
                <h3>{{ editing ? "Edit akun" : "Akun baru" }}</h3>
                <div class="form-grid">
                    <div>
                        <label for="user-name">Nama lengkap</label
                        ><input
                            id="user-name"
                            v-model="employee.name"
                            required
                            autocomplete="name"
                        />
                    </div>
                    <div>
                        <label for="user-email">Email</label
                        ><input
                            id="user-email"
                            type="email"
                            v-model="employee.email"
                            required
                            autocomplete="email"
                        />
                    </div>
                </div>
                <div class="form-grid">
                    <div>
                        <label for="user-role">Peran</label
                        ><select id="user-role" v-model="employee.role">
                            <option value="examiner">Pemeriksa</option>
                            <option value="reviewer">Reviewer</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div>
                        <label for="user-pass">{{
                            editing
                                ? "Password baru (opsional)"
                                : "Password awal"
                        }}</label
                        ><input
                            id="user-pass"
                            type="password"
                            v-model="employee.password"
                            :required="!editing"
                            minlength="12"
                            autocomplete="new-password"
                        /><small
                            >Minimal 12 karakter, huruf besar-kecil dan
                            angka.</small
                        >
                    </div>
                </div>
                <label v-if="editing" class="checkbox"
                    ><input type="checkbox" v-model="employee.is_active" />Akun
                    aktif</label
                >
                <div class="form-actions">
                    <button
                        type="button"
                        class="button secondary"
                        @click="
                            userOpen = false;
                            employee.reset('password');
                        "
                    >
                        Batal</button
                    ><button
                        class="button primary"
                        :disabled="employee.processing"
                    >
                        <Save :size="17" />Simpan akun
                    </button>
                </div>
            </form>
            <div class="employee-list">
                <article v-for="u in users" :key="u.id">
                    <span class="avatar">{{ u.name.slice(0, 1) }}</span>
                    <div>
                        <strong>{{ u.name }}</strong
                        ><small>{{ u.email }}</small>
                    </div>
                    <span class="badge">{{
                        u.role === "examiner"
                            ? "Pemeriksa"
                            : u.role === "reviewer"
                              ? "Reviewer"
                              : "Admin"
                    }}</span
                    ><span
                        :class="['badge', u.is_active ? 'complete' : 'failed']"
                        >{{ u.is_active ? "Aktif" : "Nonaktif" }}</span
                    ><button class="button secondary" @click="edit(u)">
                        Edit akun
                    </button>
                </article>
            </div>
        </section>
        <section v-if="tab === 'ai'" class="settings-panel ai-settings-multi" style="display: flex; flex-direction: column; gap: 20px;">
            <div class="agent-pool-header">
                <div>
                    <h2>Pool Agen AI Paralel (Hingga 5 Agen)</h2>
                    <p style="margin-top: 6px; color: var(--muted); font-size: 13px;">
                        Setiap agen dapat memiliki Base URL, API Key, Model, dan kapasitas konkuren masing-masing.
                        Semua agen yang aktif <strong>wajib lulus uji pembacaan gambar visual</strong> sebelum digunakan dalam pemeriksaan.
                    </p>
                </div>
                <div class="inline-actions">
                    <span :class="['badge', readyCount === activeCount && activeCount > 0 ? 'complete' : 'needs_verification']">
                        {{ readyCount }} dari {{ activeCount }} Agen Aktif Siap
                    </span>
                    <button
                        type="button"
                        class="button primary"
                        @click="testAllAgents"
                        :disabled="testingAll || testingSlot !== null || activeCount === 0"
                    >
                        <FlaskConical :size="17" />
                        {{ testingAll ? "Menguji Semua Agen..." : "Uji Semua Agent Aktif" }}
                    </button>
                </div>
            </div>

            <!-- Slot Selector Pills -->
            <div class="agent-slot-selector" role="tablist" aria-label="Slot Agen AI">
                <button
                    v-for="ag in primaryAgents"
                    :key="ag.slot"
                    type="button"
                    :class="['agent-slot-btn', { active: activeSlot === ag.slot }]"
                    @click="activeSlot = ag.slot"
                >
                    <div class="agent-slot-title-group">
                        <span :class="['agent-slot-dot', ag.status]"></span>
                        <strong>
                            <span class="hidden sm:inline">Slot {{ ag.slot }}: {{ ag.name }}</span>
                            <span class="inline sm:hidden">Slot {{ ag.slot }}</span>
                        </strong>
                    </div>
                    <span :class="['badge', ag.enabled ? 'complete' : 'neutral']" style="padding: 2px 7px; font-size: 10px;">
                        {{ ag.enabled ? 'ON' : 'OFF' }}
                    </span>
                </button>

                <!-- Dedicated Agent Cadangan (Standby) Pill -->
                <button
                    v-if="backupAgentItem"
                    type="button"
                    :class="['agent-slot-btn', 'agent-backup-btn', { active: activeSlot === 6 }]"
                    @click="activeSlot = 6"
                >
                    <div class="agent-slot-title-group">
                        <span :class="['agent-slot-dot', backupAgentItem.status]"></span>
                        <strong>
                            <span class="hidden sm:inline">🛡️ {{ backupAgentItem.name }}</span>
                            <span class="inline sm:hidden">🛡️ Cadangan</span>
                        </strong>
                    </div>
                    <span :class="['badge', backupAgentItem.enabled ? 'complete' : 'neutral']" style="padding: 2px 5px; font-size: 9.5px;">
                        <span class="hidden sm:inline">{{ backupAgentItem.enabled ? 'STANDBY' : 'OFF' }}</span>
                        <span class="inline sm:hidden">{{ backupAgentItem.enabled ? 'STBY' : 'OFF' }}</span>
                    </span>
                </button>
            </div>

            <!-- Active Slot Editor Card -->
            <div class="agent-card-editor">
                <div class="agent-card-header">
                    <div>
                        <h3 style="margin: 0; font-size: 18px;">
                            {{ activeSlot === 6 ? '🛡️ ' : '' }}Pengaturan {{ activeSlot === 6 ? 'Agent Cadangan (Standby)' : `Slot ${activeSlot}: ${currentAgent.name}` }}
                        </h3>
                        <small style="color: #666;">
                            {{ activeSlot === 6 ? 'Agen darurat jika terjadi filter recitation / safety pada agen utama' : `Kapasitas: ${currentForm.max_concurrency} halaman paralel` }}
                        </small>
                    </div>

                    <div class="inline-actions">
                        <label class="toggle-switch">
                            <input type="checkbox" v-model="currentForm.enabled" />
                            <span>{{ currentForm.enabled ? (activeSlot === 6 ? "Standby Siaga" : "Agen Aktif") : "Agen Dinonaktifkan" }}</span>
                        </label>

                        <button
                            v-if="activeSlot > 1"
                            type="button"
                            class="button secondary"
                            @click="copyFromSlot1"
                            title="Salin Base URL, Model, dan API Key dari Slot 1"
                        >
                            <Copy :size="15" />
                            {{ copySuccess ? "Tersalin!" : "Salin dari Agent 1" }}
                        </button>
                    </div>
                </div>

                <!-- Emergency Fallback Explanatory Banner for Slot 6 -->
                <div v-if="activeSlot === 6" class="finding-agreement-banner" style="background: #eef2ff; color: #3730a3; border: 1px solid #c7d2fe; margin-bottom: 16px;">
                    <ShieldCheck :size="18" style="flex-shrink: 0;" />
                    <span>
                        <strong>Peran Agen Cadangan:</strong> Agen ini dalam posisi <em>standby</em> (tidak mengambil tugas antrean rutin) dan akan <strong>otomatis aktif seketika</strong> sebagai *fallback* darurat jika salah satu agen utama terhadang filter pembatasan AI (misalnya <code>finish_reason: recitation</code> dari Google Gemini). Disarankan menggunakan model seperti <strong>claude-sonnet-4-6</strong> atau model non-Gemini lainnya.
                    </span>
                </div>

                <!-- Status Banner -->
                <div v-if="currentAgent.status === 'ready'" class="finding-agreement-banner confirmed">
                    <Check :size="16" />
                    <span>Lulus uji pembacaan gambar (Terakhir diuji: {{ currentAgent.vision_tested_at ? new Date(currentAgent.vision_tested_at).toLocaleString('id-ID') : 'Baru saja' }})</span>
                </div>
                <div v-else-if="currentAgent.status === 'testing'" class="finding-agreement-banner single-agent">
                    <RefreshCw :size="16" class="animate-spin" />
                    <span>Sedang menguji koneksi dan pembacaan visual PDF di background antrean...</span>
                </div>
                <div v-else-if="currentAgent.status === 'failed'" class="finding-agreement-banner" style="background: #f8d7da; color: #842029; border: 1px solid #f5c2c7;">
                    <AlertCircle :size="16" />
                    <span>{{ currentAgent.test_message || 'Uji gambar gagal. Periksa Base URL, API Key, dan model vision.' }}</span>
                </div>
                <div v-else class="finding-agreement-banner" style="background: #fff3cd; color: #664d03; border: 1px solid #ffecb5;">
                    <AlertCircle :size="16" />
                    <span>Agen ini belum diuji. Simpan konfigurasi lalu klik <strong>Uji Gambar Agen Ini</strong>.</span>
                </div>

                <p v-if="currentForm.errorMessage" class="notice warning">
                    {{ currentForm.errorMessage }}
                </p>

                <!-- Preset Quick Load Bar -->
                <div class="preset-slot-bar">
                    <div class="preset-slot-select-wrap">
                        <Bookmark :size="16" class="preset-bar-icon" />
                        <select
                            class="preset-slot-select"
                            @change="onSelectPresetToCurrentSlot($event)"
                            :disabled="presetBusy"
                        >
                            <option value="">📂 Muat dari Profil Model Tersimpan...</option>
                            <option v-for="p in localPresets" :key="p.id" :value="p.id">
                                {{ p.name }} ({{ p.model }})
                            </option>
                        </select>
                    </div>
                    <button
                        type="button"
                        class="button secondary btn-save-preset"
                        @click="openSaveCurrentSlotModal"
                        :disabled="!currentAgent.has_api_key && !currentForm.api_key"
                        title="Simpan konfigurasi model & API key pada slot ini sebagai profil baru"
                    >
                        <BookmarkCheck :size="15" />
                        Simpan Slot Ini sebagai Profil Baru
                    </button>
                </div>

                <!-- Form Simpan Slot Sebagai Profil Baru (Inline) -->
                <div v-if="showSavePresetModal" class="preset-inline-modal">
                    <div class="preset-modal-header">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <BookmarkCheck :size="18" style="color: #305e58;" />
                            <strong style="font-size: 14px;">Simpan Slot {{ activeSlot }} sebagai Profil Model Tersimpan</strong>
                        </div>
                        <button type="button" class="icon-button" @click="showSavePresetModal = false">
                            <X :size="16" />
                        </button>
                    </div>
                    <p style="font-size: 12px; color: var(--muted); margin: 6px 0 12px;">
                        Kombinasi Base URL (<code>{{ currentForm.base_url }}</code>), Model (<code>{{ currentForm.model }}</code>), dan API Key dari slot ini akan disimpan aman.
                    </p>
                    <div class="form-stack">
                        <div>
                            <label for="save-preset-name">Nama Profil</label>
                            <input
                                id="save-preset-name"
                                v-model="presetForm.name"
                                required
                                placeholder="Contoh: 9Router - Gemini 2.5 Flash"
                            />
                        </div>
                        <div>
                            <label for="save-preset-notes">Catatan (Opsional)</label>
                            <input
                                id="save-preset-notes"
                                v-model="presetForm.notes"
                                placeholder="Contoh: Akun pribadi kuota 100k token/hari"
                            />
                        </div>
                        <p v-if="presetForm.errorMessage" class="notice warning">
                            {{ presetForm.errorMessage }}
                        </p>
                        <div class="form-actions" style="margin-top: 10px;">
                            <button
                                type="button"
                                class="button secondary btn-sm"
                                @click="showSavePresetModal = false"
                            >
                                Batal
                            </button>
                            <button
                                type="button"
                                class="button primary btn-sm"
                                :disabled="presetForm.saving || !presetForm.name.trim()"
                                @click="submitSavePreset"
                            >
                                <Save :size="15" />
                                {{ presetForm.saving ? 'Menyimpan...' : 'Simpan Profil' }}
                            </button>
                        </div>
                    </div>
                </div>

                <form class="form-stack" @submit.prevent="saveActiveAgent">
                    <div class="form-grid">
                        <div>
                            <label for="agent-name">Nama Agen</label>
                            <input
                                id="agent-name"
                                v-model="currentForm.name"
                                required
                                placeholder="Contoh: Agent 1 (Utama)"
                            />
                        </div>
                        <div>
                            <label for="agent-concurrency">Maksimum Permintaan Bersamaan (Concurrency)</label>
                            <select id="agent-concurrency" v-model.number="currentForm.max_concurrency">
                                <option :value="1">1 permintaan paralel (Aman & Stabil)</option>
                                <option :value="2">2 permintaan paralel</option>
                                <option :value="3">3 permintaan paralel</option>
                                <option :value="4">4 permintaan paralel</option>
                                <option :value="5">5 permintaan paralel (Beban Tinggi)</option>
                            </select>
                        </div>
                    </div>

                    <label for="agent-base-url">Base URL</label>
                    <input
                        id="agent-base-url"
                        type="url"
                        v-model="currentForm.base_url"
                        required
                        placeholder="https://api.9router.com/v1"
                    />
                    <div class="preset-chips">
                        <span class="preset-label">Preset cepat:</span>
                        <button type="button" class="preset-chip" @click="currentForm.base_url = 'https://api.9router.com/v1'">
                            9Router Cloud
                        </button>
                        <button type="button" class="preset-chip" @click="currentForm.base_url = 'https://api.limitrouter.com/v1'">
                            LimitRouter
                        </button>
                    </div>
                    <p style="font-size: 0.8rem; color: #5a6460; margin-top: 0.35rem; line-height: 1.4;">
                        Untuk 9Router di komputer lokal, aktifkan fitur <strong>Tunnel</strong> di dashboard 9Router (tab Endpoint) dan gunakan URL HTTPS publik Tunnel yang dihasilkan di sini.
                    </p>

                    <label for="agent-model">Model Vision</label>
                    <input
                        id="agent-model"
                        v-model="currentForm.model"
                        required
                        :placeholder="activeSlot === 6 ? 'Contoh: claude-sonnet-4-6 atau gpt-4o' : 'Contoh: gemini-2.5-flash atau gpt-4o-mini'"
                    />

                    <label for="agent-api-key">
                        API Key
                        <span v-if="currentAgent.has_api_key" style="color: #198754; font-weight: normal;">
                            (Tersimpan terenkripsi. Kosongkan jika tidak ingin mengubah)
                        </span>
                        <span v-else style="color: #dc3545; font-weight: normal;">
                            (Wajib diisi pada konfigurasi awal)
                        </span>
                    </label>
                    <input
                        id="agent-api-key"
                        type="password"
                        v-model="currentForm.api_key"
                        :required="!currentAgent.has_api_key"
                        placeholder="sk-..."
                        autocomplete="off"
                    />

                    <div class="form-actions" style="margin-top: 18px;">
                        <button
                            type="button"
                            class="button secondary"
                            @click="testSingleAgent"
                            :disabled="!currentAgent.id || currentAgent.status === 'testing' || currentForm.saving"
                        >
                            <FlaskConical :size="17" />
                            {{ currentAgent.status === 'testing' ? 'Menguji...' : 'Uji Gambar Agen Ini' }}
                        </button>

                        <button
                            type="submit"
                            class="button primary"
                            :disabled="currentForm.saving || currentAgent.status === 'testing'"
                        >
                            <Save :size="17" />
                            {{ currentForm.saving ? 'Menyimpan...' : `Simpan Konfigurasi Slot ${activeSlot}` }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- KATALOG PROFIL MODEL TERSIMPAN (SAVED AI MODEL PRESETS) -->
            <div class="presets-catalog-card">
                <div class="presets-catalog-header">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <Bookmark :size="20" style="color: #305e58;" />
                            <h3 style="margin: 0; font-size: 18px;">Katalog Profil Model Tersimpan (Saved Presets)</h3>
                        </div>
                        <p style="margin: 4px 0 0; font-size: 12.5px; color: var(--muted);">
                            Simpan berbagai kombinasi Provider (9Router, LimitRouter, OpenRouter, dll.), Model AI, dan API Key. Tinggal 1 klik untuk memuat ke Agent 1 atau ke semua agen tanpa perlu repot mengetik ulang.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="button primary"
                        @click="openAddCustomPresetModal"
                    >
                        <Plus :size="16" />
                        Tambah Profil Baru
                    </button>
                </div>

                <!-- Form Tambah Profil Baru Manual (Inline) -->
                <div v-if="showAddCustomPresetModal" class="preset-inline-modal" style="margin-bottom: 18px;">
                    <div class="preset-modal-header">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <Bookmark :size="18" style="color: #305e58;" />
                            <strong style="font-size: 14px;">Tambah Profil Model AI Baru</strong>
                        </div>
                        <button type="button" class="icon-button" @click="showAddCustomPresetModal = false">
                            <X :size="16" />
                        </button>
                    </div>
                    <div class="form-stack">
                        <div class="form-grid">
                            <div>
                                <label for="custom-preset-name">Nama Profil</label>
                                <input
                                    id="custom-preset-name"
                                    v-model="presetForm.name"
                                    required
                                    placeholder="Contoh: LimitRouter - Claude 3.5 Sonnet"
                                />
                            </div>
                            <div>
                                <label for="custom-preset-model">Model Vision</label>
                                <input
                                    id="custom-preset-model"
                                    v-model="presetForm.model"
                                    required
                                    placeholder="Contoh: gemini-2.5-flash atau claude-sonnet-4-6"
                                />
                            </div>
                        </div>
                        <div>
                            <label for="custom-preset-base-url">Base URL</label>
                            <input
                                id="custom-preset-base-url"
                                v-model="presetForm.base_url"
                                required
                                placeholder="https://api.9router.com/v1"
                            />
                        </div>
                        <div>
                            <label for="custom-preset-api-key">API Key</label>
                            <input
                                id="custom-preset-api-key"
                                type="password"
                                v-model="presetForm.api_key"
                                required
                                placeholder="sk-..."
                                autocomplete="off"
                            />
                        </div>
                        <div>
                            <label for="custom-preset-notes">Catatan (Opsional)</label>
                            <input
                                id="custom-preset-notes"
                                v-model="presetForm.notes"
                                placeholder="Contoh: Akun cadangan kantor / kuota 50 RPM"
                            />
                        </div>

                        <p v-if="presetForm.errorMessage" class="notice warning">
                            {{ presetForm.errorMessage }}
                        </p>

                        <div class="form-actions" style="margin-top: 10px;">
                            <button
                                type="button"
                                class="button secondary btn-sm"
                                @click="showAddCustomPresetModal = false"
                            >
                                Batal
                            </button>
                            <button
                                type="button"
                                class="button primary btn-sm"
                                :disabled="presetForm.saving || !presetForm.name.trim() || !presetForm.api_key.trim()"
                                @click="submitSavePreset"
                            >
                                <Save :size="15" />
                                {{ presetForm.saving ? 'Menyimpan...' : 'Simpan ke Katalog' }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- List of Saved Presets Grid -->
                <div v-if="localPresets.length > 0" class="presets-grid">
                    <div v-for="p in localPresets" :key="p.id" class="preset-card">
                        <div class="preset-card-top">
                            <div>
                                <strong class="preset-name">{{ p.name }}</strong>
                                <span class="preset-model-tag">{{ p.model }}</span>
                            </div>
                            <button
                                type="button"
                                class="icon-button btn-del-preset"
                                @click="deletePreset(p)"
                                title="Hapus profil ini"
                            >
                                <Trash2 :size="15" />
                            </button>
                        </div>

                        <div class="preset-meta-rows">
                            <small class="preset-url">
                                <strong>Base URL:</strong> {{ p.base_url }}
                            </small>
                            <small v-if="p.notes" class="preset-notes">
                                {{ p.notes }}
                            </small>
                            <span class="preset-key-status">
                                <CheckCircle2 :size="13" /> API Key tersimpan aman (terenkripsi)
                            </span>
                        </div>

                        <div class="preset-actions-footer">
                            <button
                                type="button"
                                class="button secondary btn-sm"
                                @click="applyPreset(p.id, [1])"
                                :disabled="presetBusy"
                                title="Terapkan ke Agent 1 (Utama)"
                            >
                                Terapkan ke Agent 1
                            </button>
                            <button
                                type="button"
                                class="button primary btn-sm"
                                @click="applyPreset(p.id, [1, 2, 3, 4, 5])"
                                :disabled="presetBusy"
                                title="Terapkan serentak ke Agent 1 sampai 5"
                            >
                                Terapkan ke Semua (1–5)
                            </button>
                            <div class="preset-dropdown-apply">
                                <select @change="onApplyToCustomSlot(p.id, $event)">
                                    <option value="">Slot lain...</option>
                                    <option :value="2">Ke Slot 2</option>
                                    <option :value="3">Ke Slot 3</option>
                                    <option :value="4">Ke Slot 4</option>
                                    <option :value="5">Ke Slot 5</option>
                                    <option :value="6">Ke Slot 6 (Cadangan)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-else class="empty-presets-box">
                    <Bookmark :size="32" style="color: #94a3b8; margin-bottom: 8px;" />
                    <strong>Belum ada profil model tersimpan.</strong>
                    <p style="font-size: 12.5px; margin: 4px 0 12px; color: #64748b;">
                        Anda dapat menyimpan konfigurasi Agent 1 saat ini menjadi profil tersimpan dengan mengklik tombol "Simpan Slot Ini sebagai Profil Baru" di atas, atau klik "+ Tambah Profil Baru".
                    </p>
                </div>
            </div>
        </section>
        <section v-if="tab === 'telemetry'" class="settings-panel telemetry-panel">
            <!-- Header bar with title, range pills, and refresh button -->
            <div class="telemetry-header">
                <div>
                    <div class="telemetry-badge-live">
                        <span class="live-dot"></span>
                        <span>Telemetri Aktivitas & Biaya AI</span>
                    </div>
                    <h2>Pemantauan & Penggunaan AI</h2>
                    <p>
                        Analisis lengkap beban kerja model, konsumsi token, perkiraan biaya (USD & IDR), serta keandalan respons multi-agen.
                    </p>
                </div>
                <div class="telemetry-actions">
                    <!-- Range Selector Pills -->
                    <div class="telemetry-range-group" role="group" aria-label="Rentang Waktu">
                        <button
                            v-for="r in [
                                { id: '24h', label: '24 Jam', short: '24 Jam' },
                                { id: '3d', label: '3 Hari', short: '3 Hari' },
                                { id: '7d', label: '7 Hari', short: '7 Hari' },
                                { id: '30d', label: '30 Hari', short: '30 Hari' },
                                { id: 'all', label: 'Semua', short: 'Semua' }
                            ]"
                            :key="r.id"
                            type="button"
                            class="range-pill-btn"
                            :class="{ active: activeRange === r.id }"
                            :disabled="loadingTelemetry"
                            @click="fetchTelemetry(r.id as any)"
                        >
                            <span class="hidden sm:inline">{{ r.label }}</span>
                            <span class="inline sm:hidden">{{ r.short }}</span>
                        </button>
                    </div>
                    <button
                        type="button"
                        class="button secondary telemetry-refresh-btn"
                        :disabled="loadingTelemetry"
                        @click="fetchTelemetry(activeRange)"
                        title="Muat ulang data telemetri"
                    >
                        <RefreshCw :size="15" :class="{ 'spin-anim': loadingTelemetry }" />
                        <span>{{ loadingTelemetry ? 'Memuat...' : 'Segarkan' }}</span>
                    </button>
                </div>
            </div>

            <!-- 6 KPI Metric Cards Grid -->
            <div class="telemetry-kpi-grid">
                <!-- Card 1: Total Requests -->
                <div class="telemetry-kpi-card">
                    <div class="kpi-card-header">
                        <div class="kpi-icon-wrap emerald">
                            <Activity :size="20" />
                        </div>
                        <span class="kpi-badge emerald" v-if="currentTelemetry?.summary?.success_rate">
                            {{ currentTelemetry.summary.success_rate }}% Sukses
                        </span>
                    </div>
                    <div class="kpi-body">
                        <span class="kpi-label">Total Permintaan AI</span>
                        <strong class="kpi-value">{{ formatNumber(currentTelemetry?.summary?.total_requests) }}</strong>
                        <div class="kpi-subtext">
                            <span class="text-emerald">✓ {{ formatNumber(currentTelemetry?.summary?.success_requests) }} Sukses</span>
                            <span v-if="currentTelemetry?.summary?.failed_requests" class="text-rose">· ✕ {{ formatNumber(currentTelemetry?.summary?.failed_requests) }} Gagal</span>
                        </div>
                    </div>
                </div>

                <!-- Card 2: Token Consumption -->
                <div class="telemetry-kpi-card">
                    <div class="kpi-card-header">
                        <div class="kpi-icon-wrap indigo">
                            <Zap :size="20" />
                        </div>
                        <span class="kpi-badge neutral">
                            {{ formatTokens(currentTelemetry?.summary?.total_tokens) }}
                        </span>
                    </div>
                    <div class="kpi-body">
                        <span class="kpi-label">Total Token Diproses</span>
                        <strong class="kpi-value">{{ formatNumber(currentTelemetry?.summary?.total_tokens) }}</strong>
                        <div class="kpi-subtext">
                            <span>In: {{ formatTokens(currentTelemetry?.summary?.total_prompt_tokens) }}</span>
                            <span>· Out: {{ formatTokens(currentTelemetry?.summary?.total_completion_tokens) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Card 3: Estimated Cost (IDR & USD) -->
                <div class="telemetry-kpi-card highlight-cost">
                    <div class="kpi-card-header">
                        <div class="kpi-icon-wrap amber">
                            <Coins :size="20" />
                        </div>
                        <span class="kpi-badge amber">
                            {{ formatUsd(currentTelemetry?.summary?.estimated_cost_usd) }}
                        </span>
                    </div>
                    <div class="kpi-body">
                        <span class="kpi-label">Perkiraan Biaya (IDR)</span>
                        <strong class="kpi-value cost-value">{{ formatIdr(currentTelemetry?.summary?.estimated_cost_idr) }}</strong>
                        <div class="kpi-subtext">
                            <span>USD {{ formatUsd(currentTelemetry?.summary?.estimated_cost_usd) }} · Kurs Rp 16.000</span>
                        </div>
                    </div>
                </div>

                <!-- Card 4: Avg Tokens Per Req -->
                <div class="telemetry-kpi-card">
                    <div class="kpi-card-header">
                        <div class="kpi-icon-wrap purple">
                            <Layers :size="20" />
                        </div>
                        <span class="kpi-badge neutral">
                            Efisiensi Prompt
                        </span>
                    </div>
                    <div class="kpi-body">
                        <span class="kpi-label">Rata-rata Token / Req</span>
                        <strong class="kpi-value">{{ formatNumber(currentTelemetry?.summary?.avg_tokens_per_request) }}</strong>
                        <div class="kpi-subtext">
                            <span>Beban token rata-rata per panggilan</span>
                        </div>
                    </div>
                </div>

                <!-- Card 5: Avg Latency -->
                <div class="telemetry-kpi-card">
                    <div class="kpi-card-header">
                        <div class="kpi-icon-wrap sky">
                            <Clock :size="20" />
                        </div>
                        <span class="kpi-badge neutral">
                            Kecepatan
                        </span>
                    </div>
                    <div class="kpi-body">
                        <span class="kpi-label">Rata-rata Waktu Respons</span>
                        <strong class="kpi-value">{{ formatDuration(currentTelemetry?.summary?.avg_duration_ms) }}</strong>
                        <div class="kpi-subtext">
                            <span>Durasi inferensi & transfer data AI</span>
                        </div>
                    </div>
                </div>

                <!-- Card 6: Standby / Active Multi-Agent Status -->
                <div class="telemetry-kpi-card">
                    <div class="kpi-card-header">
                        <div class="kpi-icon-wrap ink">
                            <Bot :size="20" />
                        </div>
                        <span class="kpi-badge standby">
                            Slot 6 Standby
                        </span>
                    </div>
                    <div class="kpi-body">
                        <span class="kpi-label">Kapasitas Multi-Agen</span>
                        <strong class="kpi-value">5 Aktif + 1 Cadangan</strong>
                        <div class="kpi-subtext">
                            <span>Batas kuota 25 RPM terlindungi</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Interactive Trend Chart Section -->
            <div class="telemetry-chart-container">
                <div class="chart-header-bar">
                    <div>
                        <h3 class="chart-title">Grafik Tren Aktivitas AI</h3>
                        <span class="chart-subtitle">
                            {{ activeRange === '24h' ? 'Rincian per jam selama 24 jam terakhir' : `Rincian per hari (${activeRange === 'all' ? 'seluruh waktu' : activeRange})` }}
                        </span>
                    </div>
                    <!-- Metric tabs -->
                    <div class="chart-metric-tabs">
                        <button
                            v-for="(cfg, key) in chartMetrics"
                            :key="key"
                            type="button"
                            class="chart-metric-tab"
                            :class="{ active: activeMetric === key }"
                            @click="activeMetric = key as any; hoveredPointIndex = null"
                        >
                            <span class="tab-indicator" :style="{ backgroundColor: cfg.color }"></span>
                            <span>{{ cfg.label }}</span>
                        </button>
                    </div>
                </div>

                <!-- SVG Chart Wrapper -->
                <div class="chart-svg-wrapper">
                    <svg
                        :viewBox="`0 0 ${svgWidth} ${svgHeight}`"
                        class="telemetry-svg"
                        preserveAspectRatio="none"
                        @mouseleave="hoveredPointIndex = null"
                    >
                        <defs>
                            <linearGradient id="chartGradient" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" :stop-color="currentMetricConfig.color" stop-opacity="0.32" />
                                <stop offset="100%" :stop-color="currentMetricConfig.color" stop-opacity="0.01" />
                            </linearGradient>
                        </defs>

                        <!-- Horizontal grid lines & Y labels -->
                        <g class="chart-grid">
                            <line
                                v-for="(lbl, idx) in chartYLabels"
                                :key="idx"
                                :x1="chartPad.left"
                                :y1="lbl.y"
                                :x2="svgWidth - chartPad.right"
                                :y2="lbl.y"
                                stroke="#e5e1d8"
                                stroke-dasharray="3 3"
                                stroke-width="1"
                            />
                            <text
                                v-for="(lbl, idx) in chartYLabels"
                                :key="'t-' + idx"
                                :x="chartPad.left - 10"
                                :y="lbl.y + 4"
                                text-anchor="end"
                                font-size="11"
                                fill="#7c736e"
                                font-family="monospace"
                            >
                                {{ lbl.formatted }}
                            </text>
                        </g>

                        <!-- Area fill -->
                        <path
                            v-if="chartPaths.area"
                            :d="chartPaths.area"
                            fill="url(#chartGradient)"
                        />

                        <!-- Stroke Line -->
                        <path
                            v-if="chartPaths.line"
                            :d="chartPaths.line"
                            fill="none"
                            :stroke="currentMetricConfig.color"
                            stroke-width="2.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />

                        <!-- Data point dots -->
                        <g v-if="chartPoints.length <= 32">
                            <circle
                                v-for="(pt, idx) in chartPoints"
                                :key="idx"
                                :cx="pt.x"
                                :cy="pt.y"
                                :r="hoveredPointIndex === idx ? 6 : 3"
                                :fill="hoveredPointIndex === idx ? '#ffffff' : currentMetricConfig.color"
                                :stroke="currentMetricConfig.color"
                                :stroke-width="hoveredPointIndex === idx ? 3 : 1.5"
                                class="chart-dot"
                            />
                        </g>

                        <!-- Vertical cursor line when hovered -->
                        <line
                            v-if="activeHoveredPoint"
                            :x1="activeHoveredPoint.x"
                            :y1="chartPad.top"
                            :x2="activeHoveredPoint.x"
                            :y2="chartPad.top + chartH"
                            :stroke="currentMetricConfig.color"
                            stroke-width="1.5"
                            stroke-dasharray="4 3"
                            opacity="0.8"
                        />

                        <!-- X Axis labels -->
                        <g class="chart-x-labels">
                            <text
                                v-for="(pt, idx) in chartPoints.filter((_, i) => i % Math.max(1, Math.floor(chartPoints.length / 8)) === 0 || i === chartPoints.length - 1)"
                                :key="'x-' + idx"
                                :x="pt.x"
                                :y="svgHeight - 12"
                                text-anchor="middle"
                                font-size="11"
                                fill="#7c736e"
                                font-family="monospace"
                            >
                                {{ pt.point.label }}
                            </text>
                        </g>

                        <!-- Invisible full-height hitboxes for smooth hovering -->
                        <rect
                            v-for="(pt, idx) in chartPoints"
                            :key="'hit-' + idx"
                            :x="idx === 0 ? chartPad.left : pt.x - ((pt.x - chartPoints[idx-1].x) / 2)"
                            :y="chartPad.top"
                            :width="chartPoints.length === 1 ? chartW : (idx === chartPoints.length - 1 ? (svgWidth - chartPad.right - pt.x) + ((pt.x - chartPoints[idx-1].x) / 2) : (chartPoints[idx+1].x - pt.x))"
                            :height="chartH"
                            fill="transparent"
                            style="cursor: pointer;"
                            @mouseenter="hoveredPointIndex = idx"
                        />
                    </svg>

                    <!-- Floating Interactive Tooltip -->
                    <div
                        v-if="activeHoveredPoint"
                        class="chart-floating-tooltip"
                        :style="{
                            left: `${(activeHoveredPoint.x / svgWidth) * 100}%`,
                            top: `${(activeHoveredPoint.y / svgHeight) * 100}%`
                        }"
                    >
                        <div class="tooltip-header">
                            <Clock :size="12" />
                            <strong>{{ activeHoveredPoint.point.full_date }}</strong>
                        </div>
                        <div class="tooltip-row">
                            <span class="tooltip-label">Permintaan:</span>
                            <strong class="tooltip-value">{{ formatNumber(activeHoveredPoint.point.requests) }} req</strong>
                        </div>
                        <div v-if="activeHoveredPoint.point.failures > 0" class="tooltip-row text-rose">
                            <span class="tooltip-label">Gagal:</span>
                            <strong class="tooltip-value">{{ activeHoveredPoint.point.failures }} req</strong>
                        </div>
                        <div class="tooltip-row">
                            <span class="tooltip-label">Token:</span>
                            <strong class="tooltip-value">{{ formatTokens(activeHoveredPoint.point.tokens) }}</strong>
                        </div>
                        <div class="tooltip-row">
                            <span class="tooltip-label">Biaya:</span>
                            <strong class="tooltip-value text-amber">{{ formatIdr(activeHoveredPoint.point.cost_idr) }} ({{ formatUsd(activeHoveredPoint.point.cost_usd) }})</strong>
                        </div>
                        <div class="tooltip-row">
                            <span class="tooltip-label">Avg Latensi:</span>
                            <strong class="tooltip-value">{{ formatDuration(activeHoveredPoint.point.avg_duration_ms) }}</strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Two-Column Breakdown: Model & Agent -->
            <div class="telemetry-breakdown-grid">
                <!-- Card: Model Breakdown -->
                <div class="telemetry-subcard">
                    <div class="subcard-header">
                        <div>
                            <h4>Rincian Berdasarkan Model AI</h4>
                            <p>Pembagian permintaan, token, dan estimasi biaya per arsitektur model.</p>
                        </div>
                        <Cpu :size="18" class="text-muted" />
                    </div>

                    <div class="breakdown-table-wrapper">
                        <table class="telemetry-table">
                            <thead>
                                <tr>
                                    <th>Model</th>
                                    <th class="text-right">Permintaan</th>
                                    <th class="text-right">Token</th>
                                    <th class="text-right">Biaya (USD / IDR)</th>
                                    <th class="text-right">Latensi</th>
                                    <th class="text-right">Tingkat Sukses</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="m in currentTelemetry?.model_breakdown" :key="m.model">
                                    <td>
                                        <div class="model-name-cell">
                                            <span class="model-badge" :class="m.model.includes('claude') ? 'claude' : (m.model.includes('gemma') ? 'gemma' : 'gemini')">
                                                {{ m.model }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="text-right font-mono">{{ formatNumber(m.requests) }}</td>
                                    <td class="text-right font-mono">{{ formatTokens(m.tokens) }}</td>
                                    <td class="text-right font-mono text-amber">
                                        <div>{{ formatUsd(m.cost_usd) }}</div>
                                        <small class="text-muted">~{{ formatIdr(m.cost_idr) }}</small>
                                    </td>
                                    <td class="text-right font-mono">{{ formatDuration(m.avg_duration_ms) }}</td>
                                    <td class="text-right">
                                        <span class="status-pill" :class="m.success_rate >= 95 ? 'success' : (m.success_rate >= 80 ? 'warning' : 'danger')">
                                            {{ m.success_rate }}%
                                        </span>
                                    </td>
                                </tr>
                                <tr v-if="!currentTelemetry?.model_breakdown?.length">
                                    <td colspan="6" class="text-center py-4 text-muted">Belum ada data model pada rentang ini.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Card: Multi-Agent Slot Breakdown -->
                <div class="telemetry-subcard">
                    <div class="subcard-header">
                        <div>
                            <h4>Rincian Per Slot Agen AI</h4>
                            <p>Beban kerja terdistribusi pada Slot 1 hingga Slot 6 (Cadangan).</p>
                        </div>
                        <Bot :size="18" class="text-muted" />
                    </div>

                    <div class="breakdown-table-wrapper">
                        <table class="telemetry-table">
                            <thead>
                                <tr>
                                    <th>Slot & Agen</th>
                                    <th>Model</th>
                                    <th class="text-right">Permintaan</th>
                                    <th class="text-right">Token</th>
                                    <th class="text-right">Biaya</th>
                                    <th class="text-right">Sukses</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="ag in currentTelemetry?.agent_breakdown" :key="ag.slot">
                                    <td>
                                        <div class="agent-name-cell">
                                            <span class="slot-badge" :class="ag.slot === 6 ? 'backup' : 'regular'">
                                                {{ ag.slot === 6 ? 'Slot 6 (Cadangan)' : `Slot ${ag.slot}` }}
                                            </span>
                                            <strong class="agent-name-text">{{ ag.name }}</strong>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="model-badge small">{{ ag.model }}</span>
                                    </td>
                                    <td class="text-right font-mono">{{ formatNumber(ag.requests) }}</td>
                                    <td class="text-right font-mono">{{ formatTokens(ag.tokens) }}</td>
                                    <td class="text-right font-mono text-amber">{{ formatIdr(ag.cost_idr) }}</td>
                                    <td class="text-right">
                                        <span class="status-pill" :class="ag.success_rate >= 95 ? 'success' : (ag.success_rate >= 80 ? 'warning' : 'danger')">
                                            {{ ag.success_rate }}%
                                        </span>
                                    </td>
                                </tr>
                                <tr v-if="!currentTelemetry?.agent_breakdown?.length">
                                    <td colspan="6" class="text-center py-4 text-muted">Belum ada data agen pada rentang ini.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Recent 15 Requests Activity Feed Table -->
            <div class="telemetry-subcard">
                <div class="subcard-header">
                    <div>
                        <h4>Riwayat 15 Permintaan Terakhir</h4>
                        <p>Log pemanggilan model AI per halaman publikasi secara real-time.</p>
                    </div>
                    <Clock :size="18" class="text-muted" />
                </div>

                <div class="breakdown-table-wrapper">
                    <table class="telemetry-table">
                        <thead>
                            <tr>
                                <th>Waktu</th>
                                <th>Agen / Slot</th>
                                <th>Model AI</th>
                                <th>Publikasi & Halaman</th>
                                <th class="text-right">Durasi</th>
                                <th class="text-right">Token</th>
                                <th class="text-right">Biaya</th>
                                <th class="text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="req in currentTelemetry?.recent_requests" :key="req.id">
                                <td>
                                    <div class="recent-time-cell">
                                        <strong>{{ req.created_at_human }}</strong>
                                        <small class="text-muted">{{ req.created_at_time }}</small>
                                    </div>
                                </td>
                                <td>
                                    <div class="agent-name-cell">
                                        <span class="slot-badge" :class="req.agent_slot === 6 ? 'backup' : 'regular'">
                                            Slot {{ req.agent_slot ?? '-' }}
                                        </span>
                                        <span>{{ req.agent_name }}</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="model-badge small">{{ req.model }}</span>
                                </td>
                                <td>
                                    <div class="pub-page-cell" :title="req.publication_title">
                                        <span class="pub-title-truncate">{{ req.publication_title }}</span>
                                        <span v-if="req.physical_page" class="pub-page-badge">Hal {{ req.physical_page }}</span>
                                    </div>
                                </td>
                                <td class="text-right font-mono">{{ formatDuration(req.duration_ms) }}</td>
                                <td class="text-right font-mono">{{ formatNumber(req.tokens) }}</td>
                                <td class="text-right font-mono text-amber">
                                    <span>{{ formatUsd(req.cost_usd) }}</span>
                                    <small class="text-muted d-block">~{{ formatIdr(req.cost_idr) }}</small>
                                </td>
                                <td class="text-right">
                                    <span class="status-pill" :class="req.is_success ? 'success' : 'danger'">
                                        {{ req.is_success ? 'Berhasil' : 'Gagal' }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="!currentTelemetry?.recent_requests?.length">
                                <td colspan="8" class="text-center py-4 text-muted">Belum ada riwayat permintaan AI.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
        <section v-if="tab === 'system'" class="settings-panel">
            <div class="section-heading">
                <div>
                    <h2>Kesehatan sistem</h2>
                    <p>
                        Worker antrean harus berjalan agar pemeriksaan
                        berlanjut.
                    </p>
                </div>
                <button
                    class="button secondary"
                    @click="router.reload({ only: ['health', 'events'] })"
                >
                    Perbarui
                </button>
            </div>
            <div class="metrics system-metrics">
                <div>
                    <Server :size="22" /><strong>{{ health.queue }}</strong
                    ><span>Job dalam antrean</span>
                </div>
                <div>
                    <strong>{{ health.failed }}</strong
                    ><span>Job gagal</span>
                </div>
                <div>
                    <strong>{{ formatDiskSpace(health.disk_free_mb) }}</strong>
                    <span>Ruang tersisa</span>
                </div>
            </div>
            <p>
                Antrean: <strong>{{ health.driver }}</strong> ·
                {{ health.usage }} permintaan AI tercatat.
            </p>
            <h3>Aktivitas terbaru</h3>
            <div class="event-list">
                <article v-for="event in events" :key="event.id">
                    <time>{{
                        new Date(event.created_at).toLocaleString("id-ID")
                    }}</time
                    ><strong>{{ event.action }}</strong>
                    <details>
                        <summary>Detail</summary>
                        <pre>{{ JSON.stringify(event.details, null, 2) }}</pre>
                    </details>
                </article>
                <p v-if="!events.length">Belum ada aktivitas.</p>
            </div>
        </section></AppLayout
    >
</template>
