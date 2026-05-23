<script setup lang="ts">
defineProps<{
  label: string
  isToday: boolean
}>()

defineEmits<{
  prev: []
  next: []
  today: []
}>()
</script>

<template>
  <div class="flex items-center justify-center gap-1">
    <button
      @click="$emit('prev')"
      class="p-2 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 active:scale-95 transition-all"
      aria-label="Предыдущий день"
    >
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
      </svg>
    </button>

    <div class="text-center min-w-[160px]">
      <div class="font-semibold text-gray-900 dark:text-white text-sm capitalize leading-tight">
        {{ label }}
      </div>
      <button
        v-if="!isToday"
        @click="$emit('today')"
        class="text-xs text-primary-600 dark:text-primary-400 font-medium hover:underline"
      >Сегодня</button>
      <div v-else class="text-xs text-primary-600 dark:text-primary-400 font-medium">Сегодня</div>
    </div>

    <button
      @click="$emit('next')"
      class="p-2 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 active:scale-95 transition-all"
      aria-label="Следующий день"
    >
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
      </svg>
    </button>
  </div>
</template>
