<script setup lang="ts">
defineProps<{
  title: string
  subtitle?: string
}>()
</script>

<template>
  <div class="flex items-start justify-between gap-4">
    <div class="min-w-0">
      <h1 class="text-xl md:text-2xl font-bold text-gray-900 dark:text-white truncate">{{ title }}</h1>
      <p v-if="subtitle" class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ subtitle }}</p>
    </div>
    <div v-if="$slots.default" class="flex items-center gap-2 shrink-0 pt-0.5">
      <slot />
    </div>
  </div>
</template>
