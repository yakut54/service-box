<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useMailFailuresStore } from '@/stores/mailFailures'
import NavBadge                 from '@/components/layout/NavBadge.vue'
import SettingsProfile          from '@/components/settings/SettingsProfile.vue'
import SettingsShopInfo         from '@/components/settings/SettingsShopInfo.vue'
import SettingsBrand            from '@/components/settings/SettingsBrand.vue'
import SettingsEmbedCode        from '@/components/settings/SettingsEmbedCode.vue'
import SettingsPassword         from '@/components/settings/SettingsPassword.vue'
import SettingsTelegram         from '@/components/settings/SettingsTelegram.vue'
import SettingsMax              from '@/components/settings/SettingsMax.vue'
import SettingsMailFailures     from '@/components/settings/SettingsMailFailures.vue'
import SettingsWorkHours        from '@/components/settings/SettingsWorkHours.vue'
import SettingsYookassa         from '@/components/settings/SettingsYookassa.vue'
import SettingsWidgetAppearance from '@/components/settings/SettingsWidgetAppearance.vue'
import SettingsWidgetAnalytics  from '@/components/settings/SettingsWidgetAnalytics.vue'
import SettingsDelivery         from '@/components/settings/SettingsDelivery.vue'
import SettingsYandexDelivery   from '@/components/settings/SettingsYandexDelivery.vue'
import SettingsChatModeration   from '@/components/settings/SettingsChatModeration.vue'
import MasterTelegram           from '@/components/master/MasterTelegram.vue'
import MasterMax                from '@/components/master/MasterMax.vue'

const route     = useRoute()
const router    = useRouter()
const authStore = useAuthStore()
const mailFailuresStore = useMailFailuresStore()

// 'widget' скрыта из таб-бара (2026-08-20) — цвет+лого переехали в SettingsBrand
// на вкладке "Основное", остальное (тема/шрифт/embed/аналитика) пока не в фокусе
// (см. PLAN.md). Компонент и вкладка остаются в коде, просто недоступны из UI.
// 'profile' — ownerOrAdmin: смена пароля (SettingsPassword) ролей не
// касается вообще, но раньше вкладка была ownerOnly целиком и админ до
// неё не добирался (живой вопрос 2026-10-19 — "как Юлии сменить пароль
// через админку"). Аватар/телефон (SettingsProfile) при этом остаются
// только владельцу — они живут в public.users, у сотрудников
// (admin/collector) своя запись в shop_staff и своё редактирование через
// "Команду" (см. AdminFormModal), это разведено явно внутри блока ниже.
// 'notifications'/'payments' — ownerOnly: боты магазина и платёжные ключи
// остаются владельцу, управляющему точки (роль admin) видна вкладка
// "Основное" (там часы работы), "Доставка" и своя "Мои уведомления".
// 'my-notifications' — adminOnly: личная привязка Telegram/MAX для роли
// admin (2026-10-17, переиспользует MasterTelegram/MasterMax — те же
// role-agnostic компоненты, что и у портала мастера, см. PLAN.md). Мастер
// сюда не заходит — у него свой отдельный портал (/master/notifications).
const tabs = [
  { id: 'profile',         label: 'Профиль', ownerOrAdmin: true },
  { id: 'main',            label: 'Основное' },
  { id: 'widget',          label: 'Виджет', hidden: true },
  { id: 'notifications',   label: 'Уведомления', ownerOnly: true },
  { id: 'my-notifications', label: 'Мои уведомления', adminOnly: true },
  { id: 'payments',        label: 'Платежи', ownerOnly: true },
  { id: 'delivery',        label: 'Доставка' },
] as const

type TabId = (typeof tabs)[number]['id']

function tabVisible(tab: (typeof tabs)[number]): boolean {
  if ('hidden' in tab && tab.hidden) return false
  if ('ownerOnly' in tab && tab.ownerOnly && !authStore.isOwner) return false
  if ('adminOnly' in tab && tab.adminOnly && !authStore.isAdmin) return false
  if ('ownerOrAdmin' in tab && tab.ownerOrAdmin && !authStore.isOwner && !authStore.isAdmin) return false
  return true
}

const visibleTabs = computed(() => tabs.filter(tabVisible))

const activeTab = computed<TabId>(() => {
  const t = route.query.tab as string | undefined
  const match = tabs.find(tab => tab.id === t)
  if (!match || !tabVisible(match)) return 'main'
  return match.id
})

function setTab(id: TabId) {
  router.replace({ query: { tab: id } })
}
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Настройки</h1>
      <p class="text-gray-500 dark:text-gray-400 mt-1">Управление магазином</p>
    </div>

    <!-- Tab bar -->
    <div class="flex gap-1 p-1 bg-gray-100 dark:bg-gray-800 rounded-xl mb-6 overflow-x-auto">
      <button
        v-for="tab in visibleTabs"
        :key="tab.id"
        type="button"
        @click="setTab(tab.id)"
        :class="['flex-shrink-0 text-sm py-2 px-4 rounded-lg transition-all font-medium whitespace-nowrap',
          activeTab === tab.id
            ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm'
            : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300']"
      >
        <span class="flex items-center gap-1.5">
          {{ tab.label }}
          <NavBadge v-if="tab.id === 'notifications'" :count="mailFailuresStore.pendingCount" />
        </span>
      </button>
    </div>

    <!-- Профиль (владелец и администратор) — аватар/телефон только
         владельцу (public.users), смена пароля ролей не касается -->
    <div v-show="activeTab === 'profile'" class="max-w-xl space-y-6">
      <SettingsProfile v-if="authStore.isOwner" />
      <SettingsPassword />
    </div>

    <!-- Основное: SettingsShopInfo/Brand/ChatModeration — только владелец,
         часы работы доступны и управляющему точки -->
    <div v-show="activeTab === 'main'" class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
      <div v-if="authStore.isOwner" class="flex flex-col gap-6">
        <SettingsShopInfo />
        <SettingsBrand />
      </div>
      <div class="flex flex-col gap-6">
        <SettingsWorkHours />
        <SettingsChatModeration v-if="authStore.isOwner" />
      </div>
    </div>

    <!-- Виджет -->
    <div v-show="activeTab === 'widget'" class="flex flex-col gap-6">
      <SettingsWidgetAppearance />
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
        <SettingsEmbedCode />
        <SettingsWidgetAnalytics />
      </div>
    </div>

    <!-- Уведомления -->
    <div v-show="activeTab === 'notifications'" class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
      <SettingsTelegram />
      <SettingsMax />
      <SettingsMailFailures class="lg:col-span-2" />
    </div>

    <!-- Мои уведомления (управляющий точки, роль admin) -->
    <div v-show="activeTab === 'my-notifications'" class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
      <MasterTelegram subject="о собранных заказах" />
      <MasterMax subject="о собранных заказах" />
    </div>

    <!-- Платежи -->
    <div v-show="activeTab === 'payments'" class="max-w-xl">
      <SettingsYookassa />
    </div>

    <!-- Доставка: SettingsYandexDelivery несёт API-ключ — только владелец -->
    <div v-show="activeTab === 'delivery'" class="max-w-xl space-y-6">
      <SettingsDelivery />
      <SettingsYandexDelivery v-if="authStore.isOwner" />
    </div>
  </div>
</template>
