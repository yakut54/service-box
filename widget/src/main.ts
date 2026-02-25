import { createApp, watch, type App as VueApp } from 'vue'
import { createPinia } from 'pinia'
import AppComponent from './App.vue'
import { useShopStore } from './stores/shop'
import widgetCss from './styles/widget.css?inline'
import fabCss from './styles/fab.css?inline'

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

  // Create Shadow DOM for style isolation
  const shadow = container.attachShadow({ mode: 'open' })

  // Inject styles into shadow root
  const style = document.createElement('style')
  style.textContent = widgetCss
  shadow.appendChild(style)

  // Create mount point inside shadow
  const mountEl = document.createElement('div')
  shadow.appendChild(mountEl)

  // Create Vue app
  const app = createApp(AppComponent)
  const pinia = createPinia()
  app.use(pinia)

  // Configure shop store before mount
  const shopStore = useShopStore(pinia)
  shopStore.shopId = opts.shopId
  shopStore.apiUrl = apiUrl

  // ── FAB button ────────────────────────────────────────────
  const fabId = `sb-fab-${opts.shopId}`
  const fabStyleId = `sb-fab-style-${opts.shopId}`

  // Inject FAB styles into document head (outside shadow DOM)
  let fabStyleEl = document.getElementById(fabStyleId) as HTMLStyleElement | null
  if (!fabStyleEl) {
    fabStyleEl = document.createElement('style')
    fabStyleEl.id = fabStyleId
    fabStyleEl.textContent = fabCss
    document.head.appendChild(fabStyleEl)
  }

  // Create FAB button
  const fab = document.createElement('button')
  fab.id = fabId
  fab.className = 'sb-fab'
  fab.setAttribute('aria-label', 'Открыть магазин')
  fab.innerHTML = `<svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>`
  document.body.appendChild(fab)

  // Restore open state from localStorage
  const openKey = `sb-open:${opts.shopId}`
  const wasOpen = localStorage.getItem(openKey) === 'true'
  let configLoaded = false

  function showFab() {
    fab.classList.remove('sb-fab--hidden')
  }

  function hideFab() {
    fab.classList.add('sb-fab--hidden')
  }

  async function openWidget() {
    shopStore.isOpen = true
    hideFab()
    localStorage.setItem(openKey, 'true')

    // Deferred load: fetch config on first open
    if (!configLoaded) {
      configLoaded = true
      await shopStore.loadConfig()
    }
  }

  // Watch isOpen to show FAB and persist state when widget closes
  const stopWatch = watch(() => shopStore.isOpen, (open) => {
    if (!open) {
      showFab()
      localStorage.setItem(openKey, 'false')
    }
  })

  // FAB click handler
  fab.addEventListener('click', openWidget)

  // If was open, restore immediately
  if (wasOpen) {
    openWidget()
  }

  app.mount(mountEl)

  return {
    app,
    container,
    destroy: () => {
      stopWatch()
      app.unmount()
      fab.remove()
      fabStyleEl?.remove()
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
