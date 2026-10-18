<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { getEcho } from '@/lib/echo'
import UiModal from '@/shared/ui/UiModal.vue'

/**
 * «Вам назначили заказ» — всплывает, когда через бота (Telegram/MAX)
 * заказ закрепили за ЭТИМ пользователем (PLAN.md 2026-10-18). С общего
 * телефона магазина кнопки может нажимать кто угодно, не обязательно сам
 * назначенный, поэтому обычным тостом, который сам уезжает за несколько
 * секунд, легко промахнуться — окно висит, пока не закроют вручную или не
 * перейдут к заказу.
 *
 * Событие order.assigned шлётся всем в канале shop.{id} (том же, на
 * который и так подписан AppLayout/CollectorLayout) — отдельный приватный
 * канал на пользователя ради одного события заводить незачем, фильтр "моё
 * ли это" делаем здесь же, по payload.collectorId.
 */

interface OrderAssignedPayload {
  orderId: string
  orderShortId: string
  collectorId: string
  collectorName: string
}

const authStore = useAuthStore()
const router = useRouter()

const show = ref(false)
const orderId = ref('')
const orderShortId = ref('')

// Тот же звук и тот же тумблер localStorage, что у остальных сигналов в
// AppLayout — отдельного звука под это уведомление не заводим.
const alertAudio = new Audio('/sounds/chat-notify.wav')
function playAlertSound() {
  if (localStorage.getItem('chat_sound_enabled') === 'false') return
  alertAudio.currentTime = 0
  alertAudio.play().catch(() => { /* автовоспроизведение заблокировано браузером — тихо игнорируем */ })
}

onMounted(() => {
  const shopId = authStore.shop?.id
  if (!shopId) return

  getEcho().private(`shop.${shopId}`).listen('.order.assigned', (payload: OrderAssignedPayload) => {
    if (payload.collectorId !== authStore.user?.id) return
    orderId.value = payload.orderId
    orderShortId.value = payload.orderShortId
    show.value = true
    playAlertSound()
  })
  // Канал не покидаем здесь — им владеет layout (AppLayout/CollectorLayout),
  // который и отписывается от него при размонтировании.
})

function goToOrder() {
  show.value = false
  router.push({
    name: authStore.isCollector ? 'collector-order-detail' : 'order-detail',
    params: { id: orderId.value },
  })
}
</script>

<template>
  <UiModal v-model="show" max-width="max-w-sm">
    <div class="p-6 text-center">
      <div class="text-4xl mb-3">📦</div>
      <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Вам назначили заказ</h3>
      <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">Заказ #{{ orderShortId }} теперь на вас</p>
      <div class="flex gap-2">
        <button
          @click="show = false"
          class="flex-1 px-4 py-2 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
        >
          Закрыть
        </button>
        <button
          @click="goToOrder"
          class="flex-1 px-4 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 transition-colors"
        >
          Перейти к заказу
        </button>
      </div>
    </div>
  </UiModal>
</template>
