import { useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { ArrowDownRight, ArrowUpRight, ChevronRight, FlaskConical, Pause, Play, Radio, TriangleAlert, X } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Link } from 'react-router'
import { Section } from '../../components/AppShell'
import { InstallCard } from '../../components/Install'
import { BetaPill, CrownMark, PlanCard, ThemeButton } from '../../components/Plan'
import { Insights } from './Insights'
import { Avatar, Badge, Card, Logo, Spinner } from '../../components/ui'
import { api, post } from '../../lib/api'
import { ago, money, price, toDate } from '../../lib/format'
import { useSession } from '../../lib/session'
import type { Home as HomeT, Position } from '../../lib/types'

/** When it filled, where, the broker's ticket and how fast the terminal answered. */
function TradeMeta({ p }: { p: Position }) {
  const at = p.filled_at ?? p.opened_at
  return (
    <span className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-3 tabular">
      {p.account?.practice ? <Badge>Practice</Badge> : <Badge tone="brand">{p.account?.label ?? 'Broker'}</Badge>}
      <span>{stamp(at)}</span>
      {!p.account?.practice && <span>#{p.ticket}</span>}
      {p.latency_ms != null && <span>{p.latency_ms < 1000 ? `${p.latency_ms} ms` : `${(p.latency_ms / 1000).toFixed(1)} s`} from post</span>}
      {p.status === 'closed' && p.closed_at && <span>closed {stamp(p.closed_at)}{p.close_price != null && ` @ ${price(p.close_price)}`}</span>}
    </span>
  )
}

export function stamp(iso: string): string {
  const d = toDate(iso)
  const today = new Date().toDateString() === d.toDateString()
  const time = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })
  return today ? time : `${d.toLocaleDateString([], { day: 'numeric', month: 'short' })} ${time}`
}

export function PositionRow({ p, action }: { p: Position; action?: ReactNode }) {
  const buy = p.side === 'buy'
  return (
    <li className="flex items-center gap-2">
      <Link to={`/app/trades/${p.id}`} className="-mx-2 flex min-w-0 flex-1 items-center gap-3 rounded-2xl px-2 py-3 hover:bg-surface-2 active:bg-surface-2">
      <span className={clsx('grid size-10 shrink-0 place-items-center rounded-2xl', buy ? 'bg-up-soft text-up' : 'bg-down-soft text-down')}>
        {buy ? <ArrowUpRight className="size-5" /> : <ArrowDownRight className="size-5" />}
      </span>
      <span className="min-w-0 flex-1">
        <span className="flex items-center gap-2 font-semibold">
          {p.symbol}
          <span className={clsx('text-xs font-bold uppercase', buy ? 'text-up' : 'text-down')}>{p.side}</span>
          <span className="text-sm font-medium text-ink-3 tabular">{p.volume}</span>
        </span>
        <span className="block truncate text-sm text-ink-3 tabular">
          @ {price(p.open_price)}
          {p.sl != null && ` · SL ${price(p.sl)}`}
          {p.tp != null && ` · TP ${price(p.tp)}`}
        </span>
        {p.account && <TradeMeta p={p} />}
      </span>
      <span className="text-right">
        <span className={clsx('block font-semibold tabular', (p.profit ?? 0) >= 0 ? 'text-up' : 'text-down')}>{p.profit == null ? '—' : money(p.profit, '')}</span>
        <span className="block text-xs text-ink-3">{p.channel}</span>
      </span>
      </Link>
      {action}
    </li>
  )
}

