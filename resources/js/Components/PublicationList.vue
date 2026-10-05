<script setup lang="ts">
import { Link, router } from "@inertiajs/vue3";
import { ref } from "vue";
import { Search, FileText, ArrowUpRight } from "lucide-vue-next";
import StatusBadge from "./StatusBadge.vue";
import type { Publication, Paginated } from "../types";
const props = defineProps<{
    publications: Paginated<Publication>;
    filters?: { q?: string; status?: string };
}>();
const q = ref(props.filters?.q ?? "");
const status = ref(props.filters?.status ?? "");
function search() {
    router.get(
        "/publications",
        { q: q.value, status: status.value },
        { preserveState: true, replace: true },
    );
}
</script>
<template>
    <form class="list-toolbar" @submit.prevent="search">
        <label class="search-field"
            ><Search :size="19" /><input
                v-model="q"
                aria-label="Cari judul publikasi"
                placeholder="Cari publikasi atau daerah..." /></label
        ><select v-model="status" aria-label="Filter status" @change="search">
            <option value="">Semua status</option>
            <option value="draft">Siap diperiksa</option>
            <option value="processing">Sedang diperiksa</option>
            <option value="partial_failure">Perlu dilanjutkan</option>
            <option value="review_ready">Siap ditinjau</option>
            <option value="final">Final</option></select
        ><button class="button secondary">Cari</button>
    </form>
    <div v-if="!publications.data.length" class="empty-state">
        <span class="empty-icon"><FileText :size="38" /></span>
        <h2>{{ q ? "Publikasi belum ditemukan" : "Belum ada publikasi" }}</h2>
        <p>
            {{
                q
                    ? "Coba kata kunci lain atau ubah filter status."
                    : "Unggah PDF pertama Anda. Setiap halaman akan mendapat catatan pemeriksaan sendiri."
            }}
        </p>
        <Link v-if="!q" href="/publications/create" class="button primary"
            >Unggah publikasi</Link
        >
    </div>
    <div v-else class="publication-table">
        <div class="table-heading">
            <span>Publikasi</span><span>Kemajuan</span><span>Status</span
            ><span>Temuan</span><span></span>
        </div>
        <Link
            v-for="pub in publications.data"
            :key="pub.id"
            :href="`/publications/${pub.id}`"
            class="publication-row"
            ><div class="publication-name">
                <span class="file-icon"><FileText :size="23" /></span>
                <div>
                    <strong>{{ pub.title }}</strong
                    ><small
                        >{{ pub.region }} · {{ pub.year }} ·
                        {{ pub.page_count }} halaman</small
                    >
                </div>
            </div>
            <div class="progress-cell">
                <progress
                    :value="pub.checked_pages_count ?? 0"
                    :max="pub.page_count"
                    :aria-label="`Kemajuan ${pub.title}`"
                /><small
                    >{{ pub.checked_pages_count ?? 0 }} /
                    {{ pub.page_count }} halaman</small
                >
            </div>
            <StatusBadge :status="pub.status" /><span class="finding-count"
                >{{ pub.findings_count ?? 0 }}<small> temuan</small></span
            ><ArrowUpRight :size="20" aria-hidden="true"
        /></Link>
    </div>
    <nav
        v-if="publications.links.length > 3"
        class="pagination"
        aria-label="Halaman publikasi"
    >
        <template v-for="(link, i) in publications.links" :key="i"
            ><Link
                v-if="link.url"
                :href="link.url"
                :class="['button secondary', { selected: link.active }]"
                >{{
                    i === 0
                        ? "Sebelumnya"
                        : i === publications.links.length - 1
                          ? "Berikutnya"
                          : link.label
                }}</Link
            ></template
        >
    </nav>
</template>
