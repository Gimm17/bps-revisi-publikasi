<script setup lang="ts">
import { Link, usePage } from "@inertiajs/vue3";
import { ref } from "vue";
import {
    BookOpenCheck,
    Plus,
    ChevronDown,
    Settings,
    LogOut,
} from "lucide-vue-next";
import type { Shared } from "../types";
const page = usePage<Shared>();
const menu = ref(false);
defineProps<{ wide?: boolean }>();
</script>
<template>
    <a class="skip-link" href="#main-content">Langsung ke konten</a>
    <header class="app-header">
        <Link href="/" class="brand"
            ><span class="brand-mark"
                ><BookOpenCheck :size="24" aria-hidden="true" /></span
            ><span
                >Ruang Periksa<small
                    >Publikasi Badan Pusat Statistik</small
                ></span
            ></Link
        >
        <nav aria-label="Navigasi utama" class="main-nav">
            <Link
                href="/"
                :class="{ active: page.url === '/' }"
                :aria-current="page.url === '/' ? 'page' : undefined"
                >Beranda</Link
            ><Link
                href="/publications"
                :class="{ active: page.url.startsWith('/publications') }"
                :aria-current="
                    page.url.startsWith('/publications') ? 'page' : undefined
                "
                >Publikasi</Link
            >
        </nav>
        <div class="header-actions">
            <Link
                href="/publications/create"
                class="button primary new-audit"
                aria-label="Pemeriksaan baru"
                ><Plus :size="18" aria-hidden="true" /><span
                    >Pemeriksaan baru</span
                ></Link
            >
            <div class="account">
                <button
                    class="account-trigger"
                    @click="menu = !menu"
                    :aria-expanded="menu"
                    aria-controls="account-menu"
                    aria-label="Menu akun"
                >
                    <span class="avatar">{{
                        page.props.auth.user.name.slice(0, 1)
                    }}</span
                    ><span class="account-name">{{
                        page.props.auth.user.name.split(" ")[0]
                    }}</span
                    ><ChevronDown :size="16" />
                </button>
                <div
                    v-if="menu"
                    id="account-menu"
                    class="account-menu"
                    @keydown.esc="menu = false"
                >
                    <p>{{ page.props.auth.user.email }}</p>
                    <Link
                        v-if="page.props.auth.user.role === 'admin'"
                        href="/settings"
                        @click="menu = false"
                        ><Settings :size="18" />Pengaturan</Link
                    ><Link href="/logout" method="post" as="button"
                        ><LogOut :size="18" />Keluar</Link
                    >
                </div>
            </div>
        </div>
    </header>
    <main id="main-content" :class="['main-container', { wide }]">
        <div
            v-if="page.props.flash.success"
            class="notice success"
            role="status"
        >
            {{ page.props.flash.success }}
        </div>
        <div v-if="page.props.flash.error" class="notice error" role="alert">
            {{ page.props.flash.error }}
        </div>
        <div
            v-if="Object.keys(page.props.errors ?? {}).length"
            class="notice error"
            role="alert"
        >
            <p v-for="(error, key) in page.props.errors" :key="key">
                {{ error }}
            </p>
        </div>
        <slot />
    </main>
    <footer class="app-footer">
        Ruang Periksa · Dokumen tersimpan privat · Hasil AI tetap perlu ditinjau
        pegawai
    </footer>
</template>
