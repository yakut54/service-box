<script setup lang="ts">
import { ref, computed, nextTick, onMounted, watch, unref } from 'vue'
import { useCartStore } from '@/stores/cart'
import { useShopStore } from '@/stores/shop'
import { formatPrice, cleanPhone, isPhoneValid, isEmailValid, isPostalCodeValid } from '@/lib/utils'
import { handlePhoneInput } from '@/lib/phoneInput'
import type { WidgetOrder, DeliveryMethodKey } from '@/types'
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

// 1 = Contacts, 2 = Delivery / Address, 3 = Confirm
const checkoutStep = ref(1)

const progressKey = () => `sb_co_progress:${shopStore.shopId}`

function saveProgress() {
  try {
    localStorage.setItem(progressKey(), JSON.stringify({
      step: checkoutStep.value,
      form: form.value,
      address: address.value,
      notes: notes.value,
      deliveryMethod: deliveryMethod.value,
    }))
  } catch {}
}

function clearProgress() {
  try { localStorage.removeItem(progressKey()) } catch {}
}

const consentBlock = ref<InstanceType<typeof ConsentBlock> | null>(null)

const form = ref({
  name:  '',
  phone: '',
  email: '',
})

const address = ref({
  city:        '',
  street:      '',
  building:    '',
  apartment:   '',
  postal_code: '',
})

const notes          = ref('')
const deliveryMethod = ref<DeliveryMethodKey | ''>('')

// ── Delivery ────────────────────────────────────────────────────────────────

const deliveryOptions = computed(() => shopStore.shop?.delivery_settings ?? null)
const hasDeliveryOptions = computed(() => !!deliveryOptions.value && Object.keys(deliveryOptions.value).length > 0)

const deliveryCost = computed(() => {
  if (!deliveryMethod.value || !deliveryOptions.value) return 0
  const m = deliveryOptions.value[deliveryMethod.value as DeliveryMethodKey]
  if (!m) return 0
  const price    = m.price ?? 0
  const freeFrom = m.free_from ?? null
  if (price === 0) return 0
  if (freeFrom !== null && cartStore.totalAfterDiscount >= freeFrom) return 0
  return price
})

const needsAddress = computed(() =>
  deliveryMethod.value === 'courier' || deliveryMethod.value === 'postal'
)

const grandTotal = computed(() => cartStore.totalAfterDiscount + deliveryCost.value)

// ── Computed ─────────────────────────────────────────────────────────────────

const hasPhysical = computed(() => cartStore.items.some(i => i.type === 'physical'))

const progressSteps = computed(() => {
  const steps = [
    { key: 'cart',     label: 'Корзина',  done: true,                   active: false },
    { key: 'contacts', label: 'Контакты', done: checkoutStep.value > 1, active: checkoutStep.value === 1 },
  ]
  if (hasPhysical.value)
    steps.push({ key: 'delivery', label: 'Доставка', done: checkoutStep.value > 2, active: checkoutStep.value === 2 })
  steps.push({ key: 'confirm', label: 'Заказ', done: false, active: checkoutStep.value === 3 })
  return steps
})

// ── Lifecycle ────────────────────────────────────────────────────────────────

onMounted(() => {
  try {
    const raw = localStorage.getItem(progressKey())
    if (raw) {
      const saved = JSON.parse(raw)
      if (saved.form)              Object.assign(form.value, saved.form)
      if (saved.address)           Object.assign(address.value, saved.address)
      if (saved.notes !== undefined) notes.value = saved.notes
      if (saved.step)              checkoutStep.value = saved.step
      if (saved.deliveryMethod)    deliveryMethod.value = saved.deliveryMethod
    }
  } catch {}

  if (shopStore.prefillName)  form.value.name  = shopStore.prefillName
  if (shopStore.prefillPhone) form.value.phone = shopStore.prefillPhone
  if (shopStore.prefillEmail) form.value.email = shopStore.prefillEmail

  watch([checkoutStep, form, address, notes, deliveryMethod], saveProgress, { deep: true })
})

// ── Validation ───────────────────────────────────────────────────────────────

const { formErrors: errors, formTouched: touched, touch: touchField, clearError, isFieldValid: checkValid } = useFormValidation()

