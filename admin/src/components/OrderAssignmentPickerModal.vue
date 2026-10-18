<script setup lang="ts">
import { ref, watch, onMounted, onUnmounted } from 'vue'
import type { Channel } from 'laravel-echo'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { api, ApiError } from '@/lib/api'
import { getEcho } from '@/lib/echo'
import UiModal from '@/shared/ui/UiModal.vue'
import UiSpinner from '@/shared/ui/UiSpinner.vue'
import type { AssignmentCandidate } from '@/types'

/**
 * Кнопка «В работу» в обычной карточке заказа (PLAN.md 2026-10-19) —
 * раньше это был прямой updateStatus('processing') без выбора исполнителя
 * вообще (та же дыра, которую уже чинили для бота: статус «В работе» без
 * единого собирающего). Теперь это выбор кандидата с онлайн-статусом —
 * себя можно взять сразу, кого-то другого нужно сначала спросить
 * (OrderAssignmentPickerModal → OrderAssignmentFlow::request()).
 */

const props = defineProps<{
  modelValue: boolean
  orderId: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  assigned: []
}>()

const authStore = useAuthStore()
const toast = useToast()

const loading = ref(false)
const requesting = ref<string | null>(null)
const candidates = ref<AssignmentCandidate[]>([])

async function load() {
  loading.value = true
  try {
    const resp = await api.getAssignmentCandidates(props.orderId)
    candidates.value = resp.data
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : 'Не удалось загрузить список сотрудников')
  } finally {
    loading.value = false
  }
}

watch(() => props.modelValue, (open) => { if (open) load() })

// Живой статус без ручного «Обновить» (2026-10-19) — тот же .staff.updated,
// которым уже живёт статус «Активен» в Команде: он летит при каждом
// логине/логауте. Канал 'shop.{id}' общий на все экраны — добавляем/снимаем
// СВОЙ листенер (stopListening), не рвём канал целиком через leave() (баг
// найден живым тестом 2026-09-14, см. CollectorOrderDetailView).
let channel: Channel | null = null
const onStaffUpdated = () => { if (props.modelValue) load() }

onMounted(() => {
  const shopId = authStore.shop?.id
  if (shopId) {
    channel = getEcho().private(`shop.${shopId}`)
    channel.listen('.staff.updated', onStaffUpdated)
  }
})

onUnmounted(() => {
  channel?.stopListening('.staff.updated', onStaffUpdated)
})

async function choose(candidate: AssignmentCandidate) {
  if (!candidate.is_online) {
    toast.error('Нельзя выбрать сотрудника, который сейчас не в сети')
    return
  }

  requesting.value = candidate.id
  try {
    const resp = await api.requestOrderAssignment(props.orderId, candidate.id)
    toast.success(resp.message)
    emit('update:modelValue', false)
    emit('assigned')
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : 'Не удалось назначить исполнителя')
  } finally {
    requesting.value = null
  }
}
</script>

<template>
  <UiModal :model-value="modelValue" @update:model-value="(v) => emit('update:modelValue', v)" max-width="max-w-sm">
    <div class="p-6">
      <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Кто берёт заказ?</h3>

      <div v-if="loading" class="flex justify-center py-6">
        <UiSpinner />
      </div>

      <div v-else class="space-y-2">
        <button
          v-for="candidate in candidates"
          :key="candidate.id"
          type="button"
          :disabled="!candidate.is_online || requesting === candidate.id"
          class="w-full flex items-center gap-2 px-4 py-2 rounded-lg border text-left transition-colors disabled:opacity-50"
          :class="candidate.is_online
            ? 'border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800'
            : 'border-gray-100 dark:border-gray-800 cursor-not-allowed'"
          @click="choose(candidate)"
        >
          <span>{{ candidate.is_online ? '✅' : '❌' }}</span>
          <span class="flex-1 text-gray-900 dark:text-white">{{ candidate.name }}</span>
          <span v-if="candidate.id === authStore.user?.id" class="text-xs text-gray-400">вы</span>
          <span v-if="requesting === candidate.id" class="text-xs text-gray-400">…</span>
        </button>
      </div>
    </div>
  </UiModal>
</template>
