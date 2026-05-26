<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'

const props = withDefaults(defineProps<{
  modelValue: string
  min?: string
  placeholder?: string
  disabled?: boolean
}>(), {
  placeholder: 'Дата',
})

const emit = defineEmits<{
  'update:modelValue': [v: string]
  'change': [v: string]
}>()

// ─── Helpers ──────────────────────────────────────────────────────────────────

const today = new Date()
today.setHours(0, 0, 0, 0)

function parseYMD(s: string | undefined): Date | null {
  if (!s) return null
  const [y, m, d] = s.split('-').map(Number)
  const dt = new Date(y, m - 1, d)
  return isNaN(dt.getTime()) ? null : dt
}

function toYMD(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const SHORT_MONTHS = ['янв','фев','мар','апр','май','июн','июл','авг','сен','окт','ноя','дек']
const MONTH_FULL   = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь']
const DOW          = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс']

// ─── State ────────────────────────────────────────────────────────────────────

const open        = ref(false)
const openUp      = ref(false)
const anchorStyle = ref<Record<string, string>>({})
const viewMode    = ref<'days' | 'months' | 'years'>('days')

const selected = computed(() => parseYMD(props.modelValue))
const minDate  = computed(() => parseYMD(props.min))

const _init  = selected.value ?? today
const cursor = ref<Date>(new Date(_init.getFullYear(), _init.getMonth(), 1))

watch(() => props.modelValue, (v) => {
  const d = parseYMD(v)
  if (d) cursor.value = new Date(d.getFullYear(), d.getMonth(), 1)
})

// ─── Display ─────────────────────────────────────────────────────────────────

const displayValue = computed(() => {
  const d = selected.value
  if (!d) return ''
  return `${d.getDate()} ${SHORT_MONTHS[d.getMonth()]} ${d.getFullYear()}`
})

// Начало диапазона лет (блоками по 12, например 2024–2035)
const yearRangeStart = computed(() => Math.floor(cursor.value.getFullYear() / 12) * 12)

const headerLabel = computed(() => {
  if (viewMode.value === 'days')   return `${MONTH_FULL[cursor.value.getMonth()]} ${cursor.value.getFullYear()}`
  if (viewMode.value === 'months') return `${cursor.value.getFullYear()}`
  return `${yearRangeStart.value} – ${yearRangeStart.value + 11}`
})

// ─── Calendar cells ───────────────────────────────────────────────────────────

const cells = computed(() => {
  const year  = cursor.value.getFullYear()
  const month = cursor.value.getMonth()
  const last  = new Date(year, month + 1, 0)
  let startDow = new Date(year, month, 1).getDay()
  startDow = startDow === 0 ? 6 : startDow - 1

  const days: Array<{ date: Date; cur: boolean }> = []
  for (let i = startDow - 1; i >= 0; i--)
    days.push({ date: new Date(year, month, -i), cur: false })
  for (let d = 1; d <= last.getDate(); d++)
    days.push({ date: new Date(year, month, d), cur: true })
  const rem = days.length % 7
  if (rem) for (let d = 1; d <= 7 - rem; d++)
    days.push({ date: new Date(year, month + 1, d), cur: false })
  return days
})

const years = computed(() =>
  Array.from({ length: 12 }, (_, i) => yearRangeStart.value + i)
)

// ─── Cell predicates ──────────────────────────────────────────────────────────

const isDisabled  = (d: Date) => !!(minDate.value && d < minDate.value)
const isToday     = (d: Date) => d.getTime() === today.getTime()
const isSel       = (d: Date) => selected.value ? d.getTime() === selected.value.getTime() : false
const isWeekend   = (d: Date) => d.getDay() === 0 || d.getDay() === 6

const isSelMonth  = (i: number) =>
  !!selected.value &&
  selected.value.getFullYear() === cursor.value.getFullYear() &&
  selected.value.getMonth() === i

const isSelYear   = (y: number) =>
  !!selected.value && selected.value.getFullYear() === y

const isCurMonth  = (i: number) =>
  today.getFullYear() === cursor.value.getFullYear() && today.getMonth() === i

const isCurYear   = (y: number) => today.getFullYear() === y

// ─── Smart positioning via Teleport ──────────────────────────────────────────

const POPUP_H = 340
const POPUP_W = 268
const MARGIN  = 8

function computeAnchor() {
  const rect = root.value?.getBoundingClientRect()
  if (!rect) return

  const spaceBelow = window.innerHeight - rect.bottom
  openUp.value = spaceBelow < POPUP_H + 8

  let left = rect.left
  if (left + POPUP_W + MARGIN > window.innerWidth) left = window.innerWidth - POPUP_W - MARGIN
  if (left < MARGIN) left = MARGIN

  anchorStyle.value = openUp.value
    ? { position: 'fixed', bottom: `${window.innerHeight - rect.top + 6}px`, left: `${left}px` }
    : { position: 'fixed', top: `${rect.bottom + 6}px`, left: `${left}px` }
}

// ─── Navigation ──────────────────────────────────────────────────────────────

function prev() {
  if (viewMode.value === 'days')
    cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() - 1, 1)
  else if (viewMode.value === 'months')
    cursor.value = new Date(cursor.value.getFullYear() - 1, cursor.value.getMonth(), 1)
  else
    cursor.value = new Date(cursor.value.getFullYear() - 12, cursor.value.getMonth(), 1)
}

