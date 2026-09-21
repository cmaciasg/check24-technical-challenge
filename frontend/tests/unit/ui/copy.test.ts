import { describe, expect, it } from 'vitest'
import { activeLocale, t } from '../../../src/comparator/car-insurance/ui/copy'
import { messagesEn } from '../../../src/comparator/car-insurance/ui/copy/messages.en'
import { formatPrice } from '../../../src/comparator/car-insurance/ui/format-price'

describe('t()', () => {
  it('returns the text for a key', () => {
    expect(t('form.submit')).toBe('Compare offers')
  })

  it('replaces the parameters', () => {
    expect(t('provider.unavailable', { providerName: 'Provider C' })).toBe('Provider C is not available right now.')
  })

  it('leaves an unknown placeholder untouched', () => {
    expect(t('provider.unavailable')).toBe('{providerName} is not available right now.')
  })

  it('has an English text for every key and the active locale is English', () => {
    expect(activeLocale).toBe('en')
    for (const text of Object.values(messagesEn)) {
      expect(text).not.toBe('')
    }
  })
})

describe('formatPrice', () => {
  it('formats cents as a currency amount', () => {
    expect(formatPrice(4250, 'EUR')).toBe('€42.50')
    expect(formatPrice(3825, 'EUR')).toBe('€38.25')
    expect(formatPrice(0, 'EUR')).toBe('€0.00')
  })

  it('does not lose precision with amounts that floating point cannot represent', () => {
    expect(formatPrice(1999, 'EUR')).toBe('€19.99')
  })
})
