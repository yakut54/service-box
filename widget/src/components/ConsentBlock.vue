<script setup lang="ts">
import { ref } from 'vue'
import { useShopStore } from '@/stores/shop'

const shopStore = useShopStore()

const consentOffer   = ref(false)
const consentPrivacy = ref(false)
const errorOffer     = ref('')
const errorPrivacy   = ref('')

function validate(): boolean {
  errorOffer.value   = ''
  errorPrivacy.value = ''
  const legal = shopStore.shop?.legal
  if (!legal?.has_docs) return true
  if (!consentOffer.value)   errorOffer.value   = 'Необходимо принять условия оферты'
  if (!consentPrivacy.value) errorPrivacy.value = 'Необходимо дать согласие на обработку данных'
  return !errorOffer.value && !errorPrivacy.value
}

defineExpose({ validate, consentOffer, consentPrivacy })

function openLegal(url: string) { window.open(url, '_blank', 'noopener,noreferrer') }
</script>

<template>
  <div v-if="shopStore.shop?.legal?.has_docs" class="sb-consent-block sb-mt-4">
    <label class="sb-consent-label" :class="{ 'sb-consent-label-error': errorOffer }">
      <input type="checkbox" v-model="consentOffer" class="sb-consent-check" @change="errorOffer = ''" />
      <span>
        Принимаю условия
        <button type="button" class="sb-consent-link" @click="openLegal(shopStore.shop.legal.offer_url)">Публичной оферты</button>
        и
        <button type="button" class="sb-consent-link" @click="openLegal(shopStore.shop.legal.privacy_url)">Политики конфиденциальности</button>
      </span>
    </label>
    <p v-if="errorOffer" class="sb-error-text" role="alert">{{ errorOffer }}</p>

    <label class="sb-consent-label sb-mt-2" :class="{ 'sb-consent-label-error': errorPrivacy }">
      <input type="checkbox" v-model="consentPrivacy" class="sb-consent-check" @change="errorPrivacy = ''" />
      <span>
        Согласен на обработку
        <button type="button" class="sb-consent-link" @click="openLegal(shopStore.shop.legal.personal_data_url)">персональных данных</button>
      </span>
    </label>
    <p v-if="errorPrivacy" class="sb-error-text" role="alert">{{ errorPrivacy }}</p>
  </div>
</template>
