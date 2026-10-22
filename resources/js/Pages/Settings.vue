<script setup lang="ts">
import { Head, useForm, router } from "@inertiajs/vue3";
import { ref, computed, onUnmounted, watch } from "vue";
import { Plus, Save, FlaskConical, ShieldCheck, Server, Copy, Check, AlertCircle, RefreshCw } from "lucide-vue-next";
import AppLayout from "../Layouts/AppLayout.vue";
import type { Employee, AiAgentItem } from "../types";

const props = defineProps<{
    users: Employee[];
    ai_agents?: AiAgentItem[];
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
</script>
<template>
    <Head title="Pengaturan" /><AppLayout
        ><div class="page-heading">
            <h1>Pengaturan ruang kerja</h1>
            <p>Akun pegawai, koneksi AI, dan kesehatan pemeriksaan.</p>
        </div>
        <nav class="section-tabs" aria-label="Bagian pengaturan">
            <button
                v-for="(text, key) in {
                    users: 'Pegawai',
                    ai: 'Koneksi AI',
                    system: 'Sistem',
                }"
                :key="key"
                :class="{ selected: tab === key }"
                @click="tab = key"
            >
                {{ text }}
            </button>
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
                    <span :class="['agent-slot-dot', ag.status]"></span>
                    <strong>Slot {{ ag.slot }}: {{ ag.name }}</strong>
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
                    style="border: 1.5px dashed #4f46e5; background: #f5f3ff;"
                >
                    <span :class="['agent-slot-dot', backupAgentItem.status]"></span>
                    <strong style="color: #4f46e5;">🛡️ {{ backupAgentItem.name }}</strong>
                    <span :class="['badge', backupAgentItem.enabled ? 'complete' : 'neutral']" style="padding: 2px 7px; font-size: 10px;">
                        {{ backupAgentItem.enabled ? 'STANDBY' : 'OFF' }}
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
                            9Router
                        </button>
                        <button type="button" class="preset-chip" @click="currentForm.base_url = 'https://api.limitrouter.com/v1'">
                            LimitRouter
                        </button>
                    </div>

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
                    <strong
                        >{{
                            health.disk_free_mb.toLocaleString("id-ID")
                        }}
                        MB</strong
                    ><span>Ruang tersisa</span>
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
