<script setup lang="ts">
import { t } from '../copy'

defineEmits<{ retry: [] }>()
</script>

<template>
  <div class="empty" data-testid="state-empty">
    <h2 class="empty__title">{{ t('state.empty.title') }}</h2>
    <p class="empty__body">{{ t('state.empty.body') }}</p>
    <slot />
    <button class="empty__retry" type="button" @click="$emit('retry')">{{ t('state.empty.retry') }}</button>
  </div>
</template>

<style scoped>
.empty {
  padding: var(--space-6) var(--space-4);
  background: var(--color-surface);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
}

.empty__title {
  margin: 0 0 var(--space-2);
  font-size: 1.125rem;
}

.empty__body {
  margin: 0;
  color: var(--color-muted);
}

.empty__retry {
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
