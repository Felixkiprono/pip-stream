import { useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { ArrowDownRight, ArrowLeft, ArrowUpRight, CheckCircle2, CircleSlash, Clock, Cpu, MessageSquareQuote, Radio, Reply, ShieldCheck, Sparkles, XCircle } from 'lucide-react'
import type { ReactNode } from 'react'
import { useNavigate, useParams } from 'react-router'
import { Avatar, Badge, Card, Spinner } from '../../components/ui'
import { api } from '../../lib/api'
import { money, price, toDate } from '../../lib/format'

type Leg = { id: string; ticket: string; symbol: string; side: 'buy' | 'sell' | null; volume: number | null; open_price: number | null; sl: number | null; tp: number | null; tp_index: number | null; status: 'open' | 'closed'; close_price: number | null; profit: number | null; opened_at: string; closed_at: string | null }
type Story = {
  signal: { id: string; status: string; kind: string | null; symbol: string | null; side: string | null; confidence: number | null; reason: string | null
    entry: { type: string; price: number | null; from: number | null; to: number | null } | null; sl: number | null; tps: number[]; raw_text: string; issues: string[]
    message_id: number; edits: number; posted_at: string | null; parsed_at: string }
  channel: { id: string; title: string | null; username: string | null }
  broker: { connector: string; label: string | null } | null
  positions: Leg[]
  orders: { id: string; action: string; status: string; ticket: string | null; volume: number | null; price: number | null; error: string | null; latency_ms: number | null; created_at: string; source: 'signal' | 'followup' }[]
  followups: { id: string; text: string; raw_text: string; status: string; at: string }[]
  timeline: { step: string; label: string; at: string; ms?: number | null }[]
  totals: { legs: number; open: number; profit: number; volume: number; latency_ms: number | null }
}
type Resp = { detail: Story | null; focus?: string; alert?: { title: string; body: string; kind: string; at: string } }

const STEP: Record<string, [typeof Clock, string]> = {
  posted: [Radio, 'text-ink-2 bg-surface-2'], parsed: [Sparkles, 'text-brand bg-brand-soft'], planned: [Cpu, 'text-brand bg-brand-soft'],
  executed: [CheckCircle2, 'text-up bg-up-soft'], failed: [XCircle, 'text-down bg-down-soft'], followup: [Reply, 'text-brand bg-brand-soft'],
  closed: [ShieldCheck, 'text-ink-2 bg-surface-2'], skipped: [CircleSlash, 'text-warn bg-warn-soft'],
}

const time = (t: string) => toDate(t).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' })
const day = (t: string) => toDate(t).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })
function since(a: string, b: string) {
  const ms = toDate(b).getTime() - toDate(a).getTime()
  if (ms < 1000) return `+${Math.max(0, Math.round(ms))} ms`
  if (ms < 60_000) return `+${(ms / 1000).toFixed(1)} s`
  if (ms < 3_600_000) return `+${Math.round(ms / 60_000)} min`
  return `+${(ms / 3_600_000).toFixed(1)} h`
}
function entryText(e: Story['signal']['entry']) {
  if (!e) return '—'
  if (e.type === 'range') return `${price(e.from)} – ${price(e.to)}`
  if (e.type === 'market') return e.price != null ? `Market (~${price(e.price)})` : 'Market'
  return `${e.type} ${price(e.price)}`
}

function Row({ k, children }: { k: string; children: ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 py-2 text-sm">
      <dt className="text-ink-3">{k}</dt>
      <dd className="text-right font-semibold tabular">{children}</dd>
    </div>
  )
}

