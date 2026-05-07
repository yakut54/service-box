<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { api } from '@/lib/api'
import type { SubscriptionStatus, SubscriptionPayment } from '@/types'

// ─── State ───────────────────────────────────────────────────────────────────

const loading = ref(true)
const paying  = ref(false)
const error   = ref('')
const success = ref('')

const subscription = ref<SubscriptionStatus | null>(null)
const payments     = ref<SubscriptionPayment[]>([])

const selectedPlan   = ref('start')
const selectedPeriod = ref(1)

// ─── Plans config ─────────────────────────────────────────────────────────────

const PLAN_ORDER = ['micro', 'start', 'business', 'pro']
const PLAN_META: Record<string, { name: string; description: string; popular?: boolean }> = {
  micro:    { name: 'Micro',    description: 'Для старта' },
  start:    { name: 'Start',    description: 'Для малого бизнеса' },
  business: { name: 'Business', description: 'Для растущего бизнеса', popular: true },
  pro:      { name: 'Pro',      description: 'Максимум возможностей' },
}

interface Plan {
  id: string
  name: string
  description: string
  priceMonth: number
  features: string[]
  maxOrders: number | null
  maxMasters: number | null
  popular?: boolean
}

const plans = ref<Plan[]>([])

const PERIODS = [
  { months: 1,  label: '1 месяц',  discount: 0 },
  { months: 3,  label: '3 месяца', discount: 0 },
  { months: 6,  label: '6 месяцев', discount: 10 },
  { months: 12, label: '12 месяцев', discount: 17, note: '2 месяца в подарок' },
]

// ─── Computed ─────────────────────────────────────────────────────────────────

const currentPlan = computed(() => plans.value.find(p => p.id === subscription.value?.plan) ?? null)

const selectedPlanData = computed(() => plans.value.find(p => p.id === selectedPlan.value) ?? plans.value[0])

const totalPrice = computed(() => {
  const plan = selectedPlanData.value
  if (selectedPeriod.value >= 12) {
    return plan.priceMonth * 10 // 2 months free
  }
  return plan.priceMonth * selectedPeriod.value
})

const monthlyPrice = computed(() => totalPrice.value / selectedPeriod.value)

const isCurrentPlan = computed(() => selectedPlan.value === subscription.value?.plan)

const statusLabel = computed(() => {
  if (!subscription.value) return ''
  if (!subscription.value.is_active) return 'Истекла'
  if (subscription.value.is_expiring_soon) return `Истекает через ${subscription.value.days_until_expiration} дн.`
  return `Активна ещё ${subscription.value.days_until_expiration} дн.`
})

const statusClass = computed(() => {
  if (!subscription.value?.is_active) return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
  if (subscription.value?.is_expiring_soon) return 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400'
  return 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
})

// ─── Load ─────────────────────────────────────────────────────────────────────

async function load() {
  loading.value = true
  try {
    const [sub, pays, pricing] = await Promise.all([
      api.getSubscription(),
      api.getSubscriptionPayments(),
      api.getPricing(),
    ])
    subscription.value = sub
    payments.value     = pays
    plans.value = PLAN_ORDER
      .filter(id => pricing[id])
      .map(id => {
        const p    = pricing[id]
        const meta = PLAN_META[id]
        return {
          id,
          name:        meta.name,
          description: meta.description,
          popular:     meta.popular,
          priceMonth:  Math.round(p.price_kopecks / 100),
          features:    p.features ?? [],
          maxOrders:   p.max_orders_per_month ?? null,
          maxMasters:  p.max_masters ?? null,
        }
      })
    if (sub.plan) selectedPlan.value = sub.plan
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Ошибка загрузки данных'
  } finally {
    loading.value = false
  }
}

// ─── Pay ──────────────────────────────────────────────────────────────────────

async function handlePay() {
  error.value   = ''
  success.value = ''
  paying.value  = true
  try {
    const result = await api.createSubscriptionPayment({
      plan:          selectedPlan.value,
      period_months: selectedPeriod.value,
    })
    // Редиректим на страницу оплаты ЮКасса
    if (result.payment_url) {
      window.location.href = result.payment_url
    } else {
      success.value = `Платёж создан (ID: ${result.payment_id}). Ожидаем подтверждения.`
    }
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Ошибка создания платежа'
  } finally {
    paying.value = false
  }
}

