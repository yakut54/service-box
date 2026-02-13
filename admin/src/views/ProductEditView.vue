<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
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
  name: '', description: '', price: 0, type: 'physical' as string,
  category: '', is_active: true, image_url: '',
})

const physicalDetails = ref({ sku: '', stock_quantity: 0, weight_grams: null as number | null })
const digitalDetails = ref({ delivery_type: 'download', access_days: null as number | null, download_url: '' })
const serviceDetails = ref({ duration_minutes: 60, max_concurrent: 1 })

onMounted(async () => {
  await productsStore.fetchProducts()
  if (isEditing.value) {
    loading.value = true
    try {
      const resp = await api.getProduct(route.params.id as string)
      const p = resp.data
      form.value = { name: p.name, description: p.description || '', price: p.price / 100, type: p.type, category: p.category || '', is_active: p.is_active, image_url: p.image_url || '' }
      if (p.physical) physicalDetails.value = { sku: p.physical.sku || '', stock_quantity: p.physical.stock_quantity, weight_grams: p.physical.weight_grams }
      if (p.digital) digitalDetails.value = { delivery_type: p.digital.delivery_type, access_days: p.digital.access_days, download_url: p.digital.download_url || '' }
      if (p.service) serviceDetails.value = { duration_minutes: p.service.duration_minutes, max_concurrent: p.service.max_concurrent }
    } catch { error.value = 'Товар не найден' }
    loading.value = false
  }
})

async function handleSubmit() {
  if (!form.value.name.trim()) { error.value = 'Введите название'; return }
  if (form.value.price <= 0) { error.value = 'Введите цену'; return }

  saving.value = true
  error.value = ''

  const data: Record<string, any> = {
    name: form.value.name.trim(), description: form.value.description.trim() || null,
    price: Math.round(form.value.price * 100), type: form.value.type,
    category: form.value.category.trim() || null, is_active: form.value.is_active,
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
      <button @click="router.back()" class="text-gray-500 hover:text-gray-700 mb-2 flex items-center gap-1">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
        Назад
      </button>
      <h1 class="text-2xl font-bold text-gray-900">{{ isEditing ? 'Редактировать товар' : 'Новый товар' }}</h1>
    </div>

    <div v-if="loading" class="card py-12 text-center">
      <div class="animate-spin w-8 h-8 border-4 border-primary-600 border-t-transparent rounded-full mx-auto"></div>
    </div>

    <form v-else @submit.prevent="handleSubmit" class="space-y-6">
      <div v-if="error" class="p-4 bg-red-50 border border-red-200 rounded-lg text-red-600">{{ error }}</div>

      <div class="card">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Основная информация</h2>
        <div class="space-y-4">
          <div>
            <label class="label">Тип товара</label>
            <div class="grid grid-cols-3 gap-2">
              <button type="button" @click="form.type = 'physical'" :class="['p-3 rounded-lg border-2 text-center transition-colors', form.type === 'physical' ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-200 hover:border-gray-300']">
                <div class="text-sm font-medium">Физический</div>
              </button>
              <button type="button" @click="form.type = 'digital'" :class="['p-3 rounded-lg border-2 text-center transition-colors', form.type === 'digital' ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-200 hover:border-gray-300']">
                <div class="text-sm font-medium">Цифровой</div>
              </button>
              <button type="button" @click="form.type = 'service'" :class="['p-3 rounded-lg border-2 text-center transition-colors', form.type === 'service' ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-200 hover:border-gray-300']">
                <div class="text-sm font-medium">Услуга</div>
              </button>
            </div>
          </div>
          <div><label for="name" class="label">Название *</label><input id="name" v-model="form.name" type="text" class="input" placeholder="Название товара" /></div>
          <div><label for="description" class="label">Описание</label><textarea id="description" v-model="form.description" class="input min-h-[100px]" placeholder="Описание..." /></div>
          <div><label for="price" class="label">Цена (руб) *</label><input id="price" v-model.number="form.price" type="number" min="0" step="0.01" class="input" /></div>
          <div><label for="category" class="label">Категория</label><input id="category" v-model="form.category" type="text" class="input" placeholder="Категория" :list="'cats'" /><datalist id="cats"><option v-for="c in productsStore.categories" :key="c" :value="c" /></datalist></div>
          <div><label for="image_url" class="label">URL изображения</label><input id="image_url" v-model="form.image_url" type="url" class="input" placeholder="https://..." /></div>
          <label class="flex items-center gap-3 cursor-pointer"><input type="checkbox" v-model="form.is_active" class="w-5 h-5 rounded border-gray-300 text-primary-600 focus:ring-primary-500" /><span class="text-gray-700">Показывать в каталоге</span></label>
        </div>
      </div>

      <div v-if="form.type === 'physical'" class="card">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Параметры товара</h2>
        <div class="space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div><label class="label">Артикул</label><input v-model="physicalDetails.sku" type="text" class="input" placeholder="ABC-123" /></div>
            <div><label class="label">Кол-во на складе</label><input v-model.number="physicalDetails.stock_quantity" type="number" min="0" class="input" /></div>
          </div>
          <div><label class="label">Вес (грамм)</label><input v-model.number="physicalDetails.weight_grams" type="number" min="0" class="input" /></div>
        </div>
      </div>

      <div v-if="form.type === 'digital'" class="card">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Параметры цифрового товара</h2>
        <div class="space-y-4">
          <div><label class="label">Тип доставки</label><select v-model="digitalDetails.delivery_type" class="input"><option value="download">Скачивание</option><option value="link">Ссылка</option><option value="code">Код активации</option></select></div>
          <div><label class="label">URL для скачивания</label><input v-model="digitalDetails.download_url" type="url" class="input" /></div>
          <div><label class="label">Срок доступа (дней)</label><input v-model.number="digitalDetails.access_days" type="number" min="1" class="input" placeholder="Бессрочно" /></div>
        </div>
      </div>

      <div v-if="form.type === 'service'" class="card">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Параметры услуги</h2>
        <div class="space-y-4">
          <div><label class="label">Длительность (мин) *</label><input v-model.number="serviceDetails.duration_minutes" type="number" min="5" step="5" class="input" /></div>
          <div><label class="label">Макс. одновременных записей</label><input v-model.number="serviceDetails.max_concurrent" type="number" min="1" class="input" /></div>
        </div>
      </div>

      <div class="flex gap-3">
        <button type="button" @click="router.back()" class="btn-secondary">Отмена</button>
        <button type="submit" class="btn-primary flex-1" :disabled="saving">{{ saving ? 'Сохранение...' : (isEditing ? 'Сохранить' : 'Создать товар') }}</button>
      </div>
    </form>
  </div>
</template>
