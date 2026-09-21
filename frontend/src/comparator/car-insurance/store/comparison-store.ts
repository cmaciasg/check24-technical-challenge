import { defineStore } from 'pinia'
import { computed, reactive, ref, watch } from 'vue'
import type { ComparisonResponse, Offer, ProviderStatus } from '../domain/offer'
import {
  emptyQuoteRequestInput,
  firstInvalidField,
  toQuoteRequestPayload,
  validateField,
  validateQuoteRequest,
  type FieldErrors,
  type FieldName,
  type QuoteRequestInput,
} from '../domain/quote-request'
import { sortOffers } from '../domain/sort-offers'
import { postComparison, type ComparisonApi } from '../infra/comparison-api'
import { createSessionFormStorage, type FormStorage } from '../infra/form-storage'

/** Estado único de la vista (architecture.md §5.3). */
export type ViewState = 'idle' | 'loading' | 'success' | 'invalid-data' | 'unavailable'

export interface ComparisonStoreDependencies {
  api: ComparisonApi
  storage: FormStorage
  /** "Hoy" para validar la fecha de nacimiento; inyectable para probar sin reloj. */
  today: () => Date
}

/**
 * El estado del formulario vive aquí, desacoplado de cualquier componente de página: un futuro
 * funnel multi-página añade rutas sobre este mismo estado sin refactor (product.md §2).
 * La API y el almacenamiento entran por parámetro, con la implementación real por defecto.
 */
export function defineComparisonStore(overrides: Partial<ComparisonStoreDependencies> = {}) {
  const dependencies: ComparisonStoreDependencies = {
    api: postComparison,
    storage: createSessionFormStorage(),
    today: () => new Date(),
    ...overrides,
  }

  return defineStore('comparison', () => {
    // Hidratación desde sessionStorage al crear el store.
    const form = reactive<QuoteRequestInput>(dependencies.storage.load() ?? emptyQuoteRequestInput())
    const errors = ref<FieldErrors>({})
    const touched = ref(new Set<FieldName>())
    const viewState = ref<ViewState>('idle')
    const offers = ref<Offer[]>([])
    const providerStatuses = ref<ProviderStatus[]>([])
    /** Sube cada vez que un envío no pasa la validación; la vista lo usa para llevar el foco. */
    const focusRequest = ref<{ field: FieldName; token: number } | null>(null)

    // Serializa a sessionStorage en cada cambio.
    watch(form, (value) => dependencies.storage.save({ ...value }), { deep: true, flush: 'sync' })

    const sortedOffers = computed(() => sortOffers(offers.value))
    const failedProviders = computed(() => providerStatuses.value.filter((status) => status.status === 'failed'))
    const isLoading = computed(() => viewState.value === 'loading')
    const hasResults = computed(() => viewState.value === 'success' && offers.value.length > 0)
    const isEmptyResult = computed(() => viewState.value === 'success' && offers.value.length === 0)

    function revalidate(field: FieldName): void {
      const error = validateField(field, form[field], dependencies.today())
      const next = { ...errors.value }
      if (error === null) delete next[field]
      else next[field] = error
      errors.value = next
    }

    function setField(field: FieldName, value: string): void {
      form[field] = value
      if (touched.value.has(field)) revalidate(field)
    }

    function blurField(field: FieldName): void {
      touched.value.add(field)
      revalidate(field)
    }

    function applyResponse(data: ComparisonResponse): void {
      offers.value = data.offers
      providerStatuses.value = data.providerStatuses
      viewState.value = 'success'
    }

    async function submit(): Promise<void> {
      const today = dependencies.today()
      const validation = validateQuoteRequest(form, today)
      errors.value = validation
      FIELD_NAMES.forEach((field) => touched.value.add(field))

      const invalidField = firstInvalidField(validation)
      if (invalidField !== null) {
        focusRequest.value = { field: invalidField, token: (focusRequest.value?.token ?? 0) + 1 }
        return
      }

      const payload = toQuoteRequestPayload(form, today)
      if (payload === null) return

      // Los resultados anteriores se limpian al enviar.
      offers.value = []
      providerStatuses.value = []
      viewState.value = 'loading'

      try {
        const response = await dependencies.api(payload)
        if (response.status === 200 && response.data !== undefined) {
          // Solo un 200 limpia sessionStorage; el store en memoria conserva los valores (product.md §3).
          dependencies.storage.clear()
          applyResponse(response.data)
        } else {
          viewState.value = response.status === 400 ? 'invalid-data' : 'unavailable'
        }
      } catch {
        viewState.value = 'unavailable'
      }
    }

    return {
      form,
      errors,
      viewState,
      offers,
      providerStatuses,
      focusRequest,
      sortedOffers,
      failedProviders,
      isLoading,
      hasResults,
      isEmptyResult,
      setField,
      blurField,
      submit,
    }
  })
}

const FIELD_NAMES: readonly FieldName[] = ['birthDate', 'vehicleType', 'usageType']

export const useComparisonStore = defineComparisonStore()
