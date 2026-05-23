<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { api } from '@/lib/api'
import { parseApiError } from '@/lib/parseApiError'
import PasswordInput from '@/components/PasswordInput.vue'

const router = useRouter()
const route  = useRoute()

const token    = route.query.token as string || ''
const email    = route.query.email as string || ''
const password = ref('')
const passwordConfirm = ref('')
const loading  = ref(false)
const success  = ref(false)
const error    = ref('')

const passwordTouched = ref(false)
const confirmTouched  = ref(false)

const passwordLengthError = computed(() => {
  if (!passwordTouched.value || !password.value) return ''
  return password.value.length >= 8 ? '' : 'Минимум 8 символов'
})

const confirmStatus = computed((): 'error' | 'success' | undefined => {
  if (!confirmTouched.value || !passwordConfirm.value) return undefined
  return passwordConfirm.value === password.value ? 'success' : 'error'
})

async function handleSubmit() {
  passwordTouched.value = true
  confirmTouched.value  = true
  if (passwordLengthError.value || !password.value || !passwordConfirm.value) return
  if (password.value !== passwordConfirm.value) return
  if (!token || !email) {
    error.value = 'Недействительная ссылка для сброса пароля'
    return
  }
  loading.value = true
  error.value = ''
  try {
    await api.resetPassword(token, email, password.value, passwordConfirm.value)
    success.value = true
    setTimeout(() => router.push('/login'), 3000)
  } catch (e: unknown) {
    error.value = parseApiError(e, 'Не удалось сбросить пароль')
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
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Новый пароль</h1>
        <p class="text-gray-500 dark:text-gray-400 mt-1">Придумайте надёжный пароль</p>
      </div>

      <div class="card">
        <div v-if="success" class="text-center py-4">
          <svg class="w-12 h-12 text-green-500 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Пароль изменён!</h2>
          <p class="text-gray-500 dark:text-gray-400 text-sm">Вы будете перенаправлены на страницу входа...</p>
        </div>

        <form v-else @submit.prevent="handleSubmit" class="space-y-4">
          <div v-if="!token || !email" class="p-3 bg-red-50 border border-red-200 rounded-lg text-red-600 text-sm">
            Недействительная или устаревшая ссылка. Запросите новую.
          </div>

          <template v-if="token && email">
            <div v-if="error" class="p-3 bg-red-50 border border-red-200 rounded-lg text-red-600 text-sm">
              {{ error }}
            </div>

            <div>
              <PasswordInput label="Новый пароль" v-model="password" placeholder="Минимум 8 символов" autocomplete="new-password" with-generate @generate="v => { passwordConfirm = v; confirmTouched = true }" @blur="passwordTouched = true" />
              <p v-if="passwordLengthError" class="mt-1 text-sm text-red-500">{{ passwordLengthError }}</p>
            </div>

            <div>
              <PasswordInput label="Подтверждение пароля" v-model="passwordConfirm" placeholder="Повторите пароль" autocomplete="new-password" :status="confirmStatus" @blur="confirmTouched = true" />
              <p v-if="confirmStatus === 'error'"   class="mt-1 text-sm text-red-500">Пароли не совпадают</p>
              <p v-if="confirmStatus === 'success'" class="mt-1 text-sm text-green-600">Пароли совпадают</p>
            </div>

            <button type="submit" class="btn-primary w-full" :disabled="loading">
              {{ loading ? 'Сохранение...' : 'Сохранить пароль' }}
            </button>
          </template>
        </form>

        <p class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
          <RouterLink to="/login" class="text-primary-600 hover:text-primary-700 font-medium">← Вернуться к входу</RouterLink>
        </p>
      </div>
    </div>
  </div>
</template>
