<script setup lang="ts">
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { api, ApiError } from '@/lib/api'
import { getEcho } from '@/lib/echo'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatPrice, formatWeight } from '@/shared/lib/format'
import { ORDER_STATUS_LABELS } from '@/shared/lib/labels'
import { UiSpinner, UiNumberField, UiSlideConfirm } from '@/shared/ui'
import type { Order, OrderItem } from '@/types'

const route = useRoute()
const authStore = useAuthStore()
const toast = useToast()

const order      = ref<Order | null>(null)
const loading    = ref(true)
const error      = ref('')
const claiming   = ref(false)
const finishing  = ref(false)
const savingItem = ref<string | null>(null)

// Черновой ввод веса по каждой weight_variable-позиции — в кг, храним по
// item.id, шлём на бэкенд в граммах (см. старую реализацию — логика та же).
const weightDrafts = reactive<Record<string, string>>({})

// Ручной ввод «нашёл меньше» — открывается по позиции отдельно от обычного
// тапа-чекбокса, чтобы не путать «собрано полностью» с «собрано частично».
const shortageDrafts = reactive<Record<string, number | null>>({})
const shortageOpenFor = ref<string | null>(null)

const problemNote = ref('')
const showProblemForm = ref(false)
const reportingProblem = ref(false)
const shortageNote = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const resp = await api.getOrder(route.params.id as string)
    order.value = resp.data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Не удалось загрузить заказ'
  } finally {
    loading.value = false
  }
}

function isWeightVariable(item: OrderItem): boolean {
  return item.product?.physical?.sale_mode === 'weight_variable'
}

function isResolved(item: OrderItem): boolean {
  return isWeightVariable(item) ? item.actual_weight_grams != null : item.picked_qty != null
}

const resolvedCount = computed(() => order.value?.items?.filter(isResolved).length ?? 0)
const totalCount    = computed(() => order.value?.items?.length ?? 0)
const allResolved   = computed(() => totalCount.value > 0 && resolvedCount.value === totalCount.value)

const hasShortage = computed(() =>
  order.value?.items?.some(i => !isWeightVariable(i) && i.picked_qty != null && i.picked_qty < i.quantity) ?? false
)

// Причина, по которой слайдер сейчас не двигается — без неё выглядит как
// баг «слайдер не шевелится», а не как «сначала сделай вот это».
const finishBlockedReason = computed(() => {
  if (order.value && !order.value.paid_at) return 'Заказ ещё не оплачен'
  if (order.value?.surcharge_status === 'pending') return 'Ждём от покупателя оплату доплаты за перевес'
  if (!allResolved.value) return 'Сначала разберите все позиции'
  if (hasShortage.value && !shortageNote.value.trim()) return 'Укажите причину недобора выше'
  return undefined
})

// «Моя» ли это сборка — сравниваем по имени: у сборщика нет отдельного id
// на фронте (staff_id не отдаётся в /auth/me), а магазин маленький (1-3
// сборщика) — совпадение имён можно не учитывать всерьёз.
const isMine = computed(() => !order.value?.collector_name || order.value.collector_name === authStore.user?.name)
const isClaimed = computed(() => !!order.value?.collector_name)

async function claim(takeover = false) {
  if (!order.value) return
  claiming.value = true
  try {
    const resp = await api.claimOrder(order.value.id, takeover)
    order.value = resp.data
    toast.success('Заказ взят в работу')
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : 'Не удалось взять заказ')
  } finally {
    claiming.value = false
  }
}

async function setPicked(item: OrderItem, pickedQty: number | null) {
  if (!order.value || savingItem.value) return
  savingItem.value = item.id
  try {
    const resp = await api.pickOrderItem(order.value.id, item.id, pickedQty)
    order.value = resp.data
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : 'Не удалось сохранить отметку')
  } finally {
    savingItem.value = null
  }
}

function togglePicked(item: OrderItem) {
  if (!isMine.value) return
  setPicked(item, item.picked_qty != null ? null : item.quantity)
}

function openShortage(item: OrderItem) {
  shortageDrafts[item.id] = item.picked_qty ?? 0
  shortageOpenFor.value = item.id
}

