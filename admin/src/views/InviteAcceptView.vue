<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import AppInput from '@/components/AppInput.vue'
import PasswordInput from '@/components/PasswordInput.vue'
import UiSpinner from '@/shared/ui/UiSpinner.vue'

const route     = useRoute()
const router    = useRouter()
const authStore = useAuthStore()

const inviteToken = route.params.token as string

type Stage = 'loading' | 'error' | 'form' | 'done'

const stage      = ref<Stage>('loading')
const errorMsg   = ref('')
const shopName   = ref('')
const email      = ref('')
const requiresRegistration = ref(false)

const name            = ref('')
const password        = ref('')
const passwordConfirm = ref('')
const submitting      = ref(false)
const submitError     = ref('')

async function validateToken() {
  try {
    const data = await api.validateInvite(inviteToken)
    shopName.value           = data.shop_name
    email.value              = data.email
    requiresRegistration.value = data.requires_registration
    stage.value = 'form'
  } catch (err) {
    errorMsg.value = err instanceof ApiError ? err.message : 'Приглашение недействительно или уже использовано'
    stage.value = 'error'
  }
}

async function accept() {
  if (requiresRegistration.value) {
    if (!name.value.trim())                         { submitError.value = 'Введите ваше имя'; return }
    if (password.value.length < 8)                  { submitError.value = 'Пароль: минимум 8 символов'; return }
    if (password.value !== passwordConfirm.value)   { submitError.value = 'Пароли не совпадают'; return }
  }

  submitting.value = true
  submitError.value = ''

  try {
    const payload: { token: string; name?: string; password?: string; password_confirmation?: string } = {
      token: inviteToken,
    }
    if (requiresRegistration.value) {
      payload.name                  = name.value.trim()
      payload.password              = password.value
      payload.password_confirmation = passwordConfirm.value
    }
    const res = await api.acceptInvite(payload)
    // Set token so api.me() can authenticate, then fetch complete user+shop data
    api.setToken(res.token)
    const meData = await api.me()
    authStore.loginWithToken(res.token, meData.user, meData.shop)
    stage.value = 'done'
    setTimeout(() => router.push('/'), 1500)
  } catch (err) {
    submitError.value = err instanceof ApiError ? err.message : 'Ошибка принятия приглашения'
  } finally {
    submitting.value = false
  }
}

onMounted(validateToken)
</script>

<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-900 flex items-center justify-center p-4">
    <div class="w-full max-w-md">

      <!-- Logo header -->
      <div class="text-center mb-8">
        <div class="w-16 h-16 bg-primary-600 rounded-2xl flex items-center justify-center mx-auto mb-4">
          <span class="text-white font-bold text-3xl">S</span>
        </div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">ServiceBox</h1>
      </div>

      <!-- Loading -->
      <div v-if="stage === 'loading'" class="card flex items-center justify-center py-12">
        <UiSpinner />
      </div>

      <!-- Error -->
      <div v-else-if="stage === 'error'" class="card text-center py-10 px-6">
        <div class="w-12 h-12 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-4">
          <svg class="w-6 h-6 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Приглашение недействительно</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">{{ errorMsg }}</p>
        <RouterLink to="/login" class="btn-primary inline-flex px-6">Войти в аккаунт</RouterLink>
      </div>

      <!-- Success / redirecting -->
      <div v-else-if="stage === 'done'" class="card text-center py-10 px-6">
        <div class="w-12 h-12 bg-green-100 dark:bg-green-900/30 rounded-full flex items-center justify-center mx-auto mb-4">
          <svg class="w-6 h-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
        </div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Добро пожаловать!</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400">Переход в панель управления...</p>
      </div>

      <!-- Invite form -->
      <div v-else-if="stage === 'form'" class="card">
        <div class="mb-5">
          <div class="w-10 h-10 bg-primary-100 dark:bg-primary-900/30 rounded-xl flex items-center justify-center mb-3">
            <svg class="w-5 h-5 text-primary-600 dark:text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
          </div>
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Приглашение в команду</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            Магазин <strong class="text-gray-700 dark:text-gray-300">{{ shopName }}</strong>
            приглашает вас в качестве администратора
          </p>
        </div>

        <form @submit.prevent="accept" class="space-y-4">
          <div v-if="submitError" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">
            {{ submitError }}
          </div>

          <AppInput label="Email" v-model="email" type="email" disabled />

          <template v-if="requiresRegistration">
            <AppInput
              label="Ваше имя"
              v-model="name"
              placeholder="Иван Петров"
              autocomplete="name"
            />
            <PasswordInput
              label="Придумайте пароль"
              v-model="password"
              placeholder="Минимум 8 символов"
              autocomplete="new-password"
            />
            <PasswordInput
              label="Повторите пароль"
              v-model="passwordConfirm"
              placeholder="Повторите пароль"
              autocomplete="new-password"
            />
          </template>

          <button type="submit" class="btn-primary w-full" :disabled="submitting">
            {{ submitting ? 'Подтверждение...' : 'Принять приглашение' }}
          </button>
        </form>

        <p v-if="!requiresRegistration" class="mt-4 text-xs text-gray-400 dark:text-gray-500 text-center">
          Вы войдёте под учётной записью {{ email }}
        </p>
      </div>

    </div>
  </div>
</template>
