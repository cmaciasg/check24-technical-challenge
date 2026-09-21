import { describe, expect, it } from 'vitest'
import {
  firstInvalidField,
  toQuoteRequestPayload,
  validateBirthDate,
  validateField,
  validateQuoteRequest,
  validateUsageType,
  validateVehicleType,
} from '../../../src/comparator/car-insurance/domain/quote-request'
import { TODAY, validInput } from '../support/fixtures'

// Hoy es 2026-09-22.
describe('validateBirthDate', () => {
  it.each([
    ['exactly 18 today (birthday is today)', '2008-09-22'],
    ['typical adult', '1990-05-14'],
    ['exactly 99 today', '1927-09-22'],
    ['99 years and 364 days', '1926-09-23'],
  ])('accepts %s', (_name, value) => {
    expect(validateBirthDate(value, TODAY)).toBeNull()
  })

  it.each([
    ['empty', '', 'required'],
    ['aged 17 (17 years and 364 days)', '2008-09-23', 'age_out_of_range'],
    ['aged 17 (17th birthday today)', '2009-09-22', 'age_out_of_range'],
    ['aged 100 today', '1926-09-22', 'age_out_of_range'],
    ['aged 101', '1925-01-01', 'age_out_of_range'],
    ['born today', '2026-09-22', 'not_in_the_past'],
    ['future date', '2027-01-01', 'not_in_the_past'],
    ['regional format', '14/05/1990', 'invalid_format'],
    ['not zero padded', '1990-5-14', 'invalid_format'],
    ['half typed', '1990-05', 'invalid_format'],
    ['february 30th', '1990-02-30', 'not_a_real_date'],
    ['month 13', '1990-13-01', 'not_a_real_date'],
    ['february 29th in a non leap year', '1990-02-29', 'not_a_real_date'],
  ])('rejects %s', (_name, value, code) => {
    expect(validateBirthDate(value, TODAY)).toBe(code)
  })

  it('accepts february 29th in a leap year', () => {
    expect(validateBirthDate('1992-02-29', TODAY)).toBeNull()
  })
})

describe('catalog fields', () => {
  it.each(['SEDAN', 'HATCHBACK', 'SUV', 'VAN'])('accepts vehicle type %s', (value) => {
    expect(validateVehicleType(value)).toBeNull()
  })

  it.each([
    ['', 'required'],
    ['TRUCK', 'not_in_catalog'],
    ['sedan', 'not_in_catalog'],
  ])('rejects vehicle type "%s"', (value, code) => {
    expect(validateVehicleType(value)).toBe(code)
  })

  it.each(['PRIVATE', 'COMMERCIAL'])('accepts usage type %s', (value) => {
    expect(validateUsageType(value)).toBeNull()
  })

  it.each([
    ['', 'required'],
    ['TAXI', 'not_in_catalog'],
  ])('rejects usage type "%s"', (value, code) => {
    expect(validateUsageType(value)).toBe(code)
  })
})

describe('per-field validation', () => {
  // Cada campo se valida por su cuenta, sin depender de que el resto del formulario esté completo.
  it('validates one field without looking at the others', () => {
    expect(validateField('vehicleType', 'SUV', TODAY)).toBeNull()
    expect(validateField('birthDate', '', TODAY)).toBe('required')
    expect(validateField('usageType', 'PRIVATE', TODAY)).toBeNull()
  })

  it('reports every invalid field of a form', () => {
    expect(validateQuoteRequest({ birthDate: '', vehicleType: 'X', usageType: '' }, TODAY)).toEqual({
      birthDate: 'required',
      vehicleType: 'not_in_catalog',
      usageType: 'required',
    })
  })

  it('finds the first invalid field in form order', () => {
    expect(firstInvalidField({ usageType: 'required', vehicleType: 'required' })).toBe('vehicleType')
    expect(firstInvalidField({})).toBeNull()
  })
})

describe('toQuoteRequestPayload', () => {
  it('builds the request body from a valid input', () => {
    expect(toQuoteRequestPayload(validInput, TODAY)).toEqual(validInput)
  })

  it('returns null while any field is invalid', () => {
    expect(toQuoteRequestPayload({ ...validInput, birthDate: '1990-05' }, TODAY)).toBeNull()
  })
})
