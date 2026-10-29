<script setup lang="ts">
import { Link, usePage } from "@inertiajs/vue3";
import { ref, onMounted, onUnmounted, nextTick, watch } from "vue";
import {
    BookOpenCheck,
    Plus,
    ChevronDown,
    Settings,
    LogOut,
    Menu,
    X,
    Home,
    BookOpen,
    ShieldCheck,
    FileText,
    Brain,
    Bot,
} from "lucide-vue-next";
import type { Shared } from "../types";

const page = usePage<Shared>();
const menu = ref(false);
const mobileMenuOpen = ref(false);
const navRef = ref<HTMLElement | null>(null);
const accountRef = ref<HTMLElement | null>(null);
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

function toggleMobileMenu() {
    mobileMenuOpen.value = !mobileMenuOpen.value;
}

function closeMobileMenu() {
    mobileMenuOpen.value = false;
}

function handleKeydown(e: KeyboardEvent) {
    if (e.key === "Escape") {
        mobileMenuOpen.value = false;
        menu.value = false;
    }
}

function handleDocumentClick(e: MouseEvent) {
    if (menu.value && accountRef.value && !accountRef.value.contains(e.target as Node)) {
        menu.value = false;
    }
}

onMounted(() => {
    scrollActiveNavIntoView();
    window.addEventListener("keydown", handleKeydown);
    document.addEventListener("click", handleDocumentClick);
});

onUnmounted(() => {
    window.removeEventListener("keydown", handleKeydown);
    document.removeEventListener("click", handleDocumentClick);
});

