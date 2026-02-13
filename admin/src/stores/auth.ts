import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api, ApiError } from '@/lib/api'
import { useProductsStore } from '@/stores/products'
import { useOrdersStore } from '@/stores/orders'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<any>(null)
  const shop = ref<any>(null)
  const token = ref<string | null>(api.getToken())
  const initialized = ref(false)
  const loading = ref(false)
  const error = ref<string | null>(null)

  const isAuthenticated = computed(() => !!token.value && !!user.value)

  async function initialize() {
    if (initialized.value) return

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

  async function login(email: string, password: string) {
    try {
      loading.value = true
      error.value = null

      const response = await api.login({ email, password })
      token.value = response.token
      user.value = response.user
      shop.value = response.shop
      api.setToken(response.token)

      return { success: true }
    } catch (err: any) {
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
    shopName: string
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
      })

      token.value = response.token
      user.value = response.user
      shop.value = response.shop
      api.setToken(response.token)

      return { success: true }
    } catch (err: any) {
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
    }
  }

  async function updateShop(updates: Record<string, any>) {
    try {
      const updated = await api.updateShop(updates)
      shop.value = updated
      return { success: true }
    } catch (err: any) {
      const message = err instanceof ApiError ? err.message : 'Ошибка обновления'
      return { success: false, error: message }
    }
  }

  function clearAuth() {
    user.value = null
    shop.value = null
    token.value = null
    api.setToken(null)

    // Очищаем данные других сторов чтобы не утекали между аккаунтами
    useProductsStore().$reset()
    useOrdersStore().$reset()
  }

  return {
    user,
    shop,
    token,
    initialized,
    loading,
    error,
    isAuthenticated,
    initialize,
    login,
    register,
    logout,
    updateShop,
  }
})
