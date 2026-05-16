<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/lib/api'
import UiConfirmDialog from '@/shared/ui/UiConfirmDialog.vue'

type FontFamily = 'system' | 'inter' | 'roboto' | 'montserrat' | 'georgia'

const FONT_OPTIONS: { id: FontFamily; label: string; css: string }[] = [
  { id: 'system',     label: 'Системный', css: 'system-ui, sans-serif' },
  { id: 'inter',      label: 'Inter',     css: "'Inter', sans-serif" },
  { id: 'roboto',     label: 'Roboto',    css: "'Roboto', sans-serif" },
  { id: 'montserrat', label: 'Montserrat', css: "'Montserrat', sans-serif" },
  { id: 'georgia',    label: 'Georgia',   css: 'Georgia, serif' },
]

const authStore = useAuthStore()

const hasFeature = computed(() =>
  ['business', 'pro'].includes(authStore.shop?.subscription_plan ?? '')
)
const hasProFeature = computed(() =>
  authStore.shop?.subscription_plan === 'pro'
)

const preset         = ref<'light' | 'dark' | 'minimal'>('light')
const color          = ref('#6366f1')
const font           = ref<FontFamily>('inter')
const sidebarPos     = ref<'left' | 'right'>('left')
const bgEnabled      = ref(false)
const bgColor        = ref('#ffffff')
const borderRadius   = ref<4 | 8 | 16>(8)
const showPrice       = ref(true)
const showDuration    = ref(true)
const showMasterName  = ref(true)
const showDescription = ref(true)
const logoUrl        = ref<string | null>(null)
const whiteLabel     = ref(false)
const customCss      = ref('')
const saving         = ref(false)
const success        = ref(false)
const error          = ref('')
const uploadingLogo  = ref(false)
const logoError      = ref('')
const confirmDelete  = ref(false)

const previewIframeEl = ref<HTMLIFrameElement | null>(null)
const previewLoaded   = ref(false)
const previewVisible  = ref(false)

const _API_BASE = (import.meta.env.VITE_API_URL as string | undefined)?.replace(/\/api\/?$/, '') ?? ''
const previewUrl = computed(() => {
  const key = authStore.shop?.api_key
  if (!key) return null
  return `${_API_BASE || window.location.origin}/book/${key}?preview=1`
})
const fullPreviewUrl = computed(() => {
  const key = authStore.shop?.api_key
  if (!key) return null
  return `${_API_BASE || window.location.origin}/book/${key}`
})

onMounted(() => {
  // Load Google Fonts for preview buttons
  ;['Inter:wght@400;600', 'Roboto:wght@400;700', 'Montserrat:wght@400;600'].forEach(f => {
    const url = `https://fonts.googleapis.com/css2?family=${f}&display=swap`
    if (!document.querySelector(`link[href="${url}"]`)) {
      const link = Object.assign(document.createElement('link'), { rel: 'stylesheet', href: url })
      document.head.appendChild(link)
    }
  })

  const wc = authStore.shop?.widget_config
  if (wc) {
    if (wc.preset)                 preset.value        = wc.preset as 'light' | 'dark' | 'minimal'
    if (wc.primary_color)          color.value         = wc.primary_color
    if (wc.font_family)            font.value          = wc.font_family as FontFamily
    if (wc.sidebar_position)       sidebarPos.value    = wc.sidebar_position as 'left' | 'right'
    if (wc.bg_color)               { bgEnabled.value = true; bgColor.value = wc.bg_color }
    if (wc.border_radius != null)  borderRadius.value  = wc.border_radius as 4 | 8 | 16
    if (wc.show_price       != null) showPrice.value       = wc.show_price
    if (wc.show_duration    != null) showDuration.value    = wc.show_duration
    if (wc.show_master_name != null) showMasterName.value  = wc.show_master_name
    if (wc.show_description != null) showDescription.value = wc.show_description
    logoUrl.value    = wc.logo_url   ?? null
    whiteLabel.value = wc.white_label ?? false
    customCss.value  = wc.custom_css  ?? ''
  }
})

