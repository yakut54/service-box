<script setup lang="ts">
/**
 * Постраничная навигация для списков вида «Заказы»/«Клиенты» — единый
 * компонент, чтобы не плодить одинаковую вёрстку на каждой странице.
 * Ждёт meta ровно в форме, которую отдаёт App\Http\Controllers\Concerns\Paginates
 * (current_page/last_page/per_page/total).
 *
 * На узких экранах (<640px) номера страниц прячутся — мелкие кнопки-цифры
 * плохо нажимаются под палец — остаётся только «‹ Страница N из M ›» с
 * крупной зоной нажатия по бокам.
 */
import { computed } from 'vue'
import CustomSelect from '@/components/CustomSelect.vue'

const props = withDefaults(defineProps<{
  currentPage: number
  lastPage: number
  total: number
  perPage: number
  /** Показывать селектор «Показывать: N» — скрыт, если родитель не хочет им управлять. */
  showPerPage?: boolean
}>(), {
  showPerPage: true,
})

const emit = defineEmits<{
  'update:currentPage': [page: number]
  'update:perPage': [perPage: number]
}>()

const perPageOptions = [
  { value: '10', label: 'Показывать: 10' },
  { value: '25', label: 'Показывать: 25' },
  { value: '50', label: 'Показывать: 50' },
  { value: '100', label: 'Показывать: 100' },
]

const perPageModel = computed({
  get: () => String(props.perPage),
  set: (v: string) => emit('update:perPage', Number(v)),
})

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

const navBtn = 'h-9 min-w-[2.25rem] px-2 flex items-center justify-center rounded-lg border text-sm font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed'
const navBtnIdle = 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-primary-300 dark:hover:border-primary-600 hover:bg-gray-50 dark:hover:bg-gray-800'
const navBtnActive = 'border-primary-600 bg-primary-600 text-white'
</script>

<template>
  <div v-if="lastPage > 1 || (showPerPage && total > 0)" class="flex flex-col sm:flex-row items-center justify-between gap-3 pt-2">
    <div class="flex items-center gap-3 order-3 sm:order-1">
      <p class="text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ from }}–{{ to }} из {{ total }}</p>
      <CustomSelect v-if="showPerPage" v-model="perPageModel" :options="perPageOptions" class="w-40 shrink-0" />
    </div>

    <div v-if="lastPage > 1" class="hidden sm:flex items-center gap-1 order-2">
      <button type="button" :class="[navBtn, navBtnIdle]" :disabled="currentPage === 1" @click="go(currentPage - 1)" aria-label="Предыдущая страница">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
      </button>

      <template v-for="(p, i) in pages" :key="i">
        <span v-if="p === '...'" class="px-1.5 text-gray-400 select-none">…</span>
        <button v-else type="button" :class="[navBtn, p === currentPage ? navBtnActive : navBtnIdle]" @click="go(p)">{{ p }}</button>
      </template>

      <button type="button" :class="[navBtn, navBtnIdle]" :disabled="currentPage === lastPage" @click="go(currentPage + 1)" aria-label="Следующая страница">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
      </button>
    </div>

    <!-- Мобильный режим — без сетки цифр, только вперёд/назад покрупнее -->
    <div v-if="lastPage > 1" class="flex sm:hidden items-center gap-2 order-2 w-full justify-between">
      <button type="button" class="h-11 w-11 flex items-center justify-center rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 disabled:opacity-40" :disabled="currentPage === 1" @click="go(currentPage - 1)" aria-label="Предыдущая страница">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
      </button>
      <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Страница {{ currentPage }} из {{ lastPage }}</p>
      <button type="button" class="h-11 w-11 flex items-center justify-center rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 disabled:opacity-40" :disabled="currentPage === lastPage" @click="go(currentPage + 1)" aria-label="Следующая страница">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
      </button>
    </div>
  </div>
</template>
