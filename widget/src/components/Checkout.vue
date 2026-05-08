<script setup lang="ts">
import { ref, computed, nextTick } from 'vue'
import { useCartStore } from '@/stores/cart'
import { useShopStore } from '@/stores/shop'
import { formatPrice, cleanPhone, isPhoneValid, isEmailValid, isPostalCodeValid } from '@/lib/utils'
import { handlePhoneInput } from '@/lib/phoneInput'
import type { WidgetOrder } from '@/types'
import ConsentBlock from '@/components/ConsentBlock.vue'
import SbField from '@/components/SbField.vue'
import SbButton from '@/components/SbButton.vue'
import { useFormValidation } from '@/composables/useFormValidation'

const emit = defineEmits<{
  back: []
  success: [order: WidgetOrder]
}>()

const cartStore = useCartStore()
const shopStore = useShopStore()

const loading  = ref(false)
const error    = ref('')
const formRef  = ref<HTMLFormElement | null>(null)

// ── Согласия ──────────────────────────────────────────────────
const consentOffer   = ref(false)
const consentPrivacy = ref(false)

// ── Модал юридического документа ─────────────────────────────

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

const { formErrors: errors, formTouched: touched, touch: touchField, clearError, isFieldValid: checkValid } = useFormValidation()

const hasPhysical = computed(() => cartStore.items.some(i => i.type === 'physical'))

// ── Phone input handler (mask + cursor restore) ───────────────
function onPhoneInput(e: Event) {
  handlePhoneInput(e, (v) => {
    form.value.phone = v
    if (touched.value.phone) validateField('phone')
  })
}

// ── Touch + validate on blur ──────────────────────────────────
function touch(field: string) { touchField(field, validateField) }

// ── Per-field validation (only when touched) ──────────────────
function validateField(field: string) {
  clearError(field)

  switch (field) {
    case 'name':
      if (touched.value.name) {
        if (!form.value.name.trim())                 errors.value.name = 'Укажите имя'
        else if (form.value.name.trim().length < 2)  errors.value.name = 'Минимум 2 символа'
      }
      break
    case 'phone':
      if (touched.value.phone) {
        if (!form.value.phone.trim())               errors.value.phone = 'Укажите телефон'
        else if (!isPhoneValid(form.value.phone))   errors.value.phone = 'Введите номер полностью'
      }
      break
    case 'email':
      if (touched.value.email && form.value.email.trim() && !isEmailValid(form.value.email))
        errors.value.email = 'Неверный формат email'
      break
    case 'address.city':
      if (touched.value['address.city'] && hasPhysical.value && !address.value.city.trim())
        errors.value['address.city'] = 'Укажите город'
      break
    case 'address.street':
      if (touched.value['address.street'] && hasPhysical.value && !address.value.street.trim())
        errors.value['address.street'] = 'Укажите улицу'
      break
    case 'address.building':
      if (touched.value['address.building'] && hasPhysical.value && !address.value.building.trim())
        errors.value['address.building'] = 'Укажите дом'
      break
    case 'address.postal_code':
      if (touched.value['address.postal_code'] && hasPhysical.value) {
        if (!address.value.postal_code.trim())                   errors.value['address.postal_code'] = 'Укажите индекс'
        else if (!isPostalCodeValid(address.value.postal_code))  errors.value['address.postal_code'] = 'Индекс — 6 цифр'
      }
      break
  }
}

