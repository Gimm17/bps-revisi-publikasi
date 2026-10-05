<script setup lang="ts">
import { label } from "../types";
defineProps<{ status: string }>();
</script>
<template>
    <span :class="['badge', status]">{{ label(status) }}</span>
</template>