function dataURLtoBlob(dataUrl: string): Blob {
  const [header, data] = dataUrl.split(',')
  const mime = header.match(/:(.*?);/)![1]
  const binary = atob(data)
  const arr = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i++) arr[i] = binary.charCodeAt(i)
  return new Blob([arr], { type: mime })
}

function compressImage(file: File, maxBytes: number): Promise<File> {
  return new Promise((resolve, reject) => {
    const img = new Image()
    const objectUrl = URL.createObjectURL(file)
    img.onload = () => {
      URL.revokeObjectURL(objectUrl)
      const canvas = document.createElement('canvas')
      let { width, height } = img
      let scale = 1
      const tryCompress = (q: number): File | null => {
        canvas.width  = Math.round(width  * scale)
        canvas.height = Math.round(height * scale)
        canvas.getContext('2d')!.drawImage(img, 0, 0, canvas.width, canvas.height)
        const dataUrl = canvas.toDataURL('image/jpeg', q)
        const bytes = Math.round((dataUrl.length - 'data:image/jpeg;base64,'.length) * 0.75)
        if (bytes <= maxBytes) {
          return new File([dataURLtoBlob(dataUrl)], file.name.replace(/\.[^.]+$/, '.jpg'), { type: 'image/jpeg' })
        }
        return null
      }
      for (const q of [0.8, 0.6, 0.4]) {
        const result = tryCompress(q)
        if (result) { resolve(result); return }
      }
      scale = 0.7
      for (const q of [0.8, 0.6, 0.4]) {
        const result = tryCompress(q)
        if (result) { resolve(result); return }
      }
      reject(new Error('Не удалось сжать изображение до 1 МБ'))
    }
    img.onerror = () => { URL.revokeObjectURL(objectUrl); reject(new Error('Ошибка чтения файла')) }
    img.src = objectUrl
  })
}

async function uploadLogo(e: Event) {
  const raw = (e.target as HTMLInputElement).files?.[0]
  if (!raw) return
  const MAX_BYTES = 1 * 1024 * 1024
  logoError.value = ''
  if (!raw.type.startsWith('image/')) { logoError.value = 'Выберите изображение'; return }
  uploadingLogo.value = true
  const oldUrl = logoUrl.value
  try {
    const file = raw.size > MAX_BYTES ? await compressImage(raw, MAX_BYTES) : raw
    const res = await api.uploadImage(file)
    logoUrl.value = res.url
    if (oldUrl) await api.deleteImage(oldUrl).catch(() => {})
    await saveConfig()
  } catch (err) {
    logoError.value = err instanceof Error ? err.message : 'Ошибка загрузки логотипа'
  } finally {
    uploadingLogo.value = false
  }
}

async function removeLogo() {
  const url = logoUrl.value
  logoUrl.value = null
  if (url) await api.deleteImage(url).catch(() => {})
}

function sendPreviewConfig() {
  previewIframeEl.value?.contentWindow?.postMessage({
    type: 'sb-preview-config',
    config: {
      preset:           preset.value,
      primary_color:    color.value,
      font_family:      font.value,
      sidebar_position: sidebarPos.value,
      bg_color:         bgEnabled.value ? bgColor.value : null,
      border_radius:    borderRadius.value,
    },
  }, '*')
}

function onPreviewLoad() {
  previewLoaded.value = true
  sendPreviewConfig()
}

watch(
  [preset, color, font, sidebarPos, bgEnabled, bgColor, borderRadius],
  () => { if (previewLoaded.value) sendPreviewConfig() },
)

