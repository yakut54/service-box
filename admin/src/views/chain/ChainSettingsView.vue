<script setup lang="ts">
import { ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import ImageUpload from '@/components/ImageUpload.vue'
import SettingsProfile from '@/components/settings/SettingsProfile.vue'
import SettingsPassword from '@/components/settings/SettingsPassword.vue'
import PageHeader from '@/components/PageHeader.vue'

const authStore = useAuthStore()

const name          = ref(authStore.chain?.name ?? '')
const logoUrl       = ref<string | null>(authStore.chain?.logo_url ?? null)
const uploadingLogo = ref(false)
const nameTouched   = ref(false)
const saving        = ref(false)
const success       = ref(false)
const error         = ref('')

// authStore.chain может подъехать уже после mount (жёсткий refresh) —
// подхватываем, пока форму ещё не трогали, иначе «Сохранить» ушёл бы с
// пустым названием и затёр то, что уже было (тот же баг, что был у логотипа
// магазина, см. SettingsBrand.vue).
watch(() => authStore.chain, (c) => {
  if (!nameTouched.value) name.value = c?.name ?? ''
  if (logoUrl.value === (authStore.chain?.logo_url ?? null)) logoUrl.value = c?.logo_url ?? null
})

function onLogoChange(newUrl: string | null) {
  if (typeof newUrl === 'string' && newUrl.startsWith('blob:')) return
  save()
}

async function save() {
  nameTouched.value = true
  if (!name.value.trim()) return

  saving.value  = true
  error.value   = ''
  success.value = false

  const result = await authStore.updateChainSettings({
    name: name.value.trim(),
    logo_url: logoUrl.value,
  })

  if (result.success) {
    success.value = true
    setTimeout(() => success.value = false, 3000)
  } else {
    error.value = result.error || 'Не удалось сохранить настройки сети'
  }
  saving.value = false
}
</script>

<template>
  <div class="space-y-6">
    <PageHeader title="Настройки сети" subtitle="Название и логотип — общие для всех точек, отображаются в мобильном приложении" />

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
      <div class="card">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Бренд сети</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
          Один логотип на всю сеть — у отдельных точек своего логотипа нет
        </p>

        <div class="space-y-5">
          <div>
            <p class="label">Название сети <span class="text-red-500">*</span></p>
            <input
              v-model="name"
              type="text"
              :class="['input', nameTouched && !name.trim() ? 'input-error' : '']"
              placeholder="Барбариска"
              @blur="nameTouched = true"
              @keydown.enter="save"
            />
            <p v-if="nameTouched && !name.trim()" class="mt-1 text-xs text-red-500">Обязательное поле</p>
          </div>

          <div>
            <p class="label mb-2">Логотип сети</p>
            <ImageUpload
              v-model="logoUrl"
              v-model:uploading="uploadingLogo"
              size="xl"
              objectFit="contain"
              hint="PNG, WEBP · рекомендуется квадратный · используется в приложении и на странице заказа"
              confirmText="Логотип будет удалён с сервера без возможности восстановления."
              @update:modelValue="onLogoChange"
            />
          </div>

          <div v-if="success" class="text-sm text-green-600 dark:text-green-400">Сохранено!</div>
          <div v-if="error"   class="text-sm text-red-600">{{ error }}</div>
          <button @click="save" :disabled="saving || uploadingLogo" class="btn-primary">
            {{ saving ? 'Сохранение...' : 'Сохранить' }}
          </button>
        </div>
      </div>

      <div class="flex flex-col gap-6">
        <SettingsProfile />
        <SettingsPassword />
      </div>
    </div>
  </div>
</template>
