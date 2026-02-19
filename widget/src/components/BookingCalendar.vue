<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useShopStore } from '@/stores/shop'
import { formatPrice, cleanPhone, isPhoneValid, isEmailValid } from '@/lib/utils'
import { handlePhoneInput } from '@/lib/phoneInput'

const props = defineProps<{ product: any }>()
const emit = defineEmits<{ back: []; success: [booking: any] }>()

const shopStore = useShopStore()

type Slot = {
  time: string
  datetime: string
  available: boolean
  masters: { id: string; name: string }[]
}

const loading = ref(false)
const submitting = ref(false)
const error = ref('')
const slots = ref<Slot[]>([])
const selectedDate = ref(todayStr())
const selectedSlot = ref<Slot | null>(null)
const selectedMasterId = ref<string | null>(null)

const form = ref({
  name: '',
  phone: '',
  email: '',
  notes: '',
})

const formErrors = ref<Record<string, string>>({})
const formTouched = ref<Record<string, boolean>>({})

const durationMinutes = computed(() => props.product?.service?.duration_minutes ?? 60)

// ── Live validation ─────────────────────────────────────────
function touch(field: string) {
  formTouched.value[field] = true
  validateField(field)
}

function validateField(field: string) {
  const e = { ...formErrors.value }
  delete e[field]
  switch (field) {
    case 'name':
      if (formTouched.value.name && !form.value.name.trim()) e.name = 'Укажите имя'
      else if (formTouched.value.name && form.value.name.trim().length < 2) e.name = 'Минимум 2 символа'
      break
    case 'phone':
      if (formTouched.value.phone && !form.value.phone.trim()) e.phone = 'Укажите телефон'
      else if (formTouched.value.phone && !isPhoneValid(form.value.phone)) e.phone = 'Введите полный номер: +7 (XXX) XXX-XX-XX'
      break
    case 'email':
      if (formTouched.value.email && form.value.email.trim() && !isEmailValid(form.value.email)) e.email = 'Некорректный email'
      break
  }
  formErrors.value = e
}

function isFieldValid(field: string): boolean {
  if (!formTouched.value[field]) return false
  if (formErrors.value[field]) return false
  switch (field) {
    case 'name': return form.value.name.trim().length >= 2
    case 'phone': return isPhoneValid(form.value.phone)
    case 'email': return form.value.email.trim() !== '' && isEmailValid(form.value.email)
    default: return false
  }
}

function onPhoneInput(e: Event) {
  handlePhoneInput(e, (v) => {
    form.value.phone = v
    if (formTouched.value.phone) validateField('phone')
  })
}

