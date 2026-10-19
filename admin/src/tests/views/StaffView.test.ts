import { describe, expect, test, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import type { StaffMember } from '@/types'

/**
 * Живой разбор 2026-10-19: Ховар создал одного сборщика ("Мякиш",
 * подчиняется Юлии) — в списке появились ДВЕ карточки с одним и тем же
 * email, одна без аватарки и "Подчиняется владельцу" вместо "Администратор:
 * Юлия". В БД при этом ровно одна запись (проверено на сервере) — чисто
 * гонка на фронте: событие .staff.updated успевало перезагрузить список
 * (load(), полная замена массива) РАНЬШЕ, чем модалка создания сама же
 * эмитила 'saved' с тем же id — а handleSaved() для create слепо делал
 * unshift() без проверки, не добавляя тем самым дубль поверх уже
 * загруженной настоящей записи.
 */

const { fakeChannel, fakeEcho } = vi.hoisted(() => {
  const fakeChannel = { listen: vi.fn(), stopListening: vi.fn() }
  return { fakeChannel, fakeEcho: { private: vi.fn(() => fakeChannel) } }
})
vi.mock('@/lib/echo', () => ({ getEcho: () => fakeEcho }))

import { api } from '@/lib/api'
import StaffView from '@/views/StaffView.vue'
import AdminFormModal from '@/components/modals/AdminFormModal.vue'

const MYAKISH_FROM_SERVER: StaffMember = {
  id: 'staff-myakish',
  role: 'collector',
  master_id: null,
  admin_id: 'staff-julia',
  admin_name: 'Юлия',
  category_ids: null,
  invite_email: 'evdokiyaivanova46@gmail.com',
  invite_name: 'Мякиш',
  avatar_url: 'https://example.test/avatar.webp',
  phone: '+79136767676',
  last_login_at: null,
  accepted_at: null,
  invite_expires_at: '2026-10-21T00:00:00Z',
  is_pending: true,
  is_online: false,
  is_expired: false,
  user: null,
}

async function setup() {
  setActivePinia(createPinia())
  const authStore = useAuthStore()
  authStore.shop = { id: 'shop-1' } as never
  authStore.user = { id: 'khovar-1', name: 'Ховар', role: 'owner', is_superadmin: false } as never

  vi.spyOn(api, 'getStaff').mockResolvedValue({ data: [] })

  const wrapper = mount(StaffView, { global: { stubs: { Teleport: true } } })
  await flushPromises()
  await wrapper.vm.$nextTick()
  return wrapper
}

function flushPromises() {
  return new Promise((resolve) => setTimeout(resolve, 0))
}

describe('StaffView — гонка WS-перезагрузки и "saved" от модалки создания', () => {
  test('WS прилетает и перезагружает список ПЕРВЫМ, модалка эмитит "saved" ВТОРОЙ — карточка не задваивается', async () => {
    const wrapper = await setup()
    const getStaff = api.getStaff as unknown as ReturnType<typeof vi.fn>

    // .staff.updated прилетел первым: load() полностью заменяет массив
    // настоящей записью с сервера (с admin_name, но ЕЩЁ без аватарки —
    // ровно как в живом разборе, где update() с аватаркой ещё не долетел).
    getStaff.mockResolvedValue({ data: [{ ...MYAKISH_FROM_SERVER, avatar_url: null }] })
    const onStaffUpdated = fakeChannel.listen.mock.calls.find(([event]) => event === '.staff.updated')?.[1] as (() => void) | undefined
    onStaffUpdated?.()
    await flushPromises()
    await wrapper.vm.$nextTick()

    // Модалка создания эмитит 'saved' ВТОРЫМ (со своим локальным
    // avatar_url, который она знает раньше сервера) — тот же id.
    wrapper.findComponent(AdminFormModal).vm.$emit('saved', MYAKISH_FROM_SERVER, 'create')
    await wrapper.vm.$nextTick()

    const myakishMentions = wrapper.text().split('Мякиш').length - 1
    expect(myakishMentions).toBe(1)
  })

  test('без гонки (saved прилетает первым) — тоже ровно одна карточка', async () => {
    const wrapper = await setup()

    wrapper.findComponent(AdminFormModal).vm.$emit('saved', MYAKISH_FROM_SERVER, 'create')
    await wrapper.vm.$nextTick()

    expect(wrapper.text().split('Мякиш').length - 1).toBe(1)
  })
})
