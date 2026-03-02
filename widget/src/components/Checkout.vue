<script setup lang="ts">
import { ref, computed, nextTick } from 'vue'
import { useCartStore } from '@/stores/cart'
import { useShopStore } from '@/stores/shop'
import { formatPrice, cleanPhone, isPhoneValid, isEmailValid, isPostalCodeValid } from '@/lib/utils'
import { handlePhoneInput } from '@/lib/phoneInput'

const emit = defineEmits<{
  back: []
  success: [order: any]
}>()

const cartStore = useCartStore()
const shopStore = useShopStore()

const loading  = ref(false)
const error    = ref('')
const formRef  = ref<HTMLFormElement | null>(null)

const form = ref({
  name:  '',
  phone: '',   // phone before email — RU market convention
  email: '',
})

const address = ref({
  city:        '',
  street:      '',
  building:    '',
  apartment:   '',
  postal_code: '',
  comment:     '',
})

const notes = ref('')

const errors  = ref<Record<string, string>>({})
const touched = ref<Record<string, boolean>>({})

const hasPhysical = computed(() => cartStore.items.some(i => i.type === 'physical'))

// ── Phone input handler (mask + cursor restore) ───────────────
function onPhoneInput(e: Event) {
  handlePhoneInput(e, (v) => {
    form.value.phone = v
    if (touched.value.phone) validateField('phone')
  })
}

// ── Touch + validate on blur ──────────────────────────────────
function touch(field: string) {
  touched.value[field] = true
  validateField(field)
}

// ── Per-field validation (only when touched) ──────────────────
function validateField(field: string) {
  const e = { ...errors.value }
  delete e[field]

  switch (field) {
    case 'name':
      if (touched.value.name) {
        if (!form.value.name.trim())                    e.name = 'Укажите имя'
        else if (form.value.name.trim().length < 2)    e.name = 'Минимум 2 символа'
      }
      break
    case 'phone':
      if (touched.value.phone) {
        if (!form.value.phone.trim())                   e.phone = 'Укажите телефон'
        else if (!isPhoneValid(form.value.phone))       e.phone = 'Введите номер полностью'
      }
      break
    case 'email':
      if (touched.value.email && form.value.email.trim() && !isEmailValid(form.value.email))
        e.email = 'Неверный формат email'
      break
    case 'address.city':
      if (touched.value['address.city'] && hasPhysical.value && !address.value.city.trim())
        e['address.city'] = 'Укажите город'
      break
    case 'address.street':
      if (touched.value['address.street'] && hasPhysical.value && !address.value.street.trim())
        e['address.street'] = 'Укажите улицу'
      break
    case 'address.building':
      if (touched.value['address.building'] && hasPhysical.value && !address.value.building.trim())
        e['address.building'] = 'Укажите дом'
      break
    case 'address.postal_code':
      if (touched.value['address.postal_code'] && hasPhysical.value) {
        if (!address.value.postal_code.trim())              e['address.postal_code'] = 'Укажите индекс'
        else if (!isPostalCodeValid(address.value.postal_code)) e['address.postal_code'] = 'Индекс — 6 цифр'
      }
      break
  }

  errors.value = e
}

// ── Show green checkmark ──────────────────────────────────────
function isFieldValid(field: string): boolean {
  if (!touched.value[field] || errors.value[field]) return false
  switch (field) {
    case 'name':              return form.value.name.trim().length >= 2
    case 'phone':             return isPhoneValid(form.value.phone)
    case 'email':             return form.value.email.trim() !== '' && isEmailValid(form.value.email)
    case 'address.city':      return address.value.city.trim().length > 0
    case 'address.street':    return address.value.street.trim().length > 0
    case 'address.building':  return address.value.building.trim().length > 0
    case 'address.postal_code': return isPostalCodeValid(address.value.postal_code)
    default:                  return false
  }
}

// ── Full validation on submit ─────────────────────────────────
function validate(): boolean {
  // Touch all required fields at once
  touched.value = { name: true, phone: true, email: true }
  if (hasPhysical.value) {
    touched.value['address.city']        = true
    touched.value['address.street']      = true
    touched.value['address.building']    = true
    touched.value['address.postal_code'] = true
  }

  const e: Record<string, string> = {}

  if (!form.value.name.trim())                 e.name  = 'Укажите имя'
  else if (form.value.name.trim().length < 2)  e.name  = 'Минимум 2 символа'

  if (!form.value.phone.trim())                e.phone = 'Укажите телефон'
  else if (!isPhoneValid(form.value.phone))    e.phone = 'Введите номер полностью'

  if (form.value.email.trim() && !isEmailValid(form.value.email))
    e.email = 'Неверный формат email'

  if (hasPhysical.value) {
    if (!address.value.city.trim())                          e['address.city']        = 'Укажите город'
    if (!address.value.street.trim())                        e['address.street']      = 'Укажите улицу'
    if (!address.value.building.trim())                      e['address.building']    = 'Укажите дом'
    if (!address.value.postal_code.trim())                   e['address.postal_code'] = 'Укажите индекс'
    else if (!isPostalCodeValid(address.value.postal_code))  e['address.postal_code'] = 'Индекс — 6 цифр'
  }

  errors.value = e
  return Object.keys(e).length === 0
}