async function saveConfig() {
  saving.value  = true
  error.value   = ''
  success.value = false
  try {
    const updated = await api.updateShop({
      widget_config: {
        preset:           preset.value,
        primary_color:    color.value,
        font_family:      font.value,
        sidebar_position: sidebarPos.value,
        bg_color:         bgEnabled.value ? bgColor.value : null,
        border_radius:    borderRadius.value,
        logo_url:         logoUrl.value,
        show_price:       showPrice.value,
        show_duration:    showDuration.value,
        show_master_name: showMasterName.value,
        show_description: showDescription.value,
        white_label:      whiteLabel.value,
        custom_css:       customCss.value || null,
      },
    })
    if (authStore.shop) authStore.shop.widget_config = updated.widget_config
    success.value = true
    setTimeout(() => success.value = false, 3000)
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Ошибка сохранения'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="card">
    <div class="flex flex-col xl:flex-row xl:items-start gap-8">

    <!-- Settings column -->
    <div class="flex-1 min-w-0">
      <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Внешний вид виджета</h2>
      <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Цвет, логотип и отображение элементов</p>

    <div v-if="!hasFeature" class="flex items-start gap-3 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
      <svg class="w-5 h-5 text-gray-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m0 0v2m0-2h2m-2 0H10m2-6a4 4 0 100-8 4 4 0 000 8z" />
      </svg>
      <div>
        <div class="font-medium text-gray-700 dark:text-gray-300 text-sm">Недоступно на текущем тарифе</div>
        <div class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Кастомизация виджета доступна на тарифе <span class="font-medium">Business и выше</span>.</div>
      </div>
    </div>

    <div v-else class="space-y-5">

      <!-- Preset themes -->
      <div>
        <label class="label mb-2">Тема виджета</label>
        <div class="grid grid-cols-3 gap-3">
          <button
            v-for="t in [
              { id: 'light',   label: 'Светлая',    bg: 'bg-white border border-gray-200',       bar1: 'bg-gray-200',    bar2: 'bg-gray-100'  },
              { id: 'dark',    label: 'Тёмная',     bg: 'bg-slate-900',                           bar1: 'bg-slate-600',   bar2: 'bg-slate-700' },
              { id: 'minimal', label: 'Минимализм', bg: 'bg-white border border-gray-100',        bar1: 'bg-gray-100',    bar2: 'bg-gray-50'   },
            ]"
            :key="t.id"
            type="button"
            @click="preset = t.id as 'light' | 'dark' | 'minimal'"
            :class="['rounded-xl border-2 p-3 text-left transition-all cursor-pointer w-full',
              preset === t.id
                ? 'border-indigo-500 dark:border-indigo-400'
                : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600']"
          >
            <div :class="['rounded-lg p-2 mb-2 h-16 flex flex-col justify-between', t.bg]">
              <div class="space-y-1">
                <div :class="['h-1.5 rounded w-3/4', t.bar1]" />
                <div :class="['h-1.5 rounded w-1/2', t.bar2]" />
              </div>
              <div class="h-4 rounded-md" :style="{ background: color }" />
            </div>
            <div :class="['text-xs font-medium', preset === t.id ? 'text-indigo-600 dark:text-indigo-400' : 'text-gray-600 dark:text-gray-400']">
              {{ t.label }}
            </div>
          </button>
        </div>
      </div>

      <!-- Color -->
      <div>
        <label class="label">Основной цвет</label>
        <div class="flex items-center gap-3">
          <input type="color" v-model="color" class="w-10 h-10 rounded cursor-pointer border border-gray-200 dark:border-gray-700 p-0.5 bg-white dark:bg-gray-800" />
          <input type="text" v-model="color" class="input w-32 font-mono text-sm" placeholder="#6366f1" />
          <div class="flex-1 h-10 rounded-lg transition-colors" :style="{ background: color }" />
        </div>
        <p class="text-xs text-gray-400 mt-1">Кнопки, ссылки, акценты в виджете</p>
      </div>

      <!-- Font family -->
      <div>
        <label class="label mb-2">Шрифт виджета</label>
        <div class="grid grid-cols-5 gap-2">
          <button
            v-for="f in FONT_OPTIONS"
            :key="f.id"
            type="button"
            @click="font = f.id"
            :class="['rounded-xl border-2 p-2 text-center transition-all cursor-pointer',
              font === f.id
                ? 'border-indigo-500 dark:border-indigo-400 bg-indigo-50 dark:bg-indigo-950'
                : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600']"
          >
            <div
              class="text-xl font-semibold mb-1 leading-none text-gray-800 dark:text-gray-100"
              :style="{ fontFamily: f.css }"
            >Aa</div>
            <div :class="['text-xs truncate', font === f.id ? 'text-indigo-600 dark:text-indigo-400' : 'text-gray-500 dark:text-gray-400']">
              {{ f.label }}
            </div>
          </button>
        </div>
      </div>

      <!-- Background color override -->
      <div>
        <label class="flex items-center gap-2 cursor-pointer select-none mb-2">
          <input type="checkbox" v-model="bgEnabled" class="w-4 h-4 rounded text-indigo-600" />
          <span class="label mb-0">Свой цвет фона</span>
        </label>
        <div v-if="bgEnabled" class="flex items-center gap-3">
          <input type="color" v-model="bgColor" class="w-10 h-10 rounded cursor-pointer border border-gray-200 dark:border-gray-700 p-0.5 bg-white dark:bg-gray-800" />
          <input type="text" v-model="bgColor" class="input w-32 font-mono text-sm" placeholder="#ffffff" />
          <div class="flex-1 h-10 rounded-lg border border-gray-200 dark:border-gray-700 transition-colors" :style="{ background: bgColor }" />
        </div>
        <p class="text-xs text-gray-400 mt-1">Переопределяет цвет фона выбранной темы</p>
      </div>

      <!-- Logo + visibility / Border radius + Sidebar position -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 items-start">

        <!-- Left: Logo + Show/hide elements side by side -->
        <div class="flex gap-5 items-start">
          <!-- Logo -->
          <div class="shrink-0">
            <label class="label">Логотип магазина</label>
            <div v-if="logoUrl" class="flex items-center gap-3 mb-2">
              <img :src="logoUrl" class="h-12 w-12 object-contain rounded border border-gray-200 dark:border-gray-700 bg-white p-1" />
              <button @click="confirmDelete = true" class="text-sm text-red-500 hover:text-red-700">Удалить</button>
            </div>
            <label class="flex items-center gap-2 cursor-pointer w-fit">
              <span class="btn-secondary text-sm">{{ uploadingLogo ? 'Загрузка...' : logoUrl ? 'Изменить логотип' : 'Загрузить логотип' }}</span>
              <input type="file" accept="image/*" @change="uploadLogo" class="hidden" :disabled="uploadingLogo" />
            </label>
            <p v-if="logoError" class="text-xs text-red-500 mt-1">{{ logoError }}</p>
            <p v-else class="text-xs text-gray-400 mt-1">PNG или SVG, рекомендуется квадратный</p>
          </div>

          <!-- Show/hide elements -->
          <div>
            <label class="label mb-2">Показывать в карточке услуги</label>
            <div class="space-y-2">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="checkbox" v-model="showPrice" class="w-4 h-4 rounded text-indigo-600" />
                <span class="text-sm text-gray-700 dark:text-gray-300">Цену</span>
              </label>
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="checkbox" v-model="showDuration" class="w-4 h-4 rounded text-indigo-600" />
                <span class="text-sm text-gray-700 dark:text-gray-300">Длительность</span>
              </label>
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="checkbox" v-model="showMasterName" class="w-4 h-4 rounded text-indigo-600" />
                <span class="text-sm text-gray-700 dark:text-gray-300">Имя мастера</span>
              </label>
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="checkbox" v-model="showDescription" class="w-4 h-4 rounded text-indigo-600" />
                <span class="text-sm text-gray-700 dark:text-gray-300">Описание</span>
              </label>
            </div>
          </div>
        </div>

        <!-- Right: Border radius + Sidebar position -->
        <div class="space-y-5">
          <!-- Border radius -->
          <div>
            <label class="label">Скругление кнопок</label>
            <div class="flex gap-2">
              <button
                v-for="opt in ([{ v: 4, label: 'Острые' }, { v: 8, label: 'Средние' }, { v: 16, label: 'Круглые' }] as const)"
                :key="opt.v"
                @click="borderRadius = opt.v"
                :class="['btn-secondary text-sm flex-1 transition-all', borderRadius === opt.v ? 'ring-2 ring-indigo-500' : '']"
                :style="{ borderRadius: opt.v + 'px' }">
                {{ opt.label }}
              </button>
            </div>
          </div>

          <!-- Sidebar position -->
          <div>
            <label class="label">Навигация в виджете</label>
            <div class="flex gap-2">
              <button
                v-for="opt in ([{ v: 'left', label: '← Слева' }, { v: 'right', label: 'Справа →' }] as const)"
                :key="opt.v"
                type="button"
                @click="sidebarPos = opt.v"
                :class="['btn-secondary text-sm flex-1 transition-all', sidebarPos === opt.v ? 'ring-2 ring-indigo-500' : '']"
              >
                {{ opt.label }}
              </button>
            </div>
            <p class="text-xs text-gray-400 mt-1">С какой стороны виджета отображается боковое меню</p>
          </div>
        </div>

      </div>

      <!-- Pro: White label + Custom CSS -->
      <div v-if="hasProFeature" class="pt-4 border-t border-gray-200 dark:border-gray-700 space-y-4">
        <div class="flex items-center justify-between">
          <div>
            <div class="text-sm font-medium text-gray-700 dark:text-gray-300">Убрать «Powered by ServiceBox»</div>
            <div class="text-xs text-gray-400 mt-0.5">Виджет полностью под вашим брендом</div>
          </div>
          <button
            type="button"
            @click="whiteLabel = !whiteLabel"
            :class="['relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
              whiteLabel ? 'bg-indigo-600' : 'bg-gray-200 dark:bg-gray-700']"
          >
            <span :class="['inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
              whiteLabel ? 'translate-x-6' : 'translate-x-1']" />
          </button>
        </div>
        <div>
          <label class="label">Свой CSS <span class="text-gray-400 font-normal">(переопределяет стили виджета)</span></label>
          <textarea v-model="customCss" class="input font-mono text-xs" rows="6" placeholder=".sb-catalog-card { border-radius: 12px; }" />
          <p class="text-xs text-gray-400 mt-1">CSS применяется внутри Shadow DOM виджета</p>
        </div>
      </div>
      <div v-else class="pt-4 border-t border-gray-200 dark:border-gray-700">
        <p class="text-xs text-gray-400">White label и Custom CSS доступны на тарифе <span class="font-medium">Pro</span></p>
      </div>

      <!-- Mobile: preview toggle -->
      <button
        v-if="previewUrl"
        type="button"
        @click="previewVisible = !previewVisible"
        class="btn-secondary w-full xl:hidden text-sm"
      >{{ previewVisible ? 'Скрыть предпросмотр' : 'Предпросмотр' }}</button>

      <div v-if="success" class="text-sm text-green-600 dark:text-green-400">Сохранено!</div>
      <div v-if="error"   class="text-sm text-red-600">{{ error }}</div>
      <button @click="saveConfig" :disabled="saving" class="btn-primary">
        {{ saving ? 'Сохранение...' : 'Сохранить внешний вид' }}
      </button>
    </div>
    </div><!-- /settings column -->

    <!-- Preview column -->
    <div
      v-if="hasFeature && previewUrl"
      class="xl:w-[360px] w-full shrink-0 xl:sticky xl:top-4 xl:self-start"
      :class="previewVisible ? 'block' : 'hidden xl:block'"
    >
      <div class="flex items-center justify-between mb-2">
        <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Предпросмотр</span>
        <a
          :href="fullPreviewUrl ?? '#'"
          target="_blank"
          rel="noopener"
          class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline flex items-center gap-1"
        >
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
          </svg>
          Смотреть как клиент
        </a>
      </div>
      <div class="rounded-xl overflow-hidden border border-gray-200 dark:border-gray-700" style="height:600px;">
        <iframe
          ref="previewIframeEl"
          :src="previewUrl"
          class="w-full h-full border-0"
          @load="onPreviewLoad"
        />
      </div>
    </div>

    </div><!-- /flex wrapper -->
  </div>

  <UiConfirmDialog
    v-model="confirmDelete"
    title="Удалить логотип?"
    confirmLabel="Удалить"
    @confirm="removeLogo(); confirmDelete = false; saveConfig()"
  >
    Логотип будет удалён с сервера без возможности восстановления.
  </UiConfirmDialog>
</template>