/** One signal's whole story: post → understood → sized → filled → follow-ups → closed. */
export function StoryView({ d, focus, alert }: { d: Story; focus?: string; alert?: Resp['alert'] }) {
  const s = d.signal
  const buy = s.side === 'buy'
  const t0 = d.timeline[0]?.at
  return (
    <div className="space-y-4">
      {/* headline */}
      <Card className="space-y-3">
        <div className="flex items-start gap-3">
          <span className={clsx('grid size-12 shrink-0 place-items-center rounded-2xl', s.side ? (buy ? 'bg-up-soft text-up' : 'bg-down-soft text-down') : 'bg-surface-2 text-ink-2')}>
            {s.side ? (buy ? <ArrowUpRight className="size-6" /> : <ArrowDownRight className="size-6" />) : <Radio className="size-6" />}
          </span>
          <span className="min-w-0 flex-1">
            <span className="block text-xl font-bold tracking-tight">
              {s.kind === 'open' ? `${s.side?.toUpperCase()} ${s.symbol}` : s.kind === 'close' ? 'Close signal' : s.kind === 'modify' ? 'Update signal' : s.symbol ?? 'Post'}
            </span>
            <span className="flex flex-wrap items-center gap-1.5 text-sm text-ink-3">
              {d.channel.title}{d.channel.username && ` · @${d.channel.username}`} · {s.posted_at ? `${day(s.posted_at)}, ${time(s.posted_at)}` : time(s.parsed_at)}
            </span>
          </span>
          <span className="text-right">
            <span className={clsx('block text-lg font-bold tabular', d.totals.profit > 0 ? 'text-up' : d.totals.profit < 0 ? 'text-down' : 'text-ink-3')}>
              {d.positions.some((p) => p.profit != null) ? money(d.totals.profit, '') : '—'}
            </span>
            <span className="block text-xs text-ink-3">{d.totals.open ? `${d.totals.open} of ${d.totals.legs} open` : d.totals.legs ? 'all closed' : 'no trade'}</span>
          </span>
        </div>
        <div className="flex flex-wrap gap-1.5">
          {alert && <Badge tone="brand">{alert.title}</Badge>}
          {d.broker && <Badge>{d.broker.connector === 'paper' ? 'Practice account' : d.broker.label ?? d.broker.connector}</Badge>}
          {d.totals.latency_ms != null && <Badge tone="up"><Clock className="size-3" /> copied in {d.totals.latency_ms} ms</Badge>}
          {s.edits > 0 && <Badge tone="warn">edited {s.edits}×</Badge>}
          {s.status === 'rejected' && <Badge tone="down">not a signal: {s.reason?.replace(/_/g, ' ')}</Badge>}
        </div>
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        {/* the original post */}
        <Card className="min-w-0">
          <span className="mb-3 flex items-center gap-2 font-semibold"><MessageSquareQuote className="size-4 text-ink-3" /> Original post</span>
          <div className="flex gap-2.5">
            <Avatar title={d.channel.title ?? '?'} size={32} />
            <div className="min-w-0 flex-1 rounded-2xl rounded-tl-md bg-surface-2 px-3.5 py-2.5">
              <span className="mb-0.5 block text-xs font-semibold text-brand">{d.channel.title}</span>
              <p className="text-[15px] break-words whitespace-pre-wrap">{s.raw_text || '(no text)'}</p>
              <span className="mt-1 block text-right text-[11px] text-ink-3">#{s.message_id}{s.posted_at && ` · ${time(s.posted_at)}`}</span>
            </div>
          </div>
          {d.followups.map((f) => (
            <div key={f.id} className="mt-2 flex gap-2.5 pl-10">
              <div className="min-w-0 flex-1 rounded-2xl rounded-tl-md bg-surface-2 px-3.5 py-2">
                <span className="mb-0.5 flex items-center gap-1 text-xs text-ink-3"><Reply className="size-3" /> reply · read as <b className="text-ink-2">{f.text}</b></span>
                <p className="text-[15px] whitespace-pre-wrap">{f.raw_text}</p>
                <span className="mt-0.5 block text-right text-[11px] text-ink-3">{time(f.at)}</span>
              </div>
            </div>
          ))}
        </Card>

        {/* what it understood */}
        <Card className="min-w-0">
          <span className="mb-1 flex items-center justify-between gap-2 font-semibold">
            <span className="flex items-center gap-2"><Sparkles className="size-4 text-brand" /> What PipStream understood</span>
            {s.confidence != null && <span className={clsx('text-xs font-semibold', s.confidence >= 0.8 ? 'text-up' : 'text-warn')}>{Math.round(s.confidence * 100)}% sure</span>}
          </span>
          {s.confidence != null && (
            <div className="mb-2 h-1.5 overflow-hidden rounded-full bg-surface-2"><div className={clsx('h-full rounded-full', s.confidence >= 0.8 ? 'bg-up' : 'bg-warn')} style={{ width: `${s.confidence * 100}%` }} /></div>
          )}
          <dl className="divide-y divide-line">
            <Row k="Direction">{s.side ? <span className={buy ? 'text-up' : 'text-down'}>{s.side.toUpperCase()}</span> : '—'}</Row>
            <Row k="Symbol">{s.symbol ?? '—'}</Row>
            {s.kind === 'open' && <Row k="Entry">{entryText(s.entry)}</Row>}
            {s.kind === 'open' && <Row k="Stop loss">{s.sl != null ? price(s.sl) : <span className="text-warn">none</span>}</Row>}
            {s.kind === 'open' && <Row k="Take profits">{s.tps.length ? s.tps.map((t, i) => <span key={i} className="ml-2 inline-block">TP{i + 1} {price(t)}</span>) : '—'}</Row>}
          </dl>
          {s.issues.length > 0 && <p className="mt-2 text-xs text-warn">Notes: {s.issues.map((i) => i.replace(/_/g, ' ')).join(', ')}</p>}
        </Card>
      </div>

      {/* trades */}
      <Card className="min-w-0">
        <span className="mb-2 block font-semibold">Your trades {d.positions.length > 1 && <span className="text-sm font-normal text-ink-3">· one per take-profit</span>}</span>
        {d.positions.length ? (
          <ul className="divide-y divide-line">
            {d.positions.map((p) => (
              <li key={p.id} className={clsx('grid grid-cols-[auto_1fr_auto] items-center gap-3 py-3', p.id === focus && '-mx-2 rounded-2xl bg-brand-soft/60 px-2')}>
                <span className="grid size-10 place-items-center rounded-2xl bg-surface-2 text-xs font-bold text-ink-2">{p.tp_index ? `TP${p.tp_index}` : '—'}</span>
                <span className="min-w-0">
                  <span className="flex flex-wrap items-center gap-2 font-semibold tabular">
                    {p.volume} lot @ {price(p.open_price)}
                    <Badge tone={p.status === 'open' ? 'brand' : 'neutral'}>{p.status}</Badge>
                  </span>
                  <span className="block truncate text-sm text-ink-3 tabular">
                    #{p.ticket}{p.sl != null && ` · SL ${price(p.sl)}`}{p.tp != null && ` · TP ${price(p.tp)}`}{p.close_price != null && ` · closed @ ${price(p.close_price)}`}
                  </span>
                </span>
                <span className={clsx('text-right font-semibold tabular', (p.profit ?? 0) > 0 ? 'text-up' : (p.profit ?? 0) < 0 ? 'text-down' : 'text-ink-3')}>
                  {p.profit == null ? '—' : money(p.profit, '')}
                </span>
              </li>
            ))}
          </ul>
        ) : (
          <p className="py-4 text-sm text-ink-3">No trade was opened for you from this post.{d.timeline.some((t) => t.step === 'skipped') && ' See why below.'}</p>
        )}
      </Card>

      {/* timeline */}
      <Card className="min-w-0">
        <span className="mb-3 block font-semibold">What happened</span>
        <ol className="relative space-y-3 before:absolute before:top-3 before:bottom-3 before:left-[17px] before:w-px before:bg-line">
          {d.timeline.map((e, i) => {
            const [Icon, tone] = STEP[e.step] ?? [Clock, 'text-ink-2 bg-surface-2']
            return (
              <li key={i} className="relative flex items-start gap-3">
                <span className={clsx('z-10 grid size-9 shrink-0 place-items-center rounded-full ring-4 ring-surface', tone)}><Icon className="size-4" /></span>
                <span className="min-w-0 flex-1 pt-1.5">
                  <span className="block text-sm font-semibold">{e.label}</span>
                  <span className="block text-xs text-ink-3 tabular">
                    {time(e.at)}{t0 && i > 0 && ` · ${since(t0, e.at)}`}{e.ms != null && ` · ${e.ms} ms from the post`}
                  </span>
                </span>
              </li>
            )
          })}
        </ol>
      </Card>

      {d.orders.length > 0 && (
        <details className="rounded-[22px] border border-line bg-surface p-4">
          <summary className="cursor-pointer text-sm font-semibold text-ink-2">Order log ({d.orders.length})</summary>
          <ul className="mt-3 divide-y divide-line text-sm">
            {d.orders.map((o) => (
              <li key={o.id} className="flex flex-wrap items-center gap-x-3 gap-y-0.5 py-2">
                <span className="font-semibold capitalize">{o.action}</span>
                <Badge tone={o.status === 'executed' ? 'up' : 'down'}>{o.status}</Badge>
                <span className="text-ink-2 tabular">{o.volume != null && `${o.volume} lot`}{o.price != null && ` @ ${price(o.price)}`}{o.ticket && ` · #${o.ticket}`}</span>
                {o.error && <span className="text-down">{o.error}</span>}
                <span className="ml-auto text-xs text-ink-3 tabular">{o.latency_ms != null && `${o.latency_ms} ms · `}{time(o.created_at)}</span>
              </li>
            ))}
          </ul>
        </details>
      )}
    </div>
  )
}

