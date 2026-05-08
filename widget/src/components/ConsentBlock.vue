<script setup lang="ts">
import { useShopStore } from '@/stores/shop'

const shopStore = useShopStore()

const consentOffer   = defineModel<boolean>('offer',   { required: true })
const consentPrivacy = defineModel<boolean>('privacy', { required: true })

defineProps<{
  errorOffer?:   string
  errorPrivacy?: string
}>()

function openLegal(url: string) { window.open(url, '_blank', 'noopener,noreferrer') }
</script>

<template>
  <div v-if="shopStore.shop?.legal?.has_docs" class="sb-consent-block sb-mt-4">
    <label class="sb-consent-label" :class="{ 'sb-consent-label-error': errorOffer }">
      <input type="checkbox" v-model="consentOffer" class="sb-consent-check" />
      <span>
        Принимаю условия
        <button type="button" class="sb-consent-link" @click="openLegal(shopStore.shop.legal.offer_url)">Публичной оферты</button>
        и
        <button type="button" class="sb-consent-link" @click="openLegal(shopStore.shop.legal.privacy_url)">Политики конфиденциальности</button>
      </span>
    </label>
    <p v-if="errorOffer" class="sb-error-text" role="alert">{{ errorOffer }}</p>

    <label class="sb-consent-label sb-mt-2" :class="{ 'sb-consent-label-error': errorPrivacy }">
      <input type="checkbox" v-model="consentPrivacy" class="sb-consent-check" />
      <span>
        Согласен на обработку
        <button type="button" class="sb-consent-link" @click="openLegal(shopStore.shop.legal.personal_data_url)">персональных данных</button>
      </span>
    </label>
    <p v-if="errorPrivacy" class="sb-error-text" role="alert">{{ errorPrivacy }}</p>
  </div>
</template>
