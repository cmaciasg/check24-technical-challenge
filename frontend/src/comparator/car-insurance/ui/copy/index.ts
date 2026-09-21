import type { MessageKey, Messages } from './messages'
import { messagesEn } from './messages.en'

/** Único punto donde se elige el idioma activo. */
export const activeLocale = 'en'

const catalogs: Record<typeof activeLocale, Messages> = { en: messagesEn }

/**
 * Única puerta de acceso a los textos: ningún componente contiene literales.
 * Si algún día hicieran falta traducciones dinámicas, el cuerpo se sustituye por vue-i18n
 * sin cambiar a sus llamadores.
 */
export function t(key: MessageKey, params: Record<string, string | number> = {}): string {
  return catalogs[activeLocale][key].replace(/\{(\w+)\}/g, (placeholder, name: string) =>
    name in params ? String(params[name]) : placeholder,
  )
}
