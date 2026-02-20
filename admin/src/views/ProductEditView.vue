<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/lib/api'
import { useProductsStore } from '@/stores/products'

const route = useRoute()
const router = useRouter()
const productsStore = useProductsStore()

const isEditing = computed(() => !!route.params.id)
const loading = ref(false)
const saving = ref(false)
const error = ref('')

const form = ref({
  name: '',
  description: '',
  price: 0,
  compare_price: null as number | null,
  type: 'physical' as string,
  category: '',
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

// ── Category dropdown ───────────────────────────────────────
const catOpen = ref(false)
const catSearch = ref('')
const catInputEl = ref<HTMLInputElement | null>(null)

const filteredCategories = computed(() => {
  const q = catSearch.value.toLowerCase().trim()
  if (!q) return productsStore.categories
  return productsStore.categories.filter(c => c.toLowerCase().includes(q))
})

const showNewCategoryHint = computed(() => {
  const q = catSearch.value.trim()
  if (!q) return false
  return !productsStore.categories.some(c => c.toLowerCase() === q.toLowerCase())
})

function selectCategory(cat: string) {
  form.value.category = cat
  catSearch.value = cat
  catOpen.value = false
}

function onCatFocus() {
  catSearch.value = form.value.category
  catOpen.value = true
}

function onCatBlur() {
  setTimeout(() => {
    catOpen.value = false
    const q = catSearch.value.trim()
    if (q) {
      form.value.category = q
    } else {
      form.value.category = ''
    }
  }, 200)
}

function clearCategory() {
  form.value.category = ''
  catSearch.value = ''
  catOpen.value = false
}

function onCatEnter() {
  const q = catSearch.value.trim()
  if (!q) {
    catOpen.value = false
    return
  }
  if (filteredCategories.value.length > 0) {
    selectCategory(filteredCategories.value[0])
  } else {
    selectCategory(q)
  }
}

// ── Image upload ─────────────────────────────────────────────
const imageError   = ref(false)
const uploading    = ref(false)
const isDragging   = ref(false)
const uploadError  = ref('')
const fileInputEl  = ref<HTMLInputElement | null>(null)

const ALLOWED_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp']
const MAX_BYTES = 1 * 1024 * 1024

watch(() => form.value.image_url, () => { imageError.value = false })

function validateFile(file: File): string | null {
  if (!ALLOWED_TYPES.includes(file.type)) return 'Только JPG, PNG или WEBP'
  if (file.size > MAX_BYTES) return `Файл слишком большой (макс. 1 МБ, у вас ${(file.size / 1024 / 1024).toFixed(1)} МБ)`
  return null
}

async function handleFile(file: File) {
  const err = validateFile(file)
  if (err) { uploadError.value = err; return }
  uploadError.value = ''
  // Show local preview immediately
  form.value.image_url = URL.createObjectURL(file)
  uploading.value = true
  try {
    const result = await api.uploadImage(file)
    form.value.image_url = result.url
  } catch (e: any) {
    form.value.image_url = ''
    uploadError.value = e.message || 'Не удалось загрузить'
  }
  uploading.value = false
}

function onFileInput(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (file) handleFile(file)
}

function onDrop(e: DragEvent) {
  isDragging.value = false
  const file = e.dataTransfer?.files?.[0]
  if (file) handleFile(file)
}

function clearImage() {
  form.value.image_url = ''
  imageError.value = false
  uploadError.value = ''
  if (fileInputEl.value) fileInputEl.value.value = ''
}

// ── Data loading ────────────────────────────────────────────
onMounted(async () => {
  if (!productsStore.products.length) {
    try {
      await productsStore.fetchProducts()
    } catch { /* категории не критичны */ }
  }
  if (isEditing.value) {
    loading.value = true
    try {
      const resp = await api.getProduct(route.params.id as string)
      const p = resp.data
      form.value = {
        name: p.name,
        description: p.description || '',
        price: p.price / 100,
        compare_price: p.compare_price ? p.compare_price / 100 : null,
        type: p.type,
        category: p.category || '',
        is_active: p.is_active,
        image_url: p.image_url || ''
      }
      catSearch.value = p.category || ''
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
          delivery_type: p.digital.delivery_type,
          access_days: p.digital.access_days,
          download_url: p.digital.download_url || '',
          file_size_mb: p.digital.file_size_mb ?? null,
          file_format: p.digital.file_format || '',
        }
      }
      if (p.service) {
        serviceDetails.value = {
          duration_minutes: p.service.duration_minutes,
          max_concurrent: p.service.max_concurrent,
          break_minutes: p.service.break_minutes ?? 0,
          requires_prepayment: p.service.requires_prepayment ?? false,
        }
      }
    } catch (e: any) {
      console.error('ProductEdit load error:', e)
      error.value = e?.message || 'Товар не найден'
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
    price: Math.round(form.value.price * 100),
    compare_price: form.value.compare_price ? Math.round(form.value.compare_price * 100) : null,
    type: form.value.type,
    category: form.value.category.trim() || null,
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
  } catch (e: any) { error.value = e.message || 'Ошибка сохранения' }
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
      <div v-if="error" class="p-4 bg-red-50 border border-red-200 rounded-lg text-red-600">{{ error }}</div>

      <!-- ══════════ ОСНОВНАЯ ИНФОРМАЦИЯ ══════════ -->
      <div class="card">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Основная информация</h2>
        <div class="space-y-4">
          <!-- Тип товара -->
          <div>
            <label class="label">Тип товара</label>
            <div class="grid grid-cols-3 gap-3">
              <button
                v-for="(cfg, key) in typeConfig"
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
            <textarea id="description" v-model="form.description" class="input min-h-[100px]" :placeholder="currentTypeConfig.descPlaceholder" />
          </div>

          <!-- Цена -->
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label for="price" class="label">Цена (руб) *</label>
              <div class="relative">
                <input id="price" v-model.number="form.price" type="number" min="0" step="0.01" class="input pr-12" :placeholder="currentTypeConfig.pricePlaceholder" />
                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 text-sm pointer-events-none">₽</span>
              </div>
            </div>
            <div>
              <label for="compare_price" class="label">Старая цена (руб)
                <span class="text-gray-400 dark:text-gray-500 font-normal text-xs ml-1">для показа скидки</span>
              </label>
              <div class="relative">
                <input id="compare_price" v-model.number="form.compare_price" type="number" min="0" step="0.01" class="input pr-12" placeholder="0" />
                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 text-sm pointer-events-none">₽</span>
              </div>
              <p v-if="form.compare_price && form.compare_price <= form.price" class="text-xs text-amber-600 dark:text-amber-400 mt-1">Старая цена должна быть больше текущей</p>
            </div>
          </div>

          <!-- Категория — combobox -->
          <div class="relative">
            <label class="label">Категория</label>
            <div class="relative">
              <input
                ref="catInputEl"
                :value="catOpen ? catSearch : form.category"
                @input="catSearch = ($event.target as HTMLInputElement).value"
                @focus="onCatFocus"
                @blur="onCatBlur"
                @keydown.enter.prevent="onCatEnter"
                type="text"
                class="input pr-16"
                placeholder="Выберите или введите новую"
                autocomplete="off"
              />
              <div class="absolute right-2 top-1/2 -translate-y-1/2 flex items-center gap-1">
                <button
                  v-if="form.category"
                  type="button"
                  @mousedown.prevent="clearCategory"
                  class="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
                <svg class="w-4 h-4 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
              </div>
            </div>

            <!-- Dropdown -->
            <div
              v-if="catOpen && (filteredCategories.length > 0 || showNewCategoryHint)"
              class="absolute z-20 mt-1 w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg max-h-48 overflow-y-auto"
            >
              <button
                v-for="cat in filteredCategories"
                :key="cat"
                type="button"
                @mousedown.prevent="selectCategory(cat)"
                :class="['w-full text-left px-4 py-2.5 text-sm hover:bg-primary-50 dark:hover:bg-primary-900/30 hover:text-primary-700 dark:hover:text-primary-300 transition-colors', form.category === cat ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300 font-medium' : 'text-gray-700 dark:text-gray-300']"
              >
                {{ cat }}
              </button>
              <div
                v-if="showNewCategoryHint"
                class="px-4 py-2.5 text-sm text-gray-500 dark:text-gray-400 border-t border-gray-100 dark:border-gray-700"
              >
                <span class="text-primary-600 font-medium">Enter</span> — создать «{{ catSearch.trim() }}»
              </div>
            </div>
          </div>

          <!-- Изображение -->
          <div>
            <label class="label">Изображение</label>

            <!-- Превью (когда уже есть картинка) -->
            <div v-if="form.image_url && !imageError" class="flex items-start gap-3">
              <div class="relative flex-shrink-0">
                <img
                  :src="form.image_url"
                  @error="imageError = true"
                  class="h-24 w-24 object-cover rounded-xl border border-gray-200 dark:border-gray-700"
                  alt="Превью"
                />
                <!-- Оверлей загрузки -->
                <div v-if="uploading" class="absolute inset-0 bg-black/50 rounded-xl flex items-center justify-center">
                  <div class="animate-spin w-6 h-6 border-2 border-white border-t-transparent rounded-full"></div>
                </div>
              </div>
              <div v-if="!uploading" class="flex flex-col gap-1.5 pt-1">
                <button type="button" @click="fileInputEl?.click()"
                  class="text-xs text-primary-600 dark:text-primary-400 hover:underline text-left">
                  Заменить
                </button>
                <button type="button" @click="clearImage"
                  class="text-xs text-red-500 hover:text-red-700 flex items-center gap-1 text-left">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                  </svg>
                  Удалить
                </button>
              </div>
              <p v-else class="text-xs text-gray-400 pt-1">Загружается...</p>
            </div>

            <!-- Зона drag & drop (когда картинки нет) -->
            <div
              v-else
              @dragenter.prevent="isDragging = true"
              @dragover.prevent="isDragging = true"
              @dragleave.prevent="isDragging = false"
              @drop.prevent="onDrop"
              @click="fileInputEl?.click()"
              :class="[
                'border-2 border-dashed rounded-xl p-6 text-center cursor-pointer transition-colors',
                isDragging
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20'
                  : 'border-gray-200 dark:border-gray-700 hover:border-primary-400 hover:bg-gray-50 dark:hover:bg-gray-800/50'
              ]"
            >
              <svg class="w-8 h-8 mx-auto mb-2 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
              </svg>
              <p class="text-sm text-gray-500 dark:text-gray-400">
                <span class="text-primary-600 dark:text-primary-400 font-medium">Нажмите</span>
                {{ isDragging ? ' или отпустите файл' : ' или перетащите фото' }}
              </p>
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">JPG, PNG, WEBP · до 1 МБ</p>
            </div>

            <!-- URL вручную -->
            <div class="mt-2">
              <input
                v-model="form.image_url"
                type="url"
                class="input text-sm"
                placeholder="или вставьте ссылку https://..."
                @input="imageError = false; uploadError = ''"
              />
            </div>

            <!-- Ошибки -->
            <p v-if="uploadError" class="mt-1 text-xs text-red-500">{{ uploadError }}</p>
            <p v-if="imageError" class="mt-1 text-xs text-red-500">Не удалось загрузить изображение по ссылке</p>

            <!-- Hidden file input -->
            <input
              ref="fileInputEl"
              type="file"
              accept="image/jpeg,image/png,image/webp"
              class="hidden"
              @change="onFileInput"
            />
          </div>

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
              <label class="label">Артикул (SKU)</label>
              <input v-model="physicalDetails.sku" type="text" class="input" placeholder="NOTE-GOALS-001" />
            </div>
            <div>
              <label class="label">Кол-во на складе</label>
              <input v-model.number="physicalDetails.stock_quantity" type="number" min="0" class="input" placeholder="0" />
            </div>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">Вес (грамм)</label>
              <input v-model.number="physicalDetails.weight_grams" type="number" min="0" class="input" placeholder="350" />
            </div>
            <div>
              <label class="label">Размер (Д×Ш×В)</label>
              <input v-model="physicalDetails.dimensions" type="text" class="input" placeholder="20×14×2 см" />
            </div>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">Цвет</label>
              <input v-model="physicalDetails.color" type="text" class="input" placeholder="Тёмно-синий" />
            </div>
            <div>
              <label class="label">Материал</label>
              <input v-model="physicalDetails.material" type="text" class="input" placeholder="Экокожа" />
            </div>
          </div>
          <div>
            <label class="label">Бренд</label>
            <input v-model="physicalDetails.brand" type="text" class="input" placeholder="MyBrand" />
          </div>
        </div>
      </div>

      <!-- ══════════ ЦИФРОВОЙ ТОВАР ══════════ -->
      <div v-if="form.type === 'digital'" class="card">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Параметры цифрового товара</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Способ доставки и доступ</p>
        <div class="space-y-4">
          <div>
            <label class="label">Тип доставки</label>
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
            <label class="label">{{ digitalDetails.delivery_type === 'code' ? 'Код / Инструкция' : 'URL для скачивания / доступа' }}</label>
            <input v-model="digitalDetails.download_url" type="url" class="input"
              :placeholder="digitalDetails.delivery_type === 'code' ? 'Код будет отправлен на email' : 'https://storage.example.com/file.zip'" />
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">Срок<span class="hidden sm:inline"> доступа</span> (дней)</label>
              <input v-model.number="digitalDetails.access_days" type="number" min="1" class="input" placeholder="Бессрочно" />
            </div>
            <div>
              <label class="label">Размер файла (МБ)</label>
              <input v-model.number="digitalDetails.file_size_mb" type="number" min="0" step="0.1" class="input" placeholder="256" />
            </div>
          </div>
          <div>
            <label class="label">Формат файла</label>
            <input v-model="digitalDetails.file_format" type="text" class="input" placeholder="PDF, MP4, ZIP..." />
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
              <label class="label">Длительность (мин) *</label>
              <input v-model.number="serviceDetails.duration_minutes" type="number" min="5" step="5" class="input" placeholder="60" />
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Шаг: 5 минут</p>
            </div>
            <div>
              <label class="label">Перерыв между записями (мин)</label>
              <input v-model.number="serviceDetails.break_minutes" type="number" min="0" step="5" class="input" placeholder="0" />
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Время на подготовку</p>
            </div>
          </div>
          <div>
            <label class="label">Макс. одновременных записей</label>
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
        <button type="button" @click="router.back()" class="btn-secondary">Отмена</button>
        <button type="submit" class="btn-primary flex-1" :disabled="saving">
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
