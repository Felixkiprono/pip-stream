/** Parse ISO and Postgres-style timestamps ("2026-11-20 11:52:13.672036+00") in every browser. */
export function toDate(v: string): Date {
  const iso = v
    .replace(' ', 'T')
    .replace(/(\.\d{3})\d+/, '$1')
    .replace(/([+-]\d{2})$/, '$1:00')
  const d = new Date(iso)
  return isNaN(d.getTime()) ? new Date(v) : d
}

export function ago(iso: string | null | undefined): string {
  if (!iso) return ''
  const s = Math.max(0, (Date.now() - toDate(iso).getTime()) / 1000)
  if (s < 45) return 'just now'
  if (s < 3600) return `${Math.round(s / 60)}m ago`
  if (s < 86400) return `${Math.round(s / 3600)}h ago`
  return `${Math.round(s / 86400)}d ago`
}

export function money(v: number | null | undefined, currency = 'USD'): string {
  if (v === null || v === undefined) return '—'
  const sign = v > 0 ? '+' : v < 0 ? '−' : ''
  return `${sign}${Math.abs(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`
}

export function price(v: number | null | undefined): string {
  if (v === null || v === undefined) return '—'
  return String(+v.toFixed(5))
}

export function initials(title: string): string {
  const w = title.replace(/[^\p{L}\p{N} ]/gu, '').trim().split(/\s+/)
  return ((w[0]?.[0] ?? '?') + (w[1]?.[0] ?? '')).toUpperCase()
}

/** A stable soft colour per channel so the avatar is recognisable. */
export function hue(seed: string | number): number {
  let h = 0
  for (const ch of String(seed)) h = (h * 31 + ch.charCodeAt(0)) % 360
  return h
}
