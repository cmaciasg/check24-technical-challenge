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
            d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"
            stroke="currentColor"
            stroke-width="1.5"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
          <circle cx="7" cy="17" r="2" stroke="currentColor" stroke-width="1.5" />
          <path d="M9 17h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
          <circle cx="17" cy="17" r="2" stroke="currentColor" stroke-width="1.5" />
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
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: var(--space-3);
  margin: 0 0 var(--space-8);
}

.view__badge {
  display: grid;
  place-items: center;
  width: 3.25rem;
  height: 3.25rem;
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
  font-size: 2.25rem;
  font-weight: 800;
  letter-spacing: -0.02em;
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
