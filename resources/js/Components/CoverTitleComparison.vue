<script setup lang="ts">
import { ref } from "vue";
import { ArrowLeftRight, Check, AlertTriangle, XCircle, HelpCircle } from "lucide-vue-next";

const props = defineProps<{
    publicationId: string;
    coverPage?: number;
    titlePage?: number;
    result?: Record<string, any> | null;
}>();

const swapped = ref(false);

const getCoverNum = () => swapped.value ? (props.titlePage ?? 2) : (props.coverPage ?? 1);
const getTitleNum = () => swapped.value ? (props.coverPage ?? 1) : (props.titlePage ?? 2);
const getCoverLabel = () => swapped.value ? "Halaman Judul" : "Halaman Kover";
const getTitleLabel = () => swapped.value ? "Halaman Kover" : "Halaman Judul";

const components = [
    { key: "logo", name: "Logo & Lambang", desc: "Kover dan judul memiliki logo BPS yang selaras" },
    { key: "white_background", name: "Latar Belakang Putih", desc: "Halaman judul berlatar belakang putih murni tanpa foto kover" },
    { key: "layout_harmony", name: "Keselarasan Layout", desc: "Hierarki tipografi dan tata letak judul konsisten" },
    { key: "title_alignment", name: "Kesesuaian Judul", desc: "Teks judul persis sama antara kover dan judul dalam" },
];

function statusIcon(decision?: string) {
    switch (decision) {
        case "conform": return Check;
        case "needs_verification": return AlertTriangle;
        case "nonconform": return XCircle;
        default: return HelpCircle;
    }
}

function statusClass(decision?: string) {
    switch (decision) {
        case "conform": return "status-success";
        case "needs_verification": return "status-warning";
        case "nonconform": return "status-danger";
        default: return "status-neutral";
    }
}
</script>

<template>
    <div class="cover-title-comparison" role="region" aria-label="Pembanding visual kover dan judul">
        <div class="comparison-header">
            <h3>Perbandingan Visual Kover & Judul</h3>
            <button
                type="button"
                class="button secondary swap-button"
                @click="swapped = !swapped"
                aria-label="Tukar posisi tampilan gambar kover dan judul"
            >
                <ArrowLeftRight :size="16" />
                <span>Tukar Tampilan</span>
            </button>
        </div>

        <div class="pair-images-grid">
            <div class="pair-card">
                <div class="pair-card-header">
                    <strong>{{ getCoverLabel() }}</strong>
                    <span class="badge">PDF hlm. {{ getCoverNum() }}</span>
                </div>
                <div class="pair-img-container">
                    <img
                        :src="`/publications/${publicationId}/pages/${getCoverNum()}/image`"
                        :alt="getCoverLabel()"
                        loading="lazy"
                    />
                </div>
            </div>

            <div class="pair-card">
                <div class="pair-card-header">
                    <strong>{{ getTitleLabel() }}</strong>
                    <span class="badge">PDF hlm. {{ getTitleNum() }}</span>
                </div>
                <div class="pair-img-container">
                    <img
                        :src="`/publications/${publicationId}/pages/${getTitleNum()}/image`"
                        :alt="getTitleLabel()"
                        loading="lazy"
                    />
                </div>
            </div>
        </div>

        <div v-if="result?.components" class="pair-results-table">
            <h4>Hasil Evaluasi Komponen</h4>
            <div class="component-list">
                <div
                    v-for="c in components"
                    :key="c.key"
                    class="component-item"
                    :class="statusClass(result?.components?.[c.key]?.decision)"
                >
                    <component :is="statusIcon(result?.components?.[c.key]?.decision)" :size="18" />
                    <div class="component-details">
                        <strong>{{ c.name }}</strong>
                        <small>{{ result?.components?.[c.key]?.observed_title ?? c.desc }}</small>
                    </div>
                    <span class="decision-pill">
                        {{ result?.components?.[c.key]?.decision ?? 'Belum dievaluasi' }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.cover-title-comparison {
    margin-top: 1.5rem;
    padding: 1.25rem;
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 0.5rem;
    background: var(--surface, #ffffff);
}
.comparison-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}
.comparison-header h3 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
}
.pair-images-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 1rem;
    margin-bottom: 1.25rem;
}
.pair-card {
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 0.375rem;
    overflow: hidden;
    background: #f8fafc;
}
.pair-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem 0.75rem;
    background: #edf2f7;
    font-size: 0.9rem;
}
.pair-img-container {
    height: 340px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #ffffff;
    overflow: hidden;
}
.pair-img-container img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}
.component-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    margin-top: 0.5rem;
}
.component-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.6rem 0.85rem;
    border-radius: 0.375rem;
    border: 1px solid #e2e8f0;
}
.component-details {
    flex: 1;
    display: flex;
    flex-direction: column;
}
.component-details strong {
    font-size: 0.9rem;
}
.component-details small {
    color: #64748b;
    font-size: 0.8rem;
}
.decision-pill {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    padding: 0.2rem 0.5rem;
    border-radius: 9999px;
}
.status-success { border-color: #86efac; background: #f0fdf4; color: #166534; }
.status-warning { border-color: #fde047; background: #fefce8; color: #854d0e; }
.status-danger { border-color: #fca5a5; background: #fef2f2; color: #991b1b; }
.status-neutral { border-color: #e2e8f0; background: #f8fafc; color: #475569; }
</style>
