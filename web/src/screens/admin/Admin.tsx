import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { Activity, FlaskConical, Inbox, OctagonX, Play, Radio, ScrollText, Users } from 'lucide-react'
import { Fragment, useState, type ReactNode } from 'react'
import { NavLink, Outlet, useNavigate, useSearchParams } from 'react-router'
import { Badge, Button, Card, ErrorNote, Logo, Spinner, Toggle } from '../../components/ui'
import { api, post, put } from '../../lib/api'
import { ago } from '../../lib/format'

const NAV = [
  { to: '/admin', label: 'Overview', icon: Activity, end: true },
  { to: '/admin/channels', label: 'Channels', icon: Radio },
  { to: '/admin/parser', label: 'Parser lab', icon: FlaskConical },
  { to: '/admin/review', label: 'Review', icon: Inbox },
  { to: '/admin/users', label: 'Users', icon: Users },
  { to: '/admin/audit', label: 'Audit log', icon: ScrollText },
]

export function AdminLayout() {
  return (
    <div className="min-h-dvh md:grid md:grid-cols-[232px_1fr]">
      <aside className="sticky top-0 z-30 border-b border-line bg-surface md:h-dvh md:border-r md:border-b-0">
        <div className="flex h-14 items-center justify-between px-4 safe-top md:h-16">
          <Logo size={24} />
          <Badge tone="warn">Admin</Badge>
        </div>
        <nav className="flex gap-1 overflow-x-auto px-2 pb-2 md:flex-col md:px-3">
          {NAV.map((n) => (
            <NavLink
              key={n.to}
              to={n.to}
              end={n.end}
              className={({ isActive }) => clsx('flex shrink-0 items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold', isActive ? 'bg-brand-soft text-brand' : 'text-ink-2 hover:bg-surface-2')}
            >
              <n.icon className="size-4" /> {n.label}
            </NavLink>
          ))}
          <NavLink to="/app" className="flex shrink-0 items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold text-ink-3 md:mt-4">
            ← Customer app
          </NavLink>
        </nav>
      </aside>
      <main className="mx-auto w-full max-w-5xl p-4 md:p-8">
        <Outlet />
      </main>
    </div>
  )
}

function H({ children, right }: { children: ReactNode; right?: ReactNode }) {
  return (
    <div className="mb-5 flex items-center justify-between gap-3">
      <h1 className="text-2xl font-bold tracking-tight">{children}</h1>
      {right}
    </div>
  )
}

type Overview = {
  global_pause: boolean
  workers: { name: string; last_beat: number | null; healthy: boolean }[]
  counts: Record<string, number>
  latency_ms_p50: number | null
}

export function AdminOverview() {
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['admin', 'overview'], queryFn: () => api<Overview>('admin/overview'), refetchInterval: 5000 })
  const kill = useMutation({
    mutationFn: (on: boolean) => post('admin/kill-switch', { on }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'overview'] }),
  })
  if (!q.data) return <Spinner />
  const o = q.data
  const label: Record<string, string> = {
    users: 'Users', channels: 'Active channels', telegram_linked: 'Telegram readers', signals_24h: 'Signals (24h)',
    rejected_24h: 'Rejected (24h)', orders_24h: 'Orders (24h)', failed_24h: 'Failed (24h)',
  }
  return (
    <>
      <H>Overview</H>
      <Card className={clsx('mb-4 flex flex-wrap items-center gap-4', o.global_pause && 'border-down bg-down-soft')}>
        <span className="min-w-60 flex-1">
          <span className="block text-lg font-bold">{o.global_pause ? 'All copying is stopped' : 'Copying is running'}</span>
          <span className="block text-sm text-ink-2">The kill switch blocks every new trade for every customer within a second. Open positions are left alone.</span>
        </span>
        {o.global_pause ? (
          <Button onClick={() => kill.mutate(false)} busy={kill.isPending}>
            <Play className="size-4" /> Resume copying
          </Button>
        ) : (
          <Button variant="danger" onClick={() => window.confirm('Stop all copying for every customer?') && kill.mutate(true)} busy={kill.isPending}>
            <OctagonX className="size-4" /> Stop all copying
          </Button>
        )}
      </Card>
      <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        {o.workers.map((w) => (
          <Card key={w.name} className="flex items-center gap-3">
            <span className={clsx('size-3 rounded-full', w.healthy ? 'bg-up' : 'bg-down')} />
            <span className="flex-1">
              <span className="block font-semibold capitalize">{w.name}</span>
              <span className="block text-sm text-ink-3">{w.last_beat ? `beat ${ago(new Date(w.last_beat * 1000).toISOString())}` : 'no heartbeat'}</span>
            </span>
            <Badge tone={w.healthy ? 'up' : 'down'}>{w.healthy ? 'healthy' : 'down'}</Badge>
          </Card>
        ))}
      </div>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {Object.entries(o.counts).map(([k, v]) => (
          <Card key={k} className="p-3">
            <span className="block text-xs text-ink-3">{label[k] ?? k}</span>
            <span className={clsx('mt-1 block text-2xl font-bold tabular', (k === 'failed_24h' || k === 'rejected_24h') && v > 0 && 'text-warn')}>{v}</span>
          </Card>
        ))}
        <Card className="p-3">
          <span className="block text-xs text-ink-3">Signal → order (p50)</span>
          <span className="mt-1 block text-2xl font-bold tabular">{o.latency_ms_p50 != null ? `${Math.round(o.latency_ms_p50)} ms` : '—'}</span>
        </Card>
      </div>
    </>
  )
}

