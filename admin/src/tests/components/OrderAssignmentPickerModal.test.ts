import { describe, expect, test, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import type { AssignmentCandidate } from '@/types'

/**
 * Живой разбор 2026-10-19 ("юнит тестами весь флоу покрыл?") — ключевая
 * проверка этого пикера: клик по офлайн-кандидату должен отказывать СРАЗУ,
 * без единого запроса на бэкенд (это и есть дыра, которую весь флоу
 * request/accept/decline закрывает — "офлайн-человека назначали молча").
 */

// listen() в реальном коде компонента не чейнится (один вызов, не как у
// Telegram/MAX-пикеров) — возврату значения из мока подражать незачем,
// достаточно принимать (event, cb) с нужными типами ради .mock.calls ниже.
const { fakeChannel, fakeEcho } = vi.hoisted(() => {
  const fakeChannel = {
    listen: vi.fn((_event: string, _cb: () => void) => {}),
    stopListening: vi.fn(),
  }
  return { fakeChannel, fakeEcho: { private: vi.fn(() => fakeChannel) } }
})

vi.mock('@/lib/echo', () => ({ getEcho: () => fakeEcho }))

import { api } from '@/lib/api'
import OrderAssignmentPickerModal from '@/components/OrderAssignmentPickerModal.vue'

// spyOn реального синглтона — см. пояснение в OrderAssignmentRequestModal.test.ts.
const getAssignmentCandidates = vi.spyOn(api, 'getAssignmentCandidates')
const requestOrderAssignment = vi.spyOn(api, 'requestOrderAssignment')
  .mockResolvedValue({ message: 'Запрос отправлен Юлии', pending: true })

const CANDIDATES: AssignmentCandidate[] = [
  { id: 'user-me', name: 'Ховар', role: 'owner', is_online: true },
  { id: 'user-julia', name: 'Юлия', role: 'collector', is_online: true },
  { id: 'user-egor', name: 'Егорка', role: 'collector', is_online: false },
]

async function setup() {
  setActivePinia(createPinia())
  const authStore = useAuthStore()
  authStore.shop = { id: 'shop-1' } as never
  authStore.user = { id: 'user-me', name: 'Ховар' } as never

  getAssignmentCandidates.mockClear()
  requestOrderAssignment.mockClear()
  getAssignmentCandidates.mockResolvedValue({ data: CANDIDATES })
  useToast().toasts.value = []

  // UiModal рендерит через <Teleport to="body"> — без стаба find()/text()
  // не видят содержимое (оно физически уехало из поддерева wrapper'а).
  const wrapper = mount(OrderAssignmentPickerModal, {
    props: { modelValue: false, orderId: 'order-1' },
    global: { stubs: { Teleport: true } },
  })
  // load() вызывается watch'ем на props.modelValue (без immediate) — как и
  // в реальном использовании (OrderDetailView: кнопка переключает false→true),
  // монтирование сразу с true его бы не дёрнуло вообще.
  await wrapper.setProps({ modelValue: true })
  await flushPromises()
  await wrapper.vm.$nextTick()
  return wrapper
}

function flushPromises() {
  return new Promise((resolve) => setTimeout(resolve, 0))
}

describe('OrderAssignmentPickerModal', () => {
  beforeEach(() => {
    getAssignmentCandidates.mockClear()
  })

  test('при открытии загружает кандидатов и показывает ✅/❌ по online-статусу', async () => {
    const wrapper = await setup()

    expect(getAssignmentCandidates).toHaveBeenCalledWith('order-1')
    const buttons = wrapper.findAll('button')
    const julia = buttons.find(b => b.text().includes('Юлия'))!
    const egor = buttons.find(b => b.text().includes('Егорка'))!
    expect(julia.text()).toContain('✅')
    expect(egor.text()).toContain('❌')
    expect(egor.attributes('disabled')).toBeDefined()
  })

  test('клик по офлайн-кандидату НЕ шлёт запрос на бэкенд — отказ сразу на фронте', async () => {
    const wrapper = await setup()
    const toast = useToast()

    // disabled на кнопке и так не даёт браузеру/jsdom доставить click до
    // обработчика — это уже проверено отдельно (disabled-атрибут выше).
    // Здесь же проверяем вторую, независимую линию защиты: сам choose()
    // внутри компонента тоже отказывает офлайн-кандидату, а не полагается
    // только на HTML-атрибут (defense in depth, живой разбор 2026-10-19:
    // именно отсутствие такой двойной защиты и дало "назначили молча").
    const egor = wrapper.findAll('button').find(b => b.text().includes('Егорка'))!
    egor.element.removeAttribute('disabled')
    await egor.trigger('click')

    expect(requestOrderAssignment).not.toHaveBeenCalled()
    expect(toast.toasts.value.some(t => t.message.includes('не в сети'))).toBe(true)
  })

  test('клик по онлайн-кандидату шлёт requestOrderAssignment и закрывает модалку', async () => {
    const wrapper = await setup()

    const julia = wrapper.findAll('button').find(b => b.text().includes('Юлия'))!
    await julia.trigger('click')
    await flushPromises()

    expect(requestOrderAssignment).toHaveBeenCalledWith('order-1', 'user-julia')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([false])
    expect(wrapper.emitted('assigned')).toBeTruthy()
  })

  test('перечитывает кандидатов на .staff.updated, пока модалка открыта', async () => {
    await setup()

    expect(fakeChannel.listen).toHaveBeenCalledWith('.staff.updated', expect.any(Function))
    const onStaffUpdated = fakeChannel.listen.mock.calls.find(([event]) => event === '.staff.updated')![1]

    getAssignmentCandidates.mockClear()
    onStaffUpdated()
    await flushPromises()

    expect(getAssignmentCandidates).toHaveBeenCalledWith('order-1')
  })
})
