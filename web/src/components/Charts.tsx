// Small, dependency-free SVG charts. Colors come from the theme tokens, so they follow light/dark.
import { useId } from 'react'

const fmtDay = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' })

/** Signals received vs trades opened per day. */
export function ActivityChart({ series }: { series: { day: string; signals: number; ignored: number; opened: number }[] }) {
  const W = 600, H = 170, pad = { l: 26, r: 6, t: 8, b: 22 }
  const max = Math.max(1, ...series.map((s) => Math.max(s.signals + s.ignored, s.opened)))
  const step = (W - pad.l - pad.r) / Math.max(series.length, 1)
  const bw = Math.max(3, Math.min(14, step / 2 - 2))
  const y = (v: number) => H - pad.b - (v / max) * (H - pad.t - pad.b)
  const ticks = [0, Math.ceil(max / 2), max]
  const every = Math.ceil(series.length / 7)
  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="h-auto w-full" role="img" aria-label="Signals and trades per day">
      {ticks.map((t) => (
        <g key={t}>
          <line x1={pad.l} x2={W - pad.r} y1={y(t)} y2={y(t)} stroke="var(--line)" strokeDasharray={t ? '3 4' : undefined} />
          <text x={pad.l - 6} y={y(t) + 4} textAnchor="end" fontSize="10" fill="var(--ink-3)">{t}</text>
        </g>
      ))}
      {series.map((s, i) => {
        const x = pad.l + i * step + step / 2
        return (
          <g key={s.day}>
            <title>{`${fmtDay(s.day)}: ${s.signals} signals, ${s.ignored} ignored posts, ${s.opened} trades`}</title>
            <rect x={x - bw - 1} y={y(s.signals + s.ignored)} width={bw} height={y(0) - y(s.signals + s.ignored)} rx="2" fill="var(--brand-soft)" />
            <rect x={x - bw - 1} y={y(s.signals)} width={bw} height={y(0) - y(s.signals)} rx="2" fill="var(--brand)" />
            <rect x={x + 1} y={y(s.opened)} width={bw} height={y(0) - y(s.opened)} rx="2" fill="var(--up)" />
            {i % every === 0 && <text x={x} y={H - 6} textAnchor="middle" fontSize="10" fill="var(--ink-3)">{fmtDay(s.day)}</text>}
          </g>
        )
      })}
    </svg>
  )
}

export function Legend({ items }: { items: [string, string][] }) {
  return (
    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-3">
      {items.map(([label, color]) => (
        <span key={label} className="flex items-center gap-1.5">
          <span className="size-2.5 rounded-sm" style={{ background: color }} />
          {label}
        </span>
      ))}
    </div>
  )
}

