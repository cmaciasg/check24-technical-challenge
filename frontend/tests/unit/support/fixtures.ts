import type { ComparisonResponse, Offer, ProviderStatus } from '../../../src/comparator/car-insurance/domain/offer'
import type { QuoteRequestInput } from '../../../src/comparator/car-insurance/domain/quote-request'

/** "Hoy" fijo para que las fechas de los tests no caduquen. */
export const TODAY = new Date(2026, 8, 22, 10, 0, 0)

export const validInput: QuoteRequestInput = {
  birthDate: '1990-05-14',
  vehicleType: 'SEDAN',
  usageType: 'PRIVATE',
}

export function offer(overrides: Partial<Offer> = {}): Offer {
  return {
    providerId: 'provider-a',
    providerName: 'Provider A',
    providerQuoteReference: 'A-7f3c1e',
    priceAmountCents: 4250,
    currencyCode: 'EUR',
    coverageLevel: 'STANDARD',
    ...overrides,
  }
}

export function status(overrides: Partial<ProviderStatus> = {}): ProviderStatus {
  return { providerId: 'provider-a', providerName: 'Provider A', status: 'ok', elapsedMs: 132, ...overrides }
}

export function response(overrides: Partial<ComparisonResponse> = {}): ComparisonResponse {
  return { offers: [offer()], providerStatuses: [status()], ...overrides }
}