/** /app/alerts/:id, /app/trades/:id and /app/signals/:id all open the same story. */
export default function Detail({ kind }: { kind: 'alerts' | 'trades' | 'signals' }) {
  const { id } = useParams()
  const nav = useNavigate()
  const q = useQuery({ queryKey: ['detail', kind, id], queryFn: () => api<Resp>(`${kind}/${id}`) })
  return (
    <div className="anim-rise px-4 md:px-0">
      <header className="page-bar flex items-center gap-2">
        <button onClick={() => (history.length > 1 ? nav(-1) : nav('/app'))} className="-ml-2 grid size-10 place-items-center rounded-full text-ink-2 hover:bg-surface-2" aria-label="Back">
          <ArrowLeft className="size-5" />
        </button>
        <h1 className="text-[22px] font-bold tracking-tight md:text-2xl">{kind === 'alerts' ? 'Alert' : kind === 'trades' ? 'Trade' : 'Signal'} details</h1>
      </header>
      {q.isLoading ? (
        <Spinner />
      ) : q.error ? (
        <p className="rounded-3xl bg-surface-2 p-6 text-center text-ink-2">This item isn't available.</p>
      ) : q.data?.detail ? (
        <StoryView d={q.data.detail} focus={q.data.focus} alert={q.data.alert} />
      ) : (
        <Card>
          <span className="block font-semibold">{q.data?.alert?.title}</span>
          <span className="block text-sm text-ink-2">{q.data?.alert?.body}</span>
          <p className="mt-3 text-sm text-ink-3">There's no linked signal for this alert.</p>
        </Card>
      )}
    </div>
  )
}
