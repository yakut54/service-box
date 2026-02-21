import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { WidgetApi } from '@/lib/api'

export const useShopStore = defineStore('sb-shop', () => {
  const shopId = ref('')
  const apiUrl = ref('')
  const shop = ref<{ id: string; name: string; widget_config: Record<string, any> | null; timezone: string | null } | null>(null)
  const loading = ref(false)
  const error = ref('')

  const theme = ref<'light' | 'dark'>('light')
  const isOpen = ref(false)

  let api: WidgetApi | null = null
  let onCloseCallback: (() => void) | null = null

  const config = computed(() => shop.value?.widget_config || {})

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
    if (onCloseCallback) onCloseCallback()
  }

  function setOnClose(cb: () => void) {
    onCloseCallback = cb
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
    } catch (e: any) {
      error.value = e.message || 'Failed to load shop'
    }
    loading.value = false
  }

  function applyTheme(el: HTMLElement) {
    const c = config.value
    if (!c || !el) return
    if (c.primary_color) el.style.setProperty('--sb-primary', c.primary_color)
    if (c.secondary_color) el.style.setProperty('--sb-secondary', c.secondary_color)
    if (c.font_family) el.style.setProperty('--sb-font', c.font_family)
    if (c.border_radius != null) el.style.setProperty('--sb-radius', `${c.border_radius}px`)
    el.setAttribute('data-theme', theme.value)
  }

  return {
    shopId, apiUrl, shop, loading, error, config,
    theme, isOpen,
    getApi, loadConfig, applyTheme,
    loadTheme, toggleTheme, close, setOnClose,
  }
})
