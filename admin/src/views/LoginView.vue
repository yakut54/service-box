<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import PasswordInput from '@/components/PasswordInput.vue'
import AppInput from '@/components/AppInput.vue'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const email    = ref('')
const password = ref('')
const remember = ref(true)
const loading  = ref(false)
const error    = ref('')

const emailTouched = ref(false)
const emailError = computed(() => {
  if (!emailTouched.value) return ''
  if (!email.value) return 'Введите email'
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value) ? '' : 'Некорректный email'
})

async function handleSubmit() {
  emailTouched.value = true
  if (emailError.value || !email.value || !password.value) return

  loading.value = true
  error.value = ''

  const result = await authStore.login(email.value, password.value, remember.value)

  if (result.success) {
    const raw = route.query.redirect as string || '/'
    const redirect = raw.startsWith('/') && !raw.startsWith('//') ? raw : '/'
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

          <AppInput label="Email" v-model="email" type="email" placeholder="your@email.com" autocomplete="email" :error="emailError" @blur="emailTouched = true" />

          <PasswordInput label="Пароль" v-model="password" placeholder="Введите пароль" autocomplete="current-password" />

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