function next() {
  if (viewMode.value === 'days')
    cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + 1, 1)
  else if (viewMode.value === 'months')
    cursor.value = new Date(cursor.value.getFullYear() + 1, cursor.value.getMonth(), 1)
  else
    cursor.value = new Date(cursor.value.getFullYear() + 12, cursor.value.getMonth(), 1)
}

// Клик на заголовок — идём уровень вверх (дни → месяцы → годы)
function drillUp() {
  if (viewMode.value === 'days')   viewMode.value = 'months'
  else if (viewMode.value === 'months') viewMode.value = 'years'
  // в режиме years — дальше некуда, ничего не делаем
}

function selectMonth(i: number) {
  cursor.value = new Date(cursor.value.getFullYear(), i, 1)
  viewMode.value = 'days'
}

function selectYear(y: number) {
  cursor.value = new Date(y, cursor.value.getMonth(), 1)
  viewMode.value = 'months'
}

// ─── Actions ─────────────────────────────────────────────────────────────────

function selectDay(d: Date) {
  if (isDisabled(d)) return
  const ymd = toYMD(d)
  emit('update:modelValue', ymd)
  emit('change', ymd)
  open.value = false
}

function clearDate() {
  emit('update:modelValue', '')
  emit('change', '')
}

function toggle() {
  if (props.disabled) return
  if (!open.value) {
    computeAnchor()
    viewMode.value = 'days'
  }
  open.value = !open.value
}

// ─── Close on outside click ───────────────────────────────────────────────────

const root     = ref<HTMLElement | null>(null)
const popupRef = ref<HTMLElement | null>(null)

function onOutside(e: MouseEvent) {
  const inRoot  = root.value?.contains(e.target as Node)
  const inPopup = popupRef.value?.contains(e.target as Node)
  if (!inRoot && !inPopup) open.value = false
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') open.value = false
}

function onScroll() { if (open.value) computeAnchor() }

onMounted(() => {
  document.addEventListener('mousedown', onOutside)
  document.addEventListener('keydown', onKeydown)
  window.addEventListener('scroll', onScroll, true)
  window.addEventListener('resize', onScroll)
})
onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onOutside)
  document.removeEventListener('keydown', onKeydown)
  window.removeEventListener('scroll', onScroll, true)
  window.removeEventListener('resize', onScroll)
})

// ─── Programmatic open (for external anchor elements) ────────────────────────

