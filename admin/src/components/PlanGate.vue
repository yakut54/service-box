<script setup lang="ts">
import { RouterLink } from 'vue-router'

defineProps<{
  requiredPlan: string
  feature: string
}>()
</script>

<template>
  <div class="flex flex-col items-center justify-center py-20 px-6 text-center">
    <div class="w-16 h-16 bg-gray-100 dark:bg-gray-800 rounded-2xl flex items-center justify-center mb-4">
      <svg class="w-8 h-8 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
      </svg>
    </div>
    <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">{{ feature }}</h2>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">
      Доступно с тарифа <span class="font-semibold text-gray-700 dark:text-gray-300">{{ requiredPlan }}</span>
    </p>
    <RouterLink to="/subscription" class="btn-primary px-6">
      Обновить тариф
    </RouterLink>
  </div>
</template>
