<script setup lang="ts">
import { ref, computed } from 'vue'
import { api } from '@/lib/api'
import { parseApiError } from '@/lib/parseApiError'
import PasswordInput from '@/components/PasswordInput.vue'

const currentPassword = ref('')
const newPassword     = ref('')
const newPasswordConfirm = ref('')
const saving  = ref(false)
const success = ref(false)
const error   = ref('')

const newPasswordTouched        = ref(false)
const newPasswordConfirmTouched = ref(false)

const newPasswordLengthError = computed(() => {
  if (!newPasswordTouched.value || !newPassword.value) return ''
  return newPassword.value.length >= 8 ? '' : 'Минимум 8 символов'
})

const newPasswordConfirmStatus = computed((): 'error' | 'success' | undefined => {
  if (!newPasswordConfirmTouched.value || !newPasswordConfirm.value) return undefined
  return newPasswordConfirm.value === newPassword.value ? 'success' : 'error'
})

async function save() {
  newPasswordTouched.value        = true
  newPasswordConfirmTouched.value = true
  if (newPasswordLengthError.value || !currentPassword.value || !newPassword.value || !newPasswordConfirm.value) return
  if (newPassword.value !== newPasswordConfirm.value) return
  saving.value  = true
  error.value   = ''
  success.value = false
  try {
    await api.changePassword(currentPassword.value, newPassword.value, newPasswordConfirm.value)
    success.value = true
    currentPassword.value    = ''
    newPassword.value        = ''
    newPasswordConfirm.value = ''
    setTimeout(() => success.value = false, 3000)
  } catch (e: unknown) {
    error.value = parseApiError(e, 'Не удалось изменить пароль')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="card">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Смена пароля</h2>
    <div class="space-y-4">
      <PasswordInput label="Текущий пароль" v-model="currentPassword" placeholder="Введите текущий пароль" autocomplete="current-password" />

      <div>
        <PasswordInput
          label="Новый пароль"
          v-model="newPassword"
          placeholder="Минимум 8 символов"
          autocomplete="new-password"
          with-generate
          @generate="v => { newPasswordConfirm = v; newPasswordConfirmTouched = true }"
          @blur="newPasswordTouched = true"
        />
        <p v-if="newPasswordLengthError" class="mt-1 text-sm text-red-500">{{ newPasswordLengthError }}</p>
      </div>

      <div>
        <PasswordInput
          label="Подтверждение нового пароля"
          v-model="newPasswordConfirm"
          placeholder="Повторите новый пароль"
          autocomplete="new-password"
          :status="newPasswordConfirmStatus"
          @blur="newPasswordConfirmTouched = true"
        />
        <p v-if="newPasswordConfirmStatus === 'error'"   class="mt-1 text-sm text-red-500">Пароли не совпадают</p>
        <p v-if="newPasswordConfirmStatus === 'success'" class="mt-1 text-sm text-green-600">Пароли совпадают</p>
      </div>

      <div v-if="error"   class="text-sm text-red-600 dark:text-red-400">{{ error }}</div>
      <div v-if="success" class="text-sm text-green-600 dark:text-green-400">Пароль успешно изменён!</div>

      <button @click="save" :disabled="saving" class="btn-primary">
        {{ saving ? 'Сохранение...' : 'Сменить пароль' }}
      </button>
    </div>
  </div>
</template>
