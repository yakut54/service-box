<script setup lang="ts">
import { ref, onMounted, computed, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/lib/api'
import { parseApiError } from '@/lib/parseApiError'
import CategorySelect from '@/components/CategorySelect.vue'
import ImageUpload from '@/components/ImageUpload.vue'
import ProductImageGallery from '@/components/ProductImageGallery.vue'
import type { ProductImage } from '@/types'

const route = useRoute()
const router = useRouter()

const isEditing = computed(() => !!route.params.id)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const errorEl = ref<HTMLElement | null>(null)

const form = ref({
  name: '',
  description: '',
  price: 0,
  compare_price: null as number | null,
  type: 'physical' as string,
  category_id: '',
  is_active: true,
  image_url: '',
})

const physicalDetails = ref({
  sku: '',
  stock_quantity: 0,
  weight_grams: null as number | null,
  dimensions: '',
  color: '',
  brand: '',
  material: '',
})

const digitalDetails = ref({
  delivery_type: 'download',
  access_days: null as number | null, download_url: '',
  file_size_mb: null as number | null, file_format: '',
})

const serviceDetails = ref({
  duration_minutes: 60,
  max_concurrent: 1,
  break_minutes: 0,
  requires_prepayment: false,
})

// ── Type config ─────────────────────────────────────────────
const typeConfig = {
  physical: {
    icon: '📦', label: 'Физический', desc: 'Товар с доставкой',
    namePlaceholder: 'Ежедневник "Мои цели"',
    descPlaceholder: 'Недатированный ежедневник для планирования целей. 200 страниц, переплёт на кольцах.',
    pricePlaceholder: '890',
  },
  digital: {
    icon: '💾', label: 'Цифровой', desc: 'Файл, ссылка или код',
    namePlaceholder: 'Видеокурс "Основы дизайна"',
    descPlaceholder: '12 видеоуроков по основам графического дизайна. Доступ сразу после оплаты.',
    pricePlaceholder: '2490',
  },
  service: {
    icon: '🗓️', label: 'Услуга', desc: 'Запись по времени',
    namePlaceholder: 'Стрижка + укладка',
    descPlaceholder: 'Мужская стрижка любой сложности с мытьём головы и укладкой.',
    pricePlaceholder: '1500',
  },
}

const currentTypeConfig = computed(() => typeConfig[form.value.type as keyof typeof typeConfig] || typeConfig.physical)

// Цифровые товары и услуги пока скрыты из создания нового товара — только
// физический. Уже существующие digital/service товары остаются видимыми
// и редактируемыми как есть.
const pickableTypeConfig = computed(() =>
  isEditing.value ? typeConfig : { physical: typeConfig.physical }
)

// При редактировании необязательные поля показывают "не указано" вместо примеров
const ep = (fallback: string) => isEditing.value ? 'не указано' : fallback

// ── Image upload ─────────────────────────────────────────────
const uploading = ref(false)
const productImages = ref<ProductImage[]>([])

// ── Data loading ────────────────────────────────────────────
onMounted(async () => {
  if (isEditing.value) {
    loading.value = true
    try {
      const resp = await api.getProduct(route.params.id as string)
      const p = resp.data
      form.value = {
        name: p.name,
        description: p.description || '',
        price: p.price,
        compare_price: p.compare_price ?? null,
        type: p.type,
        category_id: p.category_id || '',
        is_active: p.is_active,
        image_url: p.image_url || ''
      }
      productImages.value = p.images || []
      if (p.physical) {
        physicalDetails.value = {
          sku: p.physical.sku || '',
          stock_quantity: p.physical.stock_quantity,
          weight_grams: p.physical.weight_grams,
          dimensions: p.physical.dimensions || '',
          color: p.physical.color || '',
          brand: p.physical.brand || '',
          material: p.physical.material || '',
        }
      }
      if (p.digital) {
        digitalDetails.value = {
          delivery_type: p.digital.delivery_type ?? 'download',
          access_days: p.digital.access_days,
          download_url: p.digital.download_url || '',
          file_size_mb: p.digital.file_size_mb ?? null,
          file_format: p.digital.file_format || '',
        }
      }
      if (p.service) {
        serviceDetails.value = {
          duration_minutes: p.service.duration_minutes ?? 60,
          max_concurrent: p.service.max_concurrent ?? 1,
          break_minutes: p.service.break_minutes ?? 0,
          requires_prepayment: p.service.requires_prepayment ?? false,
        }
      }
    } catch (e: unknown) {
      error.value = parseApiError(e, 'Не удалось загрузить товар')
    }
    loading.value = false
  }
})

async function handleSubmit() {
  if (!form.value.name.trim()) { error.value = 'Введите название'; return }
  if (form.value.price <= 0) { error.value = 'Введите цену'; return }

  saving.value = true
  error.value = ''

  const data: Record<string, any> = {
    name: form.value.name.trim(),
    description: form.value.description.trim() || null,
    price: Math.round(form.value.price),
    compare_price: form.value.compare_price ? Math.round(form.value.compare_price) : null,
    type: form.value.type,
    category_id: form.value.category_id || null,
    is_active: form.value.is_active,
    image_url: form.value.image_url.trim() || null,
  }

  if (form.value.type === 'physical') data.physical = physicalDetails.value
  if (form.value.type === 'digital') data.digital = digitalDetails.value
  if (form.value.type === 'service') data.service = serviceDetails.value

  try {
    if (isEditing.value) { await api.updateProduct(route.params.id as string, data) }
    else { await api.createProduct(data) }
    await router.push('/products')
  } catch (e: unknown) {
    error.value = parseApiError(e, 'Не удалось сохранить товар')
    nextTick(() => errorEl.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }))
  }
  saving.value = false
}
</script>

