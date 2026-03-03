<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const email    = ref('')
const password = ref('')
const remember = ref(true)
const loading  = ref(false)
const error    = ref('')

async function handleSubmit() {
  if (!email.value || !password.value) {
    error.value = 'Заполните все поля'
    return
  }

  loading.value = true
  error.value = ''

  const result = await authStore.login(email.value, password.value, remember.value)

  if (result.success) {
    const redirect = route.query.redirect as string || '/'
    router.push(redirect)
  } else {
    error.value = result.error || 'Ошибка входа'
  }

  loading.value = false
}
</script>

<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-900 flex items-center justify-center p-4">
    <div class="w-full max-w-md">
      <div class="text-center mb-8">
        <div class="w-16 h-16 bg-primary-600 rounded-2xl flex items-center justify-center mx-auto mb-4">
          <span class="text-white font-bold text-3xl">S</span>
        </div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">ServiceBox</h1>
        <p class="text-gray-500 dark:text-gray-400 mt-1">Войдите в свой аккаунт</p>
      </div>

      <div class="card">
        <form @submit.prevent="handleSubmit" class="space-y-4">
          <div v-if="error" class="p-3 bg-red-50 border border-red-200 rounded-lg text-red-600 text-sm">
            {{ error }}
          </div>

          <div>
            <label for="email" class="label">Email</label>
            <input id="email" v-model="email" type="email" class="input" placeholder="your@email.com" autocomplete="email" />
          </div>

          <div>
            <label for="password" class="label">Пароль</label>
            <input id="password" v-model="password" type="password" class="input" placeholder="********" autocomplete="current-password" />
          </div>

          <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input v-model="remember" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500 cursor-pointer" />
              <span class="text-sm text-gray-600 dark:text-gray-400">Запомнить меня</span>
            </label>
            <RouterLink to="/forgot-password" class="text-sm text-primary-600 hover:text-primary-700">Забыли пароль?</RouterLink>
          </div>

          <button type="submit" class="btn-primary w-full" :disabled="loading">
            {{ loading ? 'Вход...' : 'Войти' }}
          </button>
        </form>

        <p class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
          Нет аккаунта?
          <RouterLink to="/register" class="text-primary-600 hover:text-primary-700 font-medium">Зарегистрироваться</RouterLink>
        </p>
      </div>
    </div>
  </div>
</template>
