<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/lib/api'
import type { Product } from '@/types'
import CustomSelect from '@/components/CustomSelect.vue'

const authStore = useAuthStore()

type EmbedMode = 'popup' | 'inline' | 'auto'

const embedMode      = ref<EmbedMode>('popup')
const embedServiceId = ref('')
const embedProducts  = ref<Product[]>([])
const copied = ref(false)

const embedModes: { value: EmbedMode; label: string; desc: string }[] = [
  { value: 'popup',  label: 'Попап',        desc: 'Кнопка в углу экрана, виджет открывается поверх страницы' },
  { value: 'inline', label: 'Встроенный',   desc: 'Виджет встраивается прямо в блок на странице, всегда виден' },
  { value: 'auto',   label: 'Автооткрытие', desc: 'Попап, но открывается сразу при загрузке страницы' },
]

const typeLabel: Record<string, string> = { service: 'Услуга', physical: 'Товар', digital: 'Цифровой' }

const embedProductOptions = computed(() => [
  { value: '', label: 'Весь каталог (без deep link)' },
  ...embedProducts.value.map(p => ({
    value: p.id,
    label: `${p.name} · ${typeLabel[p.type] ?? p.type}`,
  })),
])

const generatedEmbedCode = computed(() => {
  const apiKey = authStore.shop?.api_key || 'YOUR_API_KEY'
  // Виджет отдаётся с того же домена, что и админка (nginx: /widget.js).
  const src = `${window.location.origin}/widget.js`
  const extras: string[] = []
  if (embedServiceId.value.trim())    extras.push(`data-service-id="${embedServiceId.value.trim()}"`)
  const extrasStr = extras.length ? ' ' + extras.join(' ') : ''

  if (embedMode.value === 'inline') {
    return `<div id="servicebox-widget"\n  data-shop-id="${apiKey}"\n  data-mode="inline"${extrasStr}\n  style="height:600px;">\n</div>\n<script src="${src}"><\/script>`
  }
  const modeAttr = embedMode.value === 'auto' ? ' data-mode="auto"' : ''
  return `<script src="${src}" data-shop-id="${apiKey}"${modeAttr}${extrasStr}><\/script>`
})

const qrCodeUrl = computed(() => {
  const domain = authStore.shop?.domain
  if (!domain) return null
  let url = domain.startsWith('http') ? domain : `https://${domain}`
  url += '?sb_open=1'
  if (embedServiceId.value.trim()) url += `&sb_service=${embedServiceId.value.trim()}`
  return url
})

const qrImgSrc = computed(() => {
  if (!qrCodeUrl.value) return null
  return `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(qrCodeUrl.value)}`
})

function copyCode() {
  navigator.clipboard.writeText(generatedEmbedCode.value)
  copied.value = true
  setTimeout(() => copied.value = false, 2000)
}

onMounted(async () => {
  try {
    const res = await api.getProducts({ per_page: '200' })
    embedProducts.value = res.data
  } catch {
    // не критично — поле ввода останется как fallback
  }
})
</script>

<template>
  <div class="card">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Код виджета</h2>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-4">Настройте режим и скопируйте код на сайт</p>

    <div class="flex gap-1 p-1 bg-gray-100 dark:bg-gray-800 rounded-lg mb-4">
      <button
        v-for="m in embedModes" :key="m.value"
        @click="embedMode = m.value"
        :class="['flex-1 text-sm py-1.5 px-2 rounded-md transition-all font-medium',
          embedMode === m.value
            ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm'
            : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300']"
      >{{ m.label }}</button>
    </div>
    <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ embedModes.find(m => m.value === embedMode)?.desc }}</p>

    <div class="mb-3">
      <p class="label">Открыть сразу на услуге <span class="text-gray-400 font-normal">(опционально)</span></p>
      <CustomSelect
        v-model="embedServiceId"
        :options="embedProductOptions"
        placeholder="Весь каталог (без deep link)"
        searchable
      />
    </div>

    <div class="relative">
      <pre class="bg-gray-900 text-green-400 p-4 rounded-lg overflow-x-auto text-sm whitespace-pre-wrap break-all">{{ generatedEmbedCode }}</pre>
      <button @click="copyCode" class="absolute top-2 right-2 btn-ghost btn-sm bg-gray-800 text-white hover:bg-gray-700">
        <svg v-if="!copied" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
        </svg>
        <svg v-else class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
        {{ copied ? 'Скопировано!' : 'Копировать' }}
      </button>
    </div>

    <div v-if="qrImgSrc" class="mt-5 pt-4 border-t border-gray-200 dark:border-gray-700">
      <div class="label mb-2">QR-код</div>
      <div class="flex items-start gap-4">
        <img :src="qrImgSrc" width="120" height="120" class="rounded border border-gray-200 dark:border-gray-700 bg-white" alt="QR-код виджета" />
        <div>
          <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Ведёт на:</p>
          <p class="text-xs font-mono text-gray-700 dark:text-gray-300 break-all">{{ qrCodeUrl }}</p>
          <p class="text-xs text-gray-400 dark:text-gray-500 mt-2">Клиент сканирует QR → попадает на сайт → виджет открывается автоматически</p>
        </div>
      </div>
    </div>
    <p v-else class="mt-3 text-xs text-gray-400 dark:text-gray-500">
      Чтобы получить QR-код, укажите домен сайта в разделе «Информация о магазине»
    </p>
  </div>
</template>
