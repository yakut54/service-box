<script setup lang="ts">
/**
 * Постраничная навигация для списков вида «Заказы»/«Клиенты» — единый
 * компонент, чтобы не плодить одинаковую вёрстку на каждой странице.
 * Ждёт meta ровно в форме, которую отдаёт App\Http\Controllers\Concerns\Paginates
 * (current_page/last_page/per_page/total).
 */
import { computed } from 'vue'

const props = defineProps<{
  currentPage: number
  lastPage: number
  total: number
  perPage: number
}>()

const emit = defineEmits<{ 'update:currentPage': [page: number] }>()

const from = computed(() => props.total === 0 ? 0 : (props.currentPage - 1) * props.perPage + 1)
const to = computed(() => Math.min(props.currentPage * props.perPage, props.total))

// Номера страниц с многоточиями — первая/последняя всегда видны, вокруг
// текущей ±1, остальное схлопывается, чтобы не рисовать 50 кнопок подряд.
const pages = computed<(number | '...')[]>(() => {
  const last = props.lastPage
  const cur = props.currentPage
  if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1)

  const result: (number | '...')[] = [1]
  if (cur > 3) result.push('...')
  for (let p = Math.max(2, cur - 1); p <= Math.min(last - 1, cur + 1); p++) result.push(p)
  if (cur < last - 2) result.push('...')
  result.push(last)
  return result
})

function go(page: number) {
  if (page < 1 || page > props.lastPage || page === props.currentPage) return
  emit('update:currentPage', page)
}
</script>

<template>
  <div v-if="lastPage > 1" class="flex flex-col sm:flex-row items-center justify-between gap-3 pt-2">
    <p class="text-sm text-gray-500 dark:text-gray-400 order-2 sm:order-1">
      {{ from }}–{{ to }} из {{ total }}
    </p>

    <div class="flex items-center gap-1 order-1 sm:order-2">
      <button
        type="button"
        class="btn-ghost btn-sm px-2"
        :disabled="currentPage === 1"
        @click="go(currentPage - 1)"
        aria-label="Предыдущая страница"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
      </button>

      <template v-for="(p, i) in pages" :key="i">
        <span v-if="p === '...'" class="px-2 text-gray-400 select-none">…</span>
        <button
          v-else
          type="button"
          class="btn-sm min-w-[2.25rem] rounded-lg font-medium"
          :class="p === currentPage
            ? 'bg-primary-600 text-white'
            : 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800'"
          @click="go(p)"
        >{{ p }}</button>
      </template>

      <button
        type="button"
        class="btn-ghost btn-sm px-2"
        :disabled="currentPage === lastPage"
        @click="go(currentPage + 1)"
        aria-label="Следующая страница"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
      </button>
    </div>
  </div>
</template>
