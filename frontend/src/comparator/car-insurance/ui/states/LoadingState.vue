<script setup lang="ts">
import { t } from '../copy'
</script>

<template>
  <div class="loading" data-testid="state-loading" role="status">
    <span class="loading__spinner" aria-hidden="true"></span>
    <p class="loading__text">{{ t('results.loading') }}</p>
  </div>
</template>

<style scoped>
.loading {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-3);
  padding: var(--space-8) var(--space-4);
  color: var(--color-muted);
}

.loading__text {
  margin: 0;
}

.loading__spinner {
  width: 1.5rem;
  height: 1.5rem;
  border: 3px solid var(--color-border);
  border-top-color: var(--color-primary);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

@media (prefers-reduced-motion: reduce) {
  .loading__spinner {
    animation-duration: 3s;
  }
}
</style>
