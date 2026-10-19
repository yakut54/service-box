import { describe, expect, test, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

/**
 * Живой разбор 2026-10-19 — отказ кандидата менял карточку заказа у
 * инициатора тихо, без всплывающего окна (хотя бот уже показывал явное
 * сообщение про отказ). Эта модалка — попап для веба; проверяем, что он
 * реально фильтрует "моё ли это уведомление" и ведёт в пикер по клику, а
 * не просто существует в коде.
 */

const { fakeChannel, fakeEcho, channelHandlers } = vi.hoisted(() => {
  const channelHandlers: Record<string, (payload: unknown) => void> = {}
  const fakeChannel = {
    listen: vi.fn((event: string, cb: (payload: unknown) => void) => {
      channelHandlers[event] = cb
      return fakeChannel
    }),
    stopListening: vi.fn(),
  }
  return { fakeChannel, fakeEcho: { private: vi.fn(() => fakeChannel) }, channelHandlers }
})

vi.mock('@/lib/echo', () => ({ getEcho: () => fakeEcho }))

import OrderAssignmentDeclinedModal from '@/components/OrderAssignmentDeclinedModal.vue'

const PAYLOAD = {
  orderId: 'order-1',
  orderShortId: 'order-1',
  initiatorId: 'user-me',
  declinerName: 'Юлия',
}

async function setup(userId = 'user-me') {
  setActivePinia(createPinia())
  const authStore = useAuthStore()
  authStore.shop = { id: 'shop-1' } as never
  authStore.user = { id: userId, name: 'Ховар' } as never

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div/>' } },
      { path: '/orders/:id', component: { template: '<div/>' } },
    ],
  })
  await router.push('/')

  // UiModal рендерит через <Teleport to="body"> — без стаба find()/text()
  // не видят содержимое (оно физически уехало из поддерева wrapper'а).
  const wrapper = mount(OrderAssignmentDeclinedModal, { global: { plugins: [router], stubs: { Teleport: true } } })
  return { wrapper, router }
}

describe('OrderAssignmentDeclinedModal', () => {
  beforeEach(() => {
    fakeChannel.listen.mockClear()
  })

  test('показывает попап, когда отказ адресован мне (я инициатор)', async () => {
    const { wrapper } = await setup('user-me')

    channelHandlers['.order.assignment_declined'](PAYLOAD)
    await wrapper.vm.$nextTick()

    expect(wrapper.text()).toContain('Юлия отказался(ась) от заказа')
    expect(wrapper.text()).toContain('order-1')
  })

  test('игнорирует отказ, адресованный не мне', async () => {
    const { wrapper } = await setup('someone-else')

    channelHandlers['.order.assignment_declined'](PAYLOAD)
    await wrapper.vm.$nextTick()

    expect(wrapper.text()).not.toContain('отказался(ась) от заказа')
  })

  test('"Выбрать другого" ведёт на страницу заказа с ?reassign=1', async () => {
    const { wrapper, router } = await setup('user-me')
    const pushSpy = vi.spyOn(router, 'push')

    channelHandlers['.order.assignment_declined'](PAYLOAD)
    await wrapper.vm.$nextTick()

    await wrapper.findAll('button').find(b => b.text() === 'Выбрать другого')!.trigger('click')

    expect(pushSpy).toHaveBeenCalledWith({ path: '/orders/order-1', query: { reassign: '1' } })
  })

  test('"Позже" просто закрывает попап, без перехода', async () => {
    const { wrapper, router } = await setup('user-me')
    const pushSpy = vi.spyOn(router, 'push')

    channelHandlers['.order.assignment_declined'](PAYLOAD)
    await wrapper.vm.$nextTick()

    await wrapper.findAll('button').find(b => b.text() === 'Позже')!.trigger('click')
    await wrapper.vm.$nextTick()

    expect(pushSpy).not.toHaveBeenCalled()
    expect(wrapper.text()).not.toContain('отказался(ась) от заказа')
  })
})