type AdminChannel = {
  id: string; title: string; username: string | null; tg_chat_id: number; active: boolean; parser_profile_id: string
  followers: number; parsed_7d: number; rejected_7d: number; readers: { account: string; role: string; status: string; phone_hint: string | null }[]
}

export function AdminChannels() {
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['admin', 'channels'], queryFn: () => api<{ channels: AdminChannel[]; profiles: { id: string; name: string }[] }>('admin/channels') })
  const upd = async (id: string, body: object) => {
    await put(`admin/channels/${id}`, body)
    qc.invalidateQueries({ queryKey: ['admin', 'channels'] })
  }
  if (!q.data) return <Spinner />
  return (
    <>
      <H>Channels</H>
      <div className="space-y-2">
        {q.data.channels.map((c) => {
          const rate = c.parsed_7d + c.rejected_7d ? Math.round((100 * c.parsed_7d) / (c.parsed_7d + c.rejected_7d)) : null
          return (
            <Card key={c.id} className="flex flex-wrap items-center gap-x-4 gap-y-2">
              <span className="min-w-48 flex-1">
                <span className="block font-semibold">{c.title}</span>
                <span className="block text-sm text-ink-3">
                  {c.username ? `@${c.username} · ` : ''}
                  {c.followers} copying · {c.parsed_7d} parsed / {c.rejected_7d} rejected this week{rate != null && ` (${rate}%)`}
                </span>
                <span className="mt-1 flex flex-wrap gap-1">
                  {c.readers.map((r) => (
                    <Badge key={r.account} tone={r.role === 'active' ? (r.status === 'active' ? 'up' : 'down') : 'neutral'}>
                      {r.role} reader ···{r.phone_hint}
                    </Badge>
                  ))}
                </span>
              </span>
              <select className="h-10 rounded-xl border border-line bg-surface px-3 text-sm" value={c.parser_profile_id} onChange={(e) => upd(c.id, { parser_profile_id: e.target.value })} aria-label="Parser profile">
                {q.data!.profiles.map((p) => (
                  <option key={p.id} value={p.id}>{p.name}</option>
                ))}
              </select>
              <Toggle on={c.active} onChange={(v) => upd(c.id, { active: v })} label={`Read ${c.title}`} />
            </Card>
          )
        })}
        {!q.data.channels.length && <p className="text-ink-3">No channels yet. They appear when a customer follows one.</p>}
      </div>
    </>
  )
}

function entryRows(sig: any): [string, string | null][] {
  const e = sig.entry
  const entry = !e || e.type === 'market' ? 'market' : e.type === 'range' ? `${e.from} – ${e.to}` : `${e.type} ${e.price}`
  return [
    ['Kind', sig.kind],
    ['Symbol', sig.symbol],
    ['Side', sig.side],
    ['Entry', sig.kind === 'open' ? entry : null],
    ['Stop loss', sig.sl ?? (sig.sl_pips ? `${sig.sl_pips} pips` : null)],
    ['Take profits', sig.tps?.length ? sig.tps.join(' / ') : null],
    ['Template', sig.template],
  ]
}

