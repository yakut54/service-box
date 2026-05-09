<script setup lang="ts">
import SettingsShopInfo         from '@/components/settings/SettingsShopInfo.vue'
import SettingsEmbedCode        from '@/components/settings/SettingsEmbedCode.vue'
import SettingsPassword         from '@/components/settings/SettingsPassword.vue'
import SettingsTelegram         from '@/components/settings/SettingsTelegram.vue'
import SettingsMax              from '@/components/settings/SettingsMax.vue'
import SettingsWorkHours        from '@/components/settings/SettingsWorkHours.vue'
import SettingsYookassa         from '@/components/settings/SettingsYookassa.vue'
import SettingsWidgetAppearance from '@/components/settings/SettingsWidgetAppearance.vue'
import SettingsWidgetAnalytics  from '@/components/settings/SettingsWidgetAnalytics.vue'
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Настройки</h1>
      <p class="text-gray-500 dark:text-gray-400 mt-1">Управление магазином</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
      <div class="flex flex-col gap-6">
        <SettingsShopInfo />
        <SettingsEmbedCode />
        <SettingsPassword />
        <SettingsTelegram />
        <SettingsMax />
      </div>

      <div class="flex flex-col gap-6">
        <SettingsWorkHours />
        <SettingsYookassa />
        <SettingsWidgetAppearance />
        <SettingsWidgetAnalytics />
      </div>
    </div>
  </div>
</template>
