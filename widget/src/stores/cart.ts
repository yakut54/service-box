import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

export interface CartItem {
  id: string
  name: string
  price: number        // копейки
  type: string         // physical | digital | service
  image_url?: string
  quantity: number
  maxStock?: number    // для physical — лимит по складу
}

const STORAGE_KEY = 'sb-cart'

function loadFromStorage(shopId: string): CartItem[] {
  try {
    const raw = localStorage.getItem(`${STORAGE_KEY}:${shopId}`)
    return raw ? JSON.parse(raw) : []
  } catch {
    return []
  }
}

function saveToStorage(shopId: string, items: CartItem[]) {
  try {
    localStorage.setItem(`${STORAGE_KEY}:${shopId}`, JSON.stringify(items))
  } catch {
    // localStorage недоступен — не критично
  }
}

export const useCartStore = defineStore('sb-cart', () => {
  const items = ref<CartItem[]>([])
  const shopId = ref('')

  const count = computed(() => items.value.reduce((sum, item) => sum + item.quantity, 0))
  const total = computed(() => items.value.reduce((sum, item) => sum + item.price * item.quantity, 0))
  const isEmpty = computed(() => items.value.length === 0)

  function init(id: string) {
    shopId.value = id
    items.value = loadFromStorage(id)
  }

  function persist() {
    if (shopId.value) saveToStorage(shopId.value, items.value)
  }

  function addItem(product: { id: string; name: string; price: number; type: string; image_url?: string; physical?: { stock_quantity: number } | null }) {
    const existing = items.value.find(i => i.id === product.id)

    if (existing) {
      // Не превышаем остаток на складе
      if (existing.maxStock != null && existing.quantity >= existing.maxStock) return
      existing.quantity++
    } else {
      items.value.push({
        id: product.id,
        name: product.name,
        price: product.price,
        type: product.type,
        image_url: product.image_url,
        quantity: 1,
        maxStock: product.type === 'physical' ? (product.physical?.stock_quantity ?? undefined) : undefined,
      })
    }
    persist()
  }

  function removeItem(productId: string) {
    items.value = items.value.filter(i => i.id !== productId)
    persist()
  }

  function updateQuantity(productId: string, quantity: number) {
    const item = items.value.find(i => i.id === productId)
    if (!item) return

    if (quantity <= 0) {
      removeItem(productId)
      return
    }

    // Не превышаем остаток
    if (item.maxStock != null && quantity > item.maxStock) {
      item.quantity = item.maxStock
    } else {
      item.quantity = quantity
    }
    persist()
  }

  function clear() {
    items.value = []
    persist()
  }

  function hasItem(productId: string): boolean {
    return items.value.some(i => i.id === productId)
  }

  function getItemQuantity(productId: string): number {
    return items.value.find(i => i.id === productId)?.quantity ?? 0
  }

  return {
    items,
    count,
    total,
    isEmpty,
    init,
    addItem,
    removeItem,
    updateQuantity,
    clear,
    hasItem,
    getItemQuantity,
  }
})
