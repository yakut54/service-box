<script setup lang="ts">
defineProps<{
  title: string
  description?: string
}>()
</script>

<template>
  <div class="card text-center py-16">
    <div v-if="$slots.icon" class="w-16 h-16 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mx-auto mb-4">
      <slot name="icon" />
    </div>
    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">{{ title }}</h3>
    <p v-if="description" class="text-gray-500 dark:text-gray-400 mb-6">{{ description }}</p>
    <slot />
  </div>
</template>
