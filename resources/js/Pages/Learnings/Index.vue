<script setup lang="ts">
import { Head, router } from "@inertiajs/vue3";
import { ref } from "vue";
import { Brain, CheckCircle, Clock, XCircle, Search, SlidersHorizontal, Sparkles } from "lucide-vue-next";
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

function updateStatus(learning: Learning, newStatus: "active" | "pending" | "disabled") {
    router.patch(`/learnings/${learning.id}`, { status: newStatus }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Pembelajaran AI" />
    <AppLayout>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="sm:flex sm:items-center sm:justify-between mb-8">
                <div>
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-indigo-50 rounded-lg">
                            <Brain class="w-6 h-6 text-indigo-600" />
                        </div>
                        <h1 class="text-2xl font-bold text-gray-900">Pembelajaran AI Pemeriksa</h1>
                    </div>
                    <p class="mt-2 text-sm text-gray-600">
                        Aturan pengecualian dan contoh pembelajaran permanen dari koreksi manual pemeriksa dan hasil sinkronisasi Excel.
                    </p>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-200 mb-6 flex flex-wrap gap-4 items-center justify-between">
                <div class="flex-1 min-w-[280px] relative">
                    <Search class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
                    <input
                        v-model="searchInput"
                        @keyup.enter="applyFilters"
                        type="text"
                        placeholder="Cari kata kunci, topik, atau kode aturan..."
                        class="w-full pl-9 pr-4 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                    />
                </div>
                <div class="flex items-center gap-3">
                    <select
                        v-model="selectedStatus"
                        @change="applyFilters"
                        class="text-sm border border-gray-300 rounded-lg px-3 py-2 bg-white focus:ring-indigo-500 focus:border-indigo-500"
                    >
                        <option value="">Semua Status</option>
                        <option value="active">Aktif</option>
                        <option value="pending">Menunggu Konfirmasi (Pending)</option>
                        <option value="disabled">Dinonaktifkan</option>
                    </select>
                    <select
                        v-model="selectedKind"
                        @change="applyFilters"
                        class="text-sm border border-gray-300 rounded-lg px-3 py-2 bg-white focus:ring-indigo-500 focus:border-indigo-500"
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
                    class="bg-white rounded-xl shadow-sm border border-gray-200 p-5 hover:border-gray-300 transition-all"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="space-y-2 flex-1">
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

                            <h3 class="text-base font-semibold text-gray-900">{{ item.topic || item.instruction }}</h3>

                            <div v-if="item.observed_example" class="text-sm bg-gray-50 border border-gray-100 rounded-lg p-3 text-gray-700 font-mono text-xs">
                                <span class="font-semibold text-gray-500 font-sans block mb-1">Contoh Temuan yang Dibatalkan:</span>
                                "{{ item.observed_example }}"
                            </div>

                            <div v-if="item.reason" class="text-sm text-gray-600">
                                <span class="font-medium text-gray-700">Alasan Pemeriksa:</span> {{ item.reason }}
                            </div>

                            <div class="text-sm text-indigo-900 bg-indigo-50/50 p-2.5 rounded-lg border border-indigo-100">
                                <span class="font-semibold text-indigo-700">Instruksi AI:</span> {{ item.instruction }}
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex flex-col gap-2 shrink-0">
                            <button
                                v-if="item.status !== 'active'"
                                @click="updateStatus(item, 'active')"
                                class="px-3 py-1.5 text-xs font-medium rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 transition"
                            >
                                Aktifkan
                            </button>
                            <button
                                v-if="item.status === 'active'"
                                @click="updateStatus(item, 'disabled')"
                                class="px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 transition"
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
