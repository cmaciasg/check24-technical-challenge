import type { ComparisonResponse } from '../domain/offer'
import type { QuoteRequestPayload } from '../domain/quote-request'

/** Respuesta HTTP ya interpretada. Un fallo de red no llega aquí: la promesa se rechaza. */
export interface ComparisonApiResponse {
  status: number
  data?: ComparisonResponse
}

export type ComparisonApi = (payload: QuoteRequestPayload) => Promise<ComparisonApiResponse>

/** POST /comparison contra la ruta relativa: el frontend no conoce ninguna URL absoluta. */
export const postComparison: ComparisonApi = async (payload) => {
  const response = await fetch('/comparison', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })

  if (response.status !== 200) return { status: response.status }

  return { status: 200, data: (await response.json()) as ComparisonResponse }
}