// ─── Format helpers ───────────────────────────────────────────────────────────

function formatDate(iso: string) {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('ru-RU', { day: '2-digit', month: 'long', year: 'numeric' })
}

function formatAmount(amount: number) {
  return (amount / 100).toLocaleString('ru-RU') + ' ₽'
}

function paymentStatusLabel(status: string) {
  return { pending: 'Ожидает', succeeded: 'Оплачен', failed: 'Ошибка' }[status] ?? status
}

function paymentStatusClass(status: string) {
  return {
    pending:   'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
    succeeded: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    failed:    'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
  }[status] ?? ''
}

onMounted(load)
</script>

<template>
  <div class="p-6 space-y-6 max-w-5xl mx-auto">
    <!-- Header -->
    <div>
      <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Подписка</h1>
      <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Управление тарифным планом</p>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="w-8 h-8 border-2 border-primary-600 border-t-transparent rounded-full animate-spin"></div>
    </div>

    <template v-else>
      <!-- Error -->
      <div v-if="error" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg px-4 py-3 text-red-700 dark:text-red-400 text-sm">
        {{ error }}
      </div>

      <!-- Success -->
      <div v-if="success" class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg px-4 py-3 text-green-700 dark:text-green-400 text-sm">
        {{ success }}
      </div>

      <!-- Current subscription -->
      <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-6 shadow-sm">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Текущий тариф</p>
            <div class="flex items-center gap-3">
              <h2 class="text-2xl font-bold text-gray-900 dark:text-white capitalize">
                {{ subscription?.plan ?? 'Нет' }}
              </h2>
              <span v-if="subscription" :class="['px-2.5 py-0.5 rounded-full text-xs font-medium', statusClass]">
                {{ statusLabel }}
              </span>
            </div>
            <p v-if="subscription?.expires_at" class="text-sm text-gray-500 dark:text-gray-400 mt-1">
              Действует до {{ formatDate(subscription.expires_at) }}
            </p>
          </div>

          <!-- Limits -->
          <div v-if="currentPlan" class="flex gap-6">
            <div class="text-center">
              <p class="text-xs text-gray-400 mb-0.5">Заказов/мес</p>
              <p class="font-semibold text-gray-900 dark:text-white text-sm">
                {{ subscription?.limits?.max_orders_per_month ?? '∞' }}
              </p>
            </div>
            <div class="text-center">
              <p class="text-xs text-gray-400 mb-0.5">Мастеров</p>
              <p class="font-semibold text-gray-900 dark:text-white text-sm">
                {{ subscription?.limits?.max_masters ?? '∞' }}
              </p>
            </div>
          </div>
        </div>

        <!-- Expiring soon warning -->
        <div v-if="subscription?.is_expiring_soon || !subscription?.is_active"
             class="mt-4 flex items-start gap-2 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg px-4 py-3">
          <svg class="w-4 h-4 text-yellow-600 dark:text-yellow-400 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
          </svg>
          <p class="text-sm text-yellow-700 dark:text-yellow-400">
            {{ subscription?.is_active ? 'Подписка скоро истекает. Продлите, чтобы не потерять доступ.' : 'Подписка истекла. Оплатите для восстановления доступа.' }}
          </p>
        </div>
      </div>

      <!-- Plan selection -->
      <div>
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Выберите тариф</h3>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
          <button
            v-for="plan in plans"
            :key="plan.id"
            @click="selectedPlan = plan.id"
            :class="[
              'relative text-left border rounded-xl p-4 transition-all focus:outline-none',
              selectedPlan === plan.id
                ? 'border-primary-500 ring-2 ring-primary-500/30 bg-primary-50 dark:bg-primary-900/20'
                : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 hover:border-gray-300 dark:hover:border-gray-600',
            ]"
          >
            <!-- Popular badge -->
            <span v-if="plan.popular" class="absolute -top-2.5 left-1/2 -translate-x-1/2 bg-primary-600 text-white text-xs font-semibold px-2.5 py-0.5 rounded-full whitespace-nowrap">
              Популярный
            </span>

            <!-- Plan name + price -->
            <p class="font-bold text-gray-900 dark:text-white text-lg">{{ plan.name }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ plan.description }}</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mb-0.5">
              {{ plan.priceMonth.toLocaleString('ru-RU') }} ₽
            </p>
            <p class="text-xs text-gray-400 mb-3">в месяц</p>

            <!-- Features -->
            <ul class="space-y-1.5">
              <li v-for="feat in plan.features" :key="feat" class="flex items-start gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                <svg class="w-3.5 h-3.5 text-green-500 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
                {{ feat }}
              </li>
            </ul>

            <!-- Selected indicator -->
            <div v-if="selectedPlan === plan.id" class="absolute top-3 right-3 w-4 h-4 bg-primary-600 rounded-full flex items-center justify-center">
              <svg class="w-2.5 h-2.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
              </svg>
            </div>
          </button>
        </div>

        <!-- Period selection -->
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-5 shadow-sm">
          <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Период оплаты</p>
          <div class="flex flex-wrap gap-2 mb-5">
            <button
              v-for="period in PERIODS"
              :key="period.months"
              @click="selectedPeriod = period.months"
              :class="[
                'px-4 py-2 rounded-lg text-sm font-medium border transition-all relative',
                selectedPeriod === period.months
                  ? 'bg-primary-600 border-primary-600 text-white'
                  : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:border-gray-300',
              ]"
            >
              {{ period.label }}
              <span v-if="period.discount" :class="['ml-1.5 text-xs font-semibold', selectedPeriod === period.months ? 'text-green-200' : 'text-green-600 dark:text-green-400']">
                −{{ period.discount }}%
              </span>
            </button>
          </div>

          <!-- Price summary -->
          <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div>
              <p class="text-3xl font-bold text-gray-900 dark:text-white">
                {{ totalPrice.toLocaleString('ru-RU') }} ₽
              </p>
              <p class="text-sm text-gray-500 dark:text-gray-400">
                за {{ selectedPeriod }} {{ selectedPeriod === 1 ? 'месяц' : selectedPeriod < 5 ? 'месяца' : 'месяцев' }}
                <span v-if="selectedPeriod > 1"> · {{ Math.round(monthlyPrice).toLocaleString('ru-RU') }} ₽/мес</span>
              </p>
              <p v-if="selectedPeriod >= 12" class="text-xs text-green-600 dark:text-green-400 font-medium mt-0.5">
                2 месяца в подарок при оплате за год
              </p>
            </div>

            <div class="text-right">
              <p class="text-xs text-gray-400 dark:text-gray-500 mb-2">
                Нажимая «Оплатить», вы соглашаетесь с
                <a href="/offer" target="_blank" rel="noopener" class="text-primary-500 hover:underline">офертой</a>
                и
                <a href="/privacy" target="_blank" rel="noopener" class="text-primary-500 hover:underline">политикой конфиденциальности</a>
              </p>
            <button
              @click="handlePay"
              :disabled="paying"
              class="inline-flex items-center gap-2 px-6 py-3 bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white font-semibold rounded-lg transition-colors"
            >
              <svg v-if="paying" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
              </svg>
              <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
              </svg>
              {{ paying ? 'Обработка...' : isCurrentPlan ? 'Продлить' : 'Перейти на ' + selectedPlanData.name }}
            </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Payment history -->
      <div>
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">История платежей</h3>

        <div v-if="payments.length === 0" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-8 text-center text-gray-500 dark:text-gray-400 text-sm">
          Платежей пока нет
        </div>

        <div v-else class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Дата</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Тариф</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Период</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Сумма</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Статус</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <tr v-for="pay in payments" :key="pay.id">
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ formatDate(pay.created_at) }}</td>
                <td class="px-4 py-3 font-medium text-gray-900 dark:text-white capitalize">{{ pay.plan }}</td>
                <td class="px-4 py-3 text-gray-500 dark:text-gray-400">
                  <span v-if="pay.period_start && pay.period_end">
                    {{ formatDate(pay.period_start) }} — {{ formatDate(pay.period_end) }}
                  </span>
                  <span v-else>—</span>
                </td>
                <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ formatAmount(pay.amount) }}</td>
                <td class="px-4 py-3">
                  <span :class="['px-2 py-0.5 rounded-full text-xs font-medium', paymentStatusClass(pay.status)]">
                    {{ paymentStatusLabel(pay.status) }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>
