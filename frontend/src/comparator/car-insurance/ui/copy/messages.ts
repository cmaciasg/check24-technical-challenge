// Contrato de los textos de la interfaz. Añadir un idioma es crear `messages.<código>.ts` que
// implemente `Messages`: si falta una clave, `vue-tsc` falla (architecture.md §5.5).

export interface Messages {
  'app.title': string
  'form.birthDate.label': string
  'form.vehicleType.label': string
  'form.vehicleType.placeholder': string
  'form.usageType.label': string
  'form.submit': string
  'form.submitting': string
  'vehicleType.SEDAN': string
  'vehicleType.HATCHBACK': string
  'vehicleType.SUV': string
  'vehicleType.VAN': string
  'usageType.PRIVATE': string
  'usageType.COMMERCIAL': string
  'error.required': string
  'error.invalid_format': string
  'error.not_a_real_date': string
  'error.not_in_the_past': string
  'error.age_out_of_range': string
  'error.not_in_catalog': string
  'results.heading': string
  'results.loading': string
  'offer.coverage': string
  'offer.reference': string
  'offer.originalPrice': string
  'offer.finalPrice': string
  'offer.campaign': string
  'coverage.BASIC': string
  'coverage.STANDARD': string
  'coverage.PREMIUM': string
  'provider.unavailable': string
  'state.empty.title': string
  'state.empty.body': string
  'state.empty.retry': string
  'state.invalid.title': string
  'state.invalid.body': string
  'state.unavailable.title': string
  'state.unavailable.body': string
  'state.unavailable.retry': string
}

export type MessageKey = keyof Messages
