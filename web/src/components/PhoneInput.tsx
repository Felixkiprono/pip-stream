import { ChevronDown } from 'lucide-react'
import { useMemo } from 'react'

// Countries most PipStream customers come from first; the rest alphabetically.
export const COUNTRIES = [
  { code: 'KE', dial: '254', name: 'Kenya', flag: '🇰🇪', len: 9 },
  { code: 'NG', dial: '234', name: 'Nigeria', flag: '🇳🇬', len: 10 },
  { code: 'ZA', dial: '27', name: 'South Africa', flag: '🇿🇦', len: 9 },
  { code: 'GH', dial: '233', name: 'Ghana', flag: '🇬🇭', len: 9 },
  { code: 'UG', dial: '256', name: 'Uganda', flag: '🇺🇬', len: 9 },
  { code: 'TZ', dial: '255', name: 'Tanzania', flag: '🇹🇿', len: 9 },
  { code: 'RW', dial: '250', name: 'Rwanda', flag: '🇷🇼', len: 9 },
  { code: 'ZM', dial: '260', name: 'Zambia', flag: '🇿🇲', len: 9 },
  { code: 'AE', dial: '971', name: 'United Arab Emirates', flag: '🇦🇪', len: 9 },
  { code: 'GB', dial: '44', name: 'United Kingdom', flag: '🇬🇧', len: 10 },
  { code: 'US', dial: '1', name: 'United States', flag: '🇺🇸', len: 10 },
  { code: 'IN', dial: '91', name: 'India', flag: '🇮🇳', len: 10 },
] as const
export type Country = (typeof COUNTRIES)[number]

/** "0712 345 678" or "712345678" -> "+254712345678". */
export function toE164(country: Country, local: string): string {
  const digits = local.replace(/\D/g, '').replace(/^0+/, '')
  return `+${country.dial}${digits}`
}

function group(d: string): string {
  return d.replace(/(\d{3})(?=\d)/g, '$1 ').trim()
}

export function PhoneInput({
  country,
  setCountry,
  value,
  setValue,
  error,
  onEnter,
}: {
  country: Country
  setCountry: (c: Country) => void
  value: string
  setValue: (v: string) => void
  error?: string
  onEnter?: () => void
}) {
  const shown = useMemo(() => group(value.replace(/\D/g, '').replace(/^0+/, '')), [value])
  return (
    <div>
      <div className={`flex h-15 items-stretch overflow-hidden rounded-2xl border bg-surface transition focus-within:border-brand focus-within:ring-4 focus-within:ring-brand/15 ${error ? 'border-down' : 'border-line'}`}>
        <label className="relative flex items-center gap-1.5 border-r border-line pr-2 pl-4">
          <span className="text-2xl leading-none">{country.flag}</span>
          <span className="text-[17px] font-medium">+{country.dial}</span>
          <ChevronDown className="size-4 text-ink-3" />
          <select
            aria-label="Country"
            className="absolute inset-0 opacity-0"
            value={country.code}
            onChange={(e) => setCountry(COUNTRIES.find((c) => c.code === e.target.value) ?? COUNTRIES[0])}
          >
            {COUNTRIES.map((c) => (
              <option key={c.code} value={c.code}>
                {c.flag} {c.name} (+{c.dial})
              </option>
            ))}
          </select>
        </label>
        <input
          aria-label="Phone number"
          inputMode="tel"
          autoComplete="tel-national"
          autoFocus
          placeholder={country.code === 'KE' ? '712 345 678' : 'Phone number'}
          className="min-w-0 flex-1 bg-transparent px-3 text-[19px] tracking-wide outline-none tabular placeholder:text-ink-3"
          value={shown}
          onChange={(e) => setValue(e.target.value.replace(/[^\d]/g, '').slice(0, 15))}
          onKeyDown={(e) => e.key === 'Enter' && onEnter?.()}
        />
      </div>
      {error && <p className="mt-2 text-sm text-down">{error}</p>}
    </div>
  )
}
