import { describe, expect, test, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import type { Order } from '@/types'

/**
 * Живой разбор 2026-10-20 — needs_attention не пускает прямо в collected
 * (Order::TRANSITIONS), но кнопка «Готово» выглядела активной и падала
 * необъяснённым 422. Проверяем: причина объяснена текстом, а «Проблема
 * решена» видна только не-сборщику (OrderController::updateStatus не
 * разрешает роли collector processing вообще, см. CollectorFinishBlocked
 * ByProblemTest на бэкенде).
 */

const { fakeEcho } = vi.hoisted(() => {
  const fakeChannel = { listen: vi.fn(() => fakeChannel), stopListening: vi.fn() }
  return { fakeChannel, fakeEcho: { private: vi.fn(() => fakeChannel) } }
})
vi.mock('@/lib/echo', () => ({ getEcho: () => fakeEcho }))

import { api } from '@/lib/api'
import CollectorOrderDetailView from '@/views/collector/CollectorOrderDetailView.vue'

const ORDER: Order = {
  id: 'order-1',
  customer_id: null,
  status: 'needs_attention',
  total_price: 341100,
  payment_id: null,
  payment_url: null,
  paid_at: '2026-10-20T06:00:00Z',
  customer_name: 'Артём',
  customer_phone: '+79137948487',
  delivery_method: 'pickup',
  delivery_price: 0,
  collector_id: 'user-me',
  collector_name: 'Юлия',
  pick_note: 'Картошка гнилая',
  pick_note_at: '2026-10-20T06:00:00Z',
  items: [
    { id: 'item-1', product_id: 'p1', product_name: 'Картофель молодой', product_type: 'physical', quantity: 1, price: 341100, picked_qty: 1 },
  ],
  created_at: '2026-10-20T00:00:00Z',
} as unknown as Order

async function setup(role: 'collector' | 'admin' | 'owner') {
  setActivePinia(createPinia())
  const authStore = useAuthStore()
  authStore.shop = { id: 'shop-1', timezone: 'Europe/Moscow' } as never
  authStore.user = { id: 'user-me', name: 'Юлия', role } as never

  vi.spyOn(api, 'getOrder').mockResolvedValue({ data: { ...ORDER } } as never)
  vi.spyOn(api, 'getOrderActivity').mockResolvedValue({ data: [] } as never)

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/collector/orders/:id', component: CollectorOrderDetailView },
      { path: '/collector', name: 'collector-orders', component: { template: '<div />' } },
    ],
  })
  await router.push('/collector/orders/order-1')
  await router.isReady()

  const { RouterView } = await import('vue-router')
  const wrapper = mount(RouterView, {
    global: { plugins: [router], stubs: { Teleport: true } },
  })
  await flushPromises()
  return wrapper
}

function flushPromises() {
  return new Promise((resolve) => setTimeout(resolve, 0))
}

describe('CollectorOrderDetailView — needs_attention блокирует «Готово» с объяснением', () => {
  test('кнопка «Готово» показывает причину вместо молчаливой недоступности', async () => {
    const wrapper = await setup('admin')

    expect(wrapper.text()).toContain('Сначала снимите отметку о проблеме')
  })

  test('у админа есть «Проблема решена»', async () => {
    const wrapper = await setup('admin')

    expect(wrapper.text()).toContain('Проблема решена')
  })

  test('у настоящего сборщика «Проблема решена» скрыта — эндпоинт ему всё равно откажет', async () => {
    const wrapper = await setup('collector')

    expect(wrapper.text()).not.toContain('Проблема решена')
  })
})
