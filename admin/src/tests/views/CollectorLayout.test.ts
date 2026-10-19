import { describe, expect, test, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

/**
 * Живой вопрос 2026-10-19 ("как Юлии/Егорке сменить пароль через
 * админку") — сборщика роутер уводит с ЛЮБОГО маршрута без
 * requiresCollector обратно на его кабинет (router/index.ts:271-274),
 * значит /settings ему физически недоступен, и вкладка "Профиль" (см.
 * SettingsView.test.ts) тут не спасает. Новая точка входа — кнопка
 * прямо в хедере CollectorLayout, открывающая тот же SettingsPassword.vue
 * в модалке, без копий.
 */

const { fakeEcho } = vi.hoisted(() => {
  const fakeChannel = { listen: vi.fn(), stopListening: vi.fn() }
  const fakePresenceChannel = {
    here: vi.fn(() => fakePresenceChannel),
    joining: vi.fn(() => fakePresenceChannel),
    leaving: vi.fn(() => fakePresenceChannel),
  }
  return {
    fakeChannel,
    fakePresenceChannel,
    fakeEcho: {
      private: vi.fn(() => fakeChannel),
      join: vi.fn(() => fakePresenceChannel),
      leave: vi.fn(),
      leaveChannel: vi.fn(),
      connector: { onConnectionChange: vi.fn(() => () => {}) },
    },
  }
})
vi.mock('@/lib/echo', () => ({ getEcho: () => fakeEcho }))

import { api } from '@/lib/api'
import CollectorLayout from '@/components/layout/CollectorLayout.vue'
import SettingsPassword from '@/components/settings/SettingsPassword.vue'

async function setup() {
  setActivePinia(createPinia())
  const authStore = useAuthStore()
  authStore.shop = { id: 'shop-1', name: 'Барбариска' } as never
  authStore.user = { id: 'egorka-1', name: 'Егорка', role: 'collector' } as never

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/collector', component: CollectorLayout }],
  })
  await router.push('/collector')
  await router.isReady()

  return mount(CollectorLayout, {
    global: { plugins: [router], stubs: { Teleport: true } },
  })
}

describe('CollectorLayout — смена пароля у сборщика', () => {
  test('модалка со сменой пароля закрыта по умолчанию', async () => {
    const wrapper = await setup()

    expect(wrapper.findComponent(SettingsPassword).exists()).toBe(false)
  })

  test('кнопка "Сменить пароль" открывает модалку с SettingsPassword', async () => {
    const wrapper = await setup()

    await wrapper.find('button[title="Сменить пароль"]').trigger('click')

    expect(wrapper.findComponent(SettingsPassword).exists()).toBe(true)
  })

  test('сабмит формы внутри модалки реально зовёт api.changePassword', async () => {
    const changePassword = vi.spyOn(api, 'changePassword').mockResolvedValue({ message: 'ok' })
    const wrapper = await setup()
    await wrapper.find('button[title="Сменить пароль"]').trigger('click')

    const inputs = wrapper.findAll('input')
    await inputs[0].setValue('old-password')
    await inputs[1].setValue('new-password-123')
    await inputs[2].setValue('new-password-123')
    await wrapper.find('button.btn-primary').trigger('click')
    await flushPromises()

    expect(changePassword).toHaveBeenCalledWith('old-password', 'new-password-123', 'new-password-123')
  })
})

function flushPromises() {
  return new Promise((resolve) => setTimeout(resolve, 0))
}
