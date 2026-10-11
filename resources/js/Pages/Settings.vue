<script setup lang="ts">
import { Head, useForm, router } from "@inertiajs/vue3";
import { ref } from "vue";
import { Plus, Save, FlaskConical, ShieldCheck, Server } from "lucide-vue-next";
import AppLayout from "../Layouts/AppLayout.vue";
import type { Employee } from "../types";
const props = defineProps<{
    users: Employee[];
    ai: {
        id: number;
        base_url: string;
        model: string;
        vision_tested_at: string | null;
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
const testing = ref(false);
const employee = useForm({
    name: "",
    email: "",
    password: "",
    role: "examiner",
    is_active: true,
});
const ai = useForm({
    base_url: props.ai?.base_url ?? "https://9router.com/v1",
    model: props.ai?.model ?? "",
    api_key: "",
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
function saveAi() {
    ai.post("/settings/ai", {
        preserveScroll: true,
        onSuccess: () => {
            ai.defaults({
                base_url: props.ai?.base_url ?? ai.base_url,
                model: props.ai?.model ?? ai.model,
                api_key: "",
            });
            ai.reset();
        },
    });
}
function testAi() {
    testing.value = true;
    router.post(
        "/settings/ai/test",
        {},
        { preserveScroll: true, onFinish: () => (testing.value = false) },
    );
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
        <section v-if="tab === 'ai'" class="settings-panel ai-settings">
            <div>
                <span class="aside-sticker"><ShieldCheck :size="31" /></span>
                <h2>Koneksi AI</h2>
                <p>
                    Mendukung 9Router dan LimitRouter. Model harus benar-benar
                    dapat membaca gambar, termasuk teks kecil dan tabel.
                </p>
                <span
                    :class="[
                        'badge',
                        props.ai?.vision_tested_at
                            ? 'complete'
                            : 'needs_verification',
                    ]"
                    >{{
                        props.ai?.vision_tested_at
                            ? "Pembacaan gambar sudah diuji"
                            : "Belum lulus uji gambar"
                    }}</span
                >
                <p class="small">
                    API key disimpan terenkripsi di server, tidak dikirim
                    kembali ke browser.
                </p>
                <div class="notice neutral">
                    <strong>ChatGPT Plus</strong>
                    <p>
                        Langganan Plus bukan API key. Sign in with ChatGPT belum
                        diaktifkan karena aplikasi privat memerlukan akses
                        resmi. Tidak ada pemakaian kuota pribadi secara
                        diam-diam.
                    </p>
                </div>
            </div>
            <form class="form-stack" @submit.prevent="saveAi">
                <label for="base-url">Base URL</label
                ><input
                    id="base-url"
                    type="url"
                    v-model="ai.base_url"
                    required
                    placeholder="https://router.example.go.id/v1"
                /><small
                    >HTTPS; host harus masuk daftar izin pada server. Alamat
                    lokal/IP tidak boleh.</small
                ><label for="model">Model vision</label
                ><input
                    id="model"
                    v-model="ai.model"
                    required
                    placeholder="ID model persis dari katalog penyedia"
                /><label for="api-key"
                    >API key
                    {{
                        props.ai ? "(kosongkan untuk mempertahankan kunci)" : ""
                    }}</label
                ><input
                    id="api-key"
                    type="password"
                    v-model="ai.api_key"
                    :required="!props.ai"
                    autocomplete="off"
                />
                <div class="form-actions">
                    <button
                        type="button"
                        class="button secondary"
                        @click="testAi"
                        :disabled="
                            !props.ai || testing || ai.processing || ai.isDirty
                        "
                    >
                        <FlaskConical :size="17" />{{
                            testing
                                ? "Menguji gambar..."
                                : "Uji pembacaan gambar"
                        }}</button
                    ><button
                        class="button primary"
                        :disabled="ai.processing || testing"
                    >
                        <Save :size="17" />Simpan koneksi
                    </button>
                </div>
                <small
                    >{{
                        !props.ai || ai.isDirty
                            ? "Simpan koneksi terlebih dahulu untuk mengaktifkan uji gambar."
                            : "Koneksi tersimpan. Uji pembacaan gambar sebelum memulai pemeriksaan."
                    }}
                    Uji dapat memakai kuota API. Saat mengganti penyedia, isi
                    API key penyedia yang baru.</small
                >
            </form>
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
