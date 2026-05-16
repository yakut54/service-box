<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import SettingsShopInfo         from '@/components/settings/SettingsShopInfo.vue'
import SettingsEmbedCode        from '@/components/settings/SettingsEmbedCode.vue'
import SettingsPassword         from '@/components/settings/SettingsPassword.vue'
import SettingsTelegram         from '@/components/settings/SettingsTelegram.vue'
import SettingsMax              from '@/components/settings/SettingsMax.vue'
import SettingsWorkHours        from '@/components/settings/SettingsWorkHours.vue'
import SettingsYookassa         from '@/components/settings/SettingsYookassa.vue'
import SettingsWidgetAppearance from '@/components/settings/SettingsWidgetAppearance.vue'
import SettingsWidgetAnalytics  from '@/components/settings/SettingsWidgetAnalytics.vue'
import SettingsDelivery         from '@/components/settings/SettingsDelivery.vue'

const route  = useRoute()
const router = useRouter()

const tabs = [
  { id: 'main',          label: 'Основное' },
  { id: 'widget',        label: 'Виджет' },
  { id: 'notifications', label: 'Уведомления' },
  { id: 'payments',      label: 'Платежи' },
  { id: 'delivery',      label: 'Доставка' },
] as const

type TabId = (typeof tabs)[number]['id']

const activeTab = computed<TabId>(() => {
  const t = route.query.tab as string | undefined
  return (tabs.some(tab => tab.id === t) ? t : 'main') as TabId
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
        v-for="tab in tabs"
        :key="tab.id"
        type="button"
        @click="setTab(tab.id)"
        :class="['flex-shrink-0 text-sm py-2 px-4 rounded-lg transition-all font-medium whitespace-nowrap',
          activeTab === tab.id
            ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm'
            : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300']"
      >{{ tab.label }}</button>
    </div>

    <!-- Основное -->
    <div v-show="activeTab === 'main'" class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
      <SettingsShopInfo />
      <div class="flex flex-col gap-6">
        <SettingsWorkHours />
        <SettingsPassword />
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
    </div>

    <!-- Платежи -->
    <div v-show="activeTab === 'payments'" class="max-w-xl">
      <SettingsYookassa />
    </div>

    <!-- Доставка -->
    <div v-show="activeTab === 'delivery'" class="max-w-xl">
      <SettingsDelivery />
    </div>
  </div>
</template>