// ── Show green checkmark ──────────────────────────────────────
function isFieldValid(field: string): boolean {
  switch (field) {
    case 'name':               return checkValid(field, () => form.value.name.trim().length >= 2)
    case 'phone':              return checkValid(field, () => isPhoneValid(form.value.phone))
    case 'email':              return checkValid(field, () => form.value.email.trim() !== '' && isEmailValid(form.value.email))
    case 'address.city':       return checkValid(field, () => address.value.city.trim().length > 0)
    case 'address.street':     return checkValid(field, () => address.value.street.trim().length > 0)
    case 'address.building':   return checkValid(field, () => address.value.building.trim().length > 0)
    case 'address.postal_code':return checkValid(field, () => isPostalCodeValid(address.value.postal_code))
    default:                   return false
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

  const legal = shopStore.shop?.legal
  if (legal?.has_docs) {
    if (!consentOffer.value)   e.consentOffer   = 'Необходимо принять условия оферты'
    if (!consentPrivacy.value) e.consentPrivacy = 'Необходимо дать согласие на обработку данных'
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

  const payload: Record<string, unknown> = {
    items: cartStore.items.map(i => ({ product_id: i.id, quantity: i.quantity })),
    customer: {
      name:  form.value.name.trim(),
      email: form.value.email.trim() || null,
      phone: '+' + cleanPhone(form.value.phone),
    },
    notes:                   notes.value.trim() || null,
    discount_code:           cartStore.discount?.code ?? null,
    consent_offer_accepted:  consentOffer.value,
    consent_privacy_accepted: consentPrivacy.value,
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
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Ошибка оформления заказа'
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
      <SbButton variant="ghost" @click="emit('back')">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
        Назад
      </SbButton>
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

            <SbField label="Имя *" label-for="co-name" :error="errors.name" error-id="co-name-error" :valid="isFieldValid('name')">
              <input id="co-name" v-model="form.name" type="text" class="sb-input" :class="{ 'sb-input-error': errors.name }" placeholder="Ваше имя" autocomplete="name" maxlength="100" aria-required="true" :aria-invalid="!!errors.name" :aria-describedby="errors.name ? 'co-name-error' : undefined" @blur="touch('name')" @input="validateField('name')" />
            </SbField>

            <SbField label="Телефон *" label-for="co-phone" :error="errors.phone" error-id="co-phone-error" :valid="isFieldValid('phone')">
              <input id="co-phone" :value="form.phone" type="tel" class="sb-input" :class="{ 'sb-input-error': errors.phone }" placeholder="+7 (___) ___-__-__" autocomplete="tel" inputmode="tel" aria-required="true" :aria-invalid="!!errors.phone" :aria-describedby="errors.phone ? 'co-phone-error' : undefined" @input="onPhoneInput" @change="onPhoneInput" @blur="touch('phone')" />
            </SbField>

            <SbField label="Email" label-for="co-email" :error="errors.email" error-id="co-email-error" :valid="isFieldValid('email')">
              <input id="co-email" v-model="form.email" type="email" class="sb-input" :class="{ 'sb-input-error': errors.email }" placeholder="email@example.com" autocomplete="email" maxlength="254" :aria-invalid="errors.email ? true : undefined" :aria-describedby="errors.email ? 'co-email-error' : undefined" @blur="touch('email')" @input="validateField('email')" />
            </SbField>
          </div>

          <!-- Shipping address (only for physical items) -->
          <div v-if="hasPhysical" class="sb-checkout-section">
            <h3 class="sb-checkout-section-title">Адрес доставки</h3>

            <SbField label="Город *" label-for="co-city" :error="errors['address.city']" error-id="co-city-error" :valid="isFieldValid('address.city')">
              <input id="co-city" v-model="address.city" type="text" class="sb-input" :class="{ 'sb-input-error': errors['address.city'] }" placeholder="Москва" autocomplete="address-level2" maxlength="100" aria-required="true" :aria-invalid="!!errors['address.city']" :aria-describedby="errors['address.city'] ? 'co-city-error' : undefined" @blur="touch('address.city')" @input="validateField('address.city')" />
            </SbField>

            <SbField label="Улица *" label-for="co-street" :error="errors['address.street']" error-id="co-street-error" :valid="isFieldValid('address.street')">
              <input id="co-street" v-model="address.street" type="text" class="sb-input" :class="{ 'sb-input-error': errors['address.street'] }" placeholder="ул. Ленина" autocomplete="street-address" maxlength="200" aria-required="true" :aria-invalid="!!errors['address.street']" :aria-describedby="errors['address.street'] ? 'co-street-error' : undefined" @blur="touch('address.street')" @input="validateField('address.street')" />
            </SbField>

            <!-- Building / Apartment / Postal code row -->
            <div class="sb-field-row">
              <SbField label="Дом *" label-for="co-building" :error="errors['address.building']" error-id="co-building-error" :valid="isFieldValid('address.building')">
                <input id="co-building" v-model="address.building" type="text" class="sb-input" :class="{ 'sb-input-error': errors['address.building'] }" placeholder="12" autocomplete="off" maxlength="20" aria-required="true" :aria-invalid="!!errors['address.building']" :aria-describedby="errors['address.building'] ? 'co-building-error' : undefined" @blur="touch('address.building')" @input="validateField('address.building')" />
              </SbField>

              <SbField label="Квартира" label-for="co-apartment">
                <input id="co-apartment" v-model="address.apartment" type="text" class="sb-input" placeholder="34" autocomplete="off" inputmode="numeric" maxlength="10" />
              </SbField>

              <SbField label="Индекс *" label-for="co-postal" :error="errors['address.postal_code']" error-id="co-postal-error" :valid="isFieldValid('address.postal_code')">
                <input id="co-postal" v-model="address.postal_code" type="text" class="sb-input" :class="{ 'sb-input-error': errors['address.postal_code'] }" placeholder="101000" autocomplete="postal-code" inputmode="numeric" maxlength="6" aria-required="true" :aria-invalid="!!errors['address.postal_code']" :aria-describedby="errors['address.postal_code'] ? 'co-postal-error' : undefined" @blur="touch('address.postal_code')" @input="validateField('address.postal_code')" />
              </SbField>
            </div>

            <SbField label="Комментарий к доставке" label-for="co-delivery-comment">
              <textarea id="co-delivery-comment" v-model="address.comment" class="sb-input" rows="2" placeholder="Код домофона, подъезд..." maxlength="500" />
            </SbField>
          </div>

          <!-- Order notes -->
          <div class="sb-checkout-section">
            <SbField label="Комментарий к заказу" label-for="co-notes">
              <textarea id="co-notes" v-model="notes" class="sb-input" rows="2" placeholder="Пожелания..." maxlength="500" />
            </SbField>
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

          <!-- Согласия -->
          <ConsentBlock
            v-model:offer="consentOffer"
            v-model:privacy="consentPrivacy"
            :error-offer="errors.consentOffer"
            :error-privacy="errors.consentPrivacy"
          />

          <!-- Desktop submit (inside form → Enter key works) -->
          <SbButton
            type="submit"
            block
            class="sb-mt-4 sb-co-submit-desktop"
            :disabled="loading"
          >
            {{ loading ? 'Оформление...' : 'Подтвердить заказ' }}
          </SbButton>
        </div>

      </div>
    </form>

    <!-- Mobile sticky footer (outside form — type="button" is required) -->
    <div class="sb-co-footer">
      <div class="sb-co-footer-price">
        <span class="sb-co-footer-label">{{ cartStore.discount ? 'К оплате' : 'Итого' }}</span>
        <span class="sb-co-footer-amount">{{ formatPrice(cartStore.discount ? cartStore.totalAfterDiscount : cartStore.total) }}</span>
      </div>
      <SbButton
        :disabled="loading"
        @click="handleSubmit"
      >
        {{ loading ? '...' : 'Подтвердить' }}
      </SbButton>
    </div>

  </div>
</template>
