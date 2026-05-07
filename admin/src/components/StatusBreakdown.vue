<script setup lang="ts">
defineProps<{
  items: Array<{ label: string; color: string; count: number; pct: number }>
  total: number
  loading?: boolean
  emptyText?: string
}>()
</script>

<template>
  <div>
    <div v-if="loading" class="space-y-3">
      <div v-for="i in 4" :key="i" class="h-5 bg-gray-100 dark:bg-gray-800 rounded animate-pulse"/>
    </div>
    <div v-else-if="!total" class="py-6 text-sm text-gray-400 text-center">
      {{ emptyText ?? 'Нет данных' }}
    </div>
    <div v-else class="space-y-3">
      <div v-for="s in items" :key="s.label" class="flex items-center gap-3">
        <div :class="['w-2.5 h-2.5 rounded-full flex-shrink-0', s.color]"/>
        <span class="text-sm text-gray-600 dark:text-gray-400 w-24 flex-shrink-0">{{ s.label }}</span>
        <div class="flex-1 h-1.5 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
          <div :class="['h-full rounded-full transition-all duration-500', s.color]" :style="`width:${s.pct}%`"/>
        </div>
        <span class="text-sm font-semibold text-gray-900 dark:text-white w-6 text-right tabular-nums flex-shrink-0">{{ s.count }}</span>
      </div>
      <div class="pt-2 border-t border-gray-100 dark:border-gray-800 flex justify-between text-xs text-gray-400">
        <span>Всего</span>
        <span class="font-medium text-gray-700 dark:text-gray-300 tabular-nums">{{ total }}</span>
      </div>
    </div>
  </div>
</template>
