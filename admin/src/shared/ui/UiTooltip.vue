<script setup lang="ts">
defineProps<{
  align?: 'start' | 'center' | 'end'
}>()
</script>

<template>
  <div class="relative group">
    <slot />
    <div
      :class="[
        'absolute bottom-full mb-2 z-50 pointer-events-none',
        'opacity-0 group-hover:opacity-100 transition-opacity duration-150',
        align === 'start'  ? 'left-0' :
        align === 'end'    ? 'right-0' :
        'left-1/2 -translate-x-1/2',
      ]"
    >
      <div class="bg-gray-900 dark:bg-gray-700 text-white text-xs rounded-lg px-2.5 py-2 whitespace-nowrap shadow-xl ring-1 ring-black/10">
        <slot name="content" />
      </div>
      <div
        :class="[
          'absolute top-full border-4 border-transparent border-t-gray-900 dark:border-t-gray-700',
          align === 'start'  ? 'left-3' :
          align === 'end'    ? 'right-3' :
          'left-1/2 -translate-x-1/2',
        ]"
      />
    </div>
  </div>
</template>
