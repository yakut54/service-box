import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { WidgetApi } from '@/lib/api'
import type { WidgetShop, WidgetConfig } from '@/types'

export type WidgetMode = 'popup' | 'inline' | 'auto'

function hexToRgb(hex: string): [number, number, number] | null {
  const clean = hex.replace('#', '')
  const full = clean.length === 3 ? clean.split('').map(c => c + c).join('') : clean
  const r = parseInt(full.slice(0, 2), 16)
  const g = parseInt(full.slice(2, 4), 16)
  const b = parseInt(full.slice(4, 6), 16)
  return isNaN(r) || isNaN(g) || isNaN(b) ? null : [r, g, b]
}

function isDark(hex: string): boolean {
  const rgb = hexToRgb(hex)
  return rgb ? (0.299 * rgb[0] + 0.587 * rgb[1] + 0.114 * rgb[2]) / 255 < 0.5 : false
}

export const useShopStore = defineStore('sb-shop', () => {
  const shopId = ref('')
  const apiUrl = ref('')
  const shop = ref<WidgetShop | null>(null)
  const loading = ref(false)
  const error = ref('')

  const isOpen = ref(false)

  const mode = ref<WidgetMode>('popup')
  const deepLinkServiceId = ref<string | null>(null)
  const prefillName = ref<string | null>(null)
  const prefillPhone = ref<string | null>(null)
  const prefillEmail = ref<string | null>(null)
  const bookingInProgress = ref(false)
  const sessionId = ref('')

  function initSession() {
    const key = `sb-sid:${shopId.value}`
    let sid = sessionStorage.getItem(key)
    if (!sid) {
      sid = (crypto.randomUUID?.() ?? Math.random().toString(36).slice(2) + Date.now().toString(36))
      sessionStorage.setItem(key, sid)
    }
    sessionId.value = sid
  }

  let api: WidgetApi | null = null

  const previewConfig = ref<Partial<WidgetConfig> | null>(null)

  const config = computed<WidgetConfig>(() => ({
    ...(shop.value?.widget_config ?? {}),
    ...(previewConfig.value ?? {}),
  }))

  function getApi(): WidgetApi {
    if (!api) {
      api = new WidgetApi(shopId.value, apiUrl.value)
    }
    return api
  }

  function close() {
    isOpen.value = false
  }

  async function loadConfig() {
    if (!shopId.value) {
      error.value = 'Shop ID is not set'
      return
    }
    loading.value = true
    error.value = ''
    try {
      shop.value = await getApi().getShop()
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : 'Failed to load shop'
    }
    loading.value = false
  }

  const PRESETS: Record<string, Record<string, string>> = {
    light: {
      '--sb-bg': '#ffffff', '--sb-bg-muted': '#f9fafb',
      '--sb-border': '#e5e7eb', '--sb-text': '#1f2937', '--sb-text-muted': '#6b7280',
    },
    dark: {
      '--sb-bg': '#0f172a', '--sb-bg-muted': '#1e293b',
      '--sb-border': '#334155', '--sb-text': '#f1f5f9', '--sb-text-muted': '#94a3b8',
      '--sb-primary-light': '#1e1b4b',
    },
    minimal: {
      '--sb-bg': '#ffffff', '--sb-bg-muted': '#fafafa',
      '--sb-border': '#f3f4f6', '--sb-text': '#111827', '--sb-text-muted': '#9ca3af',
    },
  }

  const FONTS: Record<string, { css: string; url?: string }> = {
    system:     { css: 'system-ui, -apple-system, BlinkMacSystemFont, sans-serif' },
    inter:      { css: "'Inter', sans-serif",      url: 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap' },
    roboto:     { css: "'Roboto', sans-serif",     url: 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap' },
    montserrat: { css: "'Montserrat', sans-serif", url: 'https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600&display=swap' },
    georgia:    { css: 'Georgia, serif' },
  }

  function applyTheme(el: HTMLElement) {
    const c = config.value
    if (!c || !el) return
    const preset = (c.preset ?? 'light') as string
    const vars = PRESETS[preset] ?? PRESETS.light
    for (const [key, val] of Object.entries(vars)) {
      el.style.setProperty(key, val)
    }
    if (c.primary_color) {
      el.style.setProperty('--sb-primary', c.primary_color)
      const rgb = hexToRgb(c.primary_color)
      if (rgb) {
        el.style.setProperty('--sb-primary-hover', `rgb(${rgb.map(v => Math.max(0, Math.round(v * 0.82))).join(',')})`)
        el.style.setProperty('--sb-primary-light', `rgba(${rgb.join(',')},0.15)`)
      }
    }
    if (c.border_radius != null) el.style.setProperty('--sb-radius', `${c.border_radius}px`)
    if (c.bg_color) el.style.setProperty('--sb-bg', c.bg_color)
    if (c.page_bg_color) el.style.setProperty('--sb-bg-muted', c.page_bg_color)
    if (c.text_color) {
      el.style.setProperty('--sb-text', c.text_color)
      const rgb = hexToRgb(c.text_color)
      if (rgb) el.style.setProperty('--sb-text-muted', `rgba(${rgb.join(',')},0.5)`)
    }
    const borderBase = c.page_bg_color ?? c.bg_color
    if (borderBase) {
      el.style.setProperty('--sb-border', isDark(borderBase) ? 'rgba(255,255,255,0.12)' : 'rgba(0,0,0,0.08)')
    }
    const fontKey = c.font_family ?? 'inter'
    const f = FONTS[fontKey] ?? FONTS.inter
    if (f.url && !document.querySelector(`link[data-sb-font="${fontKey}"]`)) {
      const link = document.createElement('link')
      link.rel = 'stylesheet'
      link.href = f.url
      link.dataset.sbFont = fontKey
      document.head.appendChild(link)
    }
    el.style.setProperty('--sb-font', f.css)
  }

  return {
    shopId, apiUrl, shop, loading, error, config, previewConfig,
    isOpen,
    mode, deepLinkServiceId, prefillName, prefillPhone, prefillEmail, bookingInProgress,
    sessionId, initSession,
    getApi, loadConfig, applyTheme, close,
  }
})
