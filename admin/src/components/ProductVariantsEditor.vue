<script setup lang="ts">
/**
 * Опции (≤3 оси: Размер, Цвет…) + матрица вариантов товара. v-model:options и
 * v-model:variants. Матрица — декартово произведение значений опций; строка
 * «продаётся» решает, попадёт ли комбинация в variants[] (бэкенд синхронит по
 * кортежу option_values — см. ProductController::syncOptionsAndVariants).
 */
import { ref, computed, watch } from 'vue'
import type { ProductOption, ProductVariant } from '@/types'

const props = defineProps<{
  options: ProductOption[]
  variants: ProductVariant[]
}>()
const emit = defineEmits<{
  'update:options': [v: ProductOption[]]
  'update:variants': [v: ProductVariant[]]
}>()

const SEP = String.fromCharCode(31)
const key = (vals: string[]) => vals.join(SEP)

// ── Опции ────────────────────────────────────────────────────
const valueDraft = ref<Record<number, string>>({})

function emitOptions(next: ProductOption[]) {
  emit('update:options', next)
}
function addOption() {
  if (props.options.length >= 3) return
  emitOptions([...props.options, { name: '', values: [] }])
}
function removeOption(i: number) {
  emitOptions(props.options.filter((_, idx) => idx !== i))
}
function setOptionName(i: number, name: string) {
  emitOptions(props.options.map((o, idx) => (idx === i ? { ...o, name } : o)))
}
function addValue(i: number) {
  const raw = (valueDraft.value[i] ?? '').trim()
  if (!raw) return
  const parts = raw.split(',').map(s => s.trim()).filter(Boolean)
  const existing = props.options[i].values.map(v => v.value)
  const merged = [...existing]
  for (const p of parts) if (!merged.includes(p)) merged.push(p)
  emitOptions(props.options.map((o, idx) =>
    idx === i ? { ...o, values: merged.map(value => ({ value })) } : o))
  valueDraft.value[i] = ''
}
function removeValue(i: number, val: string) {
  emitOptions(props.options.map((o, idx) =>
    idx === i ? { ...o, values: o.values.filter(v => v.value !== val) } : o))
}

// ── Матрица ──────────────────────────────────────────────────
const usableOptions = computed(() =>
  props.options.filter(o => o.name.trim() && o.values.length > 0))

const combos = computed<string[][]>(() => {
  let acc: string[][] = [[]]
  for (const opt of usableOptions.value) {
    const next: string[][] = []
    for (const prefix of acc) for (const v of opt.values) next.push([...prefix, v.value])
    acc = next
  }
  return usableOptions.value.length ? acc : []
})

type Row = {
  enabled: boolean
  sku: string
  price: number | null
  stock_quantity: number
  allow_backorder: boolean
  image_url: string
  is_active: boolean
}
const rows = ref<Record<string, Row>>({})
let syncingOut = false // защита от петли emit → props → seed

/**
 * Пересобирает строки матрицы под текущий набор комбинаций. Данные ячеек
 * сохраняются между пересборками; из props.variants подхватываются только
 * НОВЫЕ ключи (первичная загрузка формы) — эхо собственного emit игнорируется.
 */
function seedRows() {
  if (syncingOut) return
  const fromProduct = new Map(props.variants.map(v => [key(v.option_values), v]))
  const next: Record<string, Row> = {}
  for (const combo of combos.value) {
    const k = key(combo)
    const prev = rows.value[k]
    if (prev) { next[k] = prev; continue }
    const v = fromProduct.get(k)
    next[k] = {
      enabled: true, // по умолчанию комбинация продаётся
      sku: v?.sku ?? '',
      price: v?.price ?? null,
      stock_quantity: v?.stock_quantity ?? 0,
      allow_backorder: v?.allow_backorder ?? false,
      image_url: v?.image_url ?? '',
      is_active: v?.is_active ?? true,
    }
  }
  rows.value = next
}

// flush:'sync' — строки матрицы существуют ДО того, как шаблон их отрисует
// (иначе tick без rows[k] → обращение к .enabled у undefined).
watch(combos, seedRows, { immediate: true, deep: true, flush: 'sync' })
// Первичная подгрузка вариантов существующего товара (грузятся асинхронно
// после mount) — только если строк ещё нет.
watch(() => props.variants, () => {
  if (!syncingOut && Object.keys(rows.value).length === 0) seedRows()
}, { deep: false })

function emitVariants() {
  const out: ProductVariant[] = []
  for (const combo of combos.value) {
    const r = rows.value[key(combo)]
    if (!r || !r.enabled) continue
    out.push({
      option_values: combo,
      sku: r.sku.trim() || null,
      price: r.price != null && !Number.isNaN(r.price) ? Math.round(r.price) : null,
      stock_quantity: Math.max(0, Math.round(r.stock_quantity || 0)),
      allow_backorder: r.allow_backorder,
      image_url: r.image_url.trim() || null,
      is_active: r.is_active,
    })
  }
  syncingOut = true
  emit('update:variants', out)
  // сбросить флаг после того, как родитель прогонит реактивность
  Promise.resolve().then(() => { syncingOut = false })
}
watch([rows, combos], emitVariants, { deep: true })

