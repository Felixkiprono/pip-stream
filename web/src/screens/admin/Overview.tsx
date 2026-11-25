import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import {
  ArrowDownRight, ArrowUpRight, Ban, ChevronRight, CircleCheck, CircleX, Cpu, Filter, Gauge, OctagonX, Play, Radio, Send, UserPlus, Users, Zap,
} from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Link } from 'react-router'
import { DualArea, Donut, Sparkline } from '../../components/Charts'
import { Spinner } from '../../components/ui'
import { api, post } from '../../lib/api'
import { ago, toDate } from '../../lib/format'

type Win = { signals: number; rejected: number; intents: number; executed: number; failed: number; signups: number; active: number; p50: number | null }
type Dash = {
  days: number; bucket: 'hour' | 'day'; global_pause: boolean
  workers: { name: string; healthy: boolean; last_beat: number | null }[]
  series: { t: string; signals: number; rejected: number; executed: number; failed: number; signups: number; p50: number | null }[]
  now: Win; before: Win
  totals: { users: number; telegram: number; channels: number; open_positions: number }
  latency: { buckets: [string, number][]; p95: number | null }
  channels: { id: string; title: string; parsed: number; rejected: number; followers: number; last: string | null; rate: number | null }[]
  plans: Record<string, number>
  feed: { type: 'signal' | 'rejected' | 'order' | 'failed' | 'signup'; at: string; text: string; sub: string | null }[]
}

const RANGES: [number, string][] = [[1, '24h'], [7, '7d'], [14, '14d'], [30, '30d']]

function Panel({ title, action, children, className }: { title?: ReactNode; action?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section className={clsx('min-w-0 rounded-[22px] border border-line bg-surface p-4 shadow-[var(--shadow)] md:p-5', className)}>
      {(title || action) && (
        <header className="mb-3 flex items-center justify-between gap-3">
          <h2 className="text-[13px] font-semibold tracking-wide text-ink-3 uppercase">{title}</h2>
          {action}
        </header>
      )}
      {children}
    </section>
  )
}

/** Change vs the previous period. `lowerIsBetter` flips the colours (latency). */
function Delta({ now, before, lowerIsBetter }: { now: number | null; before: number | null; lowerIsBetter?: boolean }) {
  if (now == null || before == null) return null
  if (before === 0) return now ? <span className="rounded-full bg-brand-soft px-2 py-0.5 text-[11px] font-semibold text-brand">new</span> : null
  const pct = Math.round(((now - before) / before) * 100)
  if (!pct) return <span className="text-[11px] font-semibold text-ink-3">±0%</span>
  const good = lowerIsBetter ? pct < 0 : pct > 0
  return (
    <span className={clsx('inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-[11px] font-semibold', good ? 'bg-up-soft text-up' : 'bg-down-soft text-down')}>
      {pct > 0 ? <ArrowUpRight className="size-3" /> : <ArrowDownRight className="size-3" />}
      {Math.abs(pct)}%
    </span>
  )
}

function Kpi({ icon: Icon, label, value, unit, delta, spark, color, foot }: {
  icon: typeof Zap; label: string; value: string; unit?: string; delta?: ReactNode; spark: number[]; color: string; foot?: string
}) {
  return (
    <div className="relative min-w-0 overflow-hidden rounded-[22px] border border-line bg-surface p-3.5 shadow-[var(--shadow)] sm:p-4">
      <div className="flex items-center justify-between gap-2">
        <span className="flex min-w-0 items-center gap-2 text-[12px] font-medium text-ink-2 sm:text-[13px]">
          <span className="grid size-7 place-items-center rounded-lg" style={{ background: `color-mix(in oklab, ${color} 16%, transparent)`, color }}>
            <Icon className="size-4" />
          </span>
          {label}
        </span>
        {delta}
      </div>
      <div className="mt-3 flex items-end justify-between gap-2">
        <span className="text-[26px] leading-none font-bold tracking-tight tabular sm:text-[30px]">
          {value}
          {unit && <span className="ml-1 text-base font-semibold text-ink-3">{unit}</span>}
        </span>
        <Sparkline values={spark} color={color} className="hidden h-9 w-24 shrink-0 sm:block" />
      </div>
      <Sparkline values={spark} color={color} className="mt-2 h-6 w-full sm:hidden" />
      {foot && <p className="mt-2 line-clamp-2 text-xs text-ink-3 sm:truncate">{foot}</p>}
    </div>
  )
}

