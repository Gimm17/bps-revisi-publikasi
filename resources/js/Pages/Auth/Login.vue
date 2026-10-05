<script setup lang="ts">
import { Head, useForm } from "@inertiajs/vue3";
import { ref } from "vue";
import {
    BookOpenCheck,
    Eye,
    EyeOff,
    ShieldCheck,
    ScanText,
    FileSpreadsheet,
    ArrowRight,
} from "lucide-vue-next";
const show = ref(false);
const form = useForm({ email: "", password: "", remember: false });
function submit() {
    form.post("/login", { onFinish: () => form.reset("password") });
}
</script>
<template>
    <Head title="Masuk" />
    <main class="login-page">
        <section class="login-story">
            <a href="/login" class="brand"
                ><span class="brand-mark"><BookOpenCheck /></span
                ><span
                    >Ruang Periksa<small
                        >Publikasi Badan Pusat Statistik</small
                    ></span
                ></a
            >
            <div class="login-message">
                <span class="story-sticker"><BookOpenCheck :size="54" /></span>
                <h1>Publikasi rapi,<br />lebih percaya diri.</h1>
                <p>
                    Teman teliti untuk memeriksa setiap halaman, mencatat detail
                    kecil, dan menyiapkan lembar revisi.
                </p>
                <div class="story-pills">
                    <span><ScanText :size="17" />Per halaman</span
                    ><span><FileSpreadsheet :size="17" />Excel otomatis</span>
                </div>
            </div>
            <small
                ><ShieldCheck :size="16" />Khusus pegawai · Akses dokumen
                privat</small
            >
        </section>
        <section class="login-form-wrap">
            <form class="login-form" @submit.prevent="submit">
                <span class="eyebrow">Selamat datang kembali</span>
                <h2>Masuk ke ruang kerja</h2>
                <p>Gunakan akun yang sudah dibuat oleh admin.</p>
                <label for="email">Email pegawai</label
                ><input
                    id="email"
                    v-model="form.email"
                    type="email"
                    required
                    autocomplete="username"
                    placeholder="nama@bps.go.id"
                    :aria-invalid="!!form.errors.email"
                />
                <p v-if="form.errors.email" class="field-error" role="alert">
                    {{ form.errors.email }}
                </p>
                <label for="password">Password</label>
                <div class="password-field">
                    <input
                        id="password"
                        v-model="form.password"
                        :type="show ? 'text' : 'password'"
                        required
                        autocomplete="current-password"
                        :aria-invalid="!!form.errors.password"
                    /><button
                        type="button"
                        class="icon-button"
                        @click="show = !show"
                        :aria-label="
                            show ? 'Sembunyikan password' : 'Tampilkan password'
                        "
                    >
                        <EyeOff v-if="show" :size="20" /><Eye
                            v-else
                            :size="20"
                        />
                    </button>
                </div>
                <label class="checkbox"
                    ><input type="checkbox" v-model="form.remember" />Ingat saya
                    di perangkat ini</label
                ><button
                    class="button primary full-width"
                    :disabled="form.processing"
                >
                    {{ form.processing ? "Sedang masuk..." : "Masuk"
                    }}<ArrowRight :size="18" />
                </button>
                <p class="login-help">
                    Belum punya akun atau lupa password?<br />Hubungi admin
                    pengelola publikasi.
                </p>
            </form>
        </section>
    </main>
</template>
