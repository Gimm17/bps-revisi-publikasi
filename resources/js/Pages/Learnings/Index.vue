<script setup lang="ts">
import { Head, Link, router } from "@inertiajs/vue3";
import { ref, computed } from "vue";
import { Brain, CheckCircle, Clock, XCircle, Search, SlidersHorizontal, Sparkles, ShieldCheck, BookOpenCheck, ArrowRight, Layers } from "lucide-vue-next";
import AppLayout from "../../Layouts/AppLayout.vue";

interface Learning {
    id: number;
    key: string;
    rule_code: string;
    section: string | null;
    kind: "false_positive" | "positive_example";
    source: string;
    status: "active" | "pending" | "disabled";
    topic: string | null;
    observed_example: string | null;
    reason: string | null;
    instruction: string;
    hits: number;
    created_by_name: string | null;
    created_at: string;
}

const props = defineProps<{
    learnings: {
        data: Learning[];
        total: number;
        current_page: number;
        last_page: number;
    };
    filters: {
        status?: string;
        kind?: string;
        search?: string;
    };
}>();

const searchInput = ref(props.filters.search || "");
const selectedStatus = ref(props.filters.status || "");
const selectedKind = ref(props.filters.kind || "");

function applyFilters() {
    router.get(
        "/learnings",
        {
            search: searchInput.value || undefined,
            status: selectedStatus.value || undefined,
            kind: selectedKind.value || undefined,
        },
        { preserveState: true, replace: true }
    );
}

const showExplainer = ref(true);

const totalHits = computed(() => {
    return props.learnings.data.reduce((acc, curr) => acc + (Number(curr.hits) || 0), 0);
});

const activeCount = computed(() => {
    return props.learnings.data.filter(l => l.status === 'active').length;
});

