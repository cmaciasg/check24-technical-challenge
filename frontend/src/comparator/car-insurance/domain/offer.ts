// Tipos de la respuesta de POST /comparison (architecture.md §4.2).

export type CoverageLevel = 'BASIC' | 'STANDARD' | 'PREMIUM'

export interface Offer {
  providerId: string
  providerName: string
  providerQuoteReference: string
  /** Precio real del provider, sin campaña. */
  priceAmountCents: number
  /** Solo presente cuando la campaña está activa. */
  discountedPriceAmountCents?: number
  campaignApplied?: boolean
  currencyCode: string
  coverageLevel: CoverageLevel
}

export type ProviderFailureReason = 'timeout' | 'http_error' | 'malformed_response' | 'unknown_error'

export interface ProviderStatus {
  providerId: string
  providerName: string
  status: 'ok' | 'failed'
  reason?: ProviderFailureReason
  elapsedMs: number
}

export interface ComparisonResponse {
  offers: Offer[]
  providerStatuses: ProviderStatus[]
}