async function confirmShortage(item: OrderItem) {
  const qty = shortageDrafts[item.id]
  if (qty == null) return
  await setPicked(item, qty)
  shortageOpenFor.value = null
}

async function confirmWeight(item: OrderItem) {
  if (!order.value) return
  const kg = parseFloat(weightDrafts[item.id])
  if (!Number.isFinite(kg) || kg < 0) {
    toast.error('Введите вес')
    return
  }
  savingItem.value = item.id
  try {
    const resp = await api.submitOrderItemWeight(order.value.id, item.id, Math.round(kg * 1000))
    order.value = resp.data
    delete weightDrafts[item.id]
    toast.success('Вес подтверждён')
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : 'Не удалось сохранить вес')
  } finally {
    savingItem.value = null
  }
}

const slideRef = ref<InstanceType<typeof UiSlideConfirm> | null>(null)

async function finish() {
  if (!order.value) return
  finishing.value = true
  try {
    const resp = await api.updateOrderStatus(order.value.id, 'completed', hasShortage.value ? shortageNote.value : undefined)
    order.value = resp.data
    toast.success('Заказ собран')
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : 'Не удалось завершить заказ')
    // Сервер отклонил (например, кто-то не оплатил заказ) — бегунок иначе
    // так и остаётся у конца дорожки, будто всё получилось.
    slideRef.value?.reset()
  } finally {
    finishing.value = false
  }
}

async function submitProblem() {
  if (!order.value || !problemNote.value.trim()) return
  reportingProblem.value = true
  try {
    const resp = await api.reportOrderProblem(order.value.id, problemNote.value.trim())
    order.value = resp.data
    showProblemForm.value = false
    problemNote.value = ''
    toast.success('Владелец уведомлён')
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : 'Не удалось отправить')
  } finally {
    reportingProblem.value = false
  }
}

let channelName: string | null = null

onMounted(() => {
  load()

  const shopId = authStore.shop?.id
  if (shopId) {
    channelName = `shop.${shopId}`
    // Заказ могли отменить/переоткрыть, пока сборщик держит экран открытым
    // — тот же приём, что в очереди (см. CollectorOrdersView).
    getEcho().private(channelName).listen('.orders.updated', () => load())
  }
})

onUnmounted(() => {
  if (channelName) getEcho().leave(channelName)
})
</script>