<template>
  <div class="max-w-2xl">
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ isEditing ? 'Редактировать товар' : 'Новый товар' }}</h1>
    </div>

    <div v-if="loading" class="card py-12 text-center">
      <div class="animate-spin w-8 h-8 border-4 border-primary-600 border-t-transparent rounded-full mx-auto"></div>
    </div>

    <form v-else @submit.prevent="handleSubmit" class="space-y-6">
      <div v-if="error" ref="errorEl" class="p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">{{ error }}</div>

      <!-- ══════════ ОСНОВНАЯ ИНФОРМАЦИЯ ══════════ -->
      <div class="card">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Основная информация</h2>
        <div class="space-y-4">
          <!-- Тип товара -->
          <div>
            <p class="label">Тип товара</p>
            <div class="grid grid-cols-3 gap-3">
              <button
                v-for="(cfg, key) in pickableTypeConfig"
                :key="key"
                type="button"
                @click="form.type = key"
                :class="['p-2 sm:p-4 rounded-lg border-2 text-center transition-all', form.type === key ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300 shadow-sm' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700']"
              >
                <div class="text-xl sm:text-2xl mb-1">{{ cfg.icon }}</div>
                <div class="text-xs sm:text-sm font-semibold">{{ cfg.label }}</div>
                <div class="text-[10px] sm:text-xs text-gray-500 dark:text-gray-400 mt-0.5 leading-tight">{{ cfg.desc }}</div>
              </button>
            </div>
          </div>

          <!-- Название -->
          <div>
            <label for="name" class="label">Название *</label>
            <input id="name" v-model="form.name" type="text" class="input" :placeholder="currentTypeConfig.namePlaceholder" />
          </div>

          <!-- Описание -->
          <div>
            <label for="description" class="label">Описание</label>
            <textarea id="description" v-model="form.description" class="input min-h-[100px]" :placeholder="ep(currentTypeConfig.descPlaceholder)" />
          </div>

          <!-- Цена -->
          <div class="grid grid-cols-2 gap-4 items-end">
            <div>
              <label for="price" class="label">Цена (руб) *</label>
              <div class="relative">
                <input id="price" v-model.number="form.price" type="number" min="0" step="1" class="input pr-12" :placeholder="currentTypeConfig.pricePlaceholder" />
                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 text-sm pointer-events-none">₽</span>
              </div>
            </div>
            <div>
              <label for="compare_price" class="label">
                <span class="text-gray-400 dark:text-gray-500 font-normal text-xs">для показа скидки</span><br>
                Старая цена (руб)
              </label>
              <div class="relative">
                <input id="compare_price" v-model.number="form.compare_price" type="number" min="0" step="1" class="input pr-12" placeholder="0" />
                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 text-sm pointer-events-none">₽</span>
              </div>
              <p v-if="form.compare_price && form.compare_price <= form.price" class="text-xs text-amber-600 dark:text-amber-400 mt-1">Старая цена должна быть больше текущей</p>
            </div>
          </div>

          <!-- Категория -->
          <div>
            <p class="label">Категория</p>
            <CategorySelect v-model="form.category_id" nullable />
          </div>

          <!-- Изображение -->
          <div>
            <p class="label">Изображение</p>
            <ImageUpload
              v-model="form.image_url"
              v-model:uploading="uploading"
              size="lg"
              :withUrlInput="true"
              confirmText="Фото товара будет удалено. Это действие нельзя отменить."
            />
          </div>

          <!-- Галерея доп. фото — только у уже сохранённого товара, -->
          <!-- т.к. для прикрепления фото нужен его id -->
          <div v-if="isEditing">
            <ProductImageGallery
              :product-id="route.params.id as string"
              v-model:images="productImages"
            />
          </div>
          <p v-else class="text-xs text-gray-400 dark:text-gray-500">
            Дополнительные фото галереи можно будет добавить после сохранения товара
          </p>

          <!-- Активность -->
          <label class="flex items-center gap-3 cursor-pointer select-none">
            <div class="relative">
              <input type="checkbox" v-model="form.is_active" class="sr-only peer" />
              <div class="w-11 h-6 bg-gray-200 peer-checked:bg-primary-600 rounded-full transition-colors"></div>
              <div class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></div>
            </div>
            <span class="text-gray-700 dark:text-gray-300">Показывать в каталоге</span>
          </label>
        </div>
      </div>

      <!-- ══════════ ФИЗИЧЕСКИЙ ТОВАР ══════════ -->
      <div v-if="form.type === 'physical'" class="card">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Параметры физического товара</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Склад, габариты и характеристики</p>
        <div class="space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <p class="label">Артикул (SKU)</p>
              <input v-model="physicalDetails.sku" type="text" class="input" :placeholder="ep('NOTE-GOALS-001')" />
            </div>
            <div>
              <p class="label">Кол-во на складе</p>
              <input v-model.number="physicalDetails.stock_quantity" type="number" min="0" class="input" placeholder="0" />
            </div>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <p class="label">Вес (грамм)</p>
              <input v-model.number="physicalDetails.weight_grams" type="number" min="0" class="input" :placeholder="ep('350')" />
            </div>
            <div>
              <p class="label">Размер (Д×Ш×В)</p>
              <input v-model="physicalDetails.dimensions" type="text" class="input" :placeholder="ep('20×14×2 см')" />
            </div>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <p class="label">Цвет</p>
              <input v-model="physicalDetails.color" type="text" class="input" :placeholder="ep('Тёмно-синий')" />
            </div>
            <div>
              <p class="label">Материал</p>
              <input v-model="physicalDetails.material" type="text" class="input" :placeholder="ep('Экокожа')" />
            </div>
          </div>
          <div>
            <p class="label">Бренд</p>
            <input v-model="physicalDetails.brand" type="text" class="input" :placeholder="ep('MyBrand')" />
          </div>
        </div>
      </div>

      <!-- ══════════ ЦИФРОВОЙ ТОВАР ══════════ -->
      <div v-if="form.type === 'digital'" class="card">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Параметры цифрового товара</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Способ доставки и доступ</p>
        <div class="space-y-4">
          <div>
            <p class="label">Тип доставки</p>
            <div class="grid grid-cols-3 gap-2">
              <button type="button" @click="digitalDetails.delivery_type = 'download'"
                :class="['p-2 sm:p-3 rounded-lg border-2 text-center transition-colors text-xs sm:text-sm', digitalDetails.delivery_type === 'download' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600']">
                <div class="text-lg mb-0.5">⬇️</div>
                Скачивание
              </button>
              <button type="button" @click="digitalDetails.delivery_type = 'link'"
                :class="['p-2 sm:p-3 rounded-lg border-2 text-center transition-colors text-xs sm:text-sm', digitalDetails.delivery_type === 'link' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600']">
                <div class="text-lg mb-0.5">🔗</div>
                Ссылка
              </button>
              <button type="button" @click="digitalDetails.delivery_type = 'code'"
                :class="['p-2 sm:p-3 rounded-lg border-2 text-center transition-colors text-xs sm:text-sm', digitalDetails.delivery_type === 'code' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600']">
                <div class="text-lg mb-0.5">🔑</div>
                <span class="sm:hidden">Код</span><span class="hidden sm:inline">Код активации</span>
              </button>
            </div>
          </div>
          <div>
            <p class="label">{{ digitalDetails.delivery_type === 'code' ? 'Код / Инструкция' : 'URL для скачивания / доступа' }}</p>
            <input v-model="digitalDetails.download_url" type="text" class="input"
              :placeholder="digitalDetails.delivery_type === 'code' ? 'Код будет отправлен на email' : 'https://storage.example.com/file.zip'" />
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <p class="label">Срок<span class="hidden sm:inline"> доступа</span> (дней)</p>
              <input v-model.number="digitalDetails.access_days" type="number" min="1" class="input" placeholder="Бессрочно" />
            </div>
            <div>
              <p class="label">Размер файла (МБ)</p>
              <input v-model.number="digitalDetails.file_size_mb" type="number" min="0" step="0.1" class="input" :placeholder="ep('256')" />
            </div>
          </div>
          <div>
            <p class="label">Формат файла</p>
            <input v-model="digitalDetails.file_format" type="text" class="input" :placeholder="ep('PDF, MP4, ZIP...')" />
          </div>
        </div>
      </div>

      <!-- ══════════ УСЛУГА ══════════ -->
      <div v-if="form.type === 'service'" class="card">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Параметры услуги</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Длительность и расписание</p>
        <div class="space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <p class="label">Длительность (мин) *</p>
              <input v-model.number="serviceDetails.duration_minutes" type="number" min="5" step="5" class="input" placeholder="60" />
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Шаг: 5 минут</p>
            </div>
            <div>
              <p class="label">Перерыв (мин)</p>
              <input v-model.number="serviceDetails.break_minutes" type="number" min="0" step="5" class="input" placeholder="0" />
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Время на подготовку</p>
            </div>
          </div>
          <div>
            <p class="label">Макс. одновременных записей</p>
            <input v-model.number="serviceDetails.max_concurrent" type="number" min="1" class="input" placeholder="1" />
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Сколько клиентов одновременно могут быть записаны</p>
          </div>
          <label class="flex items-center gap-3 cursor-pointer select-none">
            <div class="relative">
              <input type="checkbox" v-model="serviceDetails.requires_prepayment" class="sr-only peer" />
              <div class="w-11 h-6 bg-gray-200 peer-checked:bg-primary-600 rounded-full transition-colors"></div>
              <div class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></div>
            </div>
            <div>
              <span class="text-gray-700 dark:text-gray-300">Требуется предоплата</span>
              <p class="text-xs text-gray-400 dark:text-gray-500">Клиент должен оплатить при записи</p>
            </div>
          </label>
        </div>
      </div>

      <!-- ══════════ КНОПКИ ══════════ -->
      <div class="flex gap-3">
        <button type="button" @click="router.back()" class="btn-secondary flex-1">Отмена</button>
        <button type="submit" class="btn-primary flex-1" :disabled="saving || uploading">
          <template v-if="saving">
            <div class="animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"></div>
            Сохранение...
          </template>
          <template v-else>{{ isEditing ? 'Сохранить' : 'Создать товар' }}</template>
        </button>
      </div>
    </form>
  </div>
</template>
