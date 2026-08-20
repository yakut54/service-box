<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import { api } from '@/lib/api'
import { parseApiError } from '@/lib/parseApiError'

type FunnelItem = { event: string; label: string; sessions: number; pct_top: number }

const funnelDays    = ref<7 | 30 | 90>(30)
const funnelData    = ref<FunnelItem[]>([])
const funnelLoading = ref(false)
const funnelError   = ref('')

async function loadFunnel() {
  funnelLoading.value = true
  funnelError.value   = ''
  try {
    const res = await api.getWidgetAnalytics(funnelDays.value)
    funnelData.value = res.funnel
  } catch (e: unknown) {
    funnelError.value = parseApiError(e, 'Не удалось загрузить аналитику')
  } finally {
    funnelLoading.value = false
  }
}

watch(funnelDays, loadFunnel)

onMounted(loadFunnel)
</script>

<template>
  <div class="card">
    <div class="flex items-center justify-between mb-1">
      <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Аналитика виджета</h2>
      <div class="flex gap-1">
        <button
          v-for="d in ([7, 30, 90] as const)" :key="d"
          @click="funnelDays = d"
          :class="['text-xs px-2 py-1 rounded transition-colors',
            funnelDays === d
              ? 'bg-indigo-600 text-white'
              : 'bg-gray-100 dark:bg-gray-800 text-gray-500 hover:text-gray-700 dark:hover:text-gray-300']"
        >{{ d }}д</button>
      </div>
    </div>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Воронка — от открытия виджета до записи</p>

    <div v-if="funnelLoading" class="text-sm text-gray-400 py-6 text-center">Загрузка...</div>
    <div v-else-if="funnelError"  class="text-sm text-red-600">{{ funnelError }}</div>
    <div v-else-if="funnelData.length" class="space-y-4">
      <div v-for="item in funnelData" :key="item.event" class="space-y-1">
        <div class="flex items-center justify-between text-sm">
          <span class="text-gray-700 dark:text-gray-300">{{ item.label }}</span>
          <span class="font-semibold text-gray-900 dark:text-white">
            {{ item.sessions.toLocaleString('ru-RU') }}
            <span class="text-xs text-gray-400 font-normal ml-1">{{ item.pct_top }}%</span>
          </span>
        </div>
        <div class="w-full bg-gray-100 dark:bg-gray-800 rounded-full h-2">
          <div class="h-2 rounded-full bg-indigo-500 transition-all duration-500" :style="{ width: item.pct_top + '%' }" />
        </div>
      </div>
    </div>
    <div v-else class="text-sm text-gray-400 py-6 text-center">Нет данных за выбранный период</div>
  </div>
</template>
