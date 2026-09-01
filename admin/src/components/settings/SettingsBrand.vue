<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/lib/api'
import { parseApiError } from '@/lib/parseApiError'
import ImageUpload from '@/components/ImageUpload.vue'
import UiHint from '@/shared/ui/UiHint.vue'

const authStore = useAuthStore()

const color         = ref('#6366f1')
const logoUrl        = ref<string | null>(null)
const logoFit         = ref<'contain' | 'cover'>('contain')
const saving         = ref(false)
const success        = ref(false)
const error          = ref('')
const uploadingLogo  = ref(false)

onMounted(() => {
  const wc = authStore.shop?.widget_config
  if (wc) {
    if (wc.primary_color) color.value = wc.primary_color
    logoUrl.value = wc.logo_url ?? null
    logoFit.value = (wc.logo_fit as 'contain' | 'cover') ?? 'contain'
  }
})

function onLogoChange(newUrl: string | null) {
  if (typeof newUrl === 'string' && newUrl.startsWith('blob:')) return
  save()
}

async function save() {
  saving.value  = true
  error.value   = ''
  success.value = false
  try {
    const updated = await api.updateShop({
      widget_config: {
        primary_color: color.value,
        logo_url:      logoUrl.value,
        logo_fit:      logoFit.value,
      },
    })
    if (authStore.shop) authStore.shop.widget_config = updated.widget_config
    success.value = true
    setTimeout(() => success.value = false, 3000)
  } catch (e: unknown) {
    error.value = parseApiError(e, 'Не удалось сохранить бренд')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="card">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Бренд</h2>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Цвет и логотип — используются в приложении и на странице заказа</p>

    <div class="space-y-5">
      <!-- Primary color -->
      <div>
        <p class="label flex items-center gap-1">
          Основной цвет
          <UiHint>Кнопки, ссылки, акценты</UiHint>
        </p>
        <div class="flex items-center gap-2">
          <input type="color" v-model="color" aria-label="Основной цвет" class="w-10 h-10 rounded cursor-pointer border border-gray-200 dark:border-gray-700 p-0.5 bg-white dark:bg-gray-800" />
          <input type="text" v-model="color" aria-label="Hex основного цвета" class="input flex-1 font-mono text-sm" placeholder="#6366f1" />
        </div>
      </div>

      <!-- Logo -->
      <div>
        <p class="label mb-2">Логотип магазина</p>
        <div class="flex items-start gap-4">
          <ImageUpload
            v-model="logoUrl"
            v-model:uploading="uploadingLogo"
            size="xl"
            :objectFit="logoFit"
            hint="PNG, WEBP · рекомендуется квадратный"
            confirmText="Логотип будет удалён с сервера без возможности восстановления."
            @update:modelValue="onLogoChange"
          />
          <div>
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-2">Отображение</p>
            <div class="flex flex-col gap-1.5">
              <button
                v-for="opt in ([
                  { v: 'contain', label: 'Вписать',   desc: 'Лого целиком, без обрезки' },
                  { v: 'cover',   label: 'Заполнить', desc: 'Заполняет область, края срезаются' },
                ] as const)"
                :key="opt.v"
                type="button"
                @click="logoFit = opt.v"
                :class="['flex items-center gap-2 px-3 py-1.5 rounded-lg border text-left transition-all text-sm',
                  logoFit === opt.v
                    ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300'
                    : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-600']"
              >
                <span class="font-medium">{{ opt.label }}</span>
                <span class="text-xs text-gray-400 dark:text-gray-500">— {{ opt.desc }}</span>
              </button>
            </div>
          </div>
        </div>
      </div>

      <div v-if="success" class="text-sm text-green-600 dark:text-green-400">Сохранено!</div>
      <div v-if="error"   class="text-sm text-red-600">{{ error }}</div>
      <button @click="save" :disabled="saving || uploadingLogo" class="btn-primary">
        {{ saving ? 'Сохранение...' : 'Сохранить' }}
      </button>
    </div>
  </div>
</template>
