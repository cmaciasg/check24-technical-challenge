<script setup lang="ts">
import { useComparisonStore } from '../store/comparison-store'
import { t } from './copy'
import OfferCard from './OfferCard.vue'
import ProviderNotices from './ProviderNotices.vue'
import QuoteForm from './QuoteForm.vue'
import EmptyState from './states/EmptyState.vue'
import ErrorState from './states/ErrorState.vue'
import LoadingState from './states/LoadingState.vue'

const store = useComparisonStore()
</script>

<template>
  <main class="view">
    <header class="view__header">
      <span class="view__badge" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path
            d="M12 2.5l7.5 3v5.2c0 5-3.2 8.6-7.5 10.3-4.3-1.7-7.5-5.3-7.5-10.3V5.5l7.5-3z"
            fill="currentColor"
            fill-opacity="0.14"
          />
          <path
            d="M12 2.5l7.5 3v5.2c0 5-3.2 8.6-7.5 10.3-4.3-1.7-7.5-5.3-7.5-10.3V5.5l7.5-3z"
            stroke="currentColor"
            stroke-width="1.4"
            stroke-linejoin="round"
          />
          <path
            d="M8.3 12.3l2.4 2.4 5-5"
            stroke="currentColor"
            stroke-width="1.4"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      </span>
      <h1 class="view__title">{{ t('app.title') }}</h1>
    </header>

    <QuoteForm />

    <section class="results" data-testid="results-panel" aria-live="polite" :aria-label="t('results.heading')">
      <LoadingState v-if="store.viewState === 'loading'" />

      <template v-else-if="store.hasResults">
        <ul class="results__offers">
          <li v-for="offer in store.sortedOffers" :key="offer.providerId">
            <OfferCard :offer="offer" />
          </li>
        </ul>
        <ProviderNotices :statuses="store.failedProviders" />
      </template>

      <EmptyState v-else-if="store.isEmptyResult" @retry="store.submit()">
        <ProviderNotices :statuses="store.failedProviders" />
      </EmptyState>

      <ErrorState v-else-if="store.viewState === 'invalid-data'" kind="invalid" />
      <ErrorState v-else-if="store.viewState === 'unavailable'" kind="unavailable" @retry="store.submit()" />
    </section>
  </main>
</template>

<style scoped>
.view {
  max-width: 64rem;
  margin: 0 auto;
  padding: var(--space-8) var(--space-4) var(--space-6);
}

.view__header {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  margin: 0 0 var(--space-6);
}

.view__badge {
  display: grid;
  place-items: center;
  width: 2.75rem;
  height: 2.75rem;
  flex: none;
  color: var(--color-primary);
  background: var(--color-primary-soft);
  border-radius: var(--radius);
}

.view__badge svg {
  width: 1.5rem;
  height: 1.5rem;
}

.view__title {
  margin: 0;
  font-size: 1.75rem;
  font-weight: 700;
  letter-spacing: -0.01em;
  color: var(--color-text);
}

.results {
  margin-top: var(--space-6);
}

.results__offers {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-4);
  margin: 0;
  padding: 0;
  list-style: none;
}

@media (min-width: 768px) {
  .results__offers {
    grid-template-columns: repeat(3, 1fr);
  }
}
</style>
