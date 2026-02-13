<script setup lang="ts">
import { ref, computed } from 'vue'
import { useCartStore } from '@/stores/cart'
import { useShopStore } from '@/stores/shop'
import { formatPrice } from '@/lib/utils'

const emit = defineEmits<{
  back: []
  success: [order: any]
}>()

const cartStore = useCartStore()
const shopStore = useShopStore()

const loading = ref(false)
const error = ref('')

const form = ref({
  name: '',
  email: '',
  phone: '',
})

const address = ref({
  city: '',
  street: '',
  building: '',
  apartment: '',
  postal_code: '',
  comment: '',
})

const notes = ref('')

const errors = ref<Record<string, string>>({})

const hasPhysical = computed(() => cartStore.items.some(i => i.type === 'physical'))

function validate(): boolean {
  const e: Record<string, string> = {}

  if (!form.value.name.trim()) e.name = 'Укажите имя'
  if (!form.value.email.trim()) e.email = 'Укажите email'
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.value.email)) e.email = 'Неверный формат email'
  if (!form.value.phone.trim()) e.phone = 'Укажите телефон'

  if (hasPhysical.value) {
    if (!address.value.city.trim()) e['address.city'] = 'Укажите город'
    if (!address.value.street.trim()) e['address.street'] = 'Укажите улицу'
    if (!address.value.building.trim()) e['address.building'] = 'Укажите дом'
    if (!address.value.postal_code.trim()) e['address.postal_code'] = 'Укажите индекс'
  }

  errors.value = e
  return Object.keys(e).length === 0
}

