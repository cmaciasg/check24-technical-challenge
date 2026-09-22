<script setup lang="ts">
import { computed } from 'vue'
import type { Offer } from '../domain/offer'
import { t } from './copy'
import { formatPrice } from './format-price'

const props = defineProps<{ offer: Offer }>()

const hasCampaign = computed(
  () => props.offer.campaignApplied === true && props.offer.discountedPriceAmountCents !== undefined,
)
const finalCents = computed(() => props.offer.discountedPriceAmountCents ?? props.offer.priceAmountCents)
</script>

<template>
  <article class="card" data-testid="offer-card" :data-provider-id="offer.providerId">
    <h2 class="card__provider">{{ offer.providerName }}</h2>
    <p v-if="hasCampaign" class="card__campaign">{{ t('offer.campaign') }}</p>

    <p class="card__price">
      <s
        v-if="hasCampaign"
        class="card__original"
        data-testid="offer-price-original"
        :aria-label="t('offer.originalPrice')"
      >
        {{ formatPrice(offer.priceAmountCents, offer.currencyCode) }}
      </s>
      <strong class="card__final" data-testid="offer-price-final" :aria-label="t('offer.finalPrice')">
        {{ formatPrice(finalCents, offer.currencyCode) }}
      </strong>
    </p>

    <dl class="card__details">
      <div>
        <dt>{{ t('offer.coverage') }}</dt>
        <dd>{{ t(`coverage.${offer.coverageLevel}`) }}</dd>
      </div>
      <div>
        <dt>{{ t('offer.reference') }}</dt>
        <dd>{{ offer.providerQuoteReference }}</dd>
      </div>
    </dl>
  </article>
</template>

<style scoped>
.card {
  display: flex;
  flex-direction: column;
  height: 100%;
  padding: var(--space-4);
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow);
  transition: transform var(--transition), box-shadow var(--transition);
}

.card:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow-lift);
}

.card__provider {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
  color: var(--color-muted);
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.card__campaign {
  display: inline-flex;
  align-self: start;
  margin: var(--space-2) 0 0;
  padding: var(--space-1) var(--space-2);
  font-size: 0.75rem;
  font-weight: 700;
  color: var(--color-accent-text);
  background: #ffe9d6;
  border-radius: 999px;
}

.card__price {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin: var(--space-3) 0;
}

.card__original {
  color: var(--color-muted);
  font-size: 1rem;
}

.card__final {
  font-size: 1.875rem;
  font-weight: 700;
  color: var(--color-primary);
  letter-spacing: -0.01em;
}

.card__details {
  display: grid;
  gap: var(--space-2);
  margin: auto 0 0;
  padding-top: var(--space-3);
  border-top: 1px solid var(--color-border);
  color: var(--color-muted);
  font-size: 0.875rem;
}

.card__details div {
  display: flex;
  justify-content: space-between;
  gap: var(--space-4);
}

.card__details dt,
.card__details dd {
  margin: 0;
}
</style>
