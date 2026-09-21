// Reglas de validación por campo del QuoteRequest (product.md §1). TypeScript puro: sin Vue, Pinia
// ni APIs del navegador. Replica las reglas del backend solo por comodidad de UX; la autoridad es el backend.

export const VEHICLE_TYPES = ['SEDAN', 'HATCHBACK', 'SUV', 'VAN'] as const
export const USAGE_TYPES = ['PRIVATE', 'COMMERCIAL'] as const

export type VehicleType = (typeof VEHICLE_TYPES)[number]
export type UsageType = (typeof USAGE_TYPES)[number]

export const MIN_AGE = 18
export const MAX_AGE = 99

/** Lo que el usuario ha escrito, tal cual, antes de validar. */
export interface QuoteRequestInput {
  birthDate: string
  vehicleType: string
  usageType: string
}

export type FieldName = keyof QuoteRequestInput

export type FieldErrorCode =
  | 'required'
  | 'invalid_format'
  | 'not_a_real_date'
  | 'not_in_the_past'
  | 'age_out_of_range'
  | 'not_in_catalog'

export type FieldErrors = Partial<Record<FieldName, FieldErrorCode>>

/** Cuerpo de POST /comparison: solo se construye cuando todos los campos son válidos. */
export interface QuoteRequestPayload {
  birthDate: string
  vehicleType: VehicleType
  usageType: UsageType
}

export const FIELD_ORDER: readonly FieldName[] = ['birthDate', 'vehicleType', 'usageType']

export function emptyQuoteRequestInput(): QuoteRequestInput {
  return { birthDate: '', vehicleType: '', usageType: '' }
}

function isoDate(date: Date): string {
  const pad = (n: number, width = 2) => String(n).padStart(width, '0')
  return `${pad(date.getFullYear(), 4)}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

function ageOn(year: number, month: number, day: number, today: Date): number {
  const beforeBirthday =
    today.getMonth() + 1 < month || (today.getMonth() + 1 === month && today.getDate() < day)
  return today.getFullYear() - year - (beforeBirthday ? 1 : 0)
}

export function validateBirthDate(value: string, today: Date = new Date()): FieldErrorCode | null {
  if (value === '') return 'required'

  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (match === null) return 'invalid_format'

  const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])]
  const parsed = new Date(Date.UTC(year, month - 1, day))
  const isRealDate =
    parsed.getUTCFullYear() === year && parsed.getUTCMonth() === month - 1 && parsed.getUTCDate() === day
  if (!isRealDate) return 'not_a_real_date'

  // Las fechas ISO con relleno de ceros ordenan igual como texto que como fecha.
  if (value >= isoDate(today)) return 'not_in_the_past'

  const age = ageOn(year, month, day, today)
  return age < MIN_AGE || age > MAX_AGE ? 'age_out_of_range' : null
}

export function validateVehicleType(value: string): FieldErrorCode | null {
  if (value === '') return 'required'
  return (VEHICLE_TYPES as readonly string[]).includes(value) ? null : 'not_in_catalog'
}

export function validateUsageType(value: string): FieldErrorCode | null {
  if (value === '') return 'required'
  return (USAGE_TYPES as readonly string[]).includes(value) ? null : 'not_in_catalog'
}

export function validateField(field: FieldName, value: string, today: Date = new Date()): FieldErrorCode | null {
  switch (field) {
    case 'birthDate':
      return validateBirthDate(value, today)
    case 'vehicleType':
      return validateVehicleType(value)
    case 'usageType':
      return validateUsageType(value)
  }
}

export function validateQuoteRequest(input: QuoteRequestInput, today: Date = new Date()): FieldErrors {
  const errors: FieldErrors = {}
  for (const field of FIELD_ORDER) {
    const error = validateField(field, input[field], today)
    if (error !== null) errors[field] = error
  }
  return errors
}

export function firstInvalidField(errors: FieldErrors): FieldName | null {
  return FIELD_ORDER.find((field) => errors[field] !== undefined) ?? null
}

/** Devuelve el cuerpo de la petición, o null si algún campo no es válido todavía. */
export function toQuoteRequestPayload(input: QuoteRequestInput, today: Date = new Date()): QuoteRequestPayload | null {
  if (firstInvalidField(validateQuoteRequest(input, today)) !== null) return null
  return {
    birthDate: input.birthDate,
    vehicleType: input.vehicleType as VehicleType,
    usageType: input.usageType as UsageType,
  }
}
