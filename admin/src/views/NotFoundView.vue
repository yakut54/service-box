<script setup lang="ts">
import { useRouter } from 'vue-router'

const router = useRouter()
</script>

<template>
  <div class="min-h-screen bg-gray-50 flex items-center justify-center p-4">
    <div class="text-center">
      <div class="text-8xl font-bold text-gray-200 mb-4">404</div>
      <h1 class="text-2xl font-bold text-gray-900 mb-2">Страница не найдена</h1>
      <p class="text-gray-500 mb-8">Похоже, вы попали не туда</p>
      <div class="flex gap-3 justify-center">
        <button @click="router.back()" class="btn-secondary">&larr; Назад</button>
        <RouterLink to="/" class="btn-primary">На главную</RouterLink>
      </div>
    </div>
  </div>
</template>
