<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import CustomSelect from '@/components/CustomSelect.vue'
import PasswordInput from '@/components/PasswordInput.vue'
import AppInput from '@/components/AppInput.vue'
import { timezoneOptions } from '@/shared/lib/timezones'
import UiCheckbox from '@/shared/ui/UiCheckbox.vue'

const router = useRouter()
const authStore = useAuthStore()

const name = ref('')
const shopName = ref('')
const email = ref('')
const password = ref('')
const passwordConfirm = ref('')
const timezone = ref('Europe/Moscow')
const loading = ref(false)
const error = ref('')
const termsAccepted = ref(false)

// ── Touched flags ─────────────────────────────────────────────
const nameTouched         = ref(false)
const shopNameTouched     = ref(false)
const emailTouched        = ref(false)
const passwordTouched     = ref(false)
const confirmTouched      = ref(false)

// ── Field errors ──────────────────────────────────────────────
const nameError = computed(() => {
  if (!nameTouched.value) return ''
  return name.value.trim() ? '' : 'Введите ваше имя'
})

const shopNameError = computed(() => {
  if (!shopNameTouched.value) return ''
  return shopName.value.trim() ? '' : 'Введите название магазина'
})

const emailError = computed(() => {
  if (!emailTouched.value) return ''
  if (!email.value) return 'Введите email'
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value) ? '' : 'Некорректный email'
})

const passwordLengthError = computed(() => {
  if (!passwordTouched.value || !password.value) return ''
  return password.value.length >= 8 ? '' : 'Минимум 8 символов'
})

const confirmStatus = computed((): 'error' | 'success' | undefined => {
  if (!confirmTouched.value || !passwordConfirm.value) return undefined
  return passwordConfirm.value === password.value ? 'success' : 'error'
})

async function handleSubmit() {
  nameTouched.value     = true
  shopNameTouched.value = true
  emailTouched.value    = true
  passwordTouched.value = true
  confirmTouched.value  = true

  if (nameError.value || shopNameError.value || emailError.value || passwordLengthError.value) return
  if (!password.value || !passwordConfirm.value) return
  if (password.value !== passwordConfirm.value) return

  if (!termsAccepted.value) {
    error.value = 'Необходимо принять условия оферты и политику конфиденциальности'
    return
  }

  loading.value = true
  error.value = ''

  const result = await authStore.register(
    email.value,
    password.value,
    name.value,
    shopName.value,
    timezone.value
  )

  if (result.success) {
    await router.push('/')
  } else {
    error.value = result.error || 'Ошибка регистрации'
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
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Создать магазин</h1>
        <p class="text-gray-500 dark:text-gray-400 mt-1">Начните принимать заказы уже сегодня</p>
      </div>

      <div class="card">
        <form @submit.prevent="handleSubmit" class="space-y-4">
          <div v-if="error" class="p-3 bg-red-50 border border-red-200 rounded-lg text-red-600 text-sm">
            {{ error }}
          </div>

          <AppInput label="Ваше имя" v-model="name" placeholder="Иван Петров" autocomplete="name" :error="nameError" @blur="nameTouched = true" />

          <AppInput label="Название магазина" v-model="shopName" placeholder="Мой магазин" :error="shopNameError" @blur="shopNameTouched = true" />

          <div>
            <label class="label">Часовой пояс</label>
            <CustomSelect v-model="timezone" :options="timezoneOptions" searchable class="w-full" />
          </div>

          <AppInput label="Email" v-model="email" type="email" placeholder="your@email.com" autocomplete="email" :error="emailError" @blur="emailTouched = true" />

          <div>
            <PasswordInput label="Пароль" v-model="password" placeholder="Минимум 8 символов" autocomplete="new-password" with-generate @generate="v => { passwordConfirm = v; confirmTouched = true }" @blur="passwordTouched = true" />
            <p v-if="passwordLengthError" class="mt-1 text-sm text-red-500">{{ passwordLengthError }}</p>
          </div>

          <div>
            <PasswordInput label="Повторите пароль" v-model="passwordConfirm" placeholder="Повторите пароль" autocomplete="new-password" :status="confirmStatus" @blur="confirmTouched = true" />
            <p v-if="confirmStatus === 'error'"   class="mt-1 text-sm text-red-500">Пароли не совпадают</p>
            <p v-if="confirmStatus === 'success'" class="mt-1 text-sm text-green-600">Пароли совпадают</p>
          </div>

          <UiCheckbox v-model="termsAccepted" align="start">
            <span class="text-sm text-gray-600 dark:text-gray-400 leading-snug">
              Я ознакомился и принимаю условия
              <a href="/offer" target="_blank" rel="noopener" class="text-primary-600 hover:text-primary-700 font-medium">публичной оферты</a>
              и
              <a href="/privacy" target="_blank" rel="noopener" class="text-primary-600 hover:text-primary-700 font-medium">политики конфиденциальности</a>
            </span>
          </UiCheckbox>

          <button type="submit" class="btn-primary w-full" :disabled="loading">
            {{ loading ? 'Создание...' : 'Создать магазин' }}
          </button>
        </form>

        <p class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
          Уже есть аккаунт?
          <RouterLink to="/login" class="text-primary-600 hover:text-primary-700 font-medium">Войти</RouterLink>
        </p>
      </div>

      <div class="mt-8 grid grid-cols-3 gap-4 text-center text-sm text-gray-500 dark:text-gray-400">
        <div>
          <div class="font-semibold text-gray-900 dark:text-white">Бесплатно</div>
          <div>14 дней</div>
        </div>
        <div>
          <div class="font-semibold text-gray-900 dark:text-white">Без %</div>
          <div>с продаж</div>
        </div>
        <div>
          <div class="font-semibold text-gray-900 dark:text-white">1 минута</div>
          <div>настройка</div>
        </div>
      </div>
    </div>
  </div>
</template>