function todayStr(): string {
  const d = new Date()
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const dd = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${dd}`
}

// Generate next 14 days for the date picker
const dateOptions = computed(() => {
  const days: { value: string; label: string; weekday: string }[] = []
  const now = new Date()
  for (let i = 0; i < 14; i++) {
    const d = new Date(now)
    d.setDate(d.getDate() + i)
    const value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
    const weekday = d.toLocaleDateString('ru-RU', { weekday: 'short' })
    const label = d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })
    days.push({ value, label, weekday })
  }
  return days
})

async function loadSlots() {
  loading.value = true
  error.value = ''
  selectedSlot.value = null
  selectedMasterId.value = null

  try {
    const result = await shopStore.getApi().getAvailableSlots({
      service_id: props.product.id,
      date: selectedDate.value,
    })
    slots.value = result.slots ?? []
  } catch (e: any) {
    error.value = e.message || 'Не удалось загрузить расписание'
    slots.value = []
  } finally {
    loading.value = false
  }
}

watch(selectedDate, () => loadSlots(), { immediate: true })

function selectSlot(slot: Slot) {
  if (!slot.available) return
  selectedSlot.value = slot
  // Auto-select first master if only one
  if (slot.masters.length === 1) {
    selectedMasterId.value = slot.masters[0].id
  } else {
    selectedMasterId.value = null
  }
}

const availableSlots = computed(() => slots.value.filter(s => s.available))
const unavailableSlots = computed(() => slots.value.filter(s => !s.available))

function validate(): boolean {
  formTouched.value = { name: true, phone: true, email: true }
  const e: Record<string, string> = {}
  if (!form.value.name.trim()) e.name = 'Укажите имя'
  else if (form.value.name.trim().length < 2) e.name = 'Минимум 2 символа'
  if (!form.value.phone.trim()) e.phone = 'Укажите телефон'
  else if (!isPhoneValid(form.value.phone)) e.phone = 'Введите полный номер: +7 (XXX) XXX-XX-XX'
  if (form.value.email.trim() && !isEmailValid(form.value.email)) e.email = 'Некорректный email'
  formErrors.value = e
  return Object.keys(e).length === 0
}

async function handleSubmit() {
  if (!selectedSlot.value) return
  if (!validate()) return

  submitting.value = true
  error.value = ''

  try {
    const payload: Record<string, any> = {
      service_id: props.product.id,
      start_time: selectedSlot.value.datetime,
      customer: {
        name: form.value.name.trim(),
        phone: form.value.phone.trim(),
        email: form.value.email.trim() || null,
      },
      notes: form.value.notes.trim() || null,
    }
    if (selectedMasterId.value) {
      payload.master_id = selectedMasterId.value
    }

    const result = await shopStore.getApi().createBooking(payload)
    emit('success', result.data)
  } catch (e: any) {
    error.value = e.message || 'Ошибка при записи'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="sb-booking">
    <!-- Header -->
    <div class="sb-flex sb-items-center sb-gap-3 sb-mb-4">
      <button class="sb-btn sb-btn-ghost" @click="emit('back')">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
        Назад
      </button>
      <h2 class="sb-title" style="margin-bottom: 0;">Запись на услугу</h2>
    </div>

    <!-- Service info -->
    <div class="sb-booking-service sb-mb-4">
      <div class="sb-flex sb-items-center sb-justify-between">
        <div>
          <h3 class="sb-booking-service-name">{{ product.name }}</h3>
          <span class="sb-badge sb-badge-info">{{ durationMinutes }} мин</span>
        </div>
        <span class="sb-price">{{ formatPrice(product.price) }}</span>
      </div>
    </div>

    <div v-if="error" class="sb-alert-error sb-mb-4">{{ error }}</div>

    <!-- Progress stepper (reuses .sb-co-progress CSS) -->
    <div class="sb-co-progress sb-mb-4">
      <div :class="['sb-co-step', selectedSlot ? 'sb-co-step-done' : 'sb-co-step-active']">
        <span class="sb-co-step-circle">
          <svg v-if="selectedSlot" width="12" height="12" viewBox="0 0 12 12" fill="none">
            <path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          <template v-else>1</template>
        </span>
        <span class="sb-co-step-label">Дата и время</span>
      </div>
      <div :class="['sb-co-line', selectedSlot ? 'sb-co-line-done' : '']"></div>
      <div :class="['sb-co-step', selectedSlot ? 'sb-co-step-active' : '']">
        <span class="sb-co-step-circle">2</span>
        <span class="sb-co-step-label">Ваши данные</span>
      </div>
      <div class="sb-co-line"></div>
      <div class="sb-co-step">
        <span class="sb-co-step-circle">3</span>
        <span class="sb-co-step-label">Готово</span>
      </div>
    </div>

    <!-- Step 1: Date & Slot -->
    <div v-if="!selectedSlot">
      <!-- Date picker (horizontal scroll) -->
      <div class="sb-booking-dates sb-mb-4">
        <button
          v-for="d in dateOptions"
          :key="d.value"
          class="sb-date-chip"
          :class="{ 'sb-date-chip-active': selectedDate === d.value }"
          @click="selectedDate = d.value"
        >
          <span class="sb-date-chip-weekday">{{ d.weekday }}</span>
          <span class="sb-date-chip-day">{{ d.label }}</span>
        </button>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="sb-booking-slots-loading">
        <div v-for="i in 6" :key="i" class="sb-skeleton sb-skeleton-slot"></div>
      </div>

      <!-- Slots grid -->
      <div v-else-if="availableSlots.length > 0" class="sb-booking-slots">
        <button
          v-for="slot in slots"
          :key="slot.time"
          class="sb-slot"
          :class="{ 'sb-slot-disabled': !slot.available }"
          :disabled="!slot.available"
          @click="selectSlot(slot)"
        >
          {{ slot.time }}
        </button>
      </div>

      <!-- No slots -->
      <div v-else class="sb-empty" style="padding: 24px;">
        <p class="sb-empty-title">Нет свободных слотов</p>
        <p class="sb-empty-text">Выберите другую дату</p>
      </div>
    </div>

    <!-- Step 2: Customer form -->
    <div v-else>
      <div class="sb-booking-selected sb-mb-4">
        <div class="sb-flex sb-items-center sb-justify-between">
          <div>
            <span class="sb-booking-selected-label">Выбрано:</span>
            <span class="sb-booking-selected-value">
              {{ new Date(selectedSlot.datetime).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' }) }},
              {{ selectedSlot.time }}
            </span>
          </div>
          <button class="sb-btn sb-btn-ghost" @click="selectedSlot = null">Изменить</button>
        </div>

        <!-- Master selection (if multiple) -->
        <div v-if="selectedSlot.masters.length > 1" class="sb-mt-4">
          <label class="sb-label">Выберите мастера</label>
          <div class="sb-master-list">
            <button
              v-for="m in selectedSlot.masters"
              :key="m.id"
              class="sb-master-chip"
              :class="{ 'sb-master-chip-active': selectedMasterId === m.id }"
              @click="selectedMasterId = m.id"
            >
              {{ m.name }}
            </button>
          </div>
        </div>
      </div>

      <form @submit.prevent="handleSubmit">
        <div class="sb-checkout-section">
          <h3 class="sb-checkout-section-title">Ваши данные</h3>

          <div class="sb-field">
            <label class="sb-label">Имя *</label>
            <div class="sb-field-wrap">
              <input
                v-model="form.name"
                type="text"
                class="sb-input"
                :class="{ 'sb-input-error': formErrors.name, 'sb-input-success': isFieldValid('name') }"
                placeholder="Ваше имя"
                @blur="touch('name')"
                @input="validateField('name')"
              />
              <svg v-if="isFieldValid('name')" class="sb-field-check" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
            </div>
            <p v-if="formErrors.name" class="sb-error-text">{{ formErrors.name }}</p>
          </div>

          <div class="sb-field">
            <label class="sb-label">Телефон *</label>
            <div class="sb-field-wrap">
              <input
                :value="form.phone"
                type="tel"
                class="sb-input"
                :class="{ 'sb-input-error': formErrors.phone, 'sb-input-success': isFieldValid('phone') }"
                placeholder="+7 (___) ___-__-__"
                @input="onPhoneInput"
                @blur="touch('phone')"
              />
              <svg v-if="isFieldValid('phone')" class="sb-field-check" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
            </div>
            <p v-if="formErrors.phone" class="sb-error-text">{{ formErrors.phone }}</p>
          </div>

          <div class="sb-field">
            <label class="sb-label">Email</label>
            <div class="sb-field-wrap">
              <input
                v-model="form.email"
                type="email"
                class="sb-input"
                :class="{ 'sb-input-error': formErrors.email, 'sb-input-success': isFieldValid('email') }"
                placeholder="email@example.com"
                @blur="touch('email')"
                @input="validateField('email')"
              />
              <svg v-if="isFieldValid('email')" class="sb-field-check" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
            </div>
            <p v-if="formErrors.email" class="sb-error-text">{{ formErrors.email }}</p>
          </div>

          <div class="sb-field">
            <label class="sb-label">Комментарий</label>
            <textarea
              v-model="form.notes"
              class="sb-input"
              rows="2"
              placeholder="Пожелания..."
            />
          </div>
        </div>

        <button
          type="submit"
          class="sb-btn sb-btn-primary sb-btn-block"
          :disabled="submitting"
        >
          {{ submitting ? 'Записываем...' : 'Записаться' }}
        </button>
      </form>
    </div>
  </div>
</template>
