import { describe, expect, test, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'

/**
 * Живой разбор 2026-10-19 ("юнит тестами весь флоу покрыл?") — раньше эта
 * модалка проверялась только vue-tsc (типы верные, компилируется), но не
 * функционально: не было проверено, что клик реально зовёт нужный запрос,
 * и что фильтр "это предложение не мне" действительно работает, а не
 * просто выглядит так в коде.
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

import { api } from '@/lib/api'
import OrderAssignmentRequestModal from '@/components/OrderAssignmentRequestModal.vue'

// spyOn, не подмена всего модуля/объекта api — api это синглтон-инстанс
// класса, его методы (getToken() и т.д.) живут на прототипе, а не как
// собственные свойства, {...api} в фейковом модуле их теряет (поймано
// именно на этом при первом запуске: "api.getToken is not a function").
const acceptOrderAssignment = vi.spyOn(api, 'acceptOrderAssignment').mockResolvedValue({ message: 'ok' })
const declineOrderAssignment = vi.spyOn(api, 'declineOrderAssignment').mockResolvedValue({ message: 'ok' })

const PAYLOAD = {
  orderId: 'order-1',
  orderShortId: 'order-1'.slice(0, 8),
  candidateId: 'user-me',
  candidateName: 'Юлия',
  initiatorName: 'Ховар',
  itemsCount: 3,
  totalPrice: 2480,
  deliveryMethod: 'courier',
}

function setup(userId = 'user-me') {
  setActivePinia(createPinia())
  const authStore = useAuthStore()
  authStore.shop = { id: 'shop-1' } as never
  authStore.user = { id: userId, name: 'Юлия' } as never
  acceptOrderAssignment.mockClear()
  declineOrderAssignment.mockClear()
  useToast().toasts.value = []
  // UiModal рендерит через <Teleport to="body"> — без стаба find()/text()
  // не видят содержимое (оно физически уехало из поддерева wrapper'а).
  return mount(OrderAssignmentRequestModal, { global: { stubs: { Teleport: true } } })
}

describe('OrderAssignmentRequestModal', () => {
  beforeEach(() => {
    fakeChannel.listen.mockClear()
  })

  test('подписывается на канал магазина при монтировании', () => {
    setup()
    expect(fakeEcho.private).toHaveBeenCalledWith('shop.shop-1')
    expect(fakeChannel.listen).toHaveBeenCalledWith('.order.assignment_requested', expect.any(Function))
  })

  test('показывает попап с инициатором и сводкой заказа, когда предложение адресовано мне', async () => {
    const wrapper = setup('user-me')

    channelHandlers['.order.assignment_requested'](PAYLOAD)
    await wrapper.vm.$nextTick()

    expect(wrapper.text()).toContain('Ховар предлагает вам заказ')
    expect(wrapper.text()).toContain('3 позиции')
    expect(wrapper.text()).toContain('Курьер')
  })

  test('игнорирует предложение, адресованное не мне', async () => {
    const wrapper = setup('someone-else')

    channelHandlers['.order.assignment_requested'](PAYLOAD)
    await wrapper.vm.$nextTick()

    expect(wrapper.text()).not.toContain('предлагает вам заказ')
  })

  test('клик "Беру" зовёт acceptOrderAssignment с id заказа из события', async () => {
    const wrapper = setup('user-me')
    channelHandlers['.order.assignment_requested'](PAYLOAD)
    await wrapper.vm.$nextTick()

    await wrapper.findAll('button').find(b => b.text() === 'Беру')!.trigger('click')
    await flushPromises()

    expect(acceptOrderAssignment).toHaveBeenCalledWith('order-1')
    expect(declineOrderAssignment).not.toHaveBeenCalled()
  })

  test('клик "Не беру" зовёт declineOrderAssignment, не accept', async () => {
    const wrapper = setup('user-me')
    channelHandlers['.order.assignment_requested'](PAYLOAD)
    await wrapper.vm.$nextTick()

    await wrapper.findAll('button').find(b => b.text() === 'Не беру')!.trigger('click')
    await flushPromises()

    expect(declineOrderAssignment).toHaveBeenCalledWith('order-1')
    expect(acceptOrderAssignment).not.toHaveBeenCalled()
  })

  test('клик по затемнению — тот же эффект, что "Не беру" (не два разных способа закрыть окно)', async () => {
    const wrapper = setup('user-me')
    channelHandlers['.order.assignment_requested'](PAYLOAD)
    await wrapper.vm.$nextTick()

    await wrapper.find('.bg-gray-900\\/50').trigger('click')
    await flushPromises()

    expect(declineOrderAssignment).toHaveBeenCalledWith('order-1')
  })
})

function flushPromises() {
  return new Promise((resolve) => setTimeout(resolve, 0))
}