function onPhoneInput(e: Event) {
  handlePhoneInput(e, (v) => {
    form.value.phone = v
    if (touched.value.phone) validateField('phone')
  })
}

function touch(field: string) { touchField(field, validateField) }

function validateField(field: string) {
  clearError(field)
  switch (field) {
    case 'name':
      if (touched.value.name) {
        if (!form.value.name.trim())                errors.value.name = 'Укажите имя'
        else if (form.value.name.trim().length < 2) errors.value.name = 'Минимум 2 символа'
      }
      break
    case 'phone':
      if (touched.value.phone) {
        if (!form.value.phone.trim())             errors.value.phone = 'Укажите телефон'
        else if (!isPhoneValid(form.value.phone)) errors.value.phone = 'Введите номер полностью'
      }
      break
    case 'email':
      if (touched.value.email && form.value.email.trim() && !isEmailValid(form.value.email))
        errors.value.email = 'Неверный формат email'
      break
    case 'address.city':
      if (touched.value['address.city'] && needsAddress.value && !address.value.city.trim())
        errors.value['address.city'] = 'Укажите город'
      break
    case 'address.street':
      if (touched.value['address.street'] && needsAddress.value && !address.value.street.trim())
        errors.value['address.street'] = 'Укажите улицу'
      break
    case 'address.building':
      if (touched.value['address.building'] && needsAddress.value && !address.value.building.trim())
        errors.value['address.building'] = 'Укажите дом'
      break
    case 'address.postal_code':
      if (touched.value['address.postal_code'] && address.value.postal_code.trim() && !isPostalCodeValid(address.value.postal_code))
        errors.value['address.postal_code'] = 'Индекс — 6 цифр'
      break
  }
}

function isFieldValid(field: string): boolean {
  switch (field) {
    case 'name':                return checkValid(field, () => form.value.name.trim().length >= 2)
    case 'phone':               return checkValid(field, () => isPhoneValid(form.value.phone))
    case 'email':               return checkValid(field, () => form.value.email.trim() !== '' && isEmailValid(form.value.email))
    case 'address.city':        return checkValid(field, () => address.value.city.trim().length > 0)
    case 'address.street':      return checkValid(field, () => address.value.street.trim().length > 0)
    case 'address.building':    return checkValid(field, () => address.value.building.trim().length > 0)
    case 'address.postal_code': return checkValid(field, () => isPostalCodeValid(address.value.postal_code))
    default:                    return false
  }
}

async function scrollToFirstError() {
  await nextTick()
  const el = formRef.value?.querySelector<HTMLElement>('[aria-invalid="true"]')
  if (el) {
    el.scrollIntoView({ behavior: 'smooth', block: 'center' })
    el.focus()
  }
}

function validateStep1(): boolean {
  touched.value = { ...touched.value, name: true, phone: true, email: true }
  const e: Record<string, string> = {}
  if (!form.value.name.trim())                e.name  = 'Укажите имя'
  else if (form.value.name.trim().length < 2) e.name  = 'Минимум 2 символа'
  if (!form.value.phone.trim())               e.phone = 'Укажите телефон'
  else if (!isPhoneValid(form.value.phone))   e.phone = 'Введите номер полностью'
  if (form.value.email.trim() && !isEmailValid(form.value.email))
    e.email = 'Неверный формат email'
  errors.value = { ...errors.value, ...e }
  return Object.keys(e).length === 0
}

