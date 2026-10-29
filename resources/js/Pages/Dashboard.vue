<script setup lang="ts">
import { Head, Link, usePage } from "@inertiajs/vue3";
import {
    BookOpenCheck,
    ArrowRight,
    Files,
    ScanText,
    CheckCheck,
} from "lucide-vue-next";
import AppLayout from "../Layouts/AppLayout.vue";
import PublicationList from "../Components/PublicationList.vue";
import type { Publication, Paginated, Shared } from "../types";
defineProps<{
    publications: Paginated<Publication>;
    filters: { q?: string; status?: string };
    metrics: { total: number; active: number; batch_queued?: number; ready: number };
    aiReady: boolean;
}>();
const page = usePage<Shared>();
</script>
<template>
    <Head title="Beranda" /><AppLayout
        ><section class="welcome-band">
            <div>
                <span class="eyebrow">Ruang kerja publikasi</span>
                <h1>Halo, {{ page.props.auth.user.name.split(" ")[0] }}!</h1>
                <p>
                    Mari lihat detail kecil yang membuat publikasi lebih baik.
                </p>
                <Link href="/publications/create" class="text-link"
                    >Mulai pemeriksaan baru<ArrowRight :size="18"
                /></Link>
            </div>
            <div class="welcome-art" aria-hidden="true">
                <span class="paper-shape"
                    ><BookOpenCheck :size="64" /><i></i><i></i><i></i></span
                ><span class="art-spark">✦</span><span class="art-dot"></span>
            </div>
        </section>
        <div class="metrics">
            <div>
                <Files :size="23" /><strong>{{ metrics.total }}</strong
                ><span>Publikasi saya</span>
            </div>
            <div>
                <ScanText :size="23" /><strong>{{ metrics.active }}</strong
                ><span
                    >Sedang diperiksa<template v-if="metrics.batch_queued">
                        · {{ metrics.batch_queued }} antre batch</template
                    ></span
                >
            </div>
            <div>
                <CheckCheck :size="23" /><strong>{{ metrics.ready }}</strong
                ><span>Siap ditinjau</span>
            </div>
        </div>
        <div v-if="!aiReady" class="notice warning">
            <strong>Koneksi AI belum siap.</strong> Dokumen tetap bisa diunggah.
            Admin perlu menyimpan koneksi dan menguji pembacaan gambar sebelum
            pemeriksaan dimulai.
            <Link
                v-if="page.props.auth.user.role === 'admin'"
                href="/settings?tab=ai"
                >Atur koneksi AI</Link
            >
        </div>
        <section class="list-section">
            <div class="section-heading">
                <div>
                    <h2>Publikasi terbaru</h2>
                    <p>Satu ruang untuk dokumen, temuan, dan hasil revisi.</p>
                </div>
                <Link href="/publications" class="text-link"
                    >Lihat semua<ArrowRight :size="17"
                /></Link>
            </div>
            <PublicationList
                :publications="publications"
                :filters="filters"
            /></section
    ></AppLayout>
</template>