// ── Массовое заполнение ──────────────────────────────────────
const bulkPrice = ref<number | null>(null)
const bulkStock = ref<number | null>(null)
function applyBulk() {
  for (const combo of combos.value) {
    const r = rows.value[key(combo)]
    if (!r || !r.enabled) continue
    if (bulkPrice.value != null) r.price = bulkPrice.value
    if (bulkStock.value != null) r.stock_quantity = bulkStock.value
  }
}

const colHeaders = computed(() => usableOptions.value.map(o => o.name.trim() || '—'))
</script>

<template>
  <div class="flex flex-col gap-4">
    <!-- Опции -->
    <div class="flex flex-col gap-3">
      <div
        v-for="(opt, i) in options"
        :key="i"
        class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 flex flex-col gap-2"
      >
        <div class="flex items-center gap-2">
          <input
            :value="opt.name"
            @input="setOptionName(i, ($event.target as HTMLInputElement).value)"
            type="text"
            class="input flex-1"
            placeholder="Название оси: Размер, Цвет…"
          />
          <button type="button" class="text-gray-400 hover:text-red-500 px-2" @click="removeOption(i)" title="Удалить ось">✕</button>
        </div>
        <div class="flex flex-wrap gap-1.5 items-center">
          <span
            v-for="v in opt.values"
            :key="v.value"
            class="inline-flex items-center gap-1 text-xs bg-gray-100 dark:bg-gray-700 rounded px-2 py-1"
          >
            {{ v.value }}
            <button type="button" class="text-gray-400 hover:text-red-500" @click="removeValue(i, v.value)">✕</button>
          </span>
          <input
            v-model="valueDraft[i]"
            @keydown.enter.prevent="addValue(i)"
            @blur="addValue(i)"
            type="text"
            class="input !py-1 !px-2 text-xs w-40"
            placeholder="значение + Enter"
          />
        </div>
      </div>
      <button
        type="button"
        class="self-start text-sm font-medium text-primary-600 dark:text-primary-400 disabled:opacity-40"
        :disabled="options.length >= 3"
        @click="addOption"
      >+ Добавить ось (размер / цвет)</button>
    </div>

    <!-- Матрица -->
    <div v-if="combos.length" class="flex flex-col gap-2">
      <div class="flex flex-wrap items-end gap-2">
        <div>
          <p class="label">Цена всем, ₽</p>
          <input v-model.number="bulkPrice" type="number" min="0" class="input !py-1 w-28 text-sm" placeholder="—" />
        </div>
        <div>
          <p class="label">Остаток всем</p>
          <input v-model.number="bulkStock" type="number" min="0" class="input !py-1 w-28 text-sm" placeholder="—" />
        </div>
        <button type="button" class="btn-ghost text-sm px-3 py-1.5" @click="applyBulk">Заполнить</button>
      </div>

      <div class="overflow-x-auto">
        <table class="text-sm border-collapse w-full">
          <thead>
            <tr class="text-left text-gray-500 dark:text-gray-400">
              <th class="p-1 font-medium">Прод.</th>
              <th v-for="h in colHeaders" :key="h" class="p-1 font-medium">{{ h }}</th>
              <th class="p-1 font-medium">SKU</th>
              <th class="p-1 font-medium">Цена&nbsp;₽</th>
              <th class="p-1 font-medium">Остаток</th>
              <th class="p-1 font-medium">Фото URL</th>
              <th class="p-1 font-medium">Активен</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="combo in combos" :key="combo.join('|')" class="border-t border-gray-100 dark:border-gray-800">
              <td class="p-1"><input type="checkbox" v-model="rows[combo.join(SEP)].enabled" /></td>
              <td v-for="(cell, ci) in combo" :key="ci" class="p-1 whitespace-nowrap">{{ cell }}</td>
              <td class="p-1"><input v-model="rows[combo.join(SEP)].sku" :disabled="!rows[combo.join(SEP)].enabled" type="text" class="input !py-1 !px-2 text-xs w-28" /></td>
              <td class="p-1"><input v-model.number="rows[combo.join(SEP)].price" :disabled="!rows[combo.join(SEP)].enabled" type="number" min="0" class="input !py-1 !px-2 text-xs w-24" placeholder="как товар" /></td>
              <td class="p-1"><input v-model.number="rows[combo.join(SEP)].stock_quantity" :disabled="!rows[combo.join(SEP)].enabled" type="number" min="0" class="input !py-1 !px-2 text-xs w-20" /></td>
              <td class="p-1"><input v-model="rows[combo.join(SEP)].image_url" :disabled="!rows[combo.join(SEP)].enabled" type="text" class="input !py-1 !px-2 text-xs w-40" placeholder="необязательно" /></td>
              <td class="p-1"><input type="checkbox" v-model="rows[combo.join(SEP)].is_active" :disabled="!rows[combo.join(SEP)].enabled" /></td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="text-xs text-gray-400">
        Пустая цена = как у товара. Снятая галочка «Прод.» — комбинации не будет.
        «Активен» — временно скрыть (напр. закончился и не завозят).
      </p>
    </div>
    <p v-else class="text-sm text-gray-400">
      Добавьте оси и значения — появится таблица комбинаций.
    </p>
  </div>
</template>
