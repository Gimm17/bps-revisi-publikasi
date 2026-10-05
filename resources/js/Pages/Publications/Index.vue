<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import AppLayout from "../../Layouts/AppLayout.vue";
import PublicationList from "../../Components/PublicationList.vue";
import type { Publication, Paginated } from "../../types";
defineProps<{
    publications: Paginated<Publication>;
    filters: { q?: string; status?: string };
}>();
</script>
<template>
    <Head title="Publikasi" /><AppLayout
        ><div class="page-heading">
            <h1>Publikasi</h1>
            <p>Temukan dokumen dan lanjutkan pemeriksaan Anda.</p>
        </div>
        <PublicationList :publications="publications" :filters="filters"
    /></AppLayout>
</template>
