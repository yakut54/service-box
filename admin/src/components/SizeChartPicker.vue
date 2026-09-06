<script setup lang="ts">
/**
 * Выбор / создание / правка размерной сетки товара. v-model — id сетки
 * (string | null). Сетки уровня магазина, переиспользуются многими товарами;
 * стартовые пресеты RU/EU/US — из shared/lib/sizeChartPresets, дальше шопер
 * правит таблицу под свой товар.
 */
import { ref, computed, onMounted } from 'vue'
import { api } from '@/lib/api'
import { parseApiError } from '@/lib/parseApiError'
import { SIZE_CHART_PRESETS } from '@/shared/lib/sizeChartPresets'
import type { SizeChart, SizeChartKind } from '@/types'

const props = defineProps<{ modelValue: string | null }>()
const emit = defineEmits<{ 'update:modelValue': [id: string | null] }>()

const charts = ref<SizeChart[]>([])
const loading = ref(false)
const error = ref('')
const busy = ref(false)

type Draft = { id: string | null; name: string; kind: SizeChartKind; columns: string[]; rows: string[][] }
const draft = ref<Draft | null>(null)

const selected = computed(() => charts.value.find(c => c.id === props.modelValue) ?? null)

async function loadCharts() {
  loading.value = true
  error.value = ''
  try {
    charts.value = (await api.getSizeCharts()).data
  } catch (e) {
    error.value = parseApiError(e, 'Не удалось загрузить размерные сетки')
  } finally {
    loading.value = false
  }
}
onMounted(loadCharts)

function onSelect(id: string) {
  emit('update:modelValue', id || null)
}

function startCreate() {
  draft.value = { id: null, name: '', kind: 'clothing', columns: ['Размер', ''], rows: [['', '']] }
}

function startEdit() {
  if (!selected.value) return
  const c = selected.value
  draft.value = {
    id: c.id,
    name: c.name,
    kind: c.kind,
    columns: [...c.columns],
    rows: c.rows.map(r => [...r]),
  }
}

function applyPreset(presetId: string) {
  const p = SIZE_CHART_PRESETS.find(x => x.id === presetId)
  if (!p || !draft.value) return
  draft.value.kind = p.kind
  draft.value.columns = [...p.columns]
  draft.value.rows = p.rows.map(r => [...r])
  if (!draft.value.name) draft.value.name = p.label
}

function addColumn() {
  if (!draft.value || draft.value.columns.length >= 12) return
  draft.value.columns.push('')
  draft.value.rows.forEach(r => r.push(''))
}
function removeColumn(i: number) {
  if (!draft.value || draft.value.columns.length <= 1) return
  draft.value.columns.splice(i, 1)
  draft.value.rows.forEach(r => r.splice(i, 1))
}
function addRow() {
  if (!draft.value || draft.value.rows.length >= 60) return
  draft.value.rows.push(draft.value.columns.map(() => ''))
}
function removeRow(i: number) {
  draft.value?.rows.splice(i, 1)
}

async function saveDraft() {
  const d = draft.value
  if (!d) return
  if (!d.name.trim()) { error.value = 'Укажите название сетки'; return }
  if (d.columns.some(c => !c.trim())) { error.value = 'Заполните заголовки всех столбцов'; return }

  busy.value = true
  error.value = ''
  const payload = {
    kind: d.kind,
    name: d.name.trim(),
    columns: d.columns.map(c => c.trim()),
    rows: d.rows,
  }
  try {
    const saved = d.id
      ? (await api.updateSizeChart(d.id, payload)).data
      : (await api.createSizeChart(payload)).data
    await loadCharts()
    emit('update:modelValue', saved.id)
    draft.value = null
  } catch (e) {
    error.value = parseApiError(e, 'Не удалось сохранить сетку')
  } finally {
    busy.value = false
  }
}

