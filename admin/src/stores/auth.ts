import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api, ApiError } from '@/lib/api'
import { useProductsStore } from '@/stores/products'
import { useOrdersStore } from '@/stores/orders'
import { useChatStore } from '@/stores/chat'
import { useReviewsStore } from '@/stores/reviews'
import { useMailFailuresStore } from '@/stores/mailFailures'
import { disconnectEcho } from '@/lib/echo'
import router from '@/router'
import type { User, Shop } from '@/types'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const shop = ref<Shop | null>(null)
  const token = ref<string | null>(api.getToken())
  const actingShopId = ref<string | null>(api.getActingShopId())
  const initialized = ref(false)
  const loading = ref(false)
  const error = ref<string | null>(null)

  const isAuthenticated = computed(() => !!token.value && !!user.value)
  const isOwner       = computed(() => user.value?.role === 'owner' || user.value?.is_superadmin === true)
  const isMaster       = computed(() => user.value?.role === 'master')
  const isCollector    = computed(() => user.value?.role === 'collector')
  const isChainOwner   = computed(() => user.value?.is_chain_owner === true)

  async function initialize() {
    if (initialized.value) return

    // Any 401 on any API call → clear auth and redirect to login
    api.setUnauthorizedHandler(() => {
      clearAuth()
      router.push({ name: 'login' })
    })

    // Владелец сети без выбранной точки, или точку удалили/передали, пока он
    // был внутри — не ломаем экран, чистим контекст и уводим в панель сети.
    api.setActingShopErrorHandler(() => {
      leaveShop()
    })

    try {
      loading.value = true
      if (token.value) {
        api.setToken(token.value)
        await loadUserData()
      }
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) {
        clearAuth()
      }
    } finally {
      initialized.value = true
      loading.value = false
    }
  }

  async function loadUserData() {
    const data = await api.me()
    user.value = data.user
    shop.value = data.shop
  }

  async function login(email: string, password: string, remember = true) {
    try {
      loading.value = true
      error.value = null

      const response = await api.login({ email, password })
      token.value = response.token
      user.value = response.user
      shop.value = response.shop
      api.setToken(response.token, remember)
      // Свежий вход всегда ведёт в панель сети — предыдущая выбранная точка
      // (если вдруг осталась в sessionStorage от другого пользователя на
      // этом же устройстве) не должна унаследоваться новой сессией.
      api.setActingShopId(null)
      actingShopId.value = null

      return { success: true }
    } catch (err: unknown) {
      const message = err instanceof ApiError ? err.message : 'Ошибка входа'
      error.value = message
      return { success: false, error: message }
    } finally {
      loading.value = false
    }
  }

  async function register(
    email: string,
    password: string,
    name: string,
    shopName: string,
    timezone: string
  ) {
    try {
      loading.value = true
      error.value = null

      const response = await api.register({
        name,
        email,
        password,
        password_confirmation: password,
        shop_name: shopName,
        timezone,
      })

      token.value = response.token
      user.value = response.user
      shop.value = response.shop
      api.setToken(response.token)

      return { success: true }
    } catch (err: unknown) {
      const message = err instanceof ApiError ? err.message : 'Ошибка регистрации'
      error.value = message
      return { success: false, error: message }
    } finally {
      loading.value = false
    }
  }

  async function logout() {
    try {
      await api.logout()
    } catch {
      // ignore
    } finally {
      clearAuth()
      router.push({ name: 'login' })
    }
  }

  function loginWithToken(authToken: string, userData: User, shopData: Shop) {
    token.value = authToken
    user.value = userData
    shop.value = shopData
    api.setToken(authToken)
  }

  async function updateShop(updates: Record<string, unknown>) {
    try {
      const updated = await api.updateShop(updates)
      shop.value = updated
      return { success: true }
    } catch (err: unknown) {
      const message = err instanceof ApiError ? err.message : 'Ошибка обновления'
      return { success: false, error: message }
    }
  }

  async function updateProfile(data: { name: string; phone?: string | null; avatar_url?: string | null }) {
    try {
      const response = await api.updateProfile(data)
      user.value = response.user
      return { success: true }
    } catch (err: unknown) {
      const message = err instanceof ApiError ? err.message : 'Ошибка обновления'
      return { success: false, error: message }
    }
  }

  /**
   * Владелец сети заходит внутрь одной из своих точек: дальше всё как у
   * обычного владельца этого магазина (заголовок на каждый запрос,
   * see App\Support\ShopAccess::forShop на бэкенде).
   */
  async function enterShop(shopId: string) {
    api.setActingShopId(shopId)
    actingShopId.value = shopId
    resetDomainStores()
    disconnectEcho()
    await loadUserData()
    router.push({ name: 'dashboard' })
  }

  /** Возврат из точки в панель сети (или просто сброс контекста при ошибке). */
  async function leaveShop() {
    api.setActingShopId(null)
    actingShopId.value = null
    resetDomainStores()
    disconnectEcho()
    if (isAuthenticated.value) {
      await loadUserData().catch(() => { /* сессия могла протухнуть параллельно — initialize/401-хендлер разберутся */ })
    }
    router.push({ name: 'chain-shops' })
  }

  /**
   * Сбрасывает данные других сторов — чтобы не утекали между аккаунтами
   * (logout) и между точками одной сети (enter/leaveShop). Раньше вызывалось
   * только из clearAuth() и не трогало reviews/mailFailures — при
   * переключении точек это было бы видно чужими бейджами в меню.
   */
  function resetDomainStores() {
    useProductsStore().$reset()
    useOrdersStore().$reset()
    useChatStore().$reset()
    useReviewsStore().$reset()
    useMailFailuresStore().$reset()
  }

  function clearAuth() {
    user.value = null
    shop.value = null
    token.value = null
    actingShopId.value = null
    api.setToken(null)
    api.setActingShopId(null)

    resetDomainStores()
  }

  return {
    user,
    shop,
    token,
    actingShopId,
    initialized,
    loading,
    error,
    isAuthenticated,
    isOwner,
    isMaster,
    isCollector,
    isChainOwner,
    initialize,
    login,
    register,
    logout,
    loginWithToken,
    updateShop,
    updateProfile,
    enterShop,
    leaveShop,
  }
})
