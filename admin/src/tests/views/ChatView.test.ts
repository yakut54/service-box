import { describe, expect, test, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import type { ChatThread } from '@/types'

/**
 * Живой разбор 2026-10-20 — на телефоне, пока открыта экранная клавиатура
 * с предиктивным набором, кнопка отправки оставалась неактивной, хотя
 * текст на экране уже есть: :disabled был завязан на draft.trim(), а
 * draft обновляется через v-model, которую Vue намеренно не трогает до
 * compositionend. Фикс — отдельный hasText, обновляемый прямым @input.
 *
 * ВАЖНО: этот тест НЕ ловит именно баг с IME-композицией — jsdom не умеет
 * достоверно её эмулировать, setValue() тут обновляет draft сразу через
 * v-model, так что тест проходит даже на старом коде (проверено откатом).
 * Он лишь закрепляет, что обычный ввод в принципе включает кнопку (базовая
 * защита от будущей поломки этой логики). Сам IME-сценарий проверен вручную
 * на реальном телефоне после фикса.
 */

const { fakeEcho } = vi.hoisted(() => {
  const fakeChannel = { listen: vi.fn(() => fakeChannel), stopListening: vi.fn() }
  return { fakeChannel, fakeEcho: { private: vi.fn(() => fakeChannel), leave: vi.fn() } }
})
vi.mock('@/lib/echo', () => ({ getEcho: () => fakeEcho }))

import { api } from '@/lib/api'
import ChatView from '@/views/ChatView.vue'

const THREAD: ChatThread = {
  id: 'thread-1',
  customer_id: 'customer-1',
  customer_name: 'Артём',
  unread_by_shop: 0,
  is_blocked_by_shop: false,
  last_message_preview: '',
  last_message_at: '2026-10-20T00:00:00Z',
} as unknown as ChatThread

async function setup() {
  setActivePinia(createPinia())
  const authStore = useAuthStore()
  authStore.shop = { id: 'shop-1', api_key: 'key-1', timezone: 'Europe/Moscow' } as never
  authStore.user = { id: 'user-me', name: 'Ховар' } as never

  vi.spyOn(api, 'getChatThreads').mockResolvedValue({ data: [THREAD] } as never)
  vi.spyOn(api, 'getChatMessages').mockResolvedValue({ data: [] } as never)
  vi.spyOn(api, 'sendChatPresence').mockResolvedValue(undefined as never)

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/chat', component: ChatView }],
  })
  await router.push('/chat')
  await router.isReady()

  const wrapper = mount(ChatView, {
    global: { plugins: [router], stubs: { Teleport: true } },
  })
  await flushPromises()

  // Открываем диалог — поле ввода рендерится только для выбранного треда.
  await wrapper.find('button').trigger('click')
  await flushPromises()

  return wrapper
}

function flushPromises() {
  return new Promise((resolve) => setTimeout(resolve, 0))
}

describe('ChatView — кнопка отправки реагирует на реальный ввод', () => {
  test('кнопка неактивна, пока поле пустое', async () => {
    const wrapper = await setup()

    const sendButton = wrapper.find('button[title="Отправить"]')
    expect(sendButton.attributes('disabled')).toBeDefined()
  })

  test('кнопка активируется сразу по @input, не дожидаясь v-model', async () => {
    const wrapper = await setup()

    const textarea = wrapper.find('textarea')
    await textarea.setValue('Ооол')

    const sendButton = wrapper.find('button[title="Отправить"]')
    expect(sendButton.attributes('disabled')).toBeUndefined()
  })
})