async function handleSubmit() {
  if (!validate()) return

  loading.value = true
  error.value = ''

  const payload: Record<string, any> = {
    items: cartStore.items.map(i => ({
      product_id: i.id,
      quantity: i.quantity,
    })),
    customer: {
      name: form.value.name.trim(),
      email: form.value.email.trim(),
      phone: form.value.phone.trim(),
    },
    notes: notes.value.trim() || null,
  }

  if (hasPhysical.value) {
    payload.shipping_address = {
      city: address.value.city.trim(),
      street: address.value.street.trim(),
      building: address.value.building.trim(),
      apartment: address.value.apartment.trim() || null,
      postal_code: address.value.postal_code.trim(),
      comment: address.value.comment.trim() || null,
    }
  }

  try {
    const result = await shopStore.getApi().createOrder(payload)
    cartStore.clear()
    emit('success', result.data)
  } catch (e: any) {
    error.value = e.message || 'Ошибка оформления заказа'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="sb-checkout">
    <!-- Header -->
    <div class="sb-flex sb-items-center sb-gap-3 sb-mb-4">
      <button class="sb-btn sb-btn-ghost" @click="emit('back')">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
        Назад
      </button>
      <h2 class="sb-title" style="margin-bottom: 0;">Оформление заказа</h2>
    </div>

    <!-- Progress -->
    <div class="sb-progress sb-mb-4">
      <div class="sb-progress-step sb-progress-done">Корзина</div>
      <div class="sb-progress-sep"></div>
      <div class="sb-progress-step sb-progress-active">Данные</div>
      <div class="sb-progress-sep"></div>
      <div class="sb-progress-step">Готово</div>
    </div>

    <div v-if="error" class="sb-alert-error sb-mb-4">{{ error }}</div>

    <form @submit.prevent="handleSubmit" class="sb-checkout-layout">
      <!-- Left: form -->
      <div class="sb-checkout-form">
        <!-- Contact -->
        <div class="sb-checkout-section">
          <h3 class="sb-checkout-section-title">Контактные данные</h3>

          <div class="sb-field">
            <label class="sb-label">Имя *</label>
            <input
              v-model="form.name"
              type="text"
              class="sb-input"
              :class="{ 'sb-input-error': errors.name }"
              placeholder="Ваше имя"
            />
            <p v-if="errors.name" class="sb-error-text">{{ errors.name }}</p>
          </div>

          <div class="sb-field">
            <label class="sb-label">Email *</label>
            <input
              v-model="form.email"
              type="email"
              class="sb-input"
              :class="{ 'sb-input-error': errors.email }"
              placeholder="email@example.com"
            />
            <p v-if="errors.email" class="sb-error-text">{{ errors.email }}</p>
          </div>

          <div class="sb-field">
            <label class="sb-label">Телефон *</label>
            <input
              v-model="form.phone"
              type="tel"
              class="sb-input"
              :class="{ 'sb-input-error': errors.phone }"
              placeholder="+7 (999) 123-45-67"
            />
            <p v-if="errors.phone" class="sb-error-text">{{ errors.phone }}</p>
          </div>
        </div>

        <!-- Shipping (only if physical) -->
        <div v-if="hasPhysical" class="sb-checkout-section">
          <h3 class="sb-checkout-section-title">Адрес доставки</h3>

          <div class="sb-field">
            <label class="sb-label">Город *</label>
            <input v-model="address.city" type="text" class="sb-input" :class="{ 'sb-input-error': errors['address.city'] }" placeholder="Москва" />
            <p v-if="errors['address.city']" class="sb-error-text">{{ errors['address.city'] }}</p>
          </div>

          <div class="sb-field">
            <label class="sb-label">Улица *</label>
            <input v-model="address.street" type="text" class="sb-input" :class="{ 'sb-input-error': errors['address.street'] }" placeholder="ул. Ленина" />
            <p v-if="errors['address.street']" class="sb-error-text">{{ errors['address.street'] }}</p>
          </div>

          <div class="sb-field-row">
            <div class="sb-field">
              <label class="sb-label">Дом *</label>
              <input v-model="address.building" type="text" class="sb-input" :class="{ 'sb-input-error': errors['address.building'] }" placeholder="12" />
              <p v-if="errors['address.building']" class="sb-error-text">{{ errors['address.building'] }}</p>
            </div>
            <div class="sb-field">
              <label class="sb-label">Квартира</label>
              <input v-model="address.apartment" type="text" class="sb-input" placeholder="34" />
            </div>
            <div class="sb-field">
              <label class="sb-label">Индекс *</label>
              <input v-model="address.postal_code" type="text" class="sb-input" :class="{ 'sb-input-error': errors['address.postal_code'] }" placeholder="101000" />
              <p v-if="errors['address.postal_code']" class="sb-error-text">{{ errors['address.postal_code'] }}</p>
            </div>
          </div>

          <div class="sb-field">
            <label class="sb-label">Комментарий к доставке</label>
            <textarea v-model="address.comment" class="sb-input" rows="2" placeholder="Код домофона, подъезд..." />
          </div>
        </div>

        <!-- Notes -->
        <div class="sb-checkout-section">
          <div class="sb-field">
            <label class="sb-label">Комментарий к заказу</label>
            <textarea v-model="notes" class="sb-input" rows="2" placeholder="Пожелания..." />
          </div>
        </div>
      </div>

      <!-- Right: summary -->
      <div class="sb-checkout-summary">
        <h3 class="sb-checkout-section-title">Ваш заказ</h3>

        <div class="sb-summary-items">
          <div v-for="item in cartStore.items" :key="item.id" class="sb-summary-item">
            <span class="sb-summary-item-name">{{ item.name }} × {{ item.quantity }}</span>
            <span class="sb-summary-item-price">{{ formatPrice(item.price * item.quantity) }}</span>
          </div>
        </div>

        <div class="sb-divider"></div>

        <div class="sb-summary-total">
          <span>Итого:</span>
          <span class="sb-price-lg">{{ formatPrice(cartStore.total) }}</span>
        </div>

        <button
          type="submit"
          class="sb-btn sb-btn-primary sb-btn-block sb-mt-4"
          :disabled="loading"
        >
          {{ loading ? 'Оформление...' : 'Подтвердить заказ' }}
        </button>
      </div>
    </form>
  </div>
</template>