/** Posts → signals → trade intents → executed, with conversion between steps. */
function Funnel({ w }: { w: Win }) {
  const posts = w.signals + w.rejected
  const steps: [string, number, string, typeof Zap][] = [
    ['Posts read', posts, 'var(--ink-3)', Radio],
    ['Signals', w.signals, 'var(--brand)', Filter],
    ['Trades planned', w.intents, 'var(--violet)', Cpu],
    ['Executed', w.executed, 'var(--up)', CircleCheck],
  ]
  const max = Math.max(1, posts)
  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
      {steps.map(([label, n, color, Icon], i) => {
        const prev = i ? steps[i - 1][1] : null
        const conv = prev ? Math.round((100 * n) / prev) : null
        return (
          <div key={label} className="relative min-w-0">
            <div className="flex items-center gap-1.5 text-xs font-medium text-ink-3"><Icon className="size-3.5" style={{ color }} /> {label}</div>
            <div className="mt-1 flex items-baseline gap-2">
              <span className="text-2xl font-bold tabular">{n}</span>
              {conv != null && <span className="text-xs font-semibold text-ink-3 tabular" title={conv > 100 ? 'one signal can become several trades (one per take-profit)' : undefined}>{!prev ? '—' : conv > 100 ? `${(n / prev).toFixed(1)}× per signal` : `${conv}%`}</span>}
            </div>
            <div className="mt-2 h-2.5 overflow-hidden rounded-full bg-surface-2">
              <div className="h-full rounded-full transition-[width] duration-700" style={{ width: `${Math.max(n ? 4 : 0, (100 * n) / max)}%`, background: color }} />
            </div>
            {i < steps.length - 1 && <ChevronRight className="absolute top-6 -right-3 hidden size-4 text-ink-3 sm:block" />}
          </div>
        )
      })}
    </div>
  )
}

const FEED: Record<Dash['feed'][number]['type'], [typeof Zap, string]> = {
  signal: [Radio, 'var(--brand)'], rejected: [Ban, 'var(--ink-3)'], order: [Send, 'var(--up)'], failed: [CircleX, 'var(--down)'], signup: [UserPlus, 'var(--violet)'],
}

