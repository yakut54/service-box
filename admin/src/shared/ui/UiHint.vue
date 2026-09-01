<script setup lang="ts">
import UiTooltip from './UiTooltip.vue'

defineProps<{
  align?: 'start' | 'center' | 'end'
}>()
</script>

<template>
  <UiTooltip side="bottom" :align="align ?? 'start'">
    <button
      type="button"
      class="inline-flex text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
      aria-label="Подсказка"
    >
      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
    </button>
    <template #content><slot /></template>
  </UiTooltip>
</template>