async function removeChart() {
  if (!selected.value) return
  if (!confirm(`Удалить размерную сетку «${selected.value.name}»? Товары, где она указана, останутся без сетки.`)) return
  busy.value = true
  try {
    await api.deleteSizeChart(selected.value.id)
    emit('update:modelValue', null)
    await loadCharts()
  } catch (e) {
    error.value = parseApiError(e, 'Не удалось удалить сетку')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <p v-if="error" class="text-xs text-red-500">{{ error }}</p>

    <!-- Выбор существующей -->
    <div v-if="!draft" class="flex flex-wrap items-center gap-2">
      <select
        :value="modelValue ?? ''"
        @change="onSelect(($event.target as HTMLSelectElement).value)"
        class="input flex-1 min-w-[180px]"
        :disabled="loading"
      >
        <option value="">— без размерной сетки —</option>
        <option v-for="c in charts" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
      <button type="button" class="btn-ghost text-sm px-3 py-2" @click="startEdit" :disabled="!selected">Изменить</button>
      <button type="button" class="btn-ghost text-sm px-3 py-2 text-red-500" @click="removeChart" :disabled="!selected || busy">Удалить</button>
      <button type="button" class="btn-secondary text-sm px-3 py-2" @click="startCreate">Создать новую</button>
    </div>

    <!-- Редактор -->
    <div v-else class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 flex flex-col gap-3">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <p class="label">Название сетки</p>
          <input v-model="draft.name" type="text" class="input" placeholder="Например: Женские платья" />
        </div>
        <div>
          <p class="label">Тип</p>
          <select v-model="draft.kind" class="input">
            <option value="clothing">Одежда</option>
            <option value="shoes">Обувь</option>
            <option value="custom">Другое</option>
          </select>
        </div>
      </div>

      <div v-if="!draft.id">
        <p class="label">Начать с шаблона</p>
        <select class="input" @change="applyPreset(($event.target as HTMLSelectElement).value); ($event.target as HTMLSelectElement).value = ''">
          <option value="">— выбрать шаблон —</option>
          <option v-for="p in SIZE_CHART_PRESETS" :key="p.id" :value="p.id">{{ p.label }}</option>
        </select>
      </div>

      <!-- Таблица -->
      <div class="overflow-x-auto">
        <table class="text-sm border-collapse">
          <thead>
            <tr>
              <th v-for="(_, ci) in draft.columns" :key="ci" class="p-1 align-bottom">
                <div class="flex items-center gap-1">
                  <input v-model="draft.columns[ci]" type="text" class="input !py-1 !px-2 text-xs w-32" placeholder="Заголовок" />
                  <button type="button" class="text-gray-400 hover:text-red-500 text-xs" @click="removeColumn(ci)" :disabled="draft.columns.length <= 1" title="Удалить столбец">✕</button>
                </div>
              </th>
              <th class="p-1 align-bottom">
                <button type="button" class="btn-ghost text-xs px-2 py-1" @click="addColumn" :disabled="draft.columns.length >= 12">+ столбец</button>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(_row, ri) in draft.rows" :key="ri">
              <td v-for="(_col, ci) in draft.columns" :key="ci" class="p-1">
                <input v-model="draft.rows[ri][ci]" type="text" class="input !py-1 !px-2 text-xs w-32" />
              </td>
              <td class="p-1">
                <button type="button" class="text-gray-400 hover:text-red-500 text-xs" @click="removeRow(ri)" title="Удалить строку">✕</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <button type="button" class="btn-ghost text-xs px-2 py-1 self-start" @click="addRow" :disabled="draft.rows.length >= 60">+ строка</button>

      <div class="flex gap-2 pt-1">
        <button type="button" class="btn-secondary text-sm flex-1" @click="draft = null">Отмена</button>
        <button type="button" class="btn-primary text-sm flex-1" @click="saveDraft" :disabled="busy">Сохранить сетку</button>
      </div>
    </div>
  </div>
</template>
