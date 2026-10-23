<script setup lang="ts">
import { Link, usePage } from "@inertiajs/vue3";
import { ref, onMounted, nextTick, watch } from "vue";
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
const navRef = ref<HTMLElement | null>(null);
defineProps<{ wide?: boolean }>();

function scrollActiveNavIntoView() {
    nextTick(() => {
        if (!navRef.value) return;
        const activeLink = navRef.value.querySelector('.active') as HTMLElement | null;
        if (activeLink) {
            activeLink.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }
    });
}

onMounted(() => {
    scrollActiveNavIntoView();
});

watch(() => page.url, () => {
    scrollActiveNavIntoView();
});
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
        <nav ref="navRef" aria-label="Navigasi utama" class="main-nav">
            <Link
                href="/"
                :class="{ active: page.url === '/' }"
                :aria-current="page.url === '/' ? 'page' : undefined"
                >Beranda</Link
            ><Link
                v-if="page.props.auth?.user"
                href="/publications"
                :class="{ active: page.url.startsWith('/publications') }"
                :aria-current="
                    page.url.startsWith('/publications') ? 'page' : undefined
                "
                >Publikasi</Link
            ><Link
                href="/rules"
                :class="{
                    active:
                        page.url.startsWith('/rules') ||
                        page.url.startsWith('/guidelines'),
                }"
                :aria-current="
                    page.url.startsWith('/rules') ? 'page' : undefined
                "
                >Aturan Periksa</Link
            ><Link
                href="/documentation"
                :class="{
                    active:
                        page.url.startsWith('/documentation') ||
                        page.url.startsWith('/docs'),
                }"
                :aria-current="
                    page.url.startsWith('/documentation') ? 'page' : undefined
                "
                >Dokumentasi</Link
            ><Link
                v-if="page.props.auth?.user"
                href="/learnings"
                :class="{ active: page.url.startsWith('/learnings') }"
                :aria-current="
                    page.url.startsWith('/learnings') ? 'page' : undefined
                "
                >Pembelajaran AI</Link
            ><Link
                v-if="page.props.auth?.user"
                href="/agent-office"
                :class="{ active: page.url.startsWith('/agent-office') }"
                :aria-current="
                    page.url.startsWith('/agent-office') ? 'page' : undefined
                "
                >Markas Agen</Link
            >
        </nav>
        <div class="header-actions">
            <template v-if="page.props.auth?.user">
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
            </template>
            <template v-else>
                <Link href="/login" class="button primary" style="min-height: 38px; padding: 8px 16px;">
                    Masuk
                </Link>
            </template>
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
