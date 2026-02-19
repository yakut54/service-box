<script setup lang="ts">
import { ref, computed } from 'vue'
import { useCartStore } from '@/stores/cart'
import { useShopStore } from '@/stores/shop'
import { formatPrice, cleanPhone, isPhoneValid, isEmailValid } from '@/lib/utils'
import { handlePhoneInput } from '@/lib/phoneInput'

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
const touched = ref<Record<string, boolean>>({})

const hasPhysical = computed(() => cartStore.items.some(i => i.type === 'physical'))

function onPhoneInput(e: Event) {
  handlePhoneInput(e, (v) => {
    form.value.phone = v
    if (touched.value.phone) validateField('phone')
  })
}

function touch(field: string) {
  touched.value[field] = true
  validateField(field)
}

function validateField(field: string) {
  const e = { ...errors.value }
  delete e[field]

  switch (field) {
    case 'name':
      if (touched.value.name && !form.value.name.trim()) e.name = 'Укажите имя'
      else if (touched.value.name && form.value.name.trim().length < 2) e.name = 'Минимум 2 символа'
      break
    case 'email':
      if (touched.value.email && !form.value.email.trim()) e.email = 'Укажите email'
      else if (touched.value.email && form.value.email.trim() && !isEmailValid(form.value.email)) e.email = 'Неверный формат email'
      break
    case 'phone':
      if (touched.value.phone && !form.value.phone.trim()) e.phone = 'Укажите телефон'
      else if (touched.value.phone && form.value.phone.trim() && !isPhoneValid(form.value.phone)) e.phone = 'Введите номер полностью'
      break
    case 'address.city':
      if (touched.value['address.city'] && hasPhysical.value && !address.value.city.trim()) e['address.city'] = 'Укажите город'
      break
    case 'address.street':
      if (touched.value['address.street'] && hasPhysical.value && !address.value.street.trim()) e['address.street'] = 'Укажите улицу'
      break
    case 'address.building':
      if (touched.value['address.building'] && hasPhysical.value && !address.value.building.trim()) e['address.building'] = 'Укажите дом'
      break
    case 'address.postal_code':
      if (touched.value['address.postal_code'] && hasPhysical.value && !address.value.postal_code.trim()) e['address.postal_code'] = 'Укажите индекс'
      break
  }

  errors.value = e
}

function isFieldValid(field: string): boolean {
  if (!touched.value[field]) return false
  if (errors.value[field]) return false
  switch (field) {
    case 'name': return form.value.name.trim().length >= 2
    case 'email': return form.value.email.trim() !== '' && isEmailValid(form.value.email)
    case 'phone': return isPhoneValid(form.value.phone)
    case 'address.city': return address.value.city.trim().length > 0
    case 'address.street': return address.value.street.trim().length > 0
    case 'address.building': return address.value.building.trim().length > 0
    case 'address.postal_code': return address.value.postal_code.trim().length > 0
    default: return false
  }
}