watch(() => page.url, () => {
    scrollActiveNavIntoView();
    mobileMenuOpen.value = false;
    menu.value = false;
});
</script>
<template>
    <a class="skip-link" href="#main-content">Langsung ke konten</a>
    <header class="app-header">
        <Link href="/" class="brand" @click="closeMobileMenu">
            <span class="brand-mark">
                <BookOpenCheck :size="24" aria-hidden="true" />
            </span>
            <span>Ruang Periksa<small>Publikasi BPS</small></span>
        </Link>

        <!-- Desktop Navigation Bar (hidden on mobile) -->
        <nav ref="navRef" aria-label="Navigasi utama" class="main-nav desktop-only">
            <Link
                href="/"
                :class="{ active: page.url === '/' }"
                :aria-current="page.url === '/' ? 'page' : undefined"
            >Beranda</Link>
            <Link
                v-if="page.props.auth?.user"
                href="/publications"
                :class="{ active: page.url.startsWith('/publications') }"
                :aria-current="page.url.startsWith('/publications') ? 'page' : undefined"
            >Publikasi</Link>
            <Link
                href="/rules"
                :class="{
                    active:
                        page.url.startsWith('/rules') ||
                        page.url.startsWith('/guidelines'),
                }"
                :aria-current="page.url.startsWith('/rules') ? 'page' : undefined"
            >Aturan Periksa</Link>
            <Link
                href="/documentation"
                :class="{
                    active:
                        page.url.startsWith('/documentation') ||
                        page.url.startsWith('/docs'),
                }"
                :aria-current="page.url.startsWith('/documentation') ? 'page' : undefined"
            >Dokumentasi</Link>
            <Link
                v-if="page.props.auth?.user"
                href="/learnings"
                :class="{ active: page.url.startsWith('/learnings') }"
                :aria-current="page.url.startsWith('/learnings') ? 'page' : undefined"
            >Pembelajaran AI</Link>
            <Link
                v-if="page.props.auth?.user"
                href="/agent-office"
                :class="{ active: page.url.startsWith('/agent-office') }"
                :aria-current="page.url.startsWith('/agent-office') ? 'page' : undefined"
            >Markas Agen</Link>
        </nav>

        <!-- Header Actions: Desktop buttons + Mobile Hamburger -->
        <div class="header-actions">
            <template v-if="page.props.auth?.user">
                <!-- Desktop "Pemeriksaan Baru" Button -->
                <Link
                    href="/publications/create"
                    class="button primary new-audit desktop-only"
                    aria-label="Pemeriksaan baru"
                >
                    <Plus :size="18" aria-hidden="true" />
                    <span>Pemeriksaan baru</span>
                </Link>

                <!-- Compact mobile "+" button -->
                <Link
                    href="/publications/create"
                    class="mobile-quick-action mobile-only"
                    aria-label="Pemeriksaan baru"
                    title="Pemeriksaan baru"
                >
                    <Plus :size="20" aria-hidden="true" />
                </Link>

                <!-- Desktop Account Dropdown -->
                <div class="account desktop-only" ref="accountRef">
                    <button
                        type="button"
                        class="account-trigger"
                        :class="{ open: menu }"
                        @click="menu = !menu"
                        :aria-expanded="menu"
                        aria-controls="account-menu"
                        aria-label="Menu akun"
                    >
                        <span class="avatar">{{
                            page.props.auth.user.name.slice(0, 1)
                        }}</span>
                        <span class="account-name">{{
                            page.props.auth.user.name.split(" ")[0]
                        }}</span>
                        <ChevronDown :size="16" class="account-chevron" :class="{ rotated: menu }" />
                    </button>
                    <Transition name="dropdown-pop">
                        <div
                            v-if="menu"
                            id="account-menu"
                            class="account-menu"
                            @keydown.esc="menu = false"
                        >
                            <div class="account-header">
                                <div class="account-user-meta">
                                    <span class="account-fullname">{{ page.props.auth.user.name }}</span>
                                    <span class="account-role-badge">
                                        {{ page.props.auth.user.role === 'admin' ? 'Admin' : 'Pemeriksa' }}
                                    </span>
                                </div>
                                <p class="account-email">{{ page.props.auth.user.email }}</p>
                            </div>
                            <div class="account-menu-items">
                                <Link
                                    v-if="page.props.auth.user.role === 'admin'"
                                    href="/settings"
                                    class="account-menu-link"
                                    @click="menu = false"
                                >
                                    <span class="menu-icon-wrap icon-settings">
                                        <Settings :size="16" />
                                    </span>
                                    <span class="menu-label">Pengaturan</span>
                                </Link>
                                <div v-if="page.props.auth.user.role === 'admin'" class="account-menu-divider" />
                                <Link
                                    href="/logout"
                                    method="post"
                                    as="button"
                                    class="account-menu-link logout"
                                    @click="menu = false"
                                >
                                    <span class="menu-icon-wrap icon-logout">
                                        <LogOut :size="16" />
                                    </span>
                                    <span class="menu-label">Keluar</span>
                                </Link>
                            </div>
                        </div>
                    </Transition>
                </div>
            </template>
            <template v-else>
                <Link href="/login" class="button primary desktop-only" style="min-height: 38px; padding: 8px 16px;">
                    Masuk
                </Link>
            </template>

            <!-- Mobile Hamburger Toggle Button -->
            <button
                type="button"
                class="mobile-menu-btn mobile-only"
                :class="{ active: mobileMenuOpen }"
                @click="toggleMobileMenu"
                :aria-expanded="mobileMenuOpen"
                aria-label="Buka menu navigasi"
            >
                <X v-if="mobileMenuOpen" :size="22" />
                <Menu v-else :size="22" />
            </button>
        </div>
    </header>

    <!-- Mobile Navigation Drawer Overlay & Panel -->
    <Teleport to="body">
        <Transition name="drawer-fade">
            <div
                v-if="mobileMenuOpen"
                class="mobile-drawer-backdrop"
                @click="closeMobileMenu"
                aria-hidden="true"
            ></div>
        </Transition>

        <Transition name="drawer-slide">
            <aside
                v-if="mobileMenuOpen"
                class="mobile-drawer"
                aria-label="Navigasi Menu Mobile"
                role="dialog"
                aria-modal="true"
            >
                <!-- Drawer Header -->
                <div class="mobile-drawer-header">
                    <Link href="/" class="brand" @click="closeMobileMenu">
                        <span class="brand-mark">
                            <BookOpenCheck :size="22" aria-hidden="true" />
                        </span>
                        <span>Ruang Periksa<small>Publikasi BPS</small></span>
                    </Link>
                    <button
                        type="button"
                        class="mobile-drawer-close"
                        @click="closeMobileMenu"
                        aria-label="Tutup menu"
                    >
                        <X :size="20" />
                    </button>
                </div>

                <!-- Drawer Navigation Links -->
                <div class="mobile-drawer-body">
                    <span class="mobile-nav-heading">MENU UTAMA</span>
                    <nav class="mobile-nav-list">
                        <Link
                            href="/"
                            class="mobile-nav-item"
                            :class="{ active: page.url === '/' }"
                            @click="closeMobileMenu"
                        >
                            <span class="mobile-nav-icon"><Home :size="18" /></span>
                            <span>Beranda</span>
                        </Link>

                        <Link
                            v-if="page.props.auth?.user"
                            href="/publications"
                            class="mobile-nav-item"
                            :class="{ active: page.url.startsWith('/publications') }"
                            @click="closeMobileMenu"
                        >
                            <span class="mobile-nav-icon"><BookOpen :size="18" /></span>
                            <span>Publikasi</span>
                        </Link>

                        <Link
                            href="/rules"
                            class="mobile-nav-item"
                            :class="{
                                active:
                                    page.url.startsWith('/rules') ||
                                    page.url.startsWith('/guidelines'),
                            }"
                            @click="closeMobileMenu"
                        >
                            <span class="mobile-nav-icon"><ShieldCheck :size="18" /></span>
                            <span>Aturan Periksa</span>
                        </Link>

                        <Link
                            href="/documentation"
                            class="mobile-nav-item"
                            :class="{
                                active:
                                    page.url.startsWith('/documentation') ||
                                    page.url.startsWith('/docs'),
                            }"
                            @click="closeMobileMenu"
                        >
                            <span class="mobile-nav-icon"><FileText :size="18" /></span>
                            <span>Dokumentasi</span>
                        </Link>

                        <Link
                            v-if="page.props.auth?.user"
                            href="/learnings"
                            class="mobile-nav-item"
                            :class="{ active: page.url.startsWith('/learnings') }"
                            @click="closeMobileMenu"
                        >
                            <span class="mobile-nav-icon"><Brain :size="18" /></span>
                            <span>Pembelajaran AI</span>
                        </Link>

                        <Link
                            v-if="page.props.auth?.user"
                            href="/agent-office"
                            class="mobile-nav-item"
                            :class="{ active: page.url.startsWith('/agent-office') }"
                            @click="closeMobileMenu"
                        >
                            <span class="mobile-nav-icon"><Bot :size="18" /></span>
                            <span>Markas Agen</span>
                        </Link>
                    </nav>

                    <!-- Primary Action -->
                    <div v-if="page.props.auth?.user" class="mobile-drawer-cta">
                        <Link
                            href="/publications/create"
                            class="button primary mobile-cta-btn"
                            @click="closeMobileMenu"
                        >
                            <Plus :size="18" />
                            <span>Pemeriksaan Baru</span>
                        </Link>
                    </div>
                </div>

                <!-- Drawer Footer: User Profile / Logout -->
                <div class="mobile-drawer-footer">
                    <template v-if="page.props.auth?.user">
                        <div class="mobile-user-card">
                            <span class="avatar">{{ page.props.auth.user.name.slice(0, 1) }}</span>
                            <div class="mobile-user-info">
                                <strong class="mobile-user-name">{{ page.props.auth.user.name }}</strong>
                                <span class="mobile-user-email">{{ page.props.auth.user.email }}</span>
                            </div>
                        </div>
                        <div class="mobile-user-actions">
                            <Link
                                v-if="page.props.auth.user.role === 'admin'"
                                href="/settings"
                                class="mobile-user-action-btn"
                                @click="closeMobileMenu"
                            >
                                <Settings :size="16" />
                                <span>Pengaturan</span>
                            </Link>
                            <Link
                                href="/logout"
                                method="post"
                                as="button"
                                class="mobile-user-action-btn logout"
                                @click="closeMobileMenu"
                            >
                                <LogOut :size="16" />
                                <span>Keluar</span>
                            </Link>
                        </div>
                    </template>
                    <template v-else>
                        <Link
                            href="/login"
                            class="button primary mobile-cta-btn"
                            @click="closeMobileMenu"
                        >
                            Masuk ke Akun
                        </Link>
                    </template>
                </div>
            </aside>
        </Transition>
    </Teleport>

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
