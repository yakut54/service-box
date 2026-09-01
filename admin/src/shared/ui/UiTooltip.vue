<script setup lang="ts">
defineProps<{
  align?: 'start' | 'center' | 'end'
  side?: 'top' | 'bottom'
  bottomPct?: number  // when set, positions tooltip above bar at given % height
}>()
</script>

<template>
  <div class="relative group">
    <slot />
    <div
      :class="[
        'absolute z-50 pointer-events-none',
        side === 'bottom'
          ? 'opacity-0 -translate-y-1 group-hover:opacity-100 group-hover:translate-y-0 group-focus-within:opacity-100 group-focus-within:translate-y-0 transition-all duration-150 ease-out top-full mt-2'
          : 'opacity-0 translate-y-1 group-hover:opacity-100 group-hover:translate-y-0 group-focus-within:opacity-100 group-focus-within:translate-y-0 transition-all duration-150 ease-out bottom-full mb-2',
        align === 'start' ? 'left-0' : align === 'end' ? 'right-0' : 'left-1/2 -translate-x-1/2',
      ]"
      :style="bottomPct !== undefined ? `bottom: calc(${bottomPct}% + 10px)` : undefined"
    >
      <div class="bg-gray-900 dark:bg-gray-700 text-white text-xs rounded-lg px-2.5 py-2 max-w-[min(200px,calc(100vw-3rem))] whitespace-normal shadow-xl ring-1 ring-black/10">
        <slot name="content" />
      </div>
      <!-- Arrow -->
      <div
        v-if="side === 'bottom'"
        :class="[
          'absolute bottom-full border-4 border-transparent border-b-gray-900 dark:border-b-gray-700',
          align === 'start' ? 'left-3' : align === 'end' ? 'right-3' : 'left-1/2 -translate-x-1/2',
        ]"
      />
      <div
        v-else
        :class="[
          'absolute top-full border-4 border-transparent border-t-gray-900 dark:border-t-gray-700',
          align === 'start' ? 'left-3' : align === 'end' ? 'right-3' : 'left-1/2 -translate-x-1/2',
        ]"
      />
    </div>
  </div>
</template>
