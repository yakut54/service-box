import { describe, expect, test } from 'vitest'
import { shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

/**
 * Живой вопрос 2026-10-19 ("как Юлии сменить пароль через админку") —
 * вкладка "Профиль" была ownerOnly целиком, хотя смена пароля
 * (SettingsPassword) ролей не касается вообще. Теперь вкладка видна и
 * owner, и admin — но SettingsProfile (аватар/телефон, живут в
 * public.users только у владельца) внутри неё по-прежнему только owner.
 *
 * shallowMount, а не mount — SettingsView рендерит ВСЕ вкладки разом
 * через v-show (не v-if), значит обычный mount поднял бы добрый десяток
 * чужих виджетов настроек (Telegram/MAX/ЮKassa/Доставка/...) с их
 * собственными API-вызовами при монтировании — совершенно не в фокусе
 * этого теста. shallowMount стабит всех детей, оставляя только то, что
 * сам SettingsView решает показать/не показать.
 *
 * ВАЖНО: v-show НЕ уничтожает компонент при false, только ставит
 * display:none — проверено эмпирически (временный откат фикса всё равно
 * давал findComponent(SettingsPassword).exists() === true). Значит
 * .exists() тут ничего не доказывает — нужно проверять реальную
 * видимость контейнера (inline style.display), не факт монтирования.
 */

import SettingsView from '@/views/SettingsView.vue'
import SettingsProfile from '@/components/settings/SettingsProfile.vue'
import SettingsPassword from '@/components/settings/SettingsPassword.vue'

async function setup(role: 'owner' | 'admin') {
  setActivePinia(createPinia())
  const authStore = useAuthStore()
  authStore.user = { id: 'user-1', name: 'Тест', role, is_superadmin: false } as never
  authStore.shop = { id: 'shop-1' } as never

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/settings', component: SettingsView }],
  })
  await router.push({ path: '/settings', query: { tab: 'profile' } })
  await router.isReady()

  return shallowMount(SettingsView, { global: { plugins: [router] } })
}

/** display контейнера, в котором лежит SettingsPassword (его прямой родитель — div с v-show) */
function profileTabVisible(wrapper: Awaited<ReturnType<typeof setup>>): boolean {
  const el = wrapper.findComponent(SettingsPassword).element as HTMLElement
  return el.parentElement?.style.display !== 'none'
}

describe('SettingsView — доступ к вкладке "Профиль"', () => {
  test('владелец видит открытую вкладку с SettingsProfile и SettingsPassword', async () => {
    const wrapper = await setup('owner')

    expect(wrapper.findAll('button').map(b => b.text())).toContain('Профиль')
    expect(profileTabVisible(wrapper)).toBe(true)
    expect(wrapper.findComponent(SettingsProfile).exists()).toBe(true)
  })

  test('администратор видит кнопку вкладки и реально открытую вкладку (не скрытую v-show на "main")', async () => {
    const wrapper = await setup('admin')

    expect(wrapper.findAll('button').map(b => b.text())).toContain('Профиль')
    expect(profileTabVisible(wrapper)).toBe(true)
  })

  test('администратору НЕ показывают SettingsProfile (аватар/телефон — только владелец)', async () => {
    const wrapper = await setup('admin')

    // v-if, не v-show — тут .exists() корректен: при false компонент
    // реально не создаётся, в отличие от SettingsPassword выше.
    expect(wrapper.findComponent(SettingsProfile).exists()).toBe(false)
  })
})
