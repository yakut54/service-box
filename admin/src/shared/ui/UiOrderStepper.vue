<script setup lang="ts">
/**
 * Визуальный таймлайн статуса заказа — «Оформлен → В работе → Собрано →
 * [В доставке] → Завершён/Доставлен», по образцу доставочных приложений
 * (Ozon/Wildberries). Плоский бейдж (`badge-${status}`) в заголовке экрана
 * заказа остаётся как есть — это отдельная карточка под ним для детального
 * вида, не замена.
 *
 * cancelled — таймлайн не рисуется, вместо него баннер.
 * needs_attention — таймлайн рисуется как обычно, застыв на шаге
 * processing (туда он всегда возвращается, см. Order::TRANSITIONS), но эта
 * точка подсвечена цветом badge-needs_attention + подпись-предупреждение.
 */
import { computed } from 'vue'
import { orderStatusSteps, orderStepIndex } from '@/shared/lib/labels'

const props = defineProps<{
  status: string
  deliveryMethod?: string | null
  /** order.pick_note — есть ли реально заметка, на которую можно сослаться.
   *  Без неё (например, заказ просто отказались собирать без причины —
   *  см. OrderController::release) подпись «см. заметку ниже» вела в
   *  никуда — найдено живым разбором 2026-10-17. */
  hasNote?: boolean
}>()

const steps = computed(() => orderStatusSteps(props.deliveryMethod))
const currentIndex = computed(() => orderStepIndex(props.status, props.deliveryMethod))
const isCancelled = computed(() => props.status === 'cancelled')
const isStuck = computed(() => props.status === 'needs_attention')
</script>

<template>
  <div class="card">
    <div v-if="isCancelled" class="flex items-center gap-2 text-red-600 dark:text-red-400 font-medium text-sm">
      <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
      </svg>
      Заказ отменён
    </div>

    <div v-else class="flex items-start">
      <template v-for="(step, i) in steps" :key="step.key">
        <div class="flex flex-col items-center flex-1 min-w-0">
          <div
            :class="[
              'w-6 h-6 rounded-full flex items-center justify-center shrink-0 border-2 transition-colors',
              i < currentIndex ? 'bg-primary-600 border-primary-600' :
              i === currentIndex && isStuck ? 'bg-pink-600 border-pink-600' :
              i === currentIndex ? 'bg-primary-600 border-primary-600' :
              'bg-transparent border-gray-300 dark:border-gray-600',
            ]"
          >
            <svg v-if="i < currentIndex" class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
            </svg>
            <svg v-else-if="i === currentIndex && isStuck" class="w-3.5 h-3.5 text-white" fill="currentColor" viewBox="0 0 24 24">
              <path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a1 1 0 00.87 1.5h18.62a1 1 0 00.87-1.5L13.71 3.86a1 1 0 00-1.72 0z" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <div v-else-if="i === currentIndex" class="w-2 h-2 rounded-full bg-white" />
          </div>
          <span
            :class="['mt-1.5 text-[11px] text-center leading-tight px-0.5 whitespace-nowrap',
              i === currentIndex ? 'font-semibold text-gray-900 dark:text-white' : 'text-gray-400 dark:text-gray-500']"
          >{{ step.label }}</span>
        </div>
        <div
          v-if="i < steps.length - 1"
          :class="['h-0.5 flex-1 max-[360px]:flex-[0.2] mt-3 -mx-1', i < currentIndex ? 'bg-primary-600' : 'bg-gray-200 dark:bg-gray-700']"
        />
      </template>
    </div>

    <p v-if="!isCancelled && isStuck" class="mt-3 text-xs text-pink-600 dark:text-pink-400 font-medium">
      <template v-if="hasNote">⚠ Требует внимания — сборка остановлена, см. заметку ниже</template>
      <template v-else>⚠ Требует внимания — посмотрите детали заказа ниже</template>
    </p>
  </div>
</template>
