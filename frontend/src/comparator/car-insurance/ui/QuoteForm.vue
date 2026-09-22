<script setup lang="ts">
import { nextTick, watch } from 'vue'
import { USAGE_TYPES, VEHICLE_TYPES, type FieldName } from '../domain/quote-request'
import { useComparisonStore } from '../store/comparison-store'
import { t } from './copy'
import type { MessageKey } from './copy/messages'

const store = useComparisonStore()

const maxBirthDate = new Date().toISOString().slice(0, 10)

const errorText = (field: FieldName): string | null => {
  const code = store.errors[field]
  return code === undefined ? null : t(`error.${code}` as MessageKey)
}

const fieldTestId: Record<FieldName, string> = {
  birthDate: 'field-birth-date',
  vehicleType: 'field-vehicle-type',
  usageType: 'field-usage-type',
}

// Con algún campo inválido, el foco va al primer campo con error.
watch(
  () => store.focusRequest,
  async (request) => {
    if (request === null) return
    await nextTick()
    const container = document.querySelector<HTMLElement>(`[data-testid="${fieldTestId[request.field]}"]`)
    const target = container?.matches('input, select') ? container : container?.querySelector<HTMLElement>('input')
    target?.focus()
  },
)

function onInput(field: FieldName, event: Event): void {
  store.setField(field, (event.target as HTMLInputElement | HTMLSelectElement).value)
}
</script>

<template>
  <form class="form" novalidate @submit.prevent="store.submit()">
    <div class="field">
      <label class="field__label" for="birth-date">{{ t('form.birthDate.label') }}</label>
      <input
        id="birth-date"
        class="field__control"
        type="date"
        data-testid="field-birth-date"
        :max="maxBirthDate"
        :value="store.form.birthDate"
        :aria-invalid="errorText('birthDate') !== null"
        aria-describedby="birth-date-error"
        @input="onInput('birthDate', $event)"
        @blur="store.blurField('birthDate')"
      />
      <p v-if="errorText('birthDate')" id="birth-date-error" class="field__error">{{ errorText('birthDate') }}</p>
    </div>

    <div class="field">
      <label class="field__label" for="vehicle-type">{{ t('form.vehicleType.label') }}</label>
      <select
        id="vehicle-type"
        class="field__control"
        data-testid="field-vehicle-type"
        :value="store.form.vehicleType"
        :aria-invalid="errorText('vehicleType') !== null"
        aria-describedby="vehicle-type-error"
        @change="onInput('vehicleType', $event)"
        @blur="store.blurField('vehicleType')"
      >
        <option value="">{{ t('form.vehicleType.placeholder') }}</option>
        <option v-for="type in VEHICLE_TYPES" :key="type" :value="type">{{ t(`vehicleType.${type}`) }}</option>
      </select>
      <p v-if="errorText('vehicleType')" id="vehicle-type-error" class="field__error">
        {{ errorText('vehicleType') }}
      </p>
    </div>

    <fieldset class="field field--group" data-testid="field-usage-type" aria-describedby="usage-type-error">
      <legend class="field__label">{{ t('form.usageType.label') }}</legend>
      <label v-for="type in USAGE_TYPES" :key="type" class="radio">
        <input
          type="radio"
          name="usage-type"
          :value="type"
          :checked="store.form.usageType === type"
          @change="onInput('usageType', $event)"
          @blur="store.blurField('usageType')"
        />
        <span>{{ t(`usageType.${type}`) }}</span>
      </label>
      <p v-if="errorText('usageType')" id="usage-type-error" class="field__error">{{ errorText('usageType') }}</p>
    </fieldset>

    <button class="form__submit" type="submit" data-testid="submit" :disabled="store.isLoading">
      {{ store.isLoading ? t('form.submitting') : t('form.submit') }}
    </button>
  </form>
</template>

<style scoped>
.form {
  display: grid;
  gap: var(--space-4);
  padding: var(--space-6) var(--space-4);
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow);
}

.field {
  display: grid;
  gap: var(--space-2);
  margin: 0;
  padding: 0;
  border: 0;
}

.field__label {
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--color-muted);
  padding: 0;
}

.field__control {
  min-height: var(--touch-target);
  padding: 0 var(--space-3);
  font: inherit;
  color: var(--color-text);
  background: var(--color-surface);
  border: 1.5px solid var(--color-border);
  border-radius: var(--radius-sm);
  transition: border-color var(--transition), box-shadow var(--transition);
}

.field__control:hover {
  border-color: #b9c3d6;
}

.field__control:focus-visible {
  border-color: var(--color-primary);
  box-shadow: 0 0 0 4px var(--color-primary-soft);
}

.field__control[aria-invalid='true'] {
  border-color: var(--color-danger);
}

.field__error {
  margin: 0;
  color: var(--color-danger);
  font-size: 0.875rem;
}

.radio {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  min-height: var(--touch-target);
  padding: 0 var(--space-1);
  cursor: pointer;
}

.radio input {
  width: 1.25rem;
  height: 1.25rem;
  accent-color: var(--color-primary);
}

.form__submit {
  min-height: var(--touch-target);
  padding: 0 var(--space-6);
  font: inherit;
  font-weight: 600;
  color: var(--color-primary-text);
  background: var(--color-primary);
  border: 0;
  border-radius: var(--radius-sm);
  box-shadow: var(--shadow-sm);
  cursor: pointer;
  transition: background var(--transition), transform var(--transition), box-shadow var(--transition);
}

.form__submit:hover:not(:disabled) {
  background: var(--color-primary-hover);
  box-shadow: var(--shadow);
  transform: translateY(-1px);
}

.form__submit:active:not(:disabled) {
  transform: translateY(0);
}

.form__submit:disabled {
  opacity: 0.7;
  cursor: progress;
}

@media (min-width: 768px) {
  .form {
    grid-template-columns: repeat(3, 1fr) auto;
    align-items: end;
  }
}
</style>