export default function AdminOverview() {
  const qc = useQueryClient()
  const [days, setDays] = useState(7)
  const q = useQuery({ queryKey: ['admin', 'dashboard', days], queryFn: () => api<Dash>(`admin/dashboard?days=${days}`), refetchInterval: 5000 })
  const kill = useMutation({
    mutationFn: (on: boolean) => post('admin/kill-switch', { on }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'dashboard'] }),
  })
  if (!q.data) return <Spinner />
  const d = q.data
  const label = (t: string) => toDate(t).toLocaleString(undefined, d.bucket === 'hour' ? { hour: '2-digit', minute: '2-digit' } : { day: 'numeric', month: 'short' })
  const col = (k: keyof Dash['series'][number]) => d.series.map((s) => Number(s[k] ?? 0))
  const healthy = d.workers.every((w) => w.healthy)
  const parseRate = d.now.signals + d.now.rejected ? Math.round((100 * d.now.signals) / (d.now.signals + d.now.rejected)) : null
  const plans = [
    { label: 'Free trial', value: d.plans.trial ?? 0, color: 'var(--ink-3)' },
    { label: 'Pro', value: d.plans.pro ?? 0, color: 'var(--brand)' },
    { label: 'Lifetime', value: d.plans.lifetime ?? 0, color: 'var(--warn)' },
    ...(d.plans.none ? [{ label: 'No plan', value: d.plans.none, color: 'var(--line)' }] : []),
  ]
  const premium = (d.plans.pro ?? 0) + (d.plans.lifetime ?? 0)
  const latMax = Math.max(1, ...d.latency.buckets.map(([, n]) => n))

  return (
    <div className="space-y-4 md:space-y-5">
      {/* header: live status, range, kill switch */}
      <div className="flex flex-wrap items-center gap-3">
        <div className="min-w-0 flex-1">
          <h1 className="text-2xl font-bold tracking-tight md:text-[28px]">Overview</h1>
          <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-ink-2">
            <span className="flex items-center gap-1.5">
              <span className={clsx('relative flex size-2.5')}>
                {!d.global_pause && healthy && <span className="absolute inline-flex size-full animate-ping rounded-full bg-up opacity-60" />}
                <span className={clsx('relative inline-flex size-2.5 rounded-full', d.global_pause ? 'bg-down' : healthy ? 'bg-up' : 'bg-warn')} />
              </span>
              {d.global_pause ? 'Copying stopped for everyone' : healthy ? 'Live: copying is running' : 'Degraded: a worker is down'}
            </span>
            {d.workers.map((w) => (
              <span key={w.name} className="flex items-center gap-1 text-xs text-ink-3">
                <span className={clsx('size-1.5 rounded-full', w.healthy ? 'bg-up' : 'bg-down')} /> {w.name}
              </span>
            ))}
          </p>
        </div>
        <div className="flex rounded-xl border border-line bg-surface p-1 text-sm font-semibold" role="radiogroup" aria-label="Period">
          {RANGES.map(([n, l]) => (
            <button key={n} role="radio" aria-checked={days === n} onClick={() => setDays(n)} className={clsx('rounded-lg px-3 py-1.5', days === n ? 'bg-ink text-bg' : 'text-ink-3 hover:text-ink')}>
              {l}
            </button>
          ))}
        </div>
        {d.global_pause ? (
          <button onClick={() => kill.mutate(false)} disabled={kill.isPending} className="flex h-10 items-center gap-2 rounded-xl bg-up px-4 text-sm font-semibold text-white disabled:opacity-60">
            <Play className="size-4" /> Resume copying
          </button>
        ) : (
          <button onClick={() => window.confirm('Stop all copying for every customer? Open positions are left alone.') && kill.mutate(true)} disabled={kill.isPending} className="flex h-10 items-center gap-2 rounded-xl border border-down/40 px-4 text-sm font-semibold text-down hover:bg-down-soft disabled:opacity-60">
            <OctagonX className="size-4" /> Kill switch
          </button>
        )}
      </div>

      {/* KPIs */}
      <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <Kpi icon={Radio} label="Signals parsed" value={String(d.now.signals)} delta={<Delta now={d.now.signals} before={d.before.signals} />} spark={col('signals')} color="var(--brand)" foot={parseRate != null ? `${parseRate}% of ${d.now.signals + d.now.rejected} posts were signals` : 'No posts in this period'} />
        <Kpi icon={Send} label="Orders executed" value={String(d.now.executed)} delta={<Delta now={d.now.executed} before={d.before.executed} />} spark={col('executed')} color="var(--up)" foot={`${d.now.failed} failed · ${d.totals.open_positions} positions open now`} />
        <Kpi icon={Gauge} label="Copy speed (p50)" value={d.now.p50 != null ? String(d.now.p50) : '—'} unit={d.now.p50 != null ? 'ms' : undefined} delta={<Delta now={d.now.p50} before={d.before.p50} lowerIsBetter />} spark={col('p50')} color="var(--violet)" foot={d.latency.p95 != null ? `p95 ${d.latency.p95} ms, signal post → broker order` : 'signal post → broker order'} />
        <Kpi icon={Users} label="New customers" value={String(d.now.signups)} delta={<Delta now={d.now.signups} before={d.before.signups} />} spark={col('signups')} color="var(--warn)" foot={`${d.totals.users} total · ${d.now.active} traded in this period`} />
      </div>

      {/* activity + pipeline */}
      <div className="grid gap-4 xl:grid-cols-[1.6fr_1fr]">
        <Panel
          title="Signals vs executed orders"
          action={<span className="flex gap-3 text-xs text-ink-3"><span className="flex items-center gap-1.5"><span className="size-2 rounded-full bg-brand" />Signals</span><span className="flex items-center gap-1.5"><span className="size-2 rounded-full bg-up" />Executed</span></span>}
        >
          <DualArea data={d.series.map((s) => ({ t: s.t, a: s.signals, b: s.executed }))} a="var(--brand)" b="var(--up)" labelOf={label} />
        </Panel>
        <Panel title="Pipeline">
          <Funnel w={d.now} />
          <div className="mt-5 grid grid-cols-3 gap-2 text-center">
            {[['Telegram readers', d.totals.telegram], ['Channels read', d.totals.channels], ['Failed orders', d.now.failed]].map(([l, v]) => (
              <div key={l as string} className="rounded-2xl bg-surface-2 px-2 py-3">
                <div className={clsx('text-xl font-bold tabular', l === 'Failed orders' && Number(v) > 0 && 'text-down')}>{v}</div>
                <div className="text-[11px] text-ink-3">{l}</div>
              </div>
            ))}
          </div>
        </Panel>
      </div>

      {/* channels + latency + plans */}
      <div className="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
        <Panel title="Top channels" action={<Link to="/admin/channels" className="text-xs font-semibold text-brand">All</Link>} className="xl:col-span-1">
          {d.channels.length ? (
            <ol className="space-y-3">
              {d.channels.map((c, i) => (
                <li key={c.id} className="flex items-center gap-3">
                  <span className={clsx('grid size-7 shrink-0 place-items-center rounded-lg text-xs font-bold', i === 0 ? 'bg-warn-soft text-warn' : 'bg-surface-2 text-ink-3')}>{i + 1}</span>
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-semibold">{c.title}</span>
                    <span className="block text-xs text-ink-3">{c.followers} copying · {c.last ? `last ${ago(c.last)}` : 'no posts yet'}</span>
                  </span>
                  <span className="text-right">
                    <span className="block text-sm font-bold tabular">{c.parsed}</span>
                    <span className="block text-[11px] text-ink-3 tabular">{c.rate != null ? `${c.rate}% parsed` : '—'}</span>
                  </span>
                </li>
              ))}
            </ol>
          ) : <p className="text-sm text-ink-3">No channels yet. They appear when customers follow one.</p>}
        </Panel>

        <Panel title="Copy speed" action={<span className="text-xs text-ink-3">post → order</span>}>
          <div className="flex h-40 items-end gap-2">
            {d.latency.buckets.map(([l, n], i) => (
              <div key={l} className="flex h-full min-w-0 flex-1 flex-col justify-end gap-1.5 text-center">
                <span className="text-xs font-semibold tabular">{n || ''}</span>
                <div className="w-full rounded-t-lg" style={{ height: `${Math.max(n ? 6 : 2, (100 * n) / latMax)}%`, background: i < 2 ? 'var(--up)' : i < 4 ? 'var(--warn)' : 'var(--down)', opacity: n ? 1 : 0.25 }} />
                <span className="truncate text-[10px] text-ink-3">{l}</span>
              </div>
            ))}
          </div>
          <p className="mt-3 text-xs text-ink-3">Green: under 250 ms. Most of the time is Telegram delivering the post.</p>
        </Panel>

        <Panel title="Customers by plan" className="lg:col-span-2 xl:col-span-1">
          <Donut parts={plans} center={String(d.totals.users)} sub="customers" />
          <p className="mt-3 text-xs text-ink-3">{premium} premium ({d.totals.users ? Math.round((100 * premium) / d.totals.users) : 0}%). <Link to="/admin/users" className="font-semibold text-brand">Manage customers</Link></p>
        </Panel>
      </div>

      {/* live feed */}
      <Panel title="Live activity" action={<span className="flex items-center gap-1.5 text-xs text-ink-3"><span className="size-1.5 animate-pulse rounded-full bg-up" /> updates every 5 s</span>}>
        {d.feed.length ? (
          <ol className="relative space-y-0.5 before:absolute before:top-2 before:bottom-2 before:left-[15px] before:w-px before:bg-line">
            {d.feed.map((e, i) => {
              const [Icon, color] = FEED[e.type]
              return (
                <li key={i} className="relative flex items-center gap-3 rounded-xl py-1.5 pr-2">
                  <span className="z-10 grid size-8 shrink-0 place-items-center rounded-full border border-line bg-surface" style={{ color }}><Icon className="size-4" /></span>
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-semibold">{e.text}</span>
                    {e.sub && <span className="block truncate text-xs text-ink-3">{e.sub}</span>}
                  </span>
                  <span className="shrink-0 text-xs text-ink-3">{ago(e.at)}</span>
                </li>
              )
            })}
          </ol>
        ) : <p className="text-sm text-ink-3">Nothing yet. Activity streams in here as signals arrive.</p>}
      </Panel>
    </div>
  )
}
