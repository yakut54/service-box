<script setup lang="ts">
/**
 * Красный кружок-счётчик на пункте меню сайдбара — раньше был скопирован
 * inline дважды (чат, отзывы) прямо в AppLayout.vue; вынесен сюда при
 * добавлении третьей копии (несработавшие письма), чтобы не плодить
 * одинаковую вёрстку дальше.
 */
defineProps<{ count: number }>()
</script>

<template>
  <span
    v-if="count > 0"
    class="min-w-[1.25rem] h-5 px-1 flex items-center justify-center rounded-full bg-red-500 text-white text-[11px] font-semibold leading-none"
  >
    {{ count > 99 ? '99+' : count }}
  </span>
</template>
