<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useAlertSound } from '@/composables/useAlertSound'
import { api, ApiError } from '@/lib/api'
import { getEcho } from '@/lib/echo'
import { formatPrice } from '@/shared/lib/format'
import { DELIVERY_METHOD_LABELS } from '@/shared/lib/labels'
import UiModal from '@/shared/ui/UiModal.vue'

/**
 * «Вам предлагают заказ» — всплывает, когда через бота (Telegram/MAX) или
 * кнопку «В работу» в карточке заказа кто-то выбрал ЭТОГО пользователя
 * (PLAN.md 2026-10-19). Это ещё не назначение — claimBy() ждёт, пока сам
 * кандидат примет или откажется здесь. С общего телефона магазина кнопки
 * может нажимать кто угодно, не обязательно сам выбранный, и живой тест
 * нашёл дыру: офлайн-человека раньше "назначали" молча, он никогда бы не
 * узнал. Окно висит, пока не ответят явно — обычный тост, который сам
 * уезжает за несколько секунд, легко промахнуться.
 *
 * Событие order.assignment_requested шлётся всем в канале shop.{id} (том
 * же, на который и так подписан AppLayout/CollectorLayout) — отдельный
 * приватный канал на пользователя ради одного события заводить незачем,
 * фильтр "моё ли это" делаем здесь же, по payload.candidateId.
 */

interface AssignmentRequestedPayload {
  orderId: string
  orderShortId: string
  candidateId: string
  candidateName: string
  initiatorName: string
  itemsCount: number | null
  totalPrice: number | null
  deliveryMethod: string | null
}

const authStore = useAuthStore()
const toast = useToast()
const { playAlertSound } = useAlertSound()

const show = ref(false)
const orderId = ref('')
const orderShortId = ref('')
const initiatorName = ref('')
const itemsCount = ref<number | null>(null)
const totalPrice = ref<number | null>(null)
const deliveryMethod = ref<string | null>(null)
const responding = ref(false)

onMounted(() => {
  const shopId = authStore.shop?.id
  if (!shopId) return

  getEcho().private(`shop.${shopId}`).listen('.order.assignment_requested', (payload: AssignmentRequestedPayload) => {
    if (payload.candidateId !== authStore.user?.id) return
    orderId.value = payload.orderId
    orderShortId.value = payload.orderShortId
    initiatorName.value = payload.initiatorName
    itemsCount.value = payload.itemsCount
    totalPrice.value = payload.totalPrice
    deliveryMethod.value = payload.deliveryMethod
    show.value = true
    playAlertSound()
  })
  // Канал не покидаем здесь — им владеет layout (AppLayout/CollectorLayout),
  // который и отписывается от него при размонтировании.
})

async function accept() {
  responding.value = true
  try {
    await api.acceptOrderAssignment(orderId.value)
    toast.success('Вы взяли заказ в работу')
  } catch (e) {
    // Запрос уже мог устареть (отменили/заказ закрыли) — всё равно прячем
    // окно, отвечать больше нечем.
    toast.error(e instanceof ApiError ? e.message : 'Не удалось принять заказ')
  } finally {
    responding.value = false
    show.value = false
  }
}

async function decline() {
  responding.value = true
  try {
    await api.declineOrderAssignment(orderId.value)
    toast.info('Вы отклонили заказ')
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : 'Не удалось отклонить заказ')
  } finally {
    responding.value = false
    show.value = false
  }
}

// Клик по затемнению за окном — тот же смысл, что кнопка «Не беру»: не
// должно быть двух способов закрыть одно и то же окно с разными
// последствиями (один — отказ, другой — молча ничего).
function onDismiss(value: boolean) {
  if (!value) decline()
}
</script>

<template>
  <UiModal :model-value="show" @update:model-value="onDismiss" max-width="max-w-sm">
    <div class="p-6 text-center">
      <div class="text-4xl mb-3">📦</div>
      <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">
        {{ initiatorName }} предлагает вам заказ
      </h3>
      <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">#{{ orderShortId }}</p>

      <!-- Сводка заказа — чтобы отвечать не вслепую (живой разбор
           2026-10-19: раньше тут был только номер заказа). -->
      <div v-if="itemsCount != null || totalPrice != null || deliveryMethod" class="text-sm text-gray-600 dark:text-gray-300 mb-5 space-x-1">
        <span v-if="itemsCount != null">{{ itemsCount }} {{ itemsCount === 1 ? 'позиция' : 'позиции' }}</span>
        <span v-if="itemsCount != null && totalPrice != null">·</span>
        <span v-if="totalPrice != null">{{ formatPrice(totalPrice) }}</span>
        <span v-if="deliveryMethod" class="block text-gray-400 dark:text-gray-500 mt-0.5">
          {{ DELIVERY_METHOD_LABELS[deliveryMethod] || deliveryMethod }}
        </span>
      </div>
      <p v-else class="text-sm text-gray-500 dark:text-gray-400 mb-5">Возьмёте в работу?</p>

      <div class="flex flex-col sm:flex-row gap-2">
        <button
          @click="decline"
          :disabled="responding"
          class="flex-1 px-4 py-2 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors disabled:opacity-50"
        >
          Не беру
        </button>
        <button
          @click="accept"
          :disabled="responding"
          class="flex-1 px-4 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 transition-colors disabled:opacity-50"
        >
          {{ responding ? 'Секунду…' : 'Беру' }}
        </button>
      </div>
    </div>
  </UiModal>
</template>
