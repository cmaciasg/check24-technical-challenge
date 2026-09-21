import { activeLocale } from './copy'

/** Los importes se muestran siempre con Intl, nunca concatenando símbolos a mano. */
export function formatPrice(amountCents: number, currencyCode: string): string {
  return new Intl.NumberFormat(activeLocale, { style: 'currency', currency: currencyCode }).format(amountCents / 100)
}