/** Cumulative closed profit/loss over time. */
export function PnlCurve({ points }: { points: { t: string; v: number }[] }) {
  const id = useId().replace(/:/g, '')
  const W = 600, H = 160, pad = { l: 40, r: 8, t: 10, b: 10 }
  const pts = points.length === 1 ? [{ t: points[0].t, v: 0 }, points[0]] : points
  const vals = pts.map((p) => p.v)
  const lo = Math.min(0, ...vals), hi = Math.max(0, ...vals)
  const span = hi - lo || 1
  const x = (i: number) => pad.l + (i / Math.max(pts.length - 1, 1)) * (W - pad.l - pad.r)
  const y = (v: number) => pad.t + ((hi - v) / span) * (H - pad.t - pad.b)
  const last = vals[vals.length - 1] ?? 0
  const color = last >= 0 ? 'var(--up)' : 'var(--down)'
  const line = pts.map((p, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(p.v).toFixed(1)}`).join(' ')
  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="h-auto w-full" role="img" aria-label={`Cumulative profit and loss, now ${last.toFixed(2)}`}>
      <defs>
        <linearGradient id={`g${id}`} x1="0" x2="0" y1="0" y2="1">
          <stop offset="0" stopColor={color} stopOpacity="0.28" />
          <stop offset="1" stopColor={color} stopOpacity="0" />
        </linearGradient>
      </defs>
      <line x1={pad.l} x2={W - pad.r} y1={y(0)} y2={y(0)} stroke="var(--line)" />
      <text x={pad.l - 6} y={y(hi) + 4} textAnchor="end" fontSize="10" fill="var(--ink-3)">{hi.toFixed(0)}</text>
      <text x={pad.l - 6} y={y(lo) + 4} textAnchor="end" fontSize="10" fill="var(--ink-3)">{lo.toFixed(0)}</text>
      <path d={`${line} L${x(pts.length - 1)},${y(0)} L${x(0)},${y(0)} Z`} fill={`url(#g${id})`} />
      <path d={line} fill="none" stroke={color} strokeWidth="2.5" strokeLinejoin="round" strokeLinecap="round" />
      <circle cx={x(pts.length - 1)} cy={y(last)} r="4" fill={color} />
    </svg>
  )
}

/** Win/loss donut with the win rate in the middle. */
export function WinRing({ wins, losses }: { wins: number; losses: number }) {
  const total = wins + losses
  const r = 42, c = 2 * Math.PI * r
  const w = total ? (wins / total) * c : 0
  return (
    <svg viewBox="0 0 110 110" className="size-28 shrink-0" role="img" aria-label={`${wins} wins, ${losses} losses`}>
      <circle cx="55" cy="55" r={r} fill="none" stroke={total ? 'var(--down)' : 'var(--surface-2)'} strokeWidth="12" />
      {total > 0 && <circle cx="55" cy="55" r={r} fill="none" stroke="var(--up)" strokeWidth="12" strokeDasharray={`${w} ${c - w}`} transform="rotate(-90 55 55)" strokeLinecap={wins && losses ? 'round' : 'butt'} />}
      <text x="55" y="54" textAnchor="middle" fontSize="20" fontWeight="700" fill="var(--ink)">{total ? `${Math.round((100 * wins) / total)}%` : '—'}</text>
      <text x="55" y="70" textAnchor="middle" fontSize="10" fill="var(--ink-3)">win rate</text>
    </svg>
  )
}

/** Horizontal bars: label, value, optional secondary text. */
export function BarList({ rows }: { rows: { label: string; value: number; sub?: string; tone?: 'brand' | 'up' | 'down' }[] }) {
  const max = Math.max(1, ...rows.map((r) => r.value))
  return (
    <ul className="space-y-2.5">
      {rows.map((r) => (
        <li key={r.label}>
          <div className="mb-1 flex items-baseline justify-between gap-3 text-sm">
            <span className="min-w-0 truncate font-semibold">{r.label}</span>
            <span className="shrink-0 text-xs text-ink-3 tabular">{r.sub}</span>
          </div>
          <div className="h-2 overflow-hidden rounded-full bg-surface-2">
            <div className="h-full rounded-full" style={{ width: `${(100 * r.value) / max}%`, background: `var(--${r.tone ?? 'brand'})` }} />
          </div>
        </li>
      ))}
    </ul>
  )
}

/** Tiny trend line for KPI cards. */
export function Sparkline({ values, color = 'var(--brand)', className = 'h-8 w-24' }: { values: number[]; color?: string; className?: string }) {
  const id = useId().replace(/:/g, '')
  const W = 100, H = 32
  const v = values.length > 1 ? values : [0, ...values, 0].slice(0, 2)
  const hi = Math.max(1, ...v), lo = Math.min(0, ...v)
  const x = (i: number) => (i / (v.length - 1)) * W
  const y = (n: number) => H - 2 - ((n - lo) / (hi - lo || 1)) * (H - 4)
  const d = v.map((n, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(n).toFixed(1)}`).join(' ')
  return (
    <svg viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none" className={className} aria-hidden>
      <defs>
        <linearGradient id={`s${id}`} x1="0" x2="0" y1="0" y2="1">
          <stop offset="0" stopColor={color} stopOpacity="0.3" />
          <stop offset="1" stopColor={color} stopOpacity="0" />
        </linearGradient>
      </defs>
      <path d={`${d} L${W},${H} L0,${H} Z`} fill={`url(#s${id})`} />
      <path d={d} fill="none" stroke={color} strokeWidth="2" vectorEffect="non-scaling-stroke" strokeLinejoin="round" />
    </svg>
  )
}

/** Two overlaid smooth areas over time (e.g. signals vs executed orders). */
export function DualArea({ data, a, b, labelOf }: { data: { a: number; b: number; t: string }[]; a: string; b: string; labelOf: (t: string) => string }) {
  const id = useId().replace(/:/g, '')
  const W = 800, H = 220, pad = { l: 30, r: 10, t: 12, b: 24 }
  const max = Math.max(1, ...data.map((d) => Math.max(d.a, d.b)))
  const n = Math.max(data.length - 1, 1)
  const x = (i: number) => pad.l + (i / n) * (W - pad.l - pad.r)
  const y = (v: number) => pad.t + (1 - v / max) * (H - pad.t - pad.b)
  const path = (k: 'a' | 'b') => {
    if (!data.length) return ''
    let p = `M${x(0)},${y(data[0][k])}`
    for (let i = 1; i < data.length; i++) {
      const cx = (x(i - 1) + x(i)) / 2
      p += ` C${cx},${y(data[i - 1][k])} ${cx},${y(data[i][k])} ${x(i)},${y(data[i][k])}`
    }
    return p
  }
  const every = Math.ceil(data.length / 8)
  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="h-auto w-full" role="img" aria-label="Activity over time">
      <defs>
        {[['a', a], ['b', b]].map(([k, c]) => (
          <linearGradient key={k} id={`${k}${id}`} x1="0" x2="0" y1="0" y2="1">
            <stop offset="0" stopColor={c} stopOpacity="0.35" />
            <stop offset="1" stopColor={c} stopOpacity="0" />
          </linearGradient>
        ))}
      </defs>
      {[0, 0.5, 1].map((f) => (
        <g key={f}>
          <line x1={pad.l} x2={W - pad.r} y1={y(max * f)} y2={y(max * f)} stroke="var(--line)" strokeDasharray={f ? '3 5' : undefined} />
          <text x={pad.l - 8} y={y(max * f) + 4} textAnchor="end" fontSize="11" fill="var(--ink-3)">{Math.round(max * f)}</text>
        </g>
      ))}
      {(['a', 'b'] as const).map((k) => (
        <g key={k}>
          <path d={`${path(k)} L${x(data.length - 1)},${y(0)} L${x(0)},${y(0)} Z`} fill={`url(#${k}${id})`} />
          <path d={path(k)} fill="none" stroke={k === 'a' ? a : b} strokeWidth="2.5" strokeLinecap="round" />
        </g>
      ))}
      {data.map((d, i) => (
        <g key={d.t}>
          <title>{`${labelOf(d.t)}: ${d.a} / ${d.b}`}</title>
          <rect x={x(i) - (W / data.length) / 2} y={pad.t} width={W / data.length} height={H - pad.t - pad.b} fill="transparent" />
          {i % every === 0 && <text x={x(i)} y={H - 6} textAnchor="middle" fontSize="11" fill="var(--ink-3)">{labelOf(d.t)}</text>}
        </g>
      ))}
    </svg>
  )
}

/** Donut with a legend, for shares (e.g. plan mix). */
export function Donut({ parts, center, sub }: { parts: { label: string; value: number; color: string }[]; center: string; sub: string }) {
  const r = 40, c = 2 * Math.PI * r
  const total = parts.reduce((a, p) => a + p.value, 0)
  let off = 0
  return (
    <div className="flex items-center gap-5">
      <svg viewBox="0 0 110 110" className="size-32 shrink-0" role="img" aria-label={parts.map((p) => `${p.label} ${p.value}`).join(', ')}>
        <circle cx="55" cy="55" r={r} fill="none" stroke="var(--surface-2)" strokeWidth="14" />
        {total > 0 && parts.map((p) => {
          const len = (p.value / total) * c
          const el = <circle key={p.label} cx="55" cy="55" r={r} fill="none" stroke={p.color} strokeWidth="14" strokeDasharray={`${len} ${c - len}`} strokeDashoffset={-off} transform="rotate(-90 55 55)" />
          off += len
          return el
        })}
        <text x="55" y="55" textAnchor="middle" fontSize="22" fontWeight="700" fill="var(--ink)">{center}</text>
        <text x="55" y="71" textAnchor="middle" fontSize="10" fill="var(--ink-3)">{sub}</text>
      </svg>
      <ul className="min-w-0 flex-1 space-y-2 text-sm">
        {parts.map((p) => (
          <li key={p.label} className="flex items-center gap-2">
            <span className="size-2.5 shrink-0 rounded-full" style={{ background: p.color }} />
            <span className="flex-1 truncate text-ink-2">{p.label}</span>
            <b className="tabular">{p.value}</b>
            <span className="w-10 text-right text-xs text-ink-3 tabular">{total ? Math.round((100 * p.value) / total) : 0}%</span>
          </li>
        ))}
      </ul>
    </div>
  )
}
