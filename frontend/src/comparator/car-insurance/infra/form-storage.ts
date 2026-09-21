import type { QuoteRequestInput } from '../domain/quote-request'

/** Persistencia del formulario ante una recarga de página (product.md §3). */
export interface FormStorage {
  load(): QuoteRequestInput | null
  save(input: QuoteRequestInput): void
  clear(): void
}

export const STORAGE_KEY = 'comparator.car-insurance.quote-form'

function isQuoteRequestInput(value: unknown): value is QuoteRequestInput {
  if (typeof value !== 'object' || value === null) return false
  const candidate = value as Record<string, unknown>
  return ['birthDate', 'vehicleType', 'usageType'].every((key) => typeof candidate[key] === 'string')
}

/**
 * sessionStorage, no localStorage: sobrevive a una recarga de la pestaña y se borra al cerrarla.
 * Guarda el input crudo, válido o no. Si el navegador lo bloquea, el formulario funciona igual sin persistir.
 */
export function createSessionFormStorage(storage?: Storage): FormStorage {
  const resolve = (): Storage | null => {
    try {
      return storage ?? window.sessionStorage
    } catch {
      return null
    }
  }

  return {
    load() {
      try {
        const raw = resolve()?.getItem(STORAGE_KEY)
        if (raw === null || raw === undefined) return null
        const parsed: unknown = JSON.parse(raw)
        return isQuoteRequestInput(parsed) ? parsed : null
      } catch {
        return null
      }
    },
    save(input) {
      try {
        resolve()?.setItem(STORAGE_KEY, JSON.stringify(input))
      } catch {
        // Sin persistencia disponible: no es un error para el usuario.
      }
    },
    clear() {
      try {
        resolve()?.removeItem(STORAGE_KEY)
      } catch {
        // Ídem.
      }
    },
  }
}
