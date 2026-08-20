<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/lib/api'
import { parseApiError } from '@/lib/parseApiError'
import UiCheckbox from '@/shared/ui/UiCheckbox.vue'

type FontFamily = 'system' | 'inter' | 'roboto' | 'montserrat' | 'georgia'

const FONT_OPTIONS: { id: FontFamily; label: string; css: string }[] = [
  { id: 'system',     label: 'Системный', css: 'system-ui, sans-serif' },
  { id: 'inter',      label: 'Inter',     css: "'Inter', sans-serif" },
  { id: 'roboto',     label: 'Roboto',    css: "'Roboto', sans-serif" },
  { id: 'montserrat', label: 'Montserrat', css: "'Montserrat', sans-serif" },
  { id: 'georgia',    label: 'Georgia',   css: 'Georgia, serif' },
]

const authStore = useAuthStore()

const preset         = ref<'light' | 'dark' | 'minimal'>('light')
const color          = ref('#6366f1')
const font           = ref<FontFamily>('inter')
const sidebarPos     = ref<'left' | 'right'>('left')
const bgEnabled      = ref(false)
const bgColor        = ref('#ffffff')
const pageBgEnabled  = ref(false)
const pageBgColor    = ref('#f1f5f9')
const textColorEnabled = ref(false)
const textColor      = ref('#1f2937')
const borderRadius   = ref<4 | 8 | 16>(8)
const showPrice       = ref(true)
const showDuration    = ref(true)
const showMasterName  = ref(true)
const showDescription = ref(true)
const logoUrl        = ref<string | null>(null)
const logoFit        = ref<'contain' | 'cover'>('contain')
const whiteLabel     = ref(false)
const customCss      = ref('')
const saving         = ref(false)
const success        = ref(false)
const error          = ref('')

const previewIframeEl = ref<HTMLIFrameElement | null>(null)
const previewLoaded   = ref(false)
const previewVisible  = ref(false)

const _API_BASE = (import.meta.env.VITE_API_URL as string | undefined)?.replace(/\/api\/?$/, '') ?? ''
const previewUrl = computed(() => {
  const key = authStore.shop?.api_key
  if (!key) return null
  return `${_API_BASE || window.location.origin}/book/${key}?preview=1`
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
    if (wc.page_bg_color)         { pageBgEnabled.value = true; pageBgColor.value = wc.page_bg_color }
    if (wc.text_color)            { textColorEnabled.value = true; textColor.value = wc.text_color }
    if (wc.border_radius != null)  borderRadius.value  = wc.border_radius as 4 | 8 | 16
    if (wc.show_price       != null) showPrice.value       = wc.show_price
    if (wc.show_duration    != null) showDuration.value    = wc.show_duration
    if (wc.show_master_name != null) showMasterName.value  = wc.show_master_name
    if (wc.show_description != null) showDescription.value = wc.show_description
    logoUrl.value    = wc.logo_url   ?? null
    logoFit.value    = (wc.logo_fit as 'contain' | 'cover') ?? 'contain'
    whiteLabel.value = wc.white_label ?? false
    customCss.value  = wc.custom_css  ?? ''
  }
})

function sendPreviewConfig() {
  previewIframeEl.value?.contentWindow?.postMessage({
    type: 'sb-preview-config',
    config: {
      preset:           preset.value,
      primary_color:    color.value,
      font_family:      font.value,
      sidebar_position: sidebarPos.value,
      bg_color:         bgEnabled.value ? bgColor.value : null,
      page_bg_color:    pageBgEnabled.value ? pageBgColor.value : null,
      text_color:       textColorEnabled.value ? textColor.value : null,
      border_radius:    borderRadius.value,
    },
  }, '*')
}

function onPreviewLoad() {
  previewLoaded.value = true
  sendPreviewConfig()
}

