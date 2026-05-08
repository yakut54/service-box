import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { WidgetApi } from '@/lib/api'
import type { WidgetShop, WidgetConfig } from '@/types'

export type WidgetMode = 'popup' | 'inline' | 'auto'

export const useShopStore = defineStore('sb-shop', () => {
  const shopId = ref('')
  const apiUrl = ref('')
  const shop = ref<WidgetShop | null>(null)
  const loading = ref(false)
  const error = ref('')

  const theme = ref<'light' | 'dark'>('light')
  const isOpen = ref(false)

  const mode = ref<WidgetMode>('popup')
  const deepLinkServiceId = ref<string | null>(null)
  const prefillName = ref<string | null>(null)
  const prefillPhone = ref<string | null>(null)
  const prefillEmail = ref<string | null>(null)

  let api: WidgetApi | null = null

  const config = computed<WidgetConfig>(() => shop.value?.widget_config ?? {})

  function getApi(): WidgetApi {
    if (!api) {
      api = new WidgetApi(shopId.value, apiUrl.value)
    }
    return api
  }

  function loadTheme() {
    if (!shopId.value) return
    const saved = localStorage.getItem(`sb-theme:${shopId.value}`)
    if (saved === 'light' || saved === 'dark') {
      theme.value = saved
    }
  }

  function toggleTheme(el?: HTMLElement) {
    theme.value = theme.value === 'light' ? 'dark' : 'light'
    if (shopId.value) {
      localStorage.setItem(`sb-theme:${shopId.value}`, theme.value)
    }
    if (el) {
      el.setAttribute('data-theme', theme.value)
    }
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
      loadTheme()
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : 'Failed to load shop'
    }
    loading.value = false
  }

  function applyTheme(el: HTMLElement) {
    const c = config.value
    if (!c || !el) return
    if (c.primary_color) el.style.setProperty('--sb-primary', c.primary_color)
    if (c.border_radius != null) el.style.setProperty('--sb-radius', `${c.border_radius}px`)
    el.setAttribute('data-theme', theme.value)
  }

  return {
    shopId, apiUrl, shop, loading, error, config,
    theme, isOpen,
    mode, deepLinkServiceId, prefillName, prefillPhone, prefillEmail,
    getApi, loadConfig, applyTheme,
    loadTheme, toggleTheme, close,
  }
})
