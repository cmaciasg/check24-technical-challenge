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
    <h1 class="view__title">{{ t('app.title') }}</h1>

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
  padding: var(--space-6) var(--space-4);
}

.view__title {
  margin: 0 0 var(--space-6);
  font-size: 1.75rem;
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