watch(
  [preset, color, font, sidebarPos, bgEnabled, bgColor, pageBgEnabled, pageBgColor, textColorEnabled, textColor, borderRadius],
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
        page_bg_color:    pageBgEnabled.value ? pageBgColor.value : null,
        text_color:       textColorEnabled.value ? textColor.value : null,
        border_radius:    borderRadius.value,
        logo_url:         logoUrl.value,
        logo_fit:         logoFit.value,
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
    error.value = parseApiError(e, 'Не удалось сохранить внешний вид виджета')
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

    <div class="space-y-5">

      <!-- Preset themes -->
      <div>
        <p class="label mb-2">Тема виджета</p>
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

      <!-- Colors -->
      <div class="space-y-4">
        <!-- Row 1: widget bg | page bg -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 items-start">
          <!-- Widget background color -->
          <div>
            <UiCheckbox v-model="bgEnabled" class="mb-2">
              <span class="label mb-0">Фон виджета</span>
            </UiCheckbox>
            <div v-if="bgEnabled" class="flex items-center gap-2">
              <input type="color" v-model="bgColor" aria-label="Цвет фона виджета" class="w-10 h-10 rounded cursor-pointer border border-gray-200 dark:border-gray-700 p-0.5 bg-white dark:bg-gray-800" />
              <input type="text" v-model="bgColor" aria-label="Hex цвета фона виджета" class="input flex-1 font-mono text-sm" placeholder="#ffffff" />
            </div>
            <p class="text-xs text-gray-400 mt-1">Переопределяет фон темы</p>
          </div>

          <!-- Secondary background color -->
          <div>
            <UiCheckbox v-model="pageBgEnabled" class="mb-2">
              <span class="label mb-0">Вторичный фон</span>
            </UiCheckbox>
            <div v-if="pageBgEnabled" class="flex items-center gap-2">
              <input type="color" v-model="pageBgColor" aria-label="Вторичный цвет фона" class="w-10 h-10 rounded cursor-pointer border border-gray-200 dark:border-gray-700 p-0.5 bg-white dark:bg-gray-800" />
              <input type="text" v-model="pageBgColor" aria-label="Hex вторичного цвета фона" class="input flex-1 font-mono text-sm" placeholder="#f9fafb" />
            </div>
            <p class="text-xs text-gray-400 mt-1">Фон панелей и контентной области</p>
          </div>
        </div>

        <!-- Row 2: text color -->
        <div>
          <UiCheckbox v-model="textColorEnabled" class="mb-2">
            <span class="label mb-0">Свой цвет текста</span>
          </UiCheckbox>
          <div v-if="textColorEnabled" class="flex items-center gap-2">
            <input type="color" v-model="textColor" aria-label="Цвет текста" class="w-10 h-10 rounded cursor-pointer border border-gray-200 dark:border-gray-700 p-0.5 bg-white dark:bg-gray-800" />
            <input type="text" v-model="textColor" aria-label="Hex цвета текста" class="input w-32 font-mono text-sm" placeholder="#1f2937" />
            <div
              class="flex-1 h-10 rounded-lg border border-gray-200 dark:border-gray-700 flex items-center px-3 transition-colors"
              :style="{ background: bgEnabled ? bgColor : '#ffffff', color: textColor }"
            >
              <span class="text-sm font-medium">Пример текста</span>
            </div>
          </div>
          <p class="text-xs text-gray-400 mt-1">Переопределяет цвет текста выбранной темы</p>
        </div>
      </div>

      <!-- Font family + Pro section -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 items-start">
        <!-- Left: Font family -->
        <div>
          <p class="label mb-2">Шрифт виджета</p>
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

        <!-- Right: white label + custom CSS -->
        <div class="space-y-4">
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
            <p class="label">Свой CSS <span class="text-gray-400 font-normal">(переопределяет стили виджета)</span></p>
            <textarea v-model="customCss" class="input font-mono text-xs" rows="6" placeholder=".sb-catalog-card { border-radius: 12px; }" />
            <p class="text-xs text-gray-400 mt-1">CSS применяется внутри Shadow DOM виджета</p>
          </div>
        </div>
      </div>

      <!-- Visibility / Border radius + Sidebar position -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 items-start">

        <!-- Left: Show/hide elements -->
        <div>
          <p class="label mb-2">Показывать в карточке услуги</p>
          <div class="space-y-2">
            <UiCheckbox v-model="showPrice">
              <span class="text-sm text-gray-700 dark:text-gray-300">Цену</span>
            </UiCheckbox>
            <UiCheckbox v-model="showDuration">
              <span class="text-sm text-gray-700 dark:text-gray-300">Длительность</span>
            </UiCheckbox>
            <UiCheckbox v-model="showMasterName">
              <span class="text-sm text-gray-700 dark:text-gray-300">Имя мастера</span>
            </UiCheckbox>
            <UiCheckbox v-model="showDescription">
              <span class="text-sm text-gray-700 dark:text-gray-300">Описание</span>
            </UiCheckbox>
          </div>
        </div>

        <!-- Right: Border radius + Sidebar position -->
        <div class="space-y-5">
          <!-- Border radius -->
          <div>
            <p class="label">Скругление кнопок</p>
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
            <p class="label">Навигация в виджете</p>
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
      v-if="previewUrl"
      class="xl:w-[360px] w-full shrink-0 xl:sticky xl:top-4 xl:self-start"
      :class="previewVisible ? 'block' : 'hidden xl:block'"
    >
      <div class="flex items-center justify-between mb-2">
        <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Предпросмотр</span>
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

</template>
