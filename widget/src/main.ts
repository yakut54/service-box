import { createApp, watch, type App as VueApp } from 'vue'
import { createPinia } from 'pinia'
import AppComponent from './App.vue'
import { useShopStore, type WidgetMode } from './stores/shop'
import widgetCss from './styles/widget.css?inline'
import fabCss from './styles/fab.css?inline'

interface WidgetOptions {
  shopId: string
  apiUrl?: string
  container?: HTMLElement | string
  mode?: WidgetMode
  serviceId?: string
  prefillName?: string
  prefillPhone?: string
  prefillEmail?: string
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
  shopStore.mode = opts.mode ?? 'popup'
  shopStore.deepLinkServiceId = opts.serviceId ?? null
  shopStore.prefillName = opts.prefillName ?? null
  shopStore.prefillPhone = opts.prefillPhone ?? null
  shopStore.prefillEmail = opts.prefillEmail ?? null
  shopStore.initSession()

  // ── Custom CSS watch (injected into shadow DOM) ───────────
  function setupCustomCss() {
    watch(() => shopStore.config.custom_css, (css) => {
      let el = shadow.querySelector('#sb-custom') as HTMLStyleElement | null
      if (!el) {
        el = document.createElement('style')
        el.id = 'sb-custom'
        shadow.appendChild(el)
      }
      el.textContent = css || ''
    }, { immediate: true })
  }

  // ── Preview postMessage listener (admin iframe) ──────────
  const previewHandler = (e: MessageEvent) => {
    if (e.data?.type !== 'sb-preview-config') return
    shopStore.previewConfig = e.data.config
  }
  window.addEventListener('message', previewHandler)

  // ── Inline mode: no FAB, always open ─────────────────────
  if (shopStore.mode === 'inline') {
    mountEl.style.height = '100%'
    shopStore.isOpen = true
    shopStore.loadConfig()
    app.mount(mountEl)
    setupCustomCss()
    return {
      app,
      container,
      destroy: () => {
        window.removeEventListener('message', previewHandler)
        app.unmount()
        if (!opts.container) container.remove()
      },
    }
  }

  // ── FAB button (popup / auto modes) ──────────────────────
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
  let historyPushed = false

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

    // Push history entry so browser/mobile Back closes the widget instead of leaving the page
    if (!historyPushed) {
      history.pushState({ sbWidget: opts.shopId }, '')
      historyPushed = true
    }

    // Deferred load: fetch config on first open
    if (!configLoaded) {
      configLoaded = true
      await shopStore.loadConfig()
    }
  }

  // Browser Back → close widget, stay on page
  function onPopState(e: PopStateEvent) {
    if (shopStore.isOpen && !e.state?.sbWidget) {
      shopStore.isOpen = false
      showFab()
      localStorage.setItem(openKey, 'false')
      historyPushed = false
    }
  }
  window.addEventListener('popstate', onPopState)

  // Watch isOpen to show FAB, persist state, and sync history when closed via button
  const stopWatch = watch(() => shopStore.isOpen, (open) => {
    if (!open) {
      showFab()
      localStorage.setItem(openKey, 'false')
      // Pop the history entry we pushed so browser history stays consistent
      if (historyPushed) {
        historyPushed = false
        history.back()
      }
    }
  })

  // FAB click handler
  fab.addEventListener('click', openWidget)

  // Open if previously open OR auto mode
  if (wasOpen || shopStore.mode === 'auto') {
    openWidget()
  }

  app.mount(mountEl)
  setupCustomCss()

  return {
    app,
    container,
    destroy: () => {
      stopWatch()
      window.removeEventListener('popstate', onPopState)
      window.removeEventListener('message', previewHandler)
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
  // URL params for QR code / deep link: ?sb_open=1&sb_service=UUID&sb_name=X&sb_phone=Y
  const urlParams = new URLSearchParams(window.location.search)
  const urlMode: WidgetMode | null = urlParams.get('sb_open') === '1' ? 'auto' : null
  const urlServiceId  = urlParams.get('sb_service')  || undefined
  const urlPrefillName  = urlParams.get('sb_name')   || undefined
  const urlPrefillPhone = urlParams.get('sb_phone')  || undefined
  const urlPrefillEmail = urlParams.get('sb_email')  || undefined

  function attr(el: Element, name: string) { return el.getAttribute(name) || undefined }

  function fromEl(el: HTMLElement, extra: Partial<WidgetOptions> = {}): WidgetOptions {
    return {
      shopId:       el.getAttribute('data-shop-id')!,
      apiUrl:       attr(el, 'data-api-url'),
      mode:         urlMode ?? ((attr(el, 'data-mode') as WidgetMode) ?? 'popup'),
      serviceId:    urlServiceId  ?? attr(el, 'data-service-id'),
      prefillName:  urlPrefillName  ?? attr(el, 'data-prefill-name'),
      prefillPhone: urlPrefillPhone ?? attr(el, 'data-prefill-phone'),
      prefillEmail: urlPrefillEmail ?? attr(el, 'data-prefill-email'),
      ...extra,
    }
  }

  // 1. Script tag: <script src="widget.js" data-shop-id="xxx">
  const script = document.currentScript as HTMLScriptElement | null
  if (script?.getAttribute('data-shop-id')) {
    init(fromEl(script))
    return
  }

  // 2. DOM element: <div id="servicebox-widget" data-shop-id="xxx">
  const el = document.getElementById('servicebox-widget')
  if (el?.getAttribute('data-shop-id')) {
    init(fromEl(el, { container: el }))
    return
  }

  // 3. Env variable (dev mode)
  const envShopId = import.meta.env.VITE_SHOP_ID
  if (envShopId) {
    const target = document.getElementById('servicebox-widget')
    init({
      shopId: envShopId,
      mode: urlMode ?? 'popup',
      serviceId: urlServiceId,
      prefillName: urlPrefillName,
      prefillPhone: urlPrefillPhone,
      prefillEmail: urlPrefillEmail,
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
