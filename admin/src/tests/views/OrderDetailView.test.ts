import { describe, expect, test, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import type { Order } from '@/types'

/**
 * Живой тест 2026-10-19: клик "Выбрать другого" в OrderAssignmentDeclinedModal
 * ведёт на /orders/{id}?reassign=1 — но если Ховар УЖЕ стоит на странице этого
 * же заказа, router.push на тот же путь с другим query не пересоздаёт
 * компонент. Разовая проверка `if (route.query.reassign === '1')` в setup()
 * срабатывает один раз при первом открытии страницы и больше никогда — клик
 * в попапе "закрывает окно и всё", пикер не открывается. Исправлено на
 * watch(() => route.query.reassign, ..., { immediate: true }): immediate
 * покрывает переход "с нуля" (старое поведение), сам watch — переход "уже
 * стою на этой странице" (баг).
 */

const { fakeChannel, fakeEcho } = vi.hoisted(() => {
  const fakeChannel = { listen: vi.fn(), stopListening: vi.fn() }
  return { fakeChannel, fakeEcho: { private: vi.fn(() => fakeChannel) } }
})
vi.mock('@/lib/echo', () => ({ getEcho: () => fakeEcho }))

import { api } from '@/lib/api'
import OrderDetailView from '@/views/OrderDetailView.vue'
import OrderAssignmentPickerModal from '@/components/OrderAssignmentPickerModal.vue'

const ORDER: Order = {
  id: 'order-1',
  customer_id: null,
  status: 'pending',
  total_price: 106600,
  discount_id: null,
  discount_code: null,
  discount_amount: 0,
  payment_id: null,
  payment_url: null,
  customer_name: 'Артём',
  customer_email: null,
  customer_phone: '+79137948487',
  shipping_address: null,
  delivery_method: 'courier',
  delivery_price: 0,
  notes: null,
  paid_at: null,
  collector_id: null,
  collector_name: null,
  pending_collector_id: null,
  pending_collector_name: null,
  items: [],
  created_at: '2026-10-19T00:00:00Z',
} as unknown as Order

async function setup(initialPath = '/orders/order-1') {
  setActivePinia(createPinia())
  const authStore = useAuthStore()
  authStore.shop = { id: 'shop-1', timezone: 'Europe/Moscow' } as never
  authStore.user = { id: 'user-me', name: 'Ховар' } as never

  vi.spyOn(api, 'getOrder').mockResolvedValue({ data: { ...ORDER } })
  vi.spyOn(api, 'getOrderActivity').mockResolvedValue({ data: [] })
  // Пикер открывается этим тестом и сам дёргает список кандидатов — без
  // мока ушёл бы настоящий fetch на несуществующий бэкенд.
  vi.spyOn(api, 'getAssignmentCandidates').mockResolvedValue({ data: [] })

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/orders/:id', component: OrderDetailView }],
  })
  // initialPath — ЕДИНСТВЕННАЯ навигация до монтирования: тест "с нуля"
  // должен попасть на страницу сразу с ?reassign=1 одним переходом, а не
  // смонтироваться на голом пути и только потом получить query (это уже
  // другой сценарий — "уже стою на странице", см. тест бага ниже).
  await router.push(initialPath)
  await router.isReady()

  const { RouterView } = await import('vue-router')
  const wrapper = mount(RouterView, {
    global: { plugins: [router], stubs: { Teleport: true } },
  })
  await flushPromises()
  await wrapper.vm.$nextTick()
  return { wrapper, router }
}

function flushPromises() {
  return new Promise((resolve) => setTimeout(resolve, 0))
}

function pickerIsOpen(wrapper: Awaited<ReturnType<typeof setup>>['wrapper']) {
  const picker = wrapper.findComponent(OrderAssignmentPickerModal)
  return picker.exists() ? picker.props('modelValue') : false
}

describe('OrderDetailView — открытие пикера через ?reassign=1', () => {
  beforeEach(() => {
    fakeChannel.listen.mockClear()
  })

  test('открывает пикер сразу, если страница открыта с ?reassign=1 "с нуля"', async () => {
    // Единственная навигация — сразу с query, без промежуточного захода на
    // голый путь. Это покрывает immediate:true (старое поведение, если
    // Ховар переходит на страницу заказа ИЗВНЕ — например, из другого
    // раздела админки).
    const { wrapper } = await setup('/orders/order-1?reassign=1')

    expect(pickerIsOpen(wrapper)).toBe(true)
  })

  test('баг 2026-10-19: открывает пикер и когда уже стоим на этой же странице заказа', async () => {
    const { wrapper, router } = await setup()

    expect(pickerIsOpen(wrapper)).toBe(false)

    // Именно это делает OrderAssignmentDeclinedModal.pickAnother() — та же
    // страница, только query поменялся. Без watch (разовый if при setup())
    // это осталось бы незамеченным: компонент не пересоздаётся.
    await router.push({ path: '/orders/order-1', query: { reassign: '1' } })
    await flushPromises()
    await wrapper.vm.$nextTick()

    expect(pickerIsOpen(wrapper)).toBe(true)
  })

  test('чистит query сразу после открытия — F5 не открывает пикер повторно', async () => {
    const { wrapper, router } = await setup()

    await router.push({ path: '/orders/order-1', query: { reassign: '1' } })
    await flushPromises()
    await wrapper.vm.$nextTick()

    expect(router.currentRoute.value.query.reassign).toBeUndefined()
  })
})