export default function Home() {
  const { user } = useSession()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['home'], queryFn: () => api<HomeT>('home'), refetchInterval: 30_000 })
  const [busy, setBusy] = useState(false)
  if (!q.data) return <Spinner />
  const h = q.data
  const copying = !h.paused && !h.global_pause
  const first = user?.name?.split(' ')[0]

  async function toggle() {
    setBusy(true)
    try {
      await post('pause', { on: !h.paused })
      await qc.invalidateQueries({ queryKey: ['home'] })
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="anim-rise space-y-5 px-4 safe-top md:px-0">
      <header className="flex h-16 items-center justify-between gap-2 md:h-20">
        <span className="flex items-center gap-2 md:hidden"><Logo size={26} />{user?.beta && <BetaPill />}</span>
        <h1 className="hidden text-3xl font-bold tracking-tight md:block">Home</h1>
        <span className="flex items-center gap-1">
          <span className="flex items-center gap-1 text-sm text-ink-3">Hi{first ? `, ${first}` : ''} <CrownMark plan={user?.plan} /></span>
          <span className="md:hidden"><ThemeButton /></span>
        </span>
      </header>

      {user?.beta && <BetaNote />}

      <div className={clsx('rounded-3xl p-4 text-white shadow-card', copying ? 'bg-brand' : 'bg-ink-2')}>
        <div className="flex items-center gap-3">
          <span className={clsx('relative grid size-12 place-items-center rounded-full bg-white/15')}>
            <Radio className="size-6" />
            {copying && <span className="absolute top-1 right-1 size-2.5 rounded-full bg-[#5dffb7] ring-2 ring-brand" />}
          </span>
          <span className="flex-1">
            <span className="block text-lg font-bold">{h.global_pause ? 'Paused by PipStream' : h.paused ? 'Copying is paused' : 'Copying is on'}</span>
            <span className="block text-sm opacity-85">
              {h.global_pause ? 'We paused all copying for safety. Open trades are untouched.' : h.paused ? 'New signals are skipped until you resume.' : `Watching ${h.onboarding.channels} channel${h.onboarding.channels === 1 ? '' : 's'}`}
            </span>
          </span>
        </div>
        {!h.global_pause && (
          <button onClick={toggle} disabled={busy} className="mt-4 flex h-11 w-full items-center justify-center gap-2 rounded-2xl bg-white/15 font-semibold active:bg-white/25 disabled:opacity-60">
            {h.paused ? <Play className="size-4" /> : <Pause className="size-4" />}
            {h.paused ? 'Resume copying' : 'Pause copying'}
          </button>
        )}
      </div>

      {!h.onboarding.complete && (
        <Link to={!h.onboarding.steps.telegram ? '/start/telegram' : !h.onboarding.steps.channels ? '/start/channels' : '/start/style'} className="flex items-center gap-3 rounded-3xl bg-warn-soft p-4 text-warn">
          <TriangleAlert className="size-5 shrink-0" />
          <span className="flex-1 font-semibold">Finish setting up to start copying</span>
          <ChevronRight className="size-5" />
        </Link>
      )}

      <div className="grid grid-cols-3 gap-2.5">
        {[
          ['Opened today', String(h.today.opened)],
          ['Closed P/L', money(h.today.closed_profit, '')],
          ['Failed', String(h.today.failed)],
        ].map(([l, v]) => (
          <Card key={l} className="p-3">
            <span className="block text-xs text-ink-3">{l}</span>
            <span className="mt-1 block text-lg font-bold tabular">{v}</span>
          </Card>
        ))}
      </div>

      <Insights channels={h.onboarding.channels} />

      <div className="space-y-4 lg:grid lg:grid-cols-2 lg:items-start lg:gap-5 lg:space-y-0">
      <Section title="Open trades" action={<Link to="/app/trades" className="text-sm font-semibold text-brand">All trades</Link>}>
        <Card className="py-1">
          {h.positions.length ? (
            <ul className="divide-y divide-line">{h.positions.map((p) => <PositionRow key={p.id} p={p} />)}</ul>
          ) : (
            <p className="py-6 text-center text-ink-3">No open trades. They appear here the second a signal is copied.</p>
          )}
        </Card>
      </Section>

      <Section title="Latest signals">
        <Card className="py-1">
          {h.signals.length ? (
            <ul className="divide-y divide-line">
              {h.signals.map((s) => (
                <li key={s.id}>
                  <Link to={`/app/signals/${s.id}`} className="-mx-2 flex items-center gap-3 rounded-2xl px-2 py-3 hover:bg-surface-2 active:bg-surface-2">
                  <Avatar title={s.channel ?? '?'} size={36} />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-[15px] font-semibold">{s.summary || s.kind}</span>
                    <span className="block truncate text-sm text-ink-3">{s.channel}</span>
                  </span>
                  <span className="shrink-0 text-xs text-ink-3">{ago(s.at)}</span>
                  </Link>
                </li>
              ))}
            </ul>
          ) : (
            <p className="py-6 text-center text-ink-3">Waiting for your channels to post.</p>
          )}
        </Card>
      </Section>
      </div>

      {!h.onboarding.real_broker && (
        <Link to="/app/brokers" className="flex items-center gap-3 rounded-3xl border border-line bg-surface p-4">
          <Badge tone="brand">Practice</Badge>
          <span className="flex-1 text-[15px]">Trades go to practice money. Connect your broker when ready.</span>
          <ChevronRight className="size-5 text-ink-3" />
        </Link>
      )}
      <div className="lg:max-w-md"><PlanCard plan={user?.plan} /></div>
      <InstallCard />
    </div>
  )
}

/** Beta notice; can be hidden on this device. */
function BetaNote() {
  const [hidden, setHidden] = useState(() => {
    try {
      return localStorage.getItem('ps.beta.hidden') === '1'
    } catch {
      return false
    }
  })
  if (hidden) return null
  return (
    <div className="flex items-start gap-3 rounded-3xl border border-warn/30 bg-warn-soft p-4 text-sm">
      <FlaskConical className="mt-0.5 size-5 shrink-0 text-warn" />
      <span className="flex-1 text-ink-2">
        <b className="text-warn">PipStream is in beta.</b> Signals are copied to your Practice account by default, and things may change as we improve. Tell us what you think.
      </span>
      <button
        aria-label="Hide beta notice"
        className="-m-1 grid size-8 shrink-0 place-items-center rounded-full text-ink-3 hover:bg-surface"
        onClick={() => {
          setHidden(true)
          try {
            localStorage.setItem('ps.beta.hidden', '1')
          } catch {}
        }}
      >
        <X className="size-4" />
      </button>
    </div>
  )
}
