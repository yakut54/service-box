<script setup lang="ts">
import { ref } from 'vue'
import { api } from '@/lib/api'
import { parseApiError } from '@/lib/parseApiError'

const email   = ref('')
const loading = ref(false)
const sent    = ref(false)
const error   = ref('')

async function handleSubmit() {
  if (!email.value) {
    error.value = 'Введите email'
    return
  }
  loading.value = true
  error.value = ''
  try {
    await api.forgotPassword(email.value)
    sent.value = true
  } catch (e: unknown) {
    error.value = parseApiError(e, 'Не удалось отправить письмо')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-900 flex items-center justify-center p-4">
    <div class="w-full max-w-md">
      <div class="text-center mb-8">
        <div class="w-16 h-16 bg-primary-600 rounded-2xl flex items-center justify-center mx-auto mb-4">
          <span class="text-white font-bold text-3xl">S</span>
        </div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Восстановление пароля</h1>
        <p class="text-gray-500 dark:text-gray-400 mt-1">Укажите email вашего аккаунта</p>
      </div>

      <div class="card">
        <div v-if="sent" class="text-center py-4">
          <svg class="w-12 h-12 text-green-500 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
          </svg>
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Письмо отправлено</h2>
          <p class="text-gray-500 dark:text-gray-400 text-sm">
            Если аккаунт с адресом <strong>{{ email }}</strong> существует, на него придёт письмо со ссылкой для сброса пароля. Проверьте папку «Спам».
          </p>
        </div>

        <form v-else @submit.prevent="handleSubmit" class="space-y-4">
          <div v-if="error" class="p-3 bg-red-50 border border-red-200 rounded-lg text-red-600 text-sm">
            {{ error }}
          </div>

          <div>
            <label for="email" class="label">Email</label>
            <input id="email" v-model="email" type="email" class="input" placeholder="your@email.com" autocomplete="email" />
          </div>

          <button type="submit" class="btn-primary w-full" :disabled="loading">
            {{ loading ? 'Отправка...' : 'Отправить ссылку' }}
          </button>
        </form>

        <p class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
          <RouterLink to="/login" class="text-primary-600 hover:text-primary-700 font-medium">← Вернуться к входу</RouterLink>
        </p>
      </div>
    </div>
  </div>
</template>