function validate(): boolean {
  // Touch all fields
  touched.value = { name: true, email: true, phone: true }
  if (hasPhysical.value) {
    touched.value['address.city'] = true
    touched.value['address.street'] = true
    touched.value['address.building'] = true
    touched.value['address.postal_code'] = true
  }

  const e: Record<string, string> = {}

  if (!form.value.name.trim()) e.name = 'Укажите имя'
  else if (form.value.name.trim().length < 2) e.name = 'Минимум 2 символа'
  if (!form.value.email.trim()) e.email = 'Укажите email'
  else if (!isEmailValid(form.value.email)) e.email = 'Неверный формат email'
  if (!form.value.phone.trim()) e.phone = 'Укажите телефон'
  else if (!isPhoneValid(form.value.phone)) e.phone = 'Введите номер полностью'

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
      phone: '+' + cleanPhone(form.value.phone),
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

    console.log('result: ', result)
    // где то тут олжна юкасса вызываться?

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
  <div class="sb-co">
    <!-- Back + title -->
    <div class="sb-flex sb-items-center sb-gap-3 sb-mb-4">
      <button class="sb-btn sb-btn-ghost" @click="emit('back')">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
        Назад
      </button>
      <h2 class="sb-title" style="margin-bottom: 0;">Оформление заказа</h2>
    </div>

    <!-- Step progress bar -->
    <div class="sb-co-progress sb-mb-4">
      <div class="sb-co-step sb-co-step-done">
        <span class="sb-co-step-circle">
          <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
            <path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </span>
        <span class="sb-co-step-label">Корзина</span>
      </div>
      <div class="sb-co-line sb-co-line-done"></div>
      <div class="sb-co-step sb-co-step-active">
        <span class="sb-co-step-circle">2</span>
        <span class="sb-co-step-label">Данные</span>
      </div>
      <div class="sb-co-line"></div>
      <div class="sb-co-step">
        <span class="sb-co-step-circle">3</span>
        <span class="sb-co-step-label">Готово</span>
      </div>
    </div>

    <div v-if="error" class="sb-alert-error sb-mb-4">{{ error }}</div>

    <form @submit.prevent="handleSubmit">
    <div class="sb-checkout-layout">
      <!-- Left: form -->
      <div class="sb-checkout-form">
        <!-- Contact -->
        <div class="sb-checkout-section">
          <h3 class="sb-checkout-section-title">Контактные данные</h3>

          <div class="sb-field">
            <label class="sb-label">Имя *</label>
            <div class="sb-field-wrap">
              <input
                v-model="form.name"
                type="text"
                class="sb-input"
                :class="{ 'sb-input-error': errors.name, 'sb-input-success': isFieldValid('name') }"
                placeholder="Ваше имя"
                @blur="touch('name')"
                @input="validateField('name')"
              />
              <svg v-if="isFieldValid('name')" class="sb-field-check" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
            </div>
            <p v-if="errors.name" class="sb-error-text">{{ errors.name }}</p>
          </div>

          <div class="sb-field">
            <label class="sb-label">Email *</label>
            <div class="sb-field-wrap">
              <input
                v-model="form.email"
                type="email"
                class="sb-input"
                :class="{ 'sb-input-error': errors.email, 'sb-input-success': isFieldValid('email') }"
                placeholder="email@example.com"
                @blur="touch('email')"
                @input="validateField('email')"
              />
              <svg v-if="isFieldValid('email')" class="sb-field-check" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
            </div>
            <p v-if="errors.email" class="sb-error-text">{{ errors.email }}</p>
          </div>

          <div class="sb-field">
            <label class="sb-label">Телефон *</label>
            <div class="sb-field-wrap">
              <input
                :value="form.phone"
                type="tel"
                class="sb-input"
                :class="{ 'sb-input-error': errors.phone, 'sb-input-success': isFieldValid('phone') }"
                placeholder="+7 (___) ___-__-__"
                @input="onPhoneInput"
                @blur="touch('phone')"
              />
              <svg v-if="isFieldValid('phone')" class="sb-field-check" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
            </div>
            <p v-if="errors.phone" class="sb-error-text">{{ errors.phone }}</p>
          </div>
        </div>

        <!-- Shipping (only if physical) -->
        <div v-if="hasPhysical" class="sb-checkout-section">
          <h3 class="sb-checkout-section-title">Адрес доставки</h3>

          <div class="sb-field">
            <label class="sb-label">Город *</label>
            <div class="sb-field-wrap">
              <input v-model="address.city" type="text" class="sb-input" :class="{ 'sb-input-error': errors['address.city'], 'sb-input-success': isFieldValid('address.city') }" placeholder="Москва" @blur="touch('address.city')" @input="validateField('address.city')" />
              <svg v-if="isFieldValid('address.city')" class="sb-field-check" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
            </div>
            <p v-if="errors['address.city']" class="sb-error-text">{{ errors['address.city'] }}</p>
          </div>

          <div class="sb-field">
            <label class="sb-label">Улица *</label>
            <div class="sb-field-wrap">
              <input v-model="address.street" type="text" class="sb-input" :class="{ 'sb-input-error': errors['address.street'], 'sb-input-success': isFieldValid('address.street') }" placeholder="ул. Ленина" @blur="touch('address.street')" @input="validateField('address.street')" />
              <svg v-if="isFieldValid('address.street')" class="sb-field-check" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
            </div>
            <p v-if="errors['address.street']" class="sb-error-text">{{ errors['address.street'] }}</p>
          </div>

          <div class="sb-field-row">
            <div class="sb-field">
              <label class="sb-label">Дом *</label>
              <div class="sb-field-wrap">
                <input v-model="address.building" type="text" class="sb-input" :class="{ 'sb-input-error': errors['address.building'], 'sb-input-success': isFieldValid('address.building') }" placeholder="12" @blur="touch('address.building')" @input="validateField('address.building')" />
                <svg v-if="isFieldValid('address.building')" class="sb-field-check" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
              </div>
              <p v-if="errors['address.building']" class="sb-error-text">{{ errors['address.building'] }}</p>
            </div>
            <div class="sb-field">
              <label class="sb-label">Квартира</label>
              <input v-model="address.apartment" type="text" class="sb-input" placeholder="34" />
            </div>
            <div class="sb-field">
              <label class="sb-label">Индекс *</label>
              <div class="sb-field-wrap">
                <input v-model="address.postal_code" type="text" class="sb-input" :class="{ 'sb-input-error': errors['address.postal_code'], 'sb-input-success': isFieldValid('address.postal_code') }" placeholder="101000" @blur="touch('address.postal_code')" @input="validateField('address.postal_code')" />
                <svg v-if="isFieldValid('address.postal_code')" class="sb-field-check" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
              </div>
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

      <!-- Right: order summary (sticky on desktop) -->
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

        <!-- Submit button: desktop only (mobile uses sticky footer) -->
        <button
          type="submit"
          class="sb-btn sb-btn-primary sb-btn-block sb-mt-4 sb-co-submit-desktop"
          :disabled="loading"
        >
          {{ loading ? 'Оформление...' : 'Подтвердить заказ' }}
        </button>
      </div>
    </div>

    <!-- Mobile sticky footer: total + submit -->
    <div class="sb-co-footer">
      <div class="sb-co-footer-price">
        <span class="sb-co-footer-label">Итого</span>
        <span class="sb-co-footer-amount">{{ formatPrice(cartStore.total) }}</span>
      </div>
      <button type="submit" class="sb-btn sb-btn-primary" :disabled="loading">
        {{ loading ? '...' : 'Подтвердить' }}
      </button>
    </div>
    </form>
  </div>
</template>
