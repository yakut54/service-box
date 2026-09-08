<script setup lang="ts">
import { ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { handlePhoneInput } from '@/composables/usePhoneInput'
import ImageUpload from '@/components/ImageUpload.vue'

const authStore = useAuthStore()

const storeAvatar = () => authStore.user?.avatar_url ?? null

const avatarUrl = ref<string | null>(storeAvatar())
const uploading = ref(false)
const name       = ref(authStore.user?.name ?? '')
const phone      = ref(authStore.user?.phone ?? '')
const nameTouched = ref(false)
const saving  = ref(false)
const success = ref(false)
const error   = ref('')

// user в сторе может дозагрузиться/обновиться уже после монтирования —
// подхватываем аватар, пока пользователь сам его не менял в этой форме.
// Без этого форма могла уйти с avatar_url: null и осиротить файл.
const avatarTouched = ref(false)
watch(() => authStore.user?.avatar_url, (v) => {
  if (!avatarTouched.value) avatarUrl.value = v ?? null
})
watch(avatarUrl, (v) => {
  if (v !== storeAvatar()) avatarTouched.value = true
})

async function save() {
  nameTouched.value = true
  if (!name.value.trim()) return

  saving.value  = true
  error.value   = ''
  success.value = false

  const payload: { name: string; phone: string | null; avatar_url?: string | null } = {
    name: name.value.trim(),
    phone: phone.value || null,
  }
  // Отправляем avatar_url только если пользователь реально его менял —
  // иначе бэкенд не трогает аватар вообще (см. AuthController::updateProfile).
  if (avatarUrl.value !== storeAvatar()) {
    payload.avatar_url = avatarUrl.value
  }

  const result = await authStore.updateProfile(payload)

  if (result.success) {
    avatarTouched.value = false
    success.value = true
    setTimeout(() => success.value = false, 3000)
  } else {
    error.value = result.error || 'Не удалось сохранить профиль'
  }
  saving.value = false
}
</script>

<template>
  <div class="card">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Мой профиль</h2>
    <div class="space-y-4">
      <div class="flex justify-center">
        <ImageUpload
          v-model="avatarUrl"
          v-model:uploading="uploading"
          shape="circle"
          size="lg"
          confirmText="Фото профиля будет удалено."
        />
      </div>

      <div>
        <p class="label">Имя <span class="text-red-500">*</span></p>
        <input
          v-model="name"
          type="text"
          :class="['input', nameTouched && !name.trim() ? 'input-error' : '']"
          placeholder="Как к вам обращаться"
          @blur="nameTouched = true"
          @keydown.enter="save"
        />
        <p v-if="nameTouched && !name.trim()" class="mt-1 text-xs text-red-500">Обязательное поле</p>
      </div>

      <div>
        <p class="label">Email</p>
        <div class="input bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 cursor-default select-all">
          {{ authStore.user?.email }}
        </div>
        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">Email — логин для входа, менять его здесь нельзя</p>
      </div>

      <div>
        <p class="label">Телефон</p>
        <input
          :value="phone"
          type="tel"
          class="input"
          placeholder="+7 (999) 123-45-67"
          @input="handlePhoneInput($event, (v) => phone = v)"
        />
      </div>

      <div v-if="error"   class="text-sm text-red-600 dark:text-red-400">{{ error }}</div>
      <div v-if="success" class="text-sm text-green-600 dark:text-green-400">Профиль сохранён!</div>

      <button @click="save" :disabled="saving || uploading" class="btn-primary">
        {{ saving ? 'Сохранение...' : 'Сохранить' }}
      </button>
    </div>
  </div>
</template>
