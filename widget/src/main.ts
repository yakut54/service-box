import { createApp, type App as VueApp } from 'vue'
import { createPinia } from 'pinia'
import AppComponent from './App.vue'
import { useShopStore } from './stores/shop'
import './styles/widget.css'

interface WidgetOptions {
  shopId: string
  apiUrl?: string
  container?: HTMLElement | string
}

interface WidgetInstance {
  app: VueApp
  container: HTMLElement
  destroy: () => void
}

function init(options: WidgetOptions | string): WidgetInstance {
  const opts: WidgetOptions = typeof options === 'string'
    ? { shopId: options }
    : options

  if (!opts.shopId) {
    throw new Error('[ServiceBox] shopId is required')
  }

  // Resolve API URL
  const apiUrl = opts.apiUrl
    || import.meta.env.VITE_API_URL
    || 'http://localhost:8080/api'

  // Find or create container
  let container: HTMLElement
  if (opts.container) {
    container = typeof opts.container === 'string'
      ? document.querySelector(opts.container)!
      : opts.container
    if (!container) {
      throw new Error(`[ServiceBox] Container not found: ${opts.container}`)
    }
  } else {
    container = document.createElement('div')
    container.id = `sb-widget-${Math.random().toString(36).slice(2, 8)}`
    document.body.appendChild(container)
  }

  // Create Vue app
  const app = createApp(AppComponent)
  const pinia = createPinia()
  app.use(pinia)

  // Configure shop store before mount
  const shopStore = useShopStore(pinia)
  shopStore.shopId = opts.shopId
  shopStore.apiUrl = apiUrl

  app.mount(container)

  return {
    app,
    container,
    destroy: () => {
      app.unmount()
      if (!opts.container) {
        container.remove()
      }
    },
  }
}

// ── Auto-init from script tag or DOM element ───────────────────
function autoInit() {
  // 1. Script tag: <script src="widget.js" data-shop-id="xxx">
  const script = document.currentScript as HTMLScriptElement | null
  if (script?.getAttribute('data-shop-id')) {
    init({
      shopId: script.getAttribute('data-shop-id')!,
      apiUrl: script.getAttribute('data-api-url') || undefined,
    })
    return
  }

  // 2. DOM element: <div id="servicebox-widget" data-shop-id="xxx">
  const el = document.getElementById('servicebox-widget')
  if (el?.getAttribute('data-shop-id')) {
    init({
      shopId: el.getAttribute('data-shop-id')!,
      apiUrl: el.getAttribute('data-api-url') || undefined,
      container: el,
    })
    return
  }

  // 3. Env variable (dev mode)
  const envShopId = import.meta.env.VITE_SHOP_ID
  if (envShopId) {
    const target = document.getElementById('servicebox-widget')
    init({
      shopId: envShopId,
      container: target || undefined,
    })
    return
  }

  // No auto-init — user will call ServiceBoxWidget.init() manually
}

// Run auto-init when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', autoInit)
} else {
  autoInit()
}

// Export for programmatic use
;(window as any).ServiceBoxWidget = { init }
export { init }