<template>
  <div class="p-4 space-y-4 pb-28">
    <RouterLink :to="{ name: 'collector-orders' }" class="inline-flex items-center gap-1 text-sm text-gray-500 dark:text-gray-400 hover:text-primary-600 dark:hover:text-primary-400">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
      </svg>
      Все заказы
    </RouterLink>

    <div v-if="loading" class="card flex items-center justify-center py-16">
      <UiSpinner />
    </div>

    <div v-else-if="error" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">
      {{ error }}
    </div>

    <template v-else-if="order">
      <!-- Header + progress -->
      <div class="card space-y-2 sticky top-0 z-10">
        <div class="flex items-center justify-between">
          <h1 class="text-lg font-semibold text-gray-900 dark:text-white">
            <span class="text-gray-400 font-normal">#{{ order.id.slice(-4).toUpperCase() }}</span>
            {{ order.customer_name }}
          </h1>
          <span :class="`badge-${order.status}`">{{ ORDER_STATUS_LABELS[order.status] }}</span>
        </div>
        <div v-if="totalCount > 0" class="space-y-1">
          <div class="h-2 rounded-full bg-gray-100 dark:bg-gray-800 overflow-hidden">
            <div
              class="h-full bg-primary-600 transition-all"
              :style="{ width: `${(resolvedCount / totalCount) * 100}%` }"
            />
          </div>
          <p class="text-xs text-gray-500 dark:text-gray-400">Собрано {{ resolvedCount }} из {{ totalCount }}</p>
        </div>
      </div>

      <!-- Claim banner -->
      <div v-if="!isClaimed" class="card space-y-3 text-center">
        <p class="text-sm text-gray-600 dark:text-gray-300">Заказ ещё не взят в работу</p>
        <button @click="claim()" :disabled="claiming" class="btn-primary w-full">
          {{ claiming ? 'Беру…' : 'Взять в работу' }}
        </button>
      </div>
      <div v-else-if="!isMine" class="card space-y-2 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800">
        <p class="text-sm text-amber-800 dark:text-amber-300">Собирает {{ order.collector_name }}</p>
        <button @click="claim(true)" :disabled="claiming" class="btn-secondary btn-sm w-full">
          Всё равно взять
        </button>
      </div>

      <a v-if="order.customer_phone" :href="`tel:${order.customer_phone}`" class="block text-sm text-primary-600 dark:text-primary-400">
        {{ order.customer_phone }}
      </a>
      <div v-if="order.shipping_address" class="text-sm text-gray-600 dark:text-gray-300">
        {{ order.shipping_address.city }}, {{ order.shipping_address.street }} {{ order.shipping_address.building }}
        <span v-if="order.shipping_address.apartment">, кв. {{ order.shipping_address.apartment }}</span>
      </div>
      <div v-else-if="order.delivery_method" class="text-sm text-gray-600 dark:text-gray-300">{{ order.delivery_method }}</div>
      <p v-if="order.notes" class="text-sm text-gray-500 dark:text-gray-400 italic">«{{ order.notes }}»</p>

      <!-- Items -->
      <div class="card space-y-1">
        <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Состав заказа</h2>

        <div
          v-for="item in order.items"
          :key="item.id"
          class="py-2 border-b border-gray-100 dark:border-gray-800 last:border-0"
        >
          <!-- Штучный товар — тап отмечает собранным -->
          <template v-if="!isWeightVariable(item)">
            <button
              type="button"
              class="w-full flex items-center gap-3 text-left"
              :disabled="!isClaimed || !isMine || savingItem === item.id"
              @click="togglePicked(item)"
            >
              <span
                class="w-6 h-6 rounded-full border-2 flex items-center justify-center shrink-0 transition-colors"
                :class="item.picked_qty != null
                  ? (item.picked_qty < item.quantity ? 'bg-amber-500 border-amber-500' : 'bg-green-600 border-green-600')
                  : 'border-gray-300 dark:border-gray-600'"
              >
                <svg v-if="item.picked_qty != null" class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
              </span>
              <span class="flex-1 min-w-0" :class="item.picked_qty === item.quantity && 'text-gray-400 line-through'">
                <span class="text-gray-900 dark:text-white">{{ item.product_name }}</span>
                <span v-if="item.variant_label" class="text-gray-400"> · {{ item.variant_label }}</span>
                <span class="text-gray-400"> × {{ item.quantity }}</span>
              </span>
              <span class="font-medium text-gray-700 dark:text-gray-300 shrink-0">{{ formatPrice(item.price * item.quantity) }}</span>
            </button>
            <p v-if="item.picked_qty != null && item.picked_qty < item.quantity" class="text-xs text-amber-600 dark:text-amber-400 pl-9 mt-0.5">
              Собрано {{ item.picked_qty }} из {{ item.quantity }}
            </p>

            <div v-if="isMine && isClaimed" class="pl-9 mt-1">
              <button
                v-if="shortageOpenFor !== item.id"
                type="button"
                class="text-xs text-gray-400 hover:text-primary-600 dark:hover:text-primary-400"
                @click="openShortage(item)"
              >Не нашёл / нашёл меньше</button>
              <div v-else class="flex items-center gap-2 mt-1">
                <UiNumberField v-model="shortageDrafts[item.id]" :min="0" :max="item.quantity" class="max-w-[140px]" />
                <button class="btn-secondary btn-sm" @click="confirmShortage(item)" :disabled="savingItem === item.id">Ок</button>
                <button class="text-xs text-gray-400" @click="shortageOpenFor = null">Отмена</button>
              </div>
            </div>
          </template>

          <!-- Весовой товар — существующий ввод веса, с тем же индикатором
               «собрано», что и у штучных, — раньше его тут не было вовсе,
               и весовая позиция выглядела «пропущенной» на общем фоне. -->
          <template v-else>
            <div class="flex items-center gap-3">
              <span
                class="w-6 h-6 rounded-full border-2 flex items-center justify-center shrink-0 transition-colors"
                :class="item.actual_weight_grams != null ? 'bg-green-600 border-green-600' : 'border-gray-300 dark:border-gray-600'"
              >
                <svg v-if="item.actual_weight_grams != null" class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
              </span>
              <span class="flex-1 min-w-0" :class="item.actual_weight_grams != null && 'text-gray-400 line-through'">
                <span class="text-gray-900 dark:text-white">{{ item.product_name }}</span>
                <span v-if="item.weight_grams" class="text-gray-400">, заявлено ≈ {{ formatWeight(item.weight_grams) }}</span>
              </span>
              <span class="font-medium text-gray-700 dark:text-gray-300 shrink-0">
                {{ formatPrice(item.actual_price ?? (item.price * item.quantity)) }}
              </span>
            </div>

            <div v-if="item.actual_weight_grams == null && isMine && isClaimed" class="pl-9 mt-2 space-y-1">
              <p v-if="item.product?.physical?.weight_min_grams && item.product?.physical?.weight_max_grams" class="text-xs text-gray-400">
                Допустимо: {{ formatWeight(item.product.physical.weight_min_grams) }} – {{ formatWeight(item.product.physical.weight_max_grams) }}
              </p>
              <div class="flex items-center gap-2">
                <input
                  v-model="weightDrafts[item.id]"
                  type="number"
                  step="0.01"
                  min="0"
                  placeholder="Факт. вес, кг"
                  class="input flex-1"
                />
                <button
                  @click="confirmWeight(item)"
                  :disabled="savingItem === item.id || !weightDrafts[item.id]"
                  class="btn-primary btn-sm shrink-0"
                >Подтвердить</button>
              </div>
            </div>
            <p v-else-if="item.actual_weight_grams != null" class="text-xs text-green-600 dark:text-green-400 pl-9 mt-0.5">
              Факт: {{ formatWeight(item.actual_weight_grams) }} — {{ formatPrice(item.actual_price ?? 0) }}
            </p>
          </template>
        </div>

        <div class="flex items-center justify-between pt-2 font-semibold text-gray-900 dark:text-white">
          <span>Итого</span>
          <span>{{ formatPrice(order.total_price) }}</span>
        </div>
      </div>

      <div
        v-if="order.surcharge_status === 'pending'"
        class="card bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-sm text-amber-800 dark:text-amber-300"
      >
        Факт оказался тяжелее заявленного — покупателю выставлена доплата
        {{ formatPrice(order.surcharge_amount ?? 0) }}. Заказ нельзя завершить, пока
        покупатель не подтвердит оплату.
      </div>

      <div v-if="order.pick_note" class="card bg-pink-50 dark:bg-pink-900/20 border border-pink-200 dark:border-pink-800 text-sm text-pink-800 dark:text-pink-300">
        Заметка: «{{ order.pick_note }}»
      </div>

      <!-- Проблема с заказом -->
      <div v-if="isClaimed && isMine && order.status !== 'completed' && order.status !== 'cancelled'" class="card space-y-2">
        <button v-if="!showProblemForm" type="button" class="text-sm text-red-600 dark:text-red-400" @click="showProblemForm = true">
          Проблема с заказом
        </button>
        <template v-else>
          <textarea
            v-model="problemNote"
            rows="2"
            placeholder="Что случилось? (брак, просрочка и т.п.)"
            class="input w-full"
          />
          <div class="flex gap-2">
            <button class="btn-secondary btn-sm flex-1" @click="showProblemForm = false">Отмена</button>
            <button class="btn-danger btn-sm flex-1" :disabled="!problemNote.trim() || reportingProblem" @click="submitProblem">
              Сообщить владельцу
            </button>
          </div>
        </template>
      </div>

      <!-- Finish -->
      <div v-if="isClaimed && isMine && order.status !== 'completed' && order.status !== 'cancelled'" class="fixed bottom-0 left-0 right-0 z-20 p-3 bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800">
        <div class="max-w-2xl mx-auto space-y-2">
          <textarea
            v-if="hasShortage"
            v-model="shortageNote"
            rows="2"
            placeholder="Причина недобора — покупатель должен понимать, чего не хватило"
            class="input w-full"
          />
          <UiSlideConfirm
            ref="slideRef"
            label="Готово — сдвиньте"
            :disabled-reason="finishBlockedReason"
            :disabled="!!finishBlockedReason"
            :loading="finishing"
            @confirm="finish"
          />
        </div>
      </div>
    </template>
  </div>
</template>
