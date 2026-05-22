<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'
import { UiSpinner } from '@/shared/ui'

const authStore = useAuthStore()
</script>

<template>
  <div class="flex flex-col items-center justify-center min-h-[60vh] gap-4 p-6">
    <UiSpinner />
    <p class="text-gray-500 dark:text-gray-400 text-sm">
      Загрузка расписания...
    </p>
  </div>
</template>
