<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/lib/api'
import CustomSelect from '@/components/CustomSelect.vue'
import CategorySelect from '@/components/CategorySelect.vue'
import DatePicker from '@/components/DatePicker.vue'
import UiModal from '@/shared/ui/UiModal.vue'
import UiHint from '@/shared/ui/UiHint.vue'
import type { Discount, DiscountType, DiscountScope } from '@/types'

const props = defineProps<{
  modelValue: boolean
  discount: Discount | null
  products: { id: string; name: string }[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  'saved': [discount: Discount, mode: 'create' | 'edit']
}>()

const mode    = computed(() => props.discount ? 'edit' : 'create')
const saving  = ref(false)
const error   = ref('')

const scopeOptions = [
  { value: 'cart',     label: 'Всей корзине' },
  { value: 'product',  label: 'Конкретному товару' },
  { value: 'category', label: 'Категории товаров' },
]

const productOptions = computed(() =>
  props.products.map(p => ({ value: p.id, label: p.name }))
)

const emptyForm = () => ({
  name: '', type: 'percent' as DiscountType, value: 10,
  code: '', scope: 'cart' as DiscountScope, scope_value: '',
  min_order_amount: 0, max_discount_amount: '', usage_limit: '',
  per_user_limit: 1, priority: 0, is_active: true, starts_at: '', ends_at: '',
})

const form = ref(emptyForm())

watch(() => props.modelValue, (open) => {
  if (!open) return
  error.value = ''
  if (props.discount) {
    const d = props.discount
    form.value = {
      name:                d.name,
      type:                d.type,
      value:               d.type === 'fixed' ? d.value / 100 : d.value,
      code:                d.code               || '',
      scope:               d.scope,
      scope_value:         d.scope_value         || '',
      min_order_amount:    d.min_order_amount / 100,
      max_discount_amount: d.max_discount_amount !== null ? String(d.max_discount_amount / 100) : '',
      usage_limit:         d.usage_limit !== null ? String(d.usage_limit) : '',
      per_user_limit:      d.per_user_limit,
      priority:            d.priority ?? 0,
      is_active:           d.is_active,
      starts_at:           d.starts_at ? d.starts_at.slice(0, 10) : '',
      ends_at:             d.ends_at   ? d.ends_at.slice(0, 10)   : '',
    }
  } else {
    form.value = emptyForm()
  }
})

function buildPayload() {
  const f = form.value
  return {
    name:               f.name.trim(),
    type:               f.type,
    value:              f.type === 'fixed' ? Math.round(Number(f.value) * 100) : Math.round(Number(f.value)),
    code:               f.code.trim().toUpperCase() || null,
    scope:              f.scope,
    scope_value:        f.scope_value.trim() || null,
    min_order_amount:   Math.round(Number(f.min_order_amount) * 100),
    max_discount_amount: f.max_discount_amount !== '' ? Math.round(Number(f.max_discount_amount) * 100) : null,
    usage_limit:        f.usage_limit !== '' ? Number(f.usage_limit) : null,
    per_user_limit:     Number(f.per_user_limit),
    priority:           Number(f.priority),
    is_active:          f.is_active,
    starts_at:          f.starts_at || null,
    ends_at:            f.ends_at   || null,
  }
}

async function save() {
  if (!form.value.name.trim()) { error.value = 'Укажите название'; return }
  if (!form.value.value || Number(form.value.value) < 1) { error.value = 'Укажите значение скидки'; return }
  if (form.value.type === 'percent' && Number(form.value.value) > 100) { error.value = 'Процент не может превышать 100'; return }

  saving.value = true
  error.value  = ''
  try {
    const payload = buildPayload()
    let saved: Discount
    if (mode.value === 'create') {
      const res = await api.createDiscount(payload)
      saved = res.data
    } else {
      const res = await api.updateDiscount(props.discount!.id, payload)
      saved = res.data
    }
    emit('saved', saved, mode.value)
    emit('update:modelValue', false)
  } catch (e: any) {
    error.value = e.message || 'Ошибка сохранения'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <UiModal :modelValue="modelValue" @update:modelValue="emit('update:modelValue', $event)">
    <div class="flex items-center justify-between p-4 border-b border-gray-100 dark:border-gray-700 sticky top-0 bg-white dark:bg-gray-800 z-10">
      <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
        {{ mode === 'create' ? 'Новая скидка' : 'Редактировать скидку' }}
      </h2>
      <button @click="emit('update:modelValue', false)" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <div class="p-4 space-y-4">
      <div v-if="error" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">{{ error }}</div>

      <div>
        <p class="label">Название <span class="text-red-500">*</span></p>
        <input v-model="form.name" type="text" class="input" placeholder="Летняя акция, Скидка на первый заказ..." />
      </div>

      <div>
        <p class="label">Тип скидки <span class="text-red-500">*</span></p>
        <div class="flex rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
          <button type="button" :class="['flex-1 py-2 text-sm font-medium transition-colors', form.type === 'percent' ? 'bg-primary-600 text-white' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700']" @click="form.type = 'percent'">Процент (%)</button>
          <button type="button" :class="['flex-1 py-2 text-sm font-medium transition-colors', form.type === 'fixed' ? 'bg-primary-600 text-white' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700']" @click="form.type = 'fixed'">Фиксированная (₽)</button>
        </div>
      </div>

      <div>
        <p class="label flex items-center gap-1">
          Размер скидки <span class="text-red-500">*</span>
          <UiHint v-if="form.type === 'percent'">От 1 до 100%</UiHint>
        </p>
        <div class="relative">
          <input v-model.number="form.value" type="number" :min="1" :max="form.type === 'percent' ? 100 : undefined" class="input pr-12" :placeholder="form.type === 'percent' ? '10' : '500'" />
          <span class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm font-medium">{{ form.type === 'percent' ? '%' : '₽' }}</span>
        </div>
      </div>

      <div>
        <p class="label flex items-center gap-1">
          Промокод
          <UiHint>{{ form.code.trim() ? 'Клиент вводит код вручную при оформлении заказа' : 'Скидка применится автоматически при выполнении условий' }}</UiHint>
        </p>
        <input v-model="form.code" type="text" class="input font-mono uppercase" placeholder="SUMMER20 (оставьте пустым — авто-применение)" maxlength="50" />
      </div>

      <div>
        <p class="label">Применять к</p>
        <CustomSelect v-model="form.scope" :options="scopeOptions" />
      </div>

      <div v-if="form.scope === 'product'">
        <p class="label">Товар</p>
        <CustomSelect v-model="form.scope_value" :options="productOptions" placeholder="Выберите товар..." searchable>
          <template #footer="{ close }">
            <RouterLink to="/products/new" target="_blank" @click="close()"
              class="flex items-center gap-2 px-4 py-2.5 text-sm text-primary-600 dark:text-primary-400 hover:bg-gray-50 dark:hover:bg-gray-700 w-full">
              <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
              Добавить товар
            </RouterLink>
          </template>
        </CustomSelect>
        <p v-if="productOptions.length === 0" class="text-xs text-gray-400 mt-1">Нет доступных товаров</p>
      </div>
      <div v-else-if="form.scope === 'category'">
        <p class="label">Категория</p>
        <CategorySelect v-model="form.scope_value" />
      </div>

      <div>
        <p class="label">Минимальная сумма заказа, ₽</p>
        <input v-model.number="form.min_order_amount" type="number" min="0" class="input" placeholder="0 (без ограничений)" />
      </div>

      <div>
        <p class="label flex items-center gap-1">
          Максимальная скидка, ₽
          <UiHint>Защита маржи: скидка не превысит это значение в ₽</UiHint>
        </p>
        <input v-model="form.max_discount_amount" type="number" min="1" class="input" placeholder="Оставьте пустым — без ограничения" />
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <p class="label">Лимит использований</p>
          <input v-model="form.usage_limit" type="number" min="1" class="input" placeholder="∞" />
        </div>
        <div>
          <p class="label flex items-center gap-1">
            Лимит на 1 клиента
            <UiHint>0 = без ограничений</UiHint>
          </p>
          <input v-model.number="form.per_user_limit" type="number" min="0" class="input" placeholder="1" />
        </div>
      </div>

      <div>
        <p class="label flex items-center gap-1">
          Приоритет
          <UiHint>Чем выше — тем важнее. При равной корзине побеждает скидка с бо́льшим приоритетом.</UiHint>
        </p>
        <input v-model.number="form.priority" type="number" min="0" class="input" placeholder="0" />
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <p class="label">Дата начала</p>
          <DatePicker v-model="form.starts_at" placeholder="Не задана" />
        </div>
        <div>
          <p class="label">Дата окончания</p>
          <DatePicker v-model="form.ends_at" :min="form.starts_at || undefined" placeholder="Не задана" />
        </div>
      </div>

      <div class="flex items-center justify-between py-2">
        <span class="label mb-0 flex items-center gap-1">
          Активна
          <UiHint>Скидка применяется при оформлении заказов</UiHint>
        </span>
        <button type="button" @click="form.is_active = !form.is_active"
          :class="['relative w-11 h-6 rounded-full transition-colors', form.is_active ? 'bg-primary-600' : 'bg-gray-300 dark:bg-gray-600']">
          <span :class="['absolute top-1 left-1 w-4 h-4 bg-white rounded-full shadow transition-transform', form.is_active ? 'translate-x-5' : '']" />
        </button>
      </div>
    </div>

    <div class="flex gap-3 p-4 pt-0">
      <button @click="emit('update:modelValue', false)" class="btn-secondary flex-1">Отмена</button>
      <button @click="save" class="btn-primary flex-1" :disabled="saving">
        {{ saving ? 'Сохранение...' : (mode === 'create' ? 'Создать' : 'Сохранить') }}
      </button>
    </div>
  </UiModal>
</template>