export function AdminParser() {
  const [params] = useSearchParams()
  const [text, setText] = useState(params.get('text') ?? 'XAUUSD BUY 2348-2350\nSL 2340\nTP 2360 / 2375')
  const [res, setRes] = useState<any>(null)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  async function run() {
    setBusy(true)
    setErr('')
    try {
      setRes(await post('admin/parse-preview', { text, channel_id: params.get('channel') || undefined }))
    } catch (e) {
      setErr((e as Error).message)
    } finally {
      setBusy(false)
    }
  }
  const sig = res?.signal
  return (
    <>
      <H>Parser lab</H>
      <p className="mb-4 text-ink-2">Paste a channel post to see exactly how the rule-based parser reads it. Nothing is stored or traded.</p>
      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <Card className="min-w-0">
          <textarea className="h-48 w-full resize-y rounded-2xl border border-line bg-bg p-3 font-mono text-sm outline-none focus:border-brand" value={text} onChange={(e) => setText(e.target.value)} aria-label="Post text" />
          <Button className="mt-3" block busy={busy} onClick={run}>
            Parse
          </Button>
          <div className="mt-3"><ErrorNote>{err}</ErrorNote></div>
        </Card>
        <Card className="min-w-0">
          {!res ? (
            <p className="py-10 text-center text-ink-3">The result shows here.</p>
          ) : (
            <>
              <div className="mb-3 flex items-center gap-2">
                <Badge tone={res.status === 'parsed' ? 'up' : 'down'}>{res.status}</Badge>
                {(res.confidence ?? sig?.confidence) != null && <span className="text-sm text-ink-3">confidence {Number(res.confidence ?? sig?.confidence).toFixed(2)}</span>}
                {res.reason && <span className="text-sm text-down">{res.reason}</span>}
              </div>
              {sig && (
                <dl className="mb-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                  {entryRows(sig).map(([k, v]) => (
                    <Fragment key={k}>
                      <dt className="text-ink-3">{k}</dt>
                      <dd className="font-semibold tabular">{v ?? '—'}</dd>
                    </Fragment>
                  ))}
                </dl>
              )}
              <pre className="max-h-64 overflow-auto rounded-2xl bg-surface-2 p-3 text-xs">{JSON.stringify(res, null, 2)}</pre>
            </>
          )}
        </Card>
      </div>
    </>
  )
}

export function AdminReview() {
  const nav = useNavigate()
  const q = useQuery({ queryKey: ['admin', 'review'], queryFn: () => api<{ rejected: any[] }>('admin/review') })
  if (!q.data) return <Spinner />
  return (
    <>
      <H>Review queue</H>
      <p className="mb-4 text-ink-2">Posts the parser could not read. Open one in the lab to see why, then tune that channel's parser profile.</p>
      <div className="space-y-2">
        {q.data.rejected.map((s) => (
          <Card key={s.id}>
            <div className="mb-2 flex flex-wrap items-center gap-2 text-sm">
              <b>{s.channel}</b>
              <Badge tone="down">{s.reason}</Badge>
              <span className="text-ink-3">{ago(s.at)}</span>
            </div>
            <pre className="mb-3 rounded-2xl bg-surface-2 p-3 text-sm whitespace-pre-wrap">{s.text}</pre>
            <Button variant="soft" className="min-h-10" onClick={() => nav(`/admin/parser?text=${encodeURIComponent(s.text)}&channel=${s.channel_id}`)}>
              Open in parser lab
            </Button>
          </Card>
        ))}
        {!q.data.rejected.length && <p className="text-ink-3">Nothing to review.</p>}
      </div>
    </>
  )
}

export function AdminUsers() {
  const q = useQuery({ queryKey: ['admin', 'users'], queryFn: () => api<{ users: any[] }>('admin/users') })
  if (!q.data) return <Spinner />
  return (
    <>
      <H>Users</H>
      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[640px] text-left text-sm">
          <thead className="text-ink-3">
            <tr className="border-b border-line">{['Name', 'Email', 'Plan', 'Telegram', 'Channels', 'Joined'].map((h) => <th key={h} className="px-4 py-3 font-semibold">{h}</th>)}</tr>
          </thead>
          <tbody className="divide-y divide-line">
            {q.data.users.map((u) => (
              <tr key={u.id}>
                <td className="px-4 py-3 font-semibold">{u.name} {u.role === 'admin' && <Badge tone="warn">admin</Badge>}</td>
                <td className="px-4 py-3">{u.email}</td>
                <td className="px-4 py-3">{u.plan} <span className="text-ink-3">{u.billing}</span></td>
                <td className="px-4 py-3">{u.telegram ? <Badge tone="up">linked</Badge> : <Badge>no</Badge>}</td>
                <td className="px-4 py-3 tabular">{u.channels}</td>
                <td className="px-4 py-3 text-ink-3">{ago(u.joined)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>
    </>
  )
}

export function AdminAudit() {
  const q = useQuery({ queryKey: ['admin', 'audit'], queryFn: () => api<{ entries: any[] }>('admin/audit') })
  if (!q.data) return <Spinner />
  return (
    <>
      <H>Audit log</H>
      <p className="mb-4 text-ink-2">Every sensitive action, append-only. The database refuses edits and deletes on this table.</p>
      <Card className="p-0">
        <ul className="divide-y divide-line text-sm">
          {q.data.entries.map((e) => (
            <li key={e.id} className="flex flex-wrap gap-x-3 px-4 py-3">
              <span className="w-24 shrink-0 text-ink-3">{ago(e.at ?? e.created_at)}</span>
              <span className="font-semibold">{e.action}</span>
              <span className="text-ink-2">{e.entity} {e.entity_id}</span>
              <span className="ml-auto text-ink-3">{e.actor}</span>
            </li>
          ))}
        </ul>
      </Card>
    </>
  )
}