defineExpose({
  openAt(anchorEl: HTMLElement) {
    if (props.disabled) return
    const rect = anchorEl.getBoundingClientRect()
    const spaceBelow = window.innerHeight - rect.bottom
    openUp.value = spaceBelow < POPUP_H + 8
    let left = rect.left
    if (left + POPUP_W + MARGIN > window.innerWidth) left = window.innerWidth - POPUP_W - MARGIN
    if (left < MARGIN) left = MARGIN
    anchorStyle.value = openUp.value
      ? { position: 'fixed', bottom: `${window.innerHeight - rect.top + 6}px`, left: `${left}px` }
      : { position: 'fixed', top: `${rect.bottom + 6}px`, left: `${left}px` }
    viewMode.value = 'days'
    open.value = true
  },
})
</script>

<template>
  <div ref="root" class="relative">

    <!-- ─── Trigger ─────────────────────────────────────────────────────────── -->
    <button
      type="button"
      :disabled="disabled"
      @click="toggle"
      :class="[
        'input flex items-center gap-2 w-full h-10 text-xs select-none overflow-hidden',
        disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer',
        open ? '!ring-2 !ring-primary-500 !border-primary-500' : '',
      ]"
    >
      <svg class="w-4 h-4 flex-shrink-0 text-gray-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6">
        <rect x="2" y="3.5" width="16" height="15" rx="2"/>
        <path d="M6 2v3M14 2v3M2 8h16"/>
      </svg>

      <span class="flex-1 truncate text-left" :class="displayValue ? 'text-gray-900 dark:text-white' : 'text-gray-400 dark:text-gray-500'">
        {{ displayValue || placeholder }}
      </span>

      <span
        v-if="modelValue"
        role="button"
        @click.stop="clearDate"
        class="flex-shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors rounded cursor-pointer"
      >
        <svg class="w-3.5 h-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" d="M4 4l8 8M12 4l-8 8"/>
        </svg>
      </span>
    </button>

    <!-- ─── Popup via Teleport ──────────────────────────────────────────────── -->
    <Teleport to="body">
      <Transition
        :enter-active-class="'transition duration-[160ms] ease-[cubic-bezier(.2,.8,.4,1)]'"
        :enter-from-class="openUp ? 'opacity-0 translate-y-2 scale-[.97]' : 'opacity-0 -translate-y-2 scale-[.97]'"
        enter-to-class="opacity-100 translate-y-0 scale-100"
        :leave-active-class="'transition duration-[100ms] ease-in'"
        leave-from-class="opacity-100 translate-y-0 scale-100"
        :leave-to-class="openUp ? 'opacity-0 translate-y-2 scale-[.97]' : 'opacity-0 -translate-y-2 scale-[.97]'"
      >
        <div
          v-if="open"
          ref="popupRef"
          :style="anchorStyle"
          :class="[
            'z-[9999] w-[268px]',
            'bg-white dark:bg-gray-900',
            'border border-gray-200 dark:border-gray-700',
            'rounded-2xl shadow-2xl shadow-black/10 dark:shadow-black/50',
            'p-3 select-none',
            openUp ? 'origin-bottom-left' : 'origin-top-left',
          ]"
        >

          <!-- ─── Shared header: стрелки + кликабельный заголовок ──────────── -->
          <div class="flex items-center mb-3 gap-1">
            <button
              type="button" @click="prev"
              class="w-8 h-8 flex items-center justify-center rounded-lg
                     text-gray-400 hover:text-gray-700 dark:hover:text-gray-200
                     hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
            >
              <svg class="w-4 h-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 12L6 8l4-4"/>
              </svg>
            </button>

            <!-- Клик → переход на уровень выше (дни→месяцы→годы) -->
            <button
              type="button"
              @click="drillUp"
              :class="[
                'flex-1 text-center text-sm font-semibold rounded-lg py-1 transition-colors text-gray-900 dark:text-white',
                viewMode !== 'years'
                  ? 'hover:bg-gray-100 dark:hover:bg-gray-800 cursor-pointer'
                  : 'cursor-default',
              ]"
            >
              {{ headerLabel }}
            </button>

            <button
              type="button" @click="next"
              class="w-8 h-8 flex items-center justify-center rounded-lg
                     text-gray-400 hover:text-gray-700 dark:hover:text-gray-200
                     hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
            >
              <svg class="w-4 h-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 4l4 4-4 4"/>
              </svg>
            </button>
          </div>

          <!-- ─── Режим: Дни ─────────────────────────────────────────────── -->
          <template v-if="viewMode === 'days'">
            <div class="grid grid-cols-7 mb-0.5">
              <div
                v-for="(d, i) in DOW" :key="d"
                :class="['text-center text-[11px] font-semibold py-1 tracking-wide',
                  i >= 5 ? 'text-red-400' : 'text-gray-400 dark:text-gray-600']"
              >{{ d }}</div>
            </div>

            <div class="grid grid-cols-7 gap-y-0.5">
              <div
                v-for="(cell, i) in cells" :key="i"
                class="flex items-center justify-center"
              >
                <button
                  type="button"
                  :disabled="isDisabled(cell.date)"
                  @click="selectDay(cell.date)"
                  :class="[
                    'flex items-center justify-center rounded-full text-[13px] font-medium w-7 h-7 transition-all duration-100',
                    !cell.cur ? 'text-gray-300 dark:text-gray-700' : '',
                    isDisabled(cell.date) ? 'opacity-30 cursor-not-allowed' : 'cursor-pointer',
                    isSel(cell.date)
                      ? 'bg-primary-600 text-white'
                      : isToday(cell.date)
                        ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400 font-bold'
                        : cell.cur && isWeekend(cell.date) && !isDisabled(cell.date)
                          ? 'text-rose-500 dark:text-rose-400 hover:bg-gray-100 dark:hover:bg-gray-800'
                          : !isDisabled(cell.date)
                            ? 'hover:bg-gray-100 dark:hover:bg-gray-800'
                            : '',
                  ]"
                >
                  {{ cell.date.getDate() }}
                </button>
              </div>
            </div>

            <div class="mt-2 pt-2 border-t border-gray-100 dark:border-gray-800 flex items-center justify-between">
              <button
                type="button"
                v-if="modelValue" @click="clearDate"
                class="text-xs font-medium text-rose-500 hover:text-rose-600 dark:text-rose-400 transition-colors px-1"
              >Удалить</button>
              <span v-else class="text-xs text-transparent">—</span>

              <button
                type="button"
                @click="selectDay(today)"
                :disabled="isDisabled(today)"
                class="text-xs font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400 transition-colors px-1
                       disabled:opacity-30 disabled:cursor-not-allowed"
              >Сегодня</button>
            </div>
          </template>

          <!-- ─── Режим: Месяцы ──────────────────────────────────────────── -->
          <template v-else-if="viewMode === 'months'">
            <div class="grid grid-cols-3 gap-1">
              <button
                v-for="(_, i) in MONTH_FULL" :key="i"
                type="button"
                @click="selectMonth(i)"
                :class="[
                  'rounded-xl py-2.5 text-sm font-medium transition-colors',
                  isSelMonth(i)
                    ? 'bg-primary-600 text-white'
                    : isCurMonth(i)
                      ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400 font-bold'
                      : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 cursor-pointer',
                ]"
              >
                {{ SHORT_MONTHS[i] }}
              </button>
            </div>
          </template>

          <!-- ─── Режим: Годы ────────────────────────────────────────────── -->
          <template v-else>
            <div class="grid grid-cols-4 gap-1">
              <button
                v-for="y in years" :key="y"
                type="button"
                @click="selectYear(y)"
                :class="[
                  'rounded-xl py-2.5 text-sm font-medium transition-colors',
                  isSelYear(y)
                    ? 'bg-primary-600 text-white'
                    : isCurYear(y)
                      ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400 font-bold'
                      : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 cursor-pointer',
                ]"
              >
                {{ y }}
              </button>
            </div>
          </template>

        </div>
      </Transition>
    </Teleport>
  </div>
</template>