function validateStep2(): boolean {
  const e: Record<string, string> = {}

  // When delivery options exist, method selection is required
  if (hasDeliveryOptions.value) {
    if (!deliveryMethod.value) {
      e['delivery.method'] = 'Выберите способ доставки'
      errors.value = { ...errors.value, ...e }
      return false
    }
    // Address required only for courier/postal
    if (needsAddress.value) {
      touched.value = { ...touched.value, 'address.city': true, 'address.street': true, 'address.building': true }
      if (!address.value.city.trim())     e['address.city']     = 'Укажите город'
      if (!address.value.street.trim())   e['address.street']   = 'Укажите улицу'
      if (!address.value.building.trim()) e['address.building'] = 'Укажите дом'
      if (address.value.postal_code.trim() && !isPostalCodeValid(address.value.postal_code))
        e['address.postal_code'] = 'Индекс — 6 цифр'
    }
  } else {
    // No delivery configured — require address as before
    touched.value = { ...touched.value, 'address.city': true, 'address.street': true, 'address.building': true }
    if (!address.value.city.trim())     e['address.city']     = 'Укажите город'
    if (!address.value.street.trim())   e['address.street']   = 'Укажите улицу'
    if (!address.value.building.trim()) e['address.building'] = 'Укажите дом'
    if (address.value.postal_code.trim() && !isPostalCodeValid(address.value.postal_code))
      e['address.postal_code'] = 'Индекс — 6 цифр'
  }

  errors.value = { ...errors.value, ...e }
  return Object.keys(e).length === 0
}

// ── Navigation ───────────────────────────────────────────────────────────────

async function goNext() {
  if (checkoutStep.value === 1) {
    if (!validateStep1()) { scrollToFirstError(); return }
    checkoutStep.value = hasPhysical.value ? 2 : 3
  } else if (checkoutStep.value === 2) {
    if (!validateStep2()) { scrollToFirstError(); return }
    checkoutStep.value = 3
  }
  await nextTick()
  formRef.value?.closest('.sb-co')?.scrollTo({ top: 0, behavior: 'smooth' })
}

async function goBack() {
  if (checkoutStep.value === 3)      checkoutStep.value = hasPhysical.value ? 2 : 1
  else if (checkoutStep.value === 2) checkoutStep.value = 1
  else                               emit('back')
  await nextTick()
  formRef.value?.closest('.sb-co')?.scrollTo({ top: 0, behavior: 'smooth' })
}

function handleFormSubmit() {
  if (checkoutStep.value < 3) goNext()
  else handleSubmit()
}

// ── Submit ───────────────────────────────────────────────────────────────────

async function handleSubmit() {
  if (cartStore.isEmpty) return
  if (!consentBlock.value?.validate()) return

  loading.value = true
  error.value   = ''

  const payload: Record<string, unknown> = {
    items: cartStore.items.map(i => ({ product_id: i.id, quantity: i.quantity })),
    customer: {
      name:  form.value.name.trim(),
      email: form.value.email.trim() || null,
      phone: '+' + cleanPhone(form.value.phone),
    },
    notes:                    notes.value.trim() || null,
    discount_code:            cartStore.discount?.code ?? null,
    consent_offer_accepted:   unref(consentBlock.value?.consentOffer) ?? false,
    consent_privacy_accepted: unref(consentBlock.value?.consentPrivacy) ?? false,
  }

  if (hasPhysical.value) {
    if (deliveryMethod.value) {
      payload.delivery_method = deliveryMethod.value
      payload.delivery_price  = deliveryCost.value
    }

    if (needsAddress.value || (!hasDeliveryOptions.value)) {
      payload.shipping_address = {
        city:        address.value.city.trim(),
        street:      address.value.street.trim(),
        building:    address.value.building.trim(),
        apartment:   address.value.apartment.trim() || null,
        postal_code: address.value.postal_code.trim(),
      }
    }
  }

  try {
    const result = await shopStore.getApi().createOrder(payload)
    cartStore.clear()
    clearProgress()
    emit('success', result.data)
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Ошибка оформления заказа'
    await nextTick()
    formRef.value?.closest('.sb-co')?.scrollTo({ top: 0, behavior: 'smooth' })
  } finally {
    loading.value = false
  }
}

// ── Helpers ──────────────────────────────────────────────────────────────────

const methodLabels: Record<string, string> = {
  pickup:  'Самовывоз',
  courier: 'Курьер',
  postal:  'Почта / СДЭК',
}

function deliveryMethodPrice(key: string): string {
  const m = deliveryOptions.value?.[key as DeliveryMethodKey]
  if (!m) return ''
  const price    = m.price ?? 0
  const freeFrom = m.free_from ?? null
  if (price === 0) return 'Бесплатно'
  if (freeFrom !== null && cartStore.totalAfterDiscount >= freeFrom) return 'Бесплатно'
  if (freeFrom !== null) return `${formatPrice(price)} · бесплатно от ${formatPrice(freeFrom)}`
  return formatPrice(price)
}
</script>

