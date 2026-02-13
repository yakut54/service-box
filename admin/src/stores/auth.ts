import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/lib/api'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<any>(null)
  const shop = ref<any>(null)
  const isLoading = ref(false)
  const isAuthenticated = ref(false)

  async function initialize() {
    const token = api.getToken()
    if (!token) return

    try {
      isLoading.value = true
      const data = await api.me()
      user.value = data.user
      shop.value = data.shop
      isAuthenticated.value = true
    } catch {
      api.setToken(null)
      isAuthenticated.value = false
    } finally {
      isLoading.value = false
    }
  }

  async function login(email: string, password: string) {
    const data = await api.login({ email, password })
    api.setToken(data.token)
    user.value = data.user
    shop.value = data.shop
    isAuthenticated.value = true
  }

  async function register(name: string, email: string, password: string, shopName: string) {
    const data = await api.register({
      name,
      email,
      password,
      password_confirmation: password,
      shop_name: shopName,
    })
    api.setToken(data.token)
    user.value = data.user
    shop.value = data.shop
    isAuthenticated.value = true
  }

  async function logout() {
    await api.logout()
    user.value = null
    shop.value = null
    isAuthenticated.value = false
  }

  return {
    user,
    shop,
    isLoading,
    isAuthenticated,
    initialize,
    login,
    register,
    logout,
  }
})
