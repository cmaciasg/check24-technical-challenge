<script setup lang="ts">
import type { ProviderStatus } from '../domain/offer'
import { t } from './copy'

defineProps<{ statuses: readonly ProviderStatus[] }>()
</script>

<template>
  <ul v-if="statuses.length > 0" class="notices">
    <!-- Solo se muestra el nombre: la razón y el tiempo son de observabilidad, no del cliente. -->
    <li v-for="status in statuses" :key="status.providerId" class="notices__item" data-testid="provider-notice">
      {{ t('provider.unavailable', { providerName: status.providerName }) }}
    </li>
  </ul>
</template>

<style scoped>
.notices {
  display: grid;
  gap: var(--space-2);
  margin: var(--space-4) 0 0;
  padding: 0;
  list-style: none;
}

.notices__item {
  padding: var(--space-3) var(--space-4);
  background: var(--color-warning-bg);
  border: 1px solid var(--color-warning-border);
  border-radius: var(--radius);
}
</style>