<template>
  <div class="sb-co">

    <!-- Back + title -->
    <div class="sb-flex sb-items-center sb-gap-3 sb-mb-4">
      <SbButton variant="ghost" @click="goBack">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
        Назад
      </SbButton>
      <h2 class="sb-title" style="margin-bottom: 0;">Оформление заказа</h2>
    </div>

    <!-- Step progress -->
    <div class="sb-co-progress sb-mb-4">
      <template v-for="(step, i) in progressSteps" :key="step.key">
        <div :class="['sb-co-step', step.done && 'sb-co-step-done', step.active && 'sb-co-step-active']">
          <span class="sb-co-step-circle">
            <svg v-if="step.done" width="12" height="12" viewBox="0 0 12 12" fill="none">
              <path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <template v-else>{{ i + 1 }}</template>
          </span>
          <span class="sb-co-step-label">{{ step.label }}</span>
        </div>
        <div v-if="i < progressSteps.length - 1" :class="['sb-co-line', step.done && 'sb-co-line-done']"></div>
      </template>
    </div>

    <!-- API error -->
    <div v-if="error" class="sb-alert-error sb-mb-4" role="alert">{{ error }}</div>

    <form ref="formRef" @submit.prevent="handleFormSubmit" novalidate>

      <!-- ── Step 1: Contacts ──────────────────────────────────────── -->
      <div v-if="checkoutStep === 1" class="sb-checkout-section">
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

        <SbButton type="submit" block class="sb-mt-4 sb-co-submit-desktop">Далее →</SbButton>
      </div>

      <!-- ── Step 2: Delivery ──────────────────────────────────────── -->
      <div v-if="checkoutStep === 2" class="sb-checkout-section">

        <!-- With delivery options configured -->
        <template v-if="hasDeliveryOptions">
          <h3 class="sb-checkout-section-title">Способ доставки</h3>

          <div class="sb-delivery-methods">
            <label
              v-for="(method, key) in deliveryOptions"
              :key="key"
              class="sb-delivery-option"
              :class="{ 'sb-delivery-option--active': deliveryMethod === key }"
            >
              <input
                type="radio"
                :value="key"
                v-model="deliveryMethod"
                style="display:none"
              />
              <span class="sb-delivery-option-radio">
                <span v-if="deliveryMethod === key" class="sb-delivery-option-dot" />
              </span>
              <span class="sb-delivery-option-body">
                <span class="sb-delivery-option-name">{{ methodLabels[key] ?? key }}</span>
                <span v-if="key === 'pickup' && method?.address" class="sb-delivery-option-desc">{{ method?.address }}</span>
              </span>
              <span class="sb-delivery-option-price">{{ deliveryMethodPrice(key) }}</span>
            </label>
          </div>

          <p v-if="errors['delivery.method']" class="sb-field-error sb-mt-2" role="alert">
            {{ errors['delivery.method'] }}
          </p>

          <!-- Address form: only for courier / postal -->
          <template v-if="needsAddress">
            <h3 class="sb-checkout-section-title" style="margin-top: 20px;">Адрес доставки</h3>
            <SbField label="Город *" label-for="co-city" :error="errors['address.city']" error-id="co-city-error" :valid="isFieldValid('address.city')">
              <input id="co-city" v-model="address.city" type="text" class="sb-input" :class="{ 'sb-input-error': errors['address.city'] }" placeholder="Москва" autocomplete="address-level2" maxlength="100" aria-required="true" :aria-invalid="!!errors['address.city']" :aria-describedby="errors['address.city'] ? 'co-city-error' : undefined" @blur="touch('address.city')" @input="validateField('address.city')" />
            </SbField>
            <SbField label="Улица *" label-for="co-street" :error="errors['address.street']" error-id="co-street-error" :valid="isFieldValid('address.street')">
              <input id="co-street" v-model="address.street" type="text" class="sb-input" :class="{ 'sb-input-error': errors['address.street'] }" placeholder="ул. Ленина" autocomplete="off" maxlength="200" aria-required="true" :aria-invalid="!!errors['address.street']" :aria-describedby="errors['address.street'] ? 'co-street-error' : undefined" @blur="touch('address.street')" @input="validateField('address.street')" />
            </SbField>
            <div class="sb-field-row">
              <SbField label="Дом *" label-for="co-building" :error="errors['address.building']" error-id="co-building-error" :valid="isFieldValid('address.building')">
                <input id="co-building" v-model="address.building" type="text" class="sb-input" :class="{ 'sb-input-error': errors['address.building'] }" placeholder="12" autocomplete="off" maxlength="20" aria-required="true" :aria-invalid="!!errors['address.building']" :aria-describedby="errors['address.building'] ? 'co-building-error' : undefined" @blur="touch('address.building')" @input="validateField('address.building')" />
              </SbField>
              <SbField label="Квартира" label-for="co-apartment">
                <input id="co-apartment" v-model="address.apartment" type="text" class="sb-input" placeholder="34" autocomplete="off" inputmode="numeric" maxlength="10" />
              </SbField>
              <SbField label="Индекс" label-for="co-postal" :error="errors['address.postal_code']" error-id="co-postal-error" :valid="isFieldValid('address.postal_code')">
                <input id="co-postal" v-model="address.postal_code" type="text" class="sb-input" :class="{ 'sb-input-error': errors['address.postal_code'] }" placeholder="101000" autocomplete="postal-code" inputmode="numeric" maxlength="6" :aria-invalid="!!errors['address.postal_code']" :aria-describedby="errors['address.postal_code'] ? 'co-postal-error' : undefined" @blur="touch('address.postal_code')" @input="validateField('address.postal_code')" />
              </SbField>
            </div>
          </template>
        </template>

        <!-- No delivery configured — classic address form -->
        <template v-else>
          <h3 class="sb-checkout-section-title">Адрес доставки</h3>
          <SbField label="Город *" label-for="co-city" :error="errors['address.city']" error-id="co-city-error" :valid="isFieldValid('address.city')">
            <input id="co-city" v-model="address.city" type="text" class="sb-input" :class="{ 'sb-input-error': errors['address.city'] }" placeholder="Москва" autocomplete="address-level2" maxlength="100" aria-required="true" :aria-invalid="!!errors['address.city']" :aria-describedby="errors['address.city'] ? 'co-city-error' : undefined" @blur="touch('address.city')" @input="validateField('address.city')" />
          </SbField>
          <SbField label="Улица *" label-for="co-street" :error="errors['address.street']" error-id="co-street-error" :valid="isFieldValid('address.street')">
            <input id="co-street" v-model="address.street" type="text" class="sb-input" :class="{ 'sb-input-error': errors['address.street'] }" placeholder="ул. Ленина" autocomplete="off" maxlength="200" aria-required="true" :aria-invalid="!!errors['address.street']" :aria-describedby="errors['address.street'] ? 'co-street-error' : undefined" @blur="touch('address.street')" @input="validateField('address.street')" />
          </SbField>
          <div class="sb-field-row">
            <SbField label="Дом *" label-for="co-building" :error="errors['address.building']" error-id="co-building-error" :valid="isFieldValid('address.building')">
              <input id="co-building" v-model="address.building" type="text" class="sb-input" :class="{ 'sb-input-error': errors['address.building'] }" placeholder="12" autocomplete="off" maxlength="20" aria-required="true" :aria-invalid="!!errors['address.building']" :aria-describedby="errors['address.building'] ? 'co-building-error' : undefined" @blur="touch('address.building')" @input="validateField('address.building')" />
            </SbField>
            <SbField label="Квартира" label-for="co-apartment">
              <input id="co-apartment" v-model="address.apartment" type="text" class="sb-input" placeholder="34" autocomplete="off" inputmode="numeric" maxlength="10" />
            </SbField>
            <SbField label="Индекс" label-for="co-postal" :error="errors['address.postal_code']" error-id="co-postal-error" :valid="isFieldValid('address.postal_code')">
              <input id="co-postal" v-model="address.postal_code" type="text" class="sb-input" :class="{ 'sb-input-error': errors['address.postal_code'] }" placeholder="101000" autocomplete="postal-code" inputmode="numeric" maxlength="6" :aria-invalid="!!errors['address.postal_code']" :aria-describedby="errors['address.postal_code'] ? 'co-postal-error' : undefined" @blur="touch('address.postal_code')" @input="validateField('address.postal_code')" />
            </SbField>
          </div>
        </template>

        <SbButton type="submit" block class="sb-mt-4 sb-co-submit-desktop">Далее →</SbButton>
      </div>

      <!-- ── Step 3: Confirm ──────────────────────────────────────── -->
      <div v-if="checkoutStep === 3">

        <!-- Order summary -->
        <div class="sb-checkout-section">
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
          <div v-if="deliveryCost > 0" class="sb-cart-subtotal">
            <span>{{ methodLabels[deliveryMethod] ?? 'Доставка' }}</span>
            <span>{{ formatPrice(deliveryCost) }}</span>
          </div>
          <div v-if="deliveryMethod && deliveryCost === 0 && hasDeliveryOptions" class="sb-cart-subtotal">
            <span>{{ methodLabels[deliveryMethod] ?? 'Доставка' }}</span>
            <span style="color: var(--sb-success);">Бесплатно</span>
          </div>
          <div class="sb-summary-total">
            <span>{{ (cartStore.discount || deliveryCost > 0) ? 'К оплате:' : 'Итого:' }}</span>
            <span class="sb-price-lg">{{ formatPrice(grandTotal) }}</span>
          </div>
        </div>

        <!-- Delivery review -->
        <div v-if="hasPhysical" class="sb-checkout-section">
          <div class="sb-flex" style="align-items: flex-start; justify-content: space-between; gap: 8px;">
            <div>
              <div style="font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px; opacity: .6;">
                {{ deliveryMethod ? 'Доставка' : 'Адрес доставки' }}
              </div>
              <div v-if="deliveryMethod" style="font-size: 13px; font-weight: 600;">
                {{ methodLabels[deliveryMethod] ?? deliveryMethod }}
              </div>
              <div v-if="needsAddress || (!hasDeliveryOptions)" style="font-size: 13px; margin-top: 2px;">
                {{ address.city }}, {{ address.street }}, д. {{ address.building }}<template v-if="address.apartment">, кв. {{ address.apartment }}</template><template v-if="address.postal_code">, {{ address.postal_code }}</template>
              </div>
              <div v-if="deliveryMethod === 'pickup' && deliveryOptions?.[deliveryMethod]?.address" style="font-size: 13px; color: var(--sb-text-muted);">
                {{ deliveryOptions?.[deliveryMethod]?.address }}
              </div>
            </div>
            <SbButton variant="ghost" type="button" style="flex-shrink: 0; padding: 4px 8px; font-size: 12px;" @click="goBack">Изменить</SbButton>
          </div>
        </div>

        <!-- Notes -->
        <div class="sb-checkout-section">
          <SbField label="Комментарий к заказу" label-for="co-notes">
            <textarea id="co-notes" v-model="notes" class="sb-input" rows="2" placeholder="Пожелания..." maxlength="500" />
          </SbField>
        </div>

        <ConsentBlock ref="consentBlock" />

        <SbButton type="submit" block class="sb-mt-4 sb-co-submit-desktop" :disabled="loading">
          {{ loading ? 'Оформление...' : 'Подтвердить заказ' }}
        </SbButton>

      </div>

    </form>

    <!-- Mobile sticky footer -->
    <div class="sb-co-footer">
      <div class="sb-co-footer-price">
        <span class="sb-co-footer-label">{{ (cartStore.discount || deliveryCost > 0) ? 'К оплате' : 'Итого' }}</span>
        <span class="sb-co-footer-amount">{{ formatPrice(grandTotal) }}</span>
      </div>
      <SbButton v-if="checkoutStep < 3" @click="goNext">Далее →</SbButton>
      <SbButton v-else :disabled="loading" @click="handleSubmit">
        {{ loading ? '...' : 'Подтвердить' }}
      </SbButton>
    </div>

  </div>
</template>