// ── Scroll to first invalid field and focus it ────────────────
async function scrollToFirstError() {
  await nextTick()
  const el = formRef.value?.querySelector<HTMLElement>('[aria-invalid="true"]')
  if (el) {
    el.scrollIntoView({ behavior: 'smooth', block: 'center' })
    el.focus()
  }
}

// ── Submit ────────────────────────────────────────────────────
async function handleSubmit() {
  // Guard: empty cart (paranoia check)
  if (cartStore.isEmpty) return

  if (!validate()) {
    scrollToFirstError()
    return
  }

  loading.value = true
  error.value   = ''

  const payload: Record<string, any> = {
    items: cartStore.items.map(i => ({ product_id: i.id, quantity: i.quantity })),
    customer: {
      name:  form.value.name.trim(),
      email: form.value.email.trim() || null,
      phone: '+' + cleanPhone(form.value.phone),
    },
    notes:         notes.value.trim() || null,
    discount_code: cartStore.discount?.code ?? null,
  }

  if (hasPhysical.value) {
    payload.shipping_address = {
      city:        address.value.city.trim(),
      street:      address.value.street.trim(),
      building:    address.value.building.trim(),
      apartment:   address.value.apartment.trim() || null,
      postal_code: address.value.postal_code.trim(),
      comment:     address.value.comment.trim()    || null,
    }
  }

  try {
    const result = await shopStore.getApi().createOrder(payload)
    cartStore.clear()
    emit('success', result.data)
  } catch (e: any) {
    error.value = e.message || 'Ошибка оформления заказа'
    // Scroll to top so the error banner is visible
    await nextTick()
    formRef.value?.closest('.sb-co')?.scrollTo({ top: 0, behavior: 'smooth' })
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="sb-co">

    <!-- Back + title -->
    <div class="sb-flex sb-items-center sb-gap-3 sb-mb-4">
      <button type="button" class="sb-btn sb-btn-ghost" @click="emit('back')">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
        Назад
      </button>
      <h2 class="sb-title" style="margin-bottom: 0;">Оформление заказа</h2>
    </div>

    <!-- Step progress -->
    <div class="sb-co-progress sb-mb-4">
      <div class="sb-co-step sb-co-step-done">
        <span class="sb-co-step-circle">
          <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
            <path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
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

    <!-- API error banner (with role=alert so screen readers announce it) -->
    <div v-if="error" class="sb-alert-error sb-mb-4" role="alert">{{ error }}</div>

    <!-- novalidate: disables browser-native popups, custom validation only -->
    <form ref="formRef" @submit.prevent="handleSubmit" novalidate>
      <div class="sb-checkout-layout">

        <!-- ── Form ────────────────────────────────────────── -->
        <div class="sb-checkout-form">

          <!-- Contact data -->
          <div class="sb-checkout-section">
            <h3 class="sb-checkout-section-title">Контактные данные</h3>

            <!-- Name -->
            <div class="sb-field">
              <label class="sb-label" for="co-name">Имя *</label>
              <div class="sb-field-wrap">
                <input
                  id="co-name"
                  v-model="form.name"
                  type="text"
                  class="sb-input"
                  :class="{ 'sb-input-error': errors.name, 'sb-input-success': isFieldValid('name') }"
                  placeholder="Ваше имя"
                  autocomplete="name"
                  maxlength="100"
                  aria-required="true"
                  :aria-invalid="!!errors.name"
                  :aria-describedby="errors.name ? 'co-name-error' : undefined"
                  @blur="touch('name')"
                  @input="validateField('name')"
                />
                <svg v-if="isFieldValid('name')" class="sb-field-check" viewBox="0 0 20 20" fill="currentColor">
                  <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                </svg>
              </div>
              <p v-if="errors.name" id="co-name-error" class="sb-error-text" role="alert">{{ errors.name }}</p>
            </div>

            <!-- Phone — PRIMARY field for RU market, comes before email -->
            <div class="sb-field">
              <label class="sb-label" for="co-phone">Телефон *</label>
              <div class="sb-field-wrap">
                <input
                  id="co-phone"
                  :value="form.phone"
                  type="tel"
                  class="sb-input"
                  :class="{ 'sb-input-error': errors.phone, 'sb-input-success': isFieldValid('phone') }"
                  placeholder="+7 (___) ___-__-__"
                  autocomplete="tel"
                  inputmode="tel"
                  aria-required="true"
                  :aria-invalid="!!errors.phone"
                  :aria-describedby="errors.phone ? 'co-phone-error' : undefined"
                  @input="onPhoneInput"
                  @change="onPhoneInput"
                  @blur="touch('phone')"
                />
                <svg v-if="isFieldValid('phone')" class="sb-field-check" viewBox="0 0 20 20" fill="currentColor">
                  <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                </svg>
              </div>
              <p v-if="errors.phone" id="co-phone-error" class="sb-error-text" role="alert">{{ errors.phone }}</p>
            </div>

            <!-- Email — optional, after phone -->
            <div class="sb-field">
              <label class="sb-label" for="co-email">Email</label>
              <div class="sb-field-wrap">
                <input
                  id="co-email"
                  v-model="form.email"
                  type="email"
                  class="sb-input"
                  :class="{ 'sb-input-error': errors.email, 'sb-input-success': isFieldValid('email') }"
                  placeholder="email@example.com"
                  autocomplete="email"
                  maxlength="254"
                  :aria-invalid="errors.email ? true : undefined"
                  :aria-describedby="errors.email ? 'co-email-error' : undefined"
                  @blur="touch('email')"
                  @input="validateField('email')"
                />
                <svg v-if="isFieldValid('email')" class="sb-field-check" viewBox="0 0 20 20" fill="currentColor">
                  <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                </svg>
              </div>
              <p v-if="errors.email" id="co-email-error" class="sb-error-text" role="alert">{{ errors.email }}</p>
            </div>
          </div>

          <!-- Shipping address (only for physical items) -->
          <div v-if="hasPhysical" class="sb-checkout-section">
            <h3 class="sb-checkout-section-title">Адрес доставки</h3>

            <div class="sb-field">
              <label class="sb-label" for="co-city">Город *</label>
              <div class="sb-field-wrap">
                <input
                  id="co-city"
                  v-model="address.city"
                  type="text"
                  class="sb-input"
                  :class="{ 'sb-input-error': errors['address.city'], 'sb-input-success': isFieldValid('address.city') }"
                  placeholder="Москва"
                  autocomplete="address-level2"
                  maxlength="100"
                  aria-required="true"
                  :aria-invalid="!!errors['address.city']"
                  :aria-describedby="errors['address.city'] ? 'co-city-error' : undefined"
                  @blur="touch('address.city')"
                  @input="validateField('address.city')"
                />
                <svg v-if="isFieldValid('address.city')" class="sb-field-check" viewBox="0 0 20 20" fill="currentColor">
                  <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                </svg>
              </div>
              <p v-if="errors['address.city']" id="co-city-error" class="sb-error-text" role="alert">{{ errors['address.city'] }}</p>
            </div>

            <div class="sb-field">
              <label class="sb-label" for="co-street">Улица *</label>
              <div class="sb-field-wrap">
                <input
                  id="co-street"
                  v-model="address.street"
                  type="text"
                  class="sb-input"
                  :class="{ 'sb-input-error': errors['address.street'], 'sb-input-success': isFieldValid('address.street') }"
                  placeholder="ул. Ленина"
                  autocomplete="street-address"
                  maxlength="200"
                  aria-required="true"
                  :aria-invalid="!!errors['address.street']"
                  :aria-describedby="errors['address.street'] ? 'co-street-error' : undefined"
                  @blur="touch('address.street')"
                  @input="validateField('address.street')"
                />
                <svg v-if="isFieldValid('address.street')" class="sb-field-check" viewBox="0 0 20 20" fill="currentColor">
                  <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                </svg>
              </div>
              <p v-if="errors['address.street']" id="co-street-error" class="sb-error-text" role="alert">{{ errors['address.street'] }}</p>
            </div>

            <!-- Building / Apartment / Postal code row -->
            <div class="sb-field-row">
              <div class="sb-field">
                <label class="sb-label" for="co-building">Дом *</label>
                <div class="sb-field-wrap">
                  <input
                    id="co-building"
                    v-model="address.building"
                    type="text"
                    class="sb-input"
                    :class="{ 'sb-input-error': errors['address.building'], 'sb-input-success': isFieldValid('address.building') }"
                    placeholder="12"
                    autocomplete="off"
                    maxlength="20"
                    aria-required="true"
                    :aria-invalid="!!errors['address.building']"
                    :aria-describedby="errors['address.building'] ? 'co-building-error' : undefined"
                    @blur="touch('address.building')"
                    @input="validateField('address.building')"
                  />
                  <svg v-if="isFieldValid('address.building')" class="sb-field-check" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                  </svg>
                </div>
                <p v-if="errors['address.building']" id="co-building-error" class="sb-error-text" role="alert">{{ errors['address.building'] }}</p>
              </div>

              <div class="sb-field">
                <label class="sb-label" for="co-apartment">Квартира</label>
                <input
                  id="co-apartment"
                  v-model="address.apartment"
                  type="text"
                  class="sb-input"
                  placeholder="34"
                  autocomplete="off"
                  inputmode="numeric"
                  maxlength="10"
                />
              </div>

              <div class="sb-field">
                <label class="sb-label" for="co-postal">Индекс *</label>
                <div class="sb-field-wrap">
                  <input
                    id="co-postal"
                    v-model="address.postal_code"
                    type="text"
                    class="sb-input"
                    :class="{ 'sb-input-error': errors['address.postal_code'], 'sb-input-success': isFieldValid('address.postal_code') }"
                    placeholder="101000"
                    autocomplete="postal-code"
                    inputmode="numeric"
                    maxlength="6"
                    aria-required="true"
                    :aria-invalid="!!errors['address.postal_code']"
                    :aria-describedby="errors['address.postal_code'] ? 'co-postal-error' : undefined"
                    @blur="touch('address.postal_code')"
                    @input="validateField('address.postal_code')"
                  />
                  <svg v-if="isFieldValid('address.postal_code')" class="sb-field-check" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                  </svg>
                </div>
                <p v-if="errors['address.postal_code']" id="co-postal-error" class="sb-error-text" role="alert">{{ errors['address.postal_code'] }}</p>
              </div>
            </div>

            <div class="sb-field">
              <label class="sb-label" for="co-delivery-comment">Комментарий к доставке</label>
              <textarea
                id="co-delivery-comment"
                v-model="address.comment"
                class="sb-input"
                rows="2"
                placeholder="Код домофона, подъезд..."
                maxlength="500"
              />
            </div>
          </div>

          <!-- Order notes -->
          <div class="sb-checkout-section">
            <div class="sb-field">
              <label class="sb-label" for="co-notes">Комментарий к заказу</label>
              <textarea
                id="co-notes"
                v-model="notes"
                class="sb-input"
                rows="2"
                placeholder="Пожелания..."
                maxlength="500"
              />
            </div>
          </div>

        </div>

        <!-- ── Order summary (sticky on desktop) ───────────── -->
        <div class="sb-checkout-summary">
          <h3 class="sb-checkout-section-title">Ваш заказ</h3>

          <div class="sb-summary-items">
            <div v-for="item in cartStore.items" :key="item.id" class="sb-summary-item">
              <span class="sb-summary-item-name">{{ item.name }} × {{ item.quantity }}</span>
              <span class="sb-summary-item-price">{{ formatPrice(item.price * item.quantity) }}</span>
            </div>
          </div>

          <div class="sb-divider"></div>

          <div v-if="cartStore.discount" class="sb-cart-subtotal">
            <span>Товары</span>
            <span>{{ formatPrice(cartStore.total) }}</span>
          </div>
          <div v-if="cartStore.discount" class="sb-cart-discount-row">
            <span>Скидка</span>
            <span class="sb-cart-discount-amount">−{{ formatPrice(cartStore.discount.amount) }}</span>
          </div>
          <div class="sb-summary-total">
            <span>{{ cartStore.discount ? 'К оплате:' : 'Итого:' }}</span>
            <span class="sb-price-lg">{{ formatPrice(cartStore.discount ? cartStore.totalAfterDiscount : cartStore.total) }}</span>
          </div>

          <!-- Desktop submit (inside form → Enter key works) -->
          <button
            type="submit"
            class="sb-btn sb-btn-primary sb-btn-block sb-mt-4 sb-co-submit-desktop"
            :disabled="loading"
          >
            {{ loading ? 'Оформление...' : 'Подтвердить заказ' }}
          </button>
        </div>

      </div>
    </form>

    <!-- Mobile sticky footer (outside form — type="button" is required) -->
    <div class="sb-co-footer">
      <div class="sb-co-footer-price">
        <span class="sb-co-footer-label">{{ cartStore.discount ? 'К оплате' : 'Итого' }}</span>
        <span class="sb-co-footer-amount">{{ formatPrice(cartStore.discount ? cartStore.totalAfterDiscount : cartStore.total) }}</span>
      </div>
      <button
        type="button"
        class="sb-btn sb-btn-primary"
        :disabled="loading"
        @click="handleSubmit"
      >
        {{ loading ? '...' : 'Подтвердить' }}
      </button>
    </div>

  </div>
</template>