function updateStatus(learning: Learning, newStatus: "active" | "pending" | "disabled") {
    router.patch(`/learnings/${learning.id}`, { status: newStatus }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Pembelajaran AI" />
    <AppLayout>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="sm:flex sm:items-center sm:justify-between mb-6">
                <div>
                    <div class="flex items-center gap-3">
                        <div class="p-2.5 bg-[#245F59]/10 text-[#245F59] rounded-xl border border-[#245F59]/20">
                            <Brain class="w-6 h-6" />
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-[#302B2A] font-['Outfit']">Pembelajaran AI Pemeriksa</h1>
                            <p class="text-xs sm:text-sm text-gray-600 mt-0.5">
                                Pusat memori cerdas berbasis <em>Human-in-the-Loop</em> dari penolakan temuan, catatan revisi, dan impor Excel.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="mt-4 sm:mt-0 flex items-center gap-2">
                    <Link
                        href="/documentation?tab=learning"
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-[#245F59] text-white text-xs font-semibold rounded-full shadow-sm hover:bg-[#1E4F4A] transition-all"
                    >
                        <BookOpenCheck class="w-4 h-4" />
                        <span>Panduan Lengkap Arsitektur</span>
                        <ArrowRight class="w-3.5 h-3.5" />
                    </Link>
                </div>
            </div>

            <!-- Aesthetic Explainer Card (Human-in-the-Loop Architecture) -->
            <div class="bg-gradient-to-br from-[#ffffff] via-[#fcfbf7] to-[#f4f8f7] border border-[#D7D1C8] rounded-2xl p-5 sm:p-6 mb-6 shadow-xs">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 pb-4 border-b border-[#E5E0D8]">
                    <div class="space-y-1">
                        <div class="inline-flex items-center gap-2 px-2.5 py-0.5 rounded-full bg-[#BADFDB]/50 text-[#245F59] border border-[#96C9C3] text-[11px] font-bold">
                            <Sparkles class="w-3.5 h-3.5" />
                            <span>Mekanisme Closed-Loop Feedback</span>
                        </div>
                        <h2 class="text-lg font-bold text-[#302B2A]">Bagaimana AI Belajar Dari Catatan &amp; Penolakan Anda?</h2>
                        <p class="text-xs text-gray-600 max-w-3xl leading-relaxed">
                            Sistem tidak melatih ulang bobot AI secara lambat. Setiap kali pemeriksa menolak temuan palsu atau mengedit Excel, sistem mengompilasinya menjadi <strong>aturan larangan permanen</strong> yang otomatis diinjeksikan ke prompt dan dikawal jaring pengaman kemiripan teks &ge; 80% di backend.
                        </p>
                    </div>

                    <!-- Quick Telemetry Stats -->
                    <div class="flex items-center gap-3 shrink-0">
                        <div class="px-4 py-2.5 bg-white border border-[#D7D1C8] rounded-xl text-center min-w-[110px]">
                            <span class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wider">Pengecualian Aktif</span>
                            <strong class="text-lg font-bold text-[#245F59] font-['JetBrains_Mono']">{{ activeCount }}</strong>
                        </div>
                        <div class="px-4 py-2.5 bg-white border border-[#D7D1C8] rounded-xl text-center min-w-[120px]" title="Total temuan palsu yang berhasil dicegah secara otomatis oleh sistem">
                            <span class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wider">Temuan Dicegah</span>
                            <strong class="text-lg font-bold text-emerald-700 font-['JetBrains_Mono']">{{ totalHits }}×</strong>
                        </div>
                    </div>
                </div>

                <!-- 3 Pillars Grid -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3.5 pt-4">
                    <div class="bg-white/80 border border-[#E5E0D8] rounded-xl p-3.5 flex items-start gap-3">
                        <div class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-700 flex items-center justify-center shrink-0 font-bold text-xs">
                            1
                        </div>
                        <div class="space-y-1">
                            <h3 class="text-xs font-bold text-gray-900">Umpan Balik Pemeriksa</h3>
                            <p class="text-[11.5px] text-gray-600 leading-normal">
                                Tombol <strong>Tolak</strong> di web dan penghapusan baris di Excel otomatis dikonversi menjadi memori larangan permanen.
                            </p>
                        </div>
                    </div>

                    <div class="bg-white/80 border border-[#E5E0D8] rounded-xl p-3.5 flex items-start gap-3">
                        <div class="w-8 h-8 rounded-lg bg-blue-50 text-blue-700 flex items-center justify-center shrink-0 font-bold text-xs">
                            2
                        </div>
                        <div class="space-y-1">
                            <h3 class="text-xs font-bold text-gray-900">Prompt Injection (Lapis 1)</h3>
                            <p class="text-[11.5px] text-gray-600 leading-normal">
                                Sebelum Agen AI memeriksa halaman baru, sistem otomatis menyematkan 25 pembelajaran aktif teratas ke dalam instruksi model.
                            </p>
                        </div>
                    </div>

                    <div class="bg-white/80 border border-[#E5E0D8] rounded-xl p-3.5 flex items-start gap-3">
                        <div class="w-8 h-8 rounded-lg bg-amber-50 text-amber-700 flex items-center justify-center shrink-0 font-bold text-xs">
                            3
                        </div>
                        <div class="space-y-1">
                            <h3 class="text-xs font-bold text-gray-900">Suppressor Filter (Lapis 2)</h3>
                            <p class="text-[11.5px] text-gray-600 leading-normal">
                                Jika AI masih halusinasi, backend mencocokkan kemiripan teks &ge; 80% dan langsung membuangnya dari laporan akhir.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white p-3 sm:p-4 rounded-xl shadow-sm border border-gray-200 mb-6 flex flex-col md:flex-row gap-3 items-stretch md:items-center justify-between">
                <div class="flex-1 min-w-0 relative">
                    <Search class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
                    <input
                        v-model="searchInput"
                        @keyup.enter="applyFilters"
                        type="text"
                        placeholder="Cari kata kunci, topik, atau kode aturan..."
                        class="w-full pl-9 pr-4 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                    />
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:flex items-center gap-2 sm:gap-3 w-full md:w-auto">
                    <select
                        v-model="selectedStatus"
                        @change="applyFilters"
                        class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 bg-white focus:ring-indigo-500 focus:border-indigo-500"
                    >
                        <option value="">Semua Status</option>
                        <option value="active">Aktif</option>
                        <option value="pending">Menunggu Konfirmasi (Pending)</option>
                        <option value="disabled">Dinonaktifkan</option>
                    </select>
                    <select
                        v-model="selectedKind"
                        @change="applyFilters"
                        class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 bg-white focus:ring-indigo-500 focus:border-indigo-500"
                    >
                        <option value="">Semua Jenis</option>
                        <option value="false_positive">Pengecualian Temuan (False Positive)</option>
                        <option value="positive_example">Contoh Temuan Benar</option>
                    </select>
                </div>
            </div>

            <!-- Learnings List -->
            <div class="space-y-4">
                <div
                    v-for="item in learnings.data"
                    :key="item.id"
                    class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-5 hover:border-gray-300 transition-all"
                >
                    <div class="flex flex-col sm:flex-row items-start justify-between gap-3 sm:gap-4">
                        <div class="space-y-2 flex-1 min-w-0 w-full">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-800">
                                    {{ item.rule_code }}
                                </span>
                                <span
                                    v-if="item.status === 'active'"
                                    class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200"
                                >
                                    <CheckCircle class="w-3 h-3" /> Aktif
                                </span>
                                <span
                                    v-else-if="item.status === 'pending'"
                                    class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700 border border-amber-200"
                                >
                                    <Clock class="w-3 h-3" /> Pending (Dihapus dari Excel)
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600"
                                >
                                    <XCircle class="w-3 h-3" /> Nonaktif
                                </span>
                                <span v-if="item.hits > 0" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium bg-indigo-50 text-indigo-700">
                                    <Sparkles class="w-3 h-3" /> Diterapkan {{ item.hits }}×
                                </span>
                            </div>

                            <h3 class="text-base font-semibold text-gray-900 break-words">{{ item.topic || item.instruction }}</h3>

                            <div v-if="item.observed_example" class="text-sm bg-gray-50 border border-gray-100 rounded-lg p-3 text-gray-700 font-mono text-xs break-words">
                                <span class="font-semibold text-gray-500 font-sans block mb-1">Contoh Temuan yang Dibatalkan:</span>
                                "{{ item.observed_example }}"
                            </div>

                            <div v-if="item.reason" class="text-sm text-gray-600 break-words">
                                <span class="font-medium text-gray-700">Alasan Pemeriksa:</span> {{ item.reason }}
                            </div>

                            <div class="text-sm text-indigo-900 bg-indigo-50/50 p-2.5 rounded-lg border border-indigo-100 break-words">
                                <span class="font-semibold text-indigo-700">Instruksi AI:</span> {{ item.instruction }}
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex flex-row sm:flex-col gap-2 shrink-0 self-stretch sm:self-start justify-end w-full sm:w-auto">
                            <button
                                v-if="item.status !== 'active'"
                                @click="updateStatus(item, 'active')"
                                class="px-3 py-1.5 text-xs font-medium rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 transition flex-1 sm:flex-initial text-center"
                            >
                                Aktifkan
                            </button>
                            <button
                                v-if="item.status === 'active'"
                                @click="updateStatus(item, 'disabled')"
                                class="px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 transition flex-1 sm:flex-initial text-center"
                            >
                                Nonaktifkan
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="learnings.data.length === 0" class="text-center py-12 bg-white rounded-xl border border-gray-200">
                    <Brain class="w-12 h-12 text-gray-300 mx-auto mb-3" />
                    <p class="text-gray-500 text-sm">Belum ada data pembelajaran yang cocok dengan filter.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
