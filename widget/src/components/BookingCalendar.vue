<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useShopStore } from '@/stores/shop'
import { formatPrice } from '@/lib/utils'

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

const durationMinutes = computed(() => props.product?.service?.duration_minutes ?? 60)

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
  const e: Record<string, string> = {}
  if (!form.value.name.trim()) e.name = 'Укажите имя'
  if (!form.value.phone.trim()) e.phone = 'Укажите телефон'
  if (form.value.email.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.value.email)) {
    e.email = 'Неверный формат email'
  }
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

    <!-- Progress -->
    <div class="sb-progress sb-mb-4">
      <div class="sb-progress-step" :class="selectedSlot ? 'sb-progress-done' : 'sb-progress-active'">Дата и время</div>
      <div class="sb-progress-sep"></div>
      <div class="sb-progress-step" :class="selectedSlot ? 'sb-progress-active' : ''">Ваши данные</div>
      <div class="sb-progress-sep"></div>
      <div class="sb-progress-step">Готово</div>
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
            <input
              v-model="form.name"
              type="text"
              class="sb-input"
              :class="{ 'sb-input-error': formErrors.name }"
              placeholder="Ваше имя"
            />
            <p v-if="formErrors.name" class="sb-error-text">{{ formErrors.name }}</p>
          </div>

          <div class="sb-field">
            <label class="sb-label">Телефон *</label>
            <input
              v-model="form.phone"
              type="tel"
              class="sb-input"
              :class="{ 'sb-input-error': formErrors.phone }"
              placeholder="+7 (999) 123-45-67"
            />
            <p v-if="formErrors.phone" class="sb-error-text">{{ formErrors.phone }}</p>
          </div>

          <div class="sb-field">
            <label class="sb-label">Email</label>
            <input
              v-model="form.email"
              type="email"
              class="sb-input"
              :class="{ 'sb-input-error': formErrors.email }"
              placeholder="email@example.com"
            />
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
