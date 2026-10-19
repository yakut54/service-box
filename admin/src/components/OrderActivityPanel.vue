<script setup lang="ts">
// Журнал действий с заказом (взял/отпустил/сменил статус/отметил проблему) —
// общий для владельца/админа (OrderDetailView.vue) и сборщика
// (CollectorOrderDetailView.vue), чтобы не дублировать вёрстку/фетч.
import { ref, onMounted, watch } from 'vue'
import { api, ApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { formatDateTime } from '@/shared/lib/format'
import { ORDER_STATUS_LABELS } from '@/shared/lib/labels'
import { UiSkeleton } from '@/shared/ui'
import type { OrderActivity } from '@/types'

const props = defineProps<{ orderId: string }>()

const authStore = useAuthStore()
const rows = ref<OrderActivity[]>([])
const loading = ref(false)

const ACTION_LABELS: Record<OrderActivity['action'], string> = {
  claimed: 'Взял в работу',
  released: 'Отказался от заказа',
  status_changed: 'Сменил статус',
  problem_reported: 'Отметил проблему',
  assignment_requested: 'Предложил(а) заказ',
  assignment_declined: 'Отказался(ась) от предложения',
  assignment_cancelled: 'Отменил(а) запрос',
}

function statusLabel(status: string | null) {
  if (!status) return null
  return ORDER_STATUS_LABELS[status] || status
}

async function load() {
  loading.value = true
  try {
    const res = await api.getOrderActivity(props.orderId)
    rows.value = res.data
  } catch (e) {
    // Необязательная панель — если не загрузилась, просто не показываем.
    if (!(e instanceof ApiError)) throw e
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => props.orderId, load)

defineExpose({ reload: load })
</script>

<template>
  <div class="card">
    <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-3">История заказа</div>

    <div v-if="loading" class="space-y-2">
      <UiSkeleton v-for="i in 3" :key="i" height="2rem" />
    </div>
    <p v-else-if="rows.length === 0" class="text-sm text-gray-400 dark:text-gray-500">Пока ничего не происходило</p>
    <div v-else class="divide-y divide-gray-100 dark:divide-gray-700">
      <div v-for="row in rows" :key="row.id" class="py-2 flex items-start justify-between gap-3 text-sm">
        <div class="min-w-0">
          <p class="text-gray-900 dark:text-white">
            {{ ACTION_LABELS[row.action] }}
            <span v-if="row.action === 'status_changed'" class="text-gray-500 dark:text-gray-400">
              — {{ statusLabel(row.from_status) }} → {{ statusLabel(row.to_status) }}
            </span>
          </p>
          <p class="text-xs text-gray-400 dark:text-gray-500 truncate">
            {{ row.actor_name }}{{ row.note ? ` · «${row.note}»` : '' }}
          </p>
        </div>
        <p class="shrink-0 text-xs text-gray-400 dark:text-gray-500 whitespace-nowrap">{{ formatDateTime(row.created_at, authStore.shop?.timezone ?? undefined) }}</p>
      </div>
    </div>
  </div>
</template>
