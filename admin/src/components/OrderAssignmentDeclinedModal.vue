<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useAlertSound } from '@/composables/useAlertSound'
import { getEcho } from '@/lib/echo'
import UiModal from '@/shared/ui/UiModal.vue'

/**
 * «Вам отказали» — попап тому, кто предлагал заказ, когда кандидат нажал
 * «Не беру» (PLAN.md 2026-10-19). До этого отказ в веб-админке был виден
 * только как тихая смена текста в карточке заказа («Сборка» возвращалась к
 * «не взят в работу»), хотя тот же отказ в боте уже показывался явным
 * сообщением с кнопками «выберите другого» — живой тест нашёл асимметрию.
 *
 * Событие order.assignment_declined шлётся всем в канале shop.{id} (том же,
 * на который и так подписан AppLayout/CollectorLayout) — фильтр «моё ли
 * это» по payload.initiatorId, тот же приём, что в
 * OrderAssignmentRequestModal.vue.
 */

interface AssignmentDeclinedPayload {
  orderId: string
  orderShortId: string
  initiatorId: string
  declinerName: string
}

const authStore = useAuthStore()
const router = useRouter()
const { playAlertSound } = useAlertSound()

const show = ref(false)
const orderId = ref('')
const orderShortId = ref('')
const declinerName = ref('')

onMounted(() => {
  const shopId = authStore.shop?.id
  if (!shopId) return

  getEcho().private(`shop.${shopId}`).listen('.order.assignment_declined', (payload: AssignmentDeclinedPayload) => {
    if (payload.initiatorId !== authStore.user?.id) return
    orderId.value = payload.orderId
    orderShortId.value = payload.orderShortId
    declinerName.value = payload.declinerName
    show.value = true
    playAlertSound()
  })
  // Канал не покидаем здесь — им владеет layout, который и отписывается от
  // него при размонтировании (см. OrderAssignmentRequestModal.vue).
})

function pickAnother() {
  show.value = false
  router.push({ path: `/orders/${orderId.value}`, query: { reassign: '1' } })
}

function later() {
  show.value = false
}
</script>

<template>
  <UiModal v-model="show" max-width="max-w-sm">
    <div class="p-6 text-center">
      <div class="text-4xl mb-3">❌</div>
      <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">
        {{ declinerName }} отказался(ась) от заказа
      </h3>
      <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">
        Заказ #{{ orderShortId }} — нужно выбрать другого сотрудника
      </p>
      <div class="flex flex-col sm:flex-row gap-2">
        <button
          @click="later"
          class="flex-1 px-4 py-2 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
        >
          Позже
        </button>
        <button
          @click="pickAnother"
          class="flex-1 px-4 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 transition-colors"
        >
          Выбрать другого
        </button>
      </div>
    </div>
  </UiModal>
</template>
