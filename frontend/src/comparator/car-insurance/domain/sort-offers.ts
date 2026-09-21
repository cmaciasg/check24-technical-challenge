import type { Offer } from './offer'

/** Precio que paga el cliente: el rebajado si hay campaña, si no el real. */
export function effectivePriceCents(offer: Offer): number {
  return offer.discountedPriceAmountCents ?? offer.priceAmountCents
}

/**
 * Orden por precio efectivo ascendente; empate por providerName. El contrato de la API no
 * garantiza ningún orden (architecture.md §5.4). No modifica el array recibido.
 */
export function sortOffers(offers: readonly Offer[]): Offer[] {
  return [...offers].sort(
    (a, b) => effectivePriceCents(a) - effectivePriceCents(b) || a.providerName.localeCompare(b.providerName),
  )
}
