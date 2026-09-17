<script setup lang="ts">
// Пульсирующий прямоугольник-заглушка для skeleton-загрузки. Тот же визуальный
// язык, что уже был вручную написан в KpiCard/RevenueChart/StatusBreakdown/
// DashboardView/AnalyticsView (animate-pulse на bg-gray-100 dark:bg-gray-800) —
// этот компонент их не меняет по смыслу, а просто убирает копипасту разметки.
withDefaults(
  defineProps<{
    width?: string
    height?: string
    rounded?: 'full' | 'lg' | 'md'
    circle?: boolean
  }>(),
  { height: '1rem', rounded: 'md' },
)
</script>

<template>
  <div
    class="animate-pulse bg-gray-100 dark:bg-gray-800"
    :class="[
      circle ? 'rounded-full' : rounded === 'full' ? 'rounded-full' : rounded === 'lg' ? 'rounded-lg' : 'rounded',
    ]"
    :style="{ width: circle ? height : (width ?? '100%'), height }"
  />
</template>
