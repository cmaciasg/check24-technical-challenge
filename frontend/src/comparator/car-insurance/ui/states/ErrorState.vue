<script setup lang="ts">
import { t } from '../copy'

/**
 * `invalid`: 400, se pide revisar los datos, sin reintento (el servidor rechazó esos datos).
 * `unavailable`: 500 o fallo de red, fallo temporal con "Reintentar". Nunca se muestra el cuerpo del error.
 */
defineProps<{ kind: 'invalid' | 'unavailable' }>()
defineEmits<{ retry: [] }>()
</script>

<template>
  <div class="error" data-testid="state-error" role="alert">
    <h2 class="error__title">{{ t(kind === 'invalid' ? 'state.invalid.title' : 'state.unavailable.title') }}</h2>
    <p class="error__body">{{ t(kind === 'invalid' ? 'state.invalid.body' : 'state.unavailable.body') }}</p>
    <button v-if="kind === 'unavailable'" class="error__retry" type="button" @click="$emit('retry')">
      {{ t('state.unavailable.retry') }}
    </button>
  </div>
</template>

<style scoped>
.error {
  padding: var(--space-6) var(--space-4);
  background: var(--color-danger-bg);
  border-radius: var(--radius);
}

.error__title {
  margin: 0 0 var(--space-2);
  color: var(--color-danger);
  font-size: 1.125rem;
}

.error__body {
  margin: 0;
}

.error__retry {
  min-height: var(--touch-target);
  margin-top: var(--space-4);
  padding: 0 var(--space-6);
  font: inherit;
  font-weight: 600;
  color: var(--color-primary-text);
  background: var(--color-primary);
  border: 0;
  border-radius: var(--radius);
  cursor: pointer;
}
</style>
