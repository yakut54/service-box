<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const authStore = useAuthStore()

const name = ref('')
const shopName = ref('')
const email = ref('')
const password = ref('')
const passwordConfirm = ref('')
const loading = ref(false)
const error = ref('')

async function handleSubmit() {
  if (!name.value || !shopName.value || !email.value || !password.value) {
    error.value = 'Заполните все поля'
    return
  }

  if (password.value.length < 8) {
    error.value = 'Пароль должен быть не менее 8 символов'
    return
  }

  if (password.value !== passwordConfirm.value) {
    error.value = 'Пароли не совпадают'
    return
  }

  loading.value = true
  error.value = ''

  const result = await authStore.register(
    email.value,
    password.value,
    name.value,
    shopName.value
  )

  if (result.success) {
    await router.push('/')
  } else {
    error.value = result.error || 'Ошибка регистрации'
  }

  loading.value = false
}
</script>

<template>
  <div class="min-h-screen bg-gray-50 flex items-center justify-center p-4">
    <div class="w-full max-w-md">
      <div class="text-center mb-8">
        <div class="w-16 h-16 bg-primary-600 rounded-2xl flex items-center justify-center mx-auto mb-4">
          <span class="text-white font-bold text-3xl">S</span>
        </div>
        <h1 class="text-2xl font-bold text-gray-900">Создать магазин</h1>
        <p class="text-gray-500 mt-1">Начните принимать заказы уже сегодня</p>
      </div>

      <div class="card">
        <form @submit.prevent="handleSubmit" class="space-y-4">
          <div v-if="error" class="p-3 bg-red-50 border border-red-200 rounded-lg text-red-600 text-sm">
            {{ error }}
          </div>

          <div>
            <label for="name" class="label">Ваше имя</label>
            <input id="name" v-model="name" type="text" class="input" placeholder="Иван Петров" />
          </div>

          <div>
            <label for="shopName" class="label">Название магазина</label>
            <input id="shopName" v-model="shopName" type="text" class="input" placeholder="Мой магазин" />
          </div>

          <div>
            <label for="email" class="label">Email</label>
            <input id="email" v-model="email" type="email" class="input" placeholder="your@email.com" autocomplete="email" />
          </div>

          <div>
            <label for="password" class="label">Пароль</label>
            <input id="password" v-model="password" type="password" class="input" placeholder="Минимум 8 символов" autocomplete="new-password" />
          </div>

          <div>
            <label for="passwordConfirm" class="label">Повторите пароль</label>
            <input id="passwordConfirm" v-model="passwordConfirm" type="password" class="input" placeholder="********" autocomplete="new-password" />
          </div>

          <button type="submit" class="btn-primary w-full" :disabled="loading">
            {{ loading ? 'Создание...' : 'Создать магазин' }}
          </button>
        </form>

        <p class="mt-6 text-center text-sm text-gray-500">
          Уже есть аккаунт?
          <RouterLink to="/login" class="text-primary-600 hover:text-primary-700 font-medium">Войти</RouterLink>
        </p>
      </div>

      <div class="mt-8 grid grid-cols-3 gap-4 text-center text-sm text-gray-500">
        <div>
          <div class="font-semibold text-gray-900">Бесплатно</div>
          <div>14 дней</div>
        </div>
        <div>
          <div class="font-semibold text-gray-900">Без %</div>
          <div>с продаж</div>
        </div>
        <div>
          <div class="font-semibold text-gray-900">1 минута</div>
          <div>настройка</div>
        </div>
      </div>
    </div>
  </div>
</template>
