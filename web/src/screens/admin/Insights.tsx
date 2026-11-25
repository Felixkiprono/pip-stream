import { useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { Award, Banknote, ChevronRight, CircleX, Eye, Search, Send, UserPlus, Users, Wallet } from 'lucide-react'
import { useEffect, useState, type ReactNode } from 'react'
import { Link, useSearchParams } from 'react-router'
import { BarList, DualArea, Donut } from '../../components/Charts'
import { Badge, Card, ErrorNote, Spinner } from '../../components/ui'
import { ApiError, api } from '../../lib/api'
import { ago, price, toDate } from '../../lib/format'
import { H } from './Admin'

export const CONNECTOR: Record<string, string> = {
  paper: 'Practice', metaapi: 'MetaTrader (hosted)', ea_bridge: 'MetaTrader EA', mt5_bridge: 'MT5 bridge', deriv_options: 'Deriv',
}
const conn = (c: string) => CONNECTOR[c] ?? c

type Money = { currency: string; n: number; cents: number; payers?: number }
type Insights = {
  days: number
  funnel: { key: string; label: string; n: number }[]
  people: Record<'customers' | 'new' | 'seen_1d' | 'seen_7d' | 'seen_30d' | 'traders_1d' | 'traders_7d' | 'traders_window' | 'suspended' | 'paused' | 'admins', number>
  series: { t: string; signups: number; traders: number; fills: number; failed: number; signals: number }[]
  window: { fills: number; failed: number; users: number; p50: number | null; parsed: number; rejected: number }
  plans: { plan: string; name: string; status: string; n: number }[]
  revenue: { window: Money[]; all_time: Money[]; paying_now: Money[] }
  founding: { slots: number; taken: number; members: { id: string; name: string | null; email: string; number: number; joined: string; last_seen: string | null; fills: number }[] }
  brokers: { connector: string; accounts: number; enabled: number; owners: number }[]
  modes: { mode: string; rules: number; users: number }[]
  failures: { reason: string; n: number; users: number; last: string }[]
  by_connector: { connector: string; fills: number; failed: number }[]
  top_customers: { id: string; email: string; name: string | null; fills: number; failed: number; profit: number | null; last: string }[]
  top_channels: { id: string; title: string; active: boolean; followers: number }[]
}

function Panel({ title, action, children, className }: { title: ReactNode; action?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section className={clsx('min-w-0 rounded-[22px] border border-line bg-surface p-4 shadow-[var(--shadow)] md:p-5', className)}>
      <header className="mb-3 flex items-center justify-between gap-3">
        <h2 className="text-[13px] font-semibold tracking-wide text-ink-3 uppercase">{title}</h2>
        {action}
      </header>
      {children}
    </section>
  )
}

function Stat({ icon: Icon, label, value, foot, color }: { icon: typeof Users; label: string; value: ReactNode; foot?: ReactNode; color: string }) {
  return (
    <div className="min-w-0 rounded-[22px] border border-line bg-surface p-3.5 shadow-[var(--shadow)] sm:p-4">
      <span className="flex items-center gap-2 text-[12px] font-medium text-ink-2 sm:text-[13px]">
        <span className="grid size-7 place-items-center rounded-lg" style={{ background: `color-mix(in oklab, ${color} 16%, transparent)`, color }}><Icon className="size-4" /></span>
        {label}
      </span>
      <div className="mt-3 text-[26px] leading-none font-bold tracking-tight tabular sm:text-[30px]">{value}</div>
      {foot && <p className="mt-2 text-xs text-ink-3">{foot}</p>}
    </div>
  )
}

const amount = (m: Money[]) => (m.length ? m.map((r) => `${(r.cents / 100).toLocaleString()} ${r.currency}`).join(' · ') : '0')
const RANGES: [number, string][] = [[7, '7d'], [30, '30d'], [90, '90d']]
const PLAN_COLOR: Record<string, string> = { trial: 'var(--ink-3)', pro: 'var(--brand)', lifetime: 'var(--warn)', none: 'var(--line)' }

function Forbidden({ e }: { e: unknown }) {
  return <ErrorNote>{e instanceof ApiError && e.status === 403 ? 'Only the superadmin can see this page.' : 'Could not load this page.'}</ErrorNote>
}

/** Superadmin: the whole business at a glance. */
export default function AdminInsights() {
  const [days, setDays] = useState(30)
  const q = useQuery({ queryKey: ['admin', 'insights', days], queryFn: () => api<Insights>(`admin/insights?days=${days}`), refetchInterval: 30000 })
  if (q.error) return <><H>Insights</H><Forbidden e={q.error} /></>
  if (!q.data) return <Spinner />
  const d = q.data
  const p = d.people
  const top = Math.max(1, d.funnel[0]?.n ?? 0)
  const plans = Object.values(d.plans.reduce<Record<string, { label: string; value: number; color: string }>>((a, r) => {
    a[r.plan] ??= { label: r.name, value: 0, color: PLAN_COLOR[r.plan] ?? '#8b5cf6' }
    a[r.plan].value += r.n
    return a
  }, {}))
  const fillRate = d.window.fills + d.window.failed ? Math.round((100 * d.window.fills) / (d.window.fills + d.window.failed)) : null
  const label = (t: string) => toDate(t).toLocaleDateString(undefined, { day: 'numeric', month: 'short' })

  return (
    <div className="space-y-4 md:space-y-5">
      <div className="flex flex-wrap items-center gap-3">
        <div className="min-w-0 flex-1">
          <h1 className="text-2xl font-bold tracking-tight md:text-[28px]">Insights</h1>
          <p className="mt-1 text-sm text-ink-2">Every customer, what they connected and what they copy. Superadmin only.</p>
        </div>
        <div className="flex rounded-xl border border-line bg-surface p-1 text-sm font-semibold" role="radiogroup" aria-label="Period">
          {RANGES.map(([n, l]) => (
            <button key={n} role="radio" aria-checked={days === n} onClick={() => setDays(n)} className={clsx('rounded-lg px-3 py-1.5', days === n ? 'bg-ink text-bg' : 'text-ink-3 hover:text-ink')}>{l}</button>
          ))}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <Stat icon={Users} label="Customers" value={p.customers} color="var(--brand)" foot={`+${p.new} in ${days}d · ${p.suspended} suspended · ${p.paused} paused`} />
        <Stat icon={Eye} label="Active (seen 7d)" value={p.seen_7d} color="#8b5cf6" foot={`${p.seen_1d} today · ${p.seen_30d} in 30 days`} />
        <Stat icon={Send} label={`Fills (${days}d)`} value={d.window.fills} color="var(--up)" foot={`${d.window.failed} failed${fillRate != null ? ` · ${fillRate}% filled` : ''} · ${p.traders_window} customers traded`} />
        <Stat icon={Banknote} label={`Revenue (${days}d)`} value={<span className="text-xl sm:text-2xl">{amount(d.revenue.window)}</span>} color="var(--warn)" foot={`All time ${amount(d.revenue.all_time)}`} />
      </div>

      <div className="grid gap-4 xl:grid-cols-[1.4fr_1fr]">
        <Panel title="How far customers got">
          <ul className="space-y-3">
            {d.funnel.map((f, i) => (
              <li key={f.key}>
                <div className="mb-1 flex items-baseline justify-between gap-3 text-sm">
                  <span className="font-semibold">{f.label}</span>
                  <span className="text-xs text-ink-3 tabular"><b className="text-sm text-ink">{f.n}</b>{i > 0 && ` · ${Math.round((100 * f.n) / top)}%`}</span>
                </div>
                <div className="h-2.5 overflow-hidden rounded-full bg-surface-2">
                  <div className="h-full rounded-full bg-brand" style={{ width: `${Math.max(f.n ? 2 : 0, (100 * f.n) / top)}%`, opacity: 1 - i * 0.1 }} />
                </div>
              </li>
            ))}
          </ul>
          <p className="mt-3 text-xs text-ink-3">Customers only (admins left out). Broker means a real account, not Practice.</p>
        </Panel>
        <Panel title="Plans">
          <Donut parts={plans} center={String(p.customers)} sub="customers" />
          <div className="mt-4 space-y-1 text-sm">
            {d.plans.map((r) => (
              <div key={`${r.plan}-${r.status}`} className="flex justify-between gap-3"><span className="text-ink-2">{r.name} <span className="text-ink-3">· {r.status}</span></span><b className="tabular">{r.n}</b></div>
            ))}
            <div className="flex justify-between gap-3 border-t border-line pt-2"><span className="text-ink-2">Paying now</span><b className="tabular">{d.revenue.paying_now.reduce((a, r) => a + r.n, 0)} · {amount(d.revenue.paying_now)}/mo</b></div>
          </div>
        </Panel>
      </div>

      <Panel title="Every day" action={<span className="flex gap-3 text-xs text-ink-3"><span className="flex items-center gap-1.5"><span className="size-2 rounded-full bg-up" />Fills</span><span className="flex items-center gap-1.5"><span className="size-2 rounded-full bg-warn" />Customers who traded</span></span>}>
        <DualArea data={d.series.map((s) => ({ t: s.t, a: s.fills, b: s.traders }))} a="var(--up)" b="var(--warn)" labelOf={label} />
        <p className="mt-2 text-xs text-ink-3">{d.series.reduce((a, s) => a + s.signups, 0)} sign-ups · {d.window.parsed} signals parsed, {d.window.rejected} posts ignored · copy speed p50 {d.window.p50 ?? '—'} ms</p>
      </Panel>

      <div className="grid gap-4 lg:grid-cols-2">
        <Panel title={`Top customers (${days}d)`} action={<Link to="/admin/activity" className="text-xs font-semibold text-brand">All activity</Link>}>
          {!d.top_customers.length ? <p className="text-sm text-ink-3">No copying in this period.</p> : (
            <ul className="divide-y divide-line">
              {d.top_customers.map((u) => (
                <li key={u.id}>
                  <Link to={`/admin/users/${u.id}`} className="flex items-center gap-3 py-2 text-sm hover:text-brand">
                    <span className="min-w-0 flex-1 truncate font-semibold">{u.email}</span>
                    <span className="text-xs text-ink-3 tabular">{u.fills} fills{u.failed ? ` · ${u.failed} failed` : ''}</span>
                    {u.profit != null && <span className={clsx('w-20 text-right text-xs font-semibold tabular', u.profit >= 0 ? 'text-up' : 'text-down')}>{u.profit >= 0 ? '+' : ''}{u.profit}</span>}
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </Panel>
        <Panel title={`Why copies failed (${days}d)`}>
          {!d.failures.length ? <p className="text-sm text-ink-3">Nothing failed in this period.</p> : (
            <ul className="space-y-2">
              {d.failures.map((f) => (
                <li key={f.reason} className="flex items-start gap-2 text-sm">
                  <CircleX className="mt-0.5 size-4 shrink-0 text-down" />
                  <span className="min-w-0 flex-1 break-words">{f.reason}<span className="block text-xs text-ink-3">{f.users} customer{f.users === 1 ? '' : 's'} · last {ago(f.last)}</span></span>
                  <b className="tabular">{f.n}</b>
                </li>
              ))}
            </ul>
          )}
          {d.by_connector.length > 0 && (
            <div className="mt-4 border-t border-line pt-3 text-sm">
              {d.by_connector.map((c) => (
                <div key={c.connector} className="flex justify-between gap-3"><span className="text-ink-2">{conn(c.connector)}</span><span className="tabular">{c.fills} filled · <span className={c.failed ? 'text-down' : ''}>{c.failed} failed</span></span></div>
              ))}
            </div>
          )}
        </Panel>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Panel title="Broker accounts" action={<Link to="/admin/brokers" className="text-xs font-semibold text-brand">All accounts</Link>}>
          <BarList rows={d.brokers.map((b) => ({ label: conn(b.connector), value: b.accounts, sub: `${b.accounts} account${b.accounts === 1 ? '' : 's'} · ${b.owners} customer${b.owners === 1 ? '' : 's'}${b.enabled < b.accounts ? ` · ${b.accounts - b.enabled} off` : ''}` }))} />
        </Panel>
        <Panel title="Copy rules switched on">
          <BarList rows={['live', 'paper', 'shadow'].map((m) => {
            const r = d.modes.find((x) => x.mode === m)
            return { label: m === 'live' ? 'Live (real money)' : m === 'paper' ? 'Practice' : 'Watch only', value: r?.rules ?? 0, sub: `${r?.rules ?? 0} rules · ${r?.users ?? 0} customers`, tone: m === 'live' ? 'up' as const : 'brand' as const }
          })} />
        </Panel>
        <Panel title="Most followed channels" action={<Link to="/admin/channels" className="text-xs font-semibold text-brand">Channels</Link>}>
          <BarList rows={d.top_channels.map((c) => ({ label: c.title + (c.active ? '' : ' (off)'), value: c.followers, sub: `${c.followers} following` }))} />
        </Panel>
      </div>

      <Panel title={<span className="flex items-center gap-1.5"><Award className="size-4" /> Founding members · {d.founding.taken} of {d.founding.slots}</span>}>
        {!d.founding.members.length ? <p className="text-sm text-ink-3">No founding members yet.</p> : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[520px] text-left text-sm">
              <thead className="text-ink-3"><tr className="border-b border-line">{['#', 'Customer', 'Joined', 'Last seen', 'Fills'].map((h) => <th key={h} className="py-2 pr-3 font-semibold">{h}</th>)}</tr></thead>
              <tbody className="divide-y divide-line">
                {d.founding.members.map((m) => (
                  <tr key={m.id}>
                    <td className="py-2 pr-3 tabular">{m.number}</td>
                    <td className="py-2 pr-3"><Link to={`/admin/users/${m.id}`} className="font-semibold hover:text-brand">{m.name || m.email}</Link><span className="block text-xs text-ink-3">{m.email}</span></td>
                    <td className="py-2 pr-3 text-ink-3">{ago(m.joined)}</td>
                    <td className="py-2 pr-3 text-ink-3">{m.last_seen ? ago(m.last_seen) : 'not yet'}</td>
                    <td className="py-2 pr-3 tabular">{m.fills}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>
      <p className="text-xs text-ink-3">{p.admins} staff accounts · “Active” counts customers who opened the app; it started being recorded with this release.</p>
    </div>
  )
}

/* ------------------------------------------------------------------ brokers */

type BrokerRow = {
  id: string; connector: string; label: string | null; enabled: boolean; connected: boolean; platform: string | null; login_hint: string | null
  status: { state: string; detail?: string } | null
  owner: { id: string; email: string; name: string | null }; since: string
  rules: number; live_rules: number; fills_30d: number; failed_30d: number; last_order: string | null; open: number
}

function useSearch(key = 'q') {
  const [params, setParams] = useSearchParams()
  const [text, setText] = useState(params.get(key) ?? '')
  useEffect(() => {
    const t = setTimeout(() => {
      const next = new URLSearchParams(params)
      text.trim() ? next.set(key, text.trim()) : next.delete(key)
      if (next.toString() !== params.toString()) setParams(next, { replace: true })
    }, 300)
    return () => clearTimeout(t)
  }, [text]) // eslint-disable-line react-hooks/exhaustive-deps
  const set = (k: string, v: string, also: Record<string, string> = {}) => {
    const next = new URLSearchParams(params)
    for (const [key, val] of Object.entries({ [k]: v, ...also })) val ? next.set(key, val) : next.delete(key)
    setParams(next, { replace: true })
  }
  return { params, text, setText, set }
}

function SearchBox({ value, onChange, placeholder }: { value: string; onChange: (v: string) => void; placeholder: string }) {
  return (
    <label className="relative flex-1">
      <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-3" />
      <input className="h-11 w-full rounded-xl border border-line bg-surface pr-3 pl-9 text-[16px] outline-none focus:border-brand sm:text-sm" placeholder={placeholder} value={value} onChange={(e) => onChange(e.target.value)} aria-label={placeholder} />
    </label>
  )
}

function Tabs({ value, options, onChange }: { value: string; options: [string, string][]; onChange: (v: string) => void }) {
  return (
    <div className="flex overflow-x-auto rounded-xl bg-surface-2 p-1 text-sm font-semibold">
      {options.map(([v, l]) => (
        <button key={v} onClick={() => onChange(v)} className={clsx('rounded-lg px-3 py-1.5 whitespace-nowrap', value === v ? 'bg-surface text-ink shadow-sm' : 'text-ink-3')}>{l}</button>
      ))}
    </div>
  )
}

function state(b: BrokerRow) {
  if (!b.enabled) return <Badge>off</Badge>
  if (b.connector === 'paper') return <Badge tone="brand">practice</Badge>
  return b.connected ? <Badge tone="up">connected</Badge> : <Badge tone="warn">{b.status?.state ?? 'not connected'}</Badge>
}

/** Superadmin: every broker account across every customer. Never shows credentials. */
export function AdminBrokers() {
  const { params, text, setText, set } = useSearch()
  const connector = params.get('connector') ?? ''
  const qs = new URLSearchParams({ ...(params.get('q') ? { q: params.get('q')! } : {}), ...(connector ? { connector } : {}) }).toString()
  const q = useQuery({ queryKey: ['admin', 'brokers', qs], queryFn: () => api<{ connectors: string[]; accounts: BrokerRow[] }>(`admin/brokers${qs ? `?${qs}` : ''}`), refetchInterval: 15000 })
  if (q.error) return <><H>Brokers</H><Forbidden e={q.error} /></>
  const rows = q.data?.accounts
  return (
    <>
      <H>Broker accounts</H>
      <div className="mb-4 flex flex-col gap-2 sm:flex-row">
        <SearchBox value={text} onChange={setText} placeholder="Search customer email or account name" />
        <Tabs value={connector} options={[['', 'All'], ...(q.data?.connectors ?? []).map((c) => [c, conn(c)] as [string, string])]} onChange={(v) => set('connector', v)} />
      </div>
      {!rows ? <Spinner /> : !rows.length ? <p className="text-ink-3">No broker accounts match.</p> : (
        <>
          <ul className="space-y-2 md:hidden">
            {rows.map((b) => (
              <li key={b.id}>
                <Link to={`/admin/users/${b.owner.id}`} className="flex items-center gap-3 rounded-2xl border border-line bg-surface p-3 active:bg-surface-2">
                  <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-center gap-1.5 font-semibold">{b.label || conn(b.connector)} {state(b)}</span>
                    <span className="block truncate text-sm text-ink-2">{b.owner.email}</span>
                    <span className="mt-1 block text-xs text-ink-3">{conn(b.connector)}{b.login_hint ? ` · ${b.login_hint}` : ''} · {b.rules} rules{b.live_rules ? ` (${b.live_rules} live)` : ''} · {b.fills_30d} fills, {b.failed_30d} failed (30d) · {b.open} open</span>
                  </span>
                  <ChevronRight className="size-5 shrink-0 text-ink-3" />
                </Link>
              </li>
            ))}
          </ul>
          <Card className="hidden overflow-x-auto p-0 md:block">
            <table className="w-full text-left text-sm">
              <thead className="text-ink-3"><tr className="border-b border-line">{['Account', 'Customer', 'State', 'Rules', 'Fills 30d', 'Failed 30d', 'Open', 'Last order'].map((h) => <th key={h} className="px-4 py-3 font-semibold">{h}</th>)}</tr></thead>
              <tbody className="divide-y divide-line">
                {rows.map((b) => (
                  <tr key={b.id} className="hover:bg-surface-2">
                    <td className="px-4 py-3"><span className="font-semibold">{b.label || conn(b.connector)}</span><span className="block text-xs text-ink-3">{conn(b.connector)}{b.platform ? ` · ${b.platform.toUpperCase()}` : ''}{b.login_hint ? ` · ${b.login_hint}` : ''}</span></td>
                    <td className="px-4 py-3"><Link to={`/admin/users/${b.owner.id}`} className="font-semibold hover:text-brand">{b.owner.email}</Link><span className="block text-xs text-ink-3">since {ago(b.since)}</span></td>
                    <td className="px-4 py-3">{state(b)}{b.status?.detail && !b.connected && <span className="mt-1 block max-w-48 text-xs text-ink-3">{b.status.detail}</span>}</td>
                    <td className="px-4 py-3 tabular">{b.rules}{b.live_rules ? <span className="text-up"> · {b.live_rules} live</span> : ''}</td>
                    <td className="px-4 py-3 tabular">{b.fills_30d}</td>
                    <td className={clsx('px-4 py-3 tabular', b.failed_30d && 'text-down')}>{b.failed_30d}</td>
                    <td className="px-4 py-3 tabular">{b.open}</td>
                    <td className="px-4 py-3 text-ink-3">{b.last_order ? ago(b.last_order) : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>
          <p className="mt-3 text-xs text-ink-3">Newest 300. Passwords stay sealed to the trading engine and are never shown here.</p>
        </>
      )}
    </>
  )
}

/* ------------------------------------------------------------------ activity */

type OrderRow = {
  id: string; user_id: string; email: string; action: string; status: string; symbol: string | null; side: string | null; volume: number | null
  price: number | null; ticket: string | null; error: string | null; latency_ms: number | null; created_at: string; connector: string; label: string | null; channel: string | null
}
type PositionRow = {
  id: string; user_id: string; email: string; symbol: string; side: string | null; volume: number | null; open_price: number | null; close_price: number | null
  status: string; profit: number | null; ticket: string; opened_at: string; closed_at: string | null; connector: string; label: string | null; channel: string | null
}
type Activity = { view: 'orders' | 'positions'; rows: (OrderRow | PositionRow)[]; summary: { fills_24h: number; failed_24h: number; open: number; profit_24h: number } }

/** Superadmin: copying across every customer, newest first. */
export function AdminActivity() {
  const { params, text, setText, set } = useSearch()
  const view = params.get('view') === 'positions' ? 'positions' : 'orders'
  const status = params.get('status') ?? ''
  const user = params.get('user') ?? ''
  const qs = new URLSearchParams({ view, ...(status ? { status } : {}), ...(params.get('q') ? { q: params.get('q')! } : {}), ...(user ? { user } : {}) }).toString()
  const q = useQuery({ queryKey: ['admin', 'activity', qs], queryFn: () => api<Activity>(`admin/activity?${qs}`), refetchInterval: 10000 })
  if (q.error) return <><H>Copy activity</H><Forbidden e={q.error} /></>
  const s = q.data?.summary
  return (
    <>
      <H>Copy activity</H>
      {s && (
        <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
          <Stat icon={Send} label="Fills (24h)" value={s.fills_24h} color="var(--up)" />
          <Stat icon={CircleX} label="Failed (24h)" value={s.failed_24h} color="var(--down)" />
          <Stat icon={Wallet} label="Open positions" value={s.open} color="var(--brand)" />
          <Stat icon={UserPlus} label="Closed P/L (24h)" value={<span className={s.profit_24h >= 0 ? 'text-up' : 'text-down'}>{s.profit_24h >= 0 ? '+' : ''}{s.profit_24h}</span>} color="var(--warn)" foot="sum across accounts, mixed currencies" />
        </div>
      )}
      <div className="mb-4 flex flex-col gap-2 lg:flex-row">
        <Tabs value={view} options={[['orders', 'Orders'], ['positions', 'Positions']]} onChange={(v) => set('view', v, { status: '' })} />
        <SearchBox value={text} onChange={setText} placeholder="Search customer email or symbol" />
        <Tabs value={status} options={view === 'orders' ? [['', 'All'], ['executed', 'Filled'], ['failed', 'Failed']] : [['', 'All'], ['open', 'Open'], ['closed', 'Closed']]} onChange={(v) => set('status', v)} />
      </div>
      {user && <p className="mb-3 text-sm text-ink-2">One customer only · <button className="font-semibold text-brand" onClick={() => set('user', '')}>show everyone</button></p>}
      {!q.data ? <Spinner /> : !q.data.rows.length ? <p className="text-ink-3">Nothing here yet.</p> : (
        <Card className="overflow-x-auto p-0">
          {q.data.view === 'orders' ? (
            <table className="w-full min-w-[760px] text-left text-sm">
              <thead className="text-ink-3"><tr className="border-b border-line">{['When', 'Customer', 'Trade', 'Account', 'Result', 'Speed'].map((h) => <th key={h} className="px-4 py-3 font-semibold">{h}</th>)}</tr></thead>
              <tbody className="divide-y divide-line">
                {(q.data.rows as OrderRow[]).map((o) => (
                  <tr key={o.id}>
                    <td className="px-4 py-3 whitespace-nowrap text-ink-3">{ago(o.created_at)}</td>
                    <td className="px-4 py-3"><Link to={`/admin/users/${o.user_id}`} className="font-semibold hover:text-brand">{o.email}</Link>{o.channel && <span className="block text-xs text-ink-3">{o.channel}</span>}</td>
                    <td className="px-4 py-3">{o.action} {o.side?.toUpperCase()} {o.symbol}<span className="block text-xs text-ink-3 tabular">{o.volume ?? ''}{o.price ? ` @ ${price(o.price)}` : ''}{o.ticket ? ` · #${o.ticket}` : ''}</span></td>
                    <td className="px-4 py-3">{o.label || conn(o.connector)}<span className="block text-xs text-ink-3">{conn(o.connector)}</span></td>
                    <td className="px-4 py-3">{o.status === 'executed' ? <Badge tone="up">filled</Badge> : <><Badge tone="down">{o.status}</Badge>{o.error && <span className="mt-1 block max-w-64 text-xs text-ink-3">{o.error}</span>}</>}</td>
                    <td className="px-4 py-3 text-ink-3 tabular">{o.latency_ms != null ? `${o.latency_ms} ms` : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <table className="w-full min-w-[760px] text-left text-sm">
              <thead className="text-ink-3"><tr className="border-b border-line">{['Opened', 'Customer', 'Trade', 'Account', 'Status', 'P/L'].map((h) => <th key={h} className="px-4 py-3 font-semibold">{h}</th>)}</tr></thead>
              <tbody className="divide-y divide-line">
                {(q.data.rows as PositionRow[]).map((t) => (
                  <tr key={t.id}>
                    <td className="px-4 py-3 whitespace-nowrap text-ink-3">{ago(t.opened_at)}</td>
                    <td className="px-4 py-3"><Link to={`/admin/users/${t.user_id}`} className="font-semibold hover:text-brand">{t.email}</Link>{t.channel && <span className="block text-xs text-ink-3">{t.channel}</span>}</td>
                    <td className="px-4 py-3">{t.side?.toUpperCase()} {t.symbol}<span className="block text-xs text-ink-3 tabular">{t.volume ?? ''} @ {price(t.open_price)}{t.close_price ? ` → ${price(t.close_price)}` : ''}</span></td>
                    <td className="px-4 py-3">{t.label || conn(t.connector)}<span className="block text-xs text-ink-3">#{t.ticket}</span></td>
                    <td className="px-4 py-3">{t.status === 'open' ? <Badge tone="brand">open</Badge> : <Badge>closed {t.closed_at ? ago(t.closed_at) : ''}</Badge>}</td>
                    <td className={clsx('px-4 py-3 font-semibold tabular', (t.profit ?? 0) > 0 ? 'text-up' : (t.profit ?? 0) < 0 ? 'text-down' : 'text-ink-3')}>{t.profit != null ? `${t.profit > 0 ? '+' : ''}${t.profit}` : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
      )}
      <p className="mt-3 text-xs text-ink-3">Newest 200. Refreshes every 10 seconds.</p>
    </>
  )
}
