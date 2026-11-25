import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { Activity, BarChart3, Landmark, ArrowLeftRight, CheckCircle2, CircleAlert, CircleX, FlaskConical, Inbox, OctagonX, Play, Radio, ScrollText, Search, Send, Server, Users, Menu, X, ChevronRight, ArrowLeft, Crown, Mail, Award } from 'lucide-react'
import { Fragment, useEffect, useState, type ReactNode } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate, useParams, useSearchParams } from 'react-router'
import { Badge, Button, Card, ErrorNote, Logo, Spinner, Toggle } from '../../components/ui'
import { api, post, put } from '../../lib/api'
import { ThemeSwitch } from '../../components/Plan'
import { ago } from '../../lib/format'
import { useSession } from '../../lib/session'

const NAV = [
  { to: '/admin', label: 'Overview', icon: Activity, end: true },
  { to: '/admin/channels', label: 'Channels', icon: Radio },
  { to: '/admin/parser', label: 'Parser lab', icon: FlaskConical },
  { to: '/admin/review', label: 'Review', icon: Inbox },
  { to: '/admin/users', label: 'Customers', icon: Users },
  { to: '/admin/audit', label: 'Audit log', icon: ScrollText },
  { to: '/admin/system', label: 'System', icon: Server },
  { to: '/admin/insights', label: 'Insights', icon: BarChart3, superadmin: true },
  { to: '/admin/brokers', label: 'Brokers', icon: Landmark, superadmin: true },
  { to: '/admin/activity', label: 'Copy activity', icon: ArrowLeftRight, superadmin: true },
  { to: '/admin/email', label: 'Email', icon: Mail, superadmin: true },
]

export function AdminLayout() {
  const [open, setOpen] = useState(false)
  const loc = useLocation()
  useEffect(() => setOpen(false), [loc.pathname])
  const { user } = useSession()
  const nav = NAV.filter((n) => !('superadmin' in n) || user?.role === 'superadmin')
  const current = [...nav].reverse().find((n) => (n.end ? loc.pathname === n.to : loc.pathname.startsWith(n.to)))
  const links = (
    <>
      {nav.map((n) => (
        <NavLink
          key={n.to}
          to={n.to}
          end={n.end}
          className={({ isActive }) => clsx('flex items-center gap-3 rounded-xl px-3 py-2.5 text-[15px] font-semibold md:py-2 md:text-sm', isActive ? 'bg-brand-soft text-brand' : 'text-ink-2 hover:bg-surface-2')}
        >
          <n.icon className="size-5 md:size-4" /> {n.label}
        </NavLink>
      ))}
      <NavLink to="/app" className="mt-2 flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold text-ink-3 hover:bg-surface-2 md:mt-4 md:py-2">
        ← Customer app
      </NavLink>
      <div className="mt-3 px-1"><ThemeSwitch compact /></div>
    </>
  )
  return (
    <div className="min-h-dvh md:grid md:grid-cols-[232px_1fr]">
      {/* phones: top bar + slide-down menu */}
      <header className="sticky top-0 z-30 border-b border-line bg-surface/95 backdrop-blur-lg safe-top md:hidden">
        <div className="flex h-14 items-center gap-3 px-4">
          <Logo size={22} />
          <span className="min-w-0 flex-1 truncate text-sm font-semibold text-ink-2">{current?.label ?? 'Admin'}</span>
          <Badge tone="warn">Admin</Badge>
          <button className="-mr-2 grid size-10 place-items-center rounded-xl hover:bg-surface-2" onClick={() => setOpen((o) => !o)} aria-label={open ? 'Close menu' : 'Open menu'} aria-expanded={open}>
            {open ? <X className="size-5" /> : <Menu className="size-5" />}
          </button>
        </div>
        {open && <nav className="flex max-h-[70dvh] flex-col gap-0.5 overflow-y-auto border-t border-line px-3 py-3">{links}</nav>}
      </header>
      {open && <div className="fixed inset-0 z-20 bg-black/40 md:hidden" onClick={() => setOpen(false)} />}
      {/* tablets and up: sidebar */}
      <aside className="sticky top-0 hidden h-dvh flex-col border-r border-line bg-surface md:flex">
        <div className="flex h-16 items-center justify-between px-4">
          <Logo size={24} />
          <Badge tone="warn">Admin</Badge>
        </div>
        <nav className="flex flex-col gap-1 overflow-y-auto px-3 pb-4">{links}</nav>
      </aside>
      <main className="mx-auto w-full max-w-6xl min-w-0 px-4 py-5 md:p-8">
        <Outlet />
      </main>
    </div>
  )
}

export function H({ children, right }: { children: ReactNode; right?: ReactNode }) {
  return (
    <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
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

type AdminUserRow = {
  id: string; name: string | null; email: string; role: string; status: string; paused: boolean; plan: string | null
  founding?: number | null; billing: string | null; channels: number; open_trades: number; telegram: boolean; joined: string
  brokers?: number; last_seen?: string | null
}

function UserBadges({ u }: { u: { role: string; status: string; paused: boolean; plan?: string | null; founding?: number | null } }) {
  return (
    <>
      {u.founding ? <Badge tone="warn"><Award className="mr-0.5 inline size-3" />founding #{u.founding}</Badge> : null}
      {(u.plan === 'lifetime' || u.plan === 'pro') && (
        <Crown className="size-4 fill-current text-warn" aria-label={u.plan === 'lifetime' ? 'Lifetime member' : 'Pro member'} />
      )}
      {u.role === 'admin' && <Badge tone="warn">admin</Badge>}
      {u.role === 'superadmin' && <Badge tone="brand">superadmin</Badge>}
      {u.status === 'suspended' && <Badge tone="down">suspended</Badge>}
      {u.paused && <Badge tone="warn">paused</Badge>}
    </>
  )
}

export function AdminUsers() {
  const nav = useNavigate()
  const [params, setParams] = useSearchParams()
  const [text, setText] = useState(params.get('q') ?? '')
  const filter = params.get('status') ?? ''
  const qs = new URLSearchParams({ ...(params.get('q') ? { q: params.get('q')! } : {}), ...(filter ? { status: filter } : {}) }).toString()
  const q = useQuery({ queryKey: ['admin', 'users', qs], queryFn: () => api<{ users: AdminUserRow[] }>(`admin/users${qs ? `?${qs}` : ''}`) })
  useEffect(() => {
    const t = setTimeout(() => {
      const next = new URLSearchParams(params)
      text.trim() ? next.set('q', text.trim()) : next.delete('q')
      if (next.toString() !== params.toString()) setParams(next, { replace: true })
    }, 300)
    return () => clearTimeout(t)
  }, [text]) // eslint-disable-line react-hooks/exhaustive-deps
  const setFilter = (v: string) => {
    const next = new URLSearchParams(params)
    v ? next.set('status', v) : next.delete('status')
    setParams(next, { replace: true })
  }
  const users = q.data?.users
  return (
    <>
      <H>Customers</H>
      <div className="mb-4 flex flex-col gap-2 sm:flex-row">
        <label className="relative flex-1">
          <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-3" />
          <input className="h-11 w-full rounded-xl border border-line bg-surface pr-3 pl-9 text-[16px] outline-none focus:border-brand sm:text-sm" placeholder="Search name, email or ID" value={text} onChange={(e) => setText(e.target.value)} aria-label="Search customers" />
        </label>
        <div className="grid grid-cols-3 rounded-xl bg-surface-2 p-1 text-sm font-semibold sm:w-72">
          {[['', 'All'], ['active', 'Active'], ['suspended', 'Suspended']].map(([v, l]) => (
            <button key={v} onClick={() => setFilter(v)} className={clsx('rounded-lg px-2 py-1.5', filter === v ? 'bg-surface text-ink shadow-sm' : 'text-ink-3')}>{l}</button>
          ))}
        </div>
      </div>
      {!users ? (
        <Spinner />
      ) : !users.length ? (
        <p className="text-ink-3">No customers match.</p>
      ) : (
        <>
          {/* phones: cards */}
          <ul className="space-y-2 md:hidden">
            {users.map((u) => (
              <li key={u.id}>
                <Link to={`/admin/users/${u.id}`} className="flex items-center gap-3 rounded-2xl border border-line bg-surface p-3 active:bg-surface-2">
                  <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-center gap-1.5 font-semibold">{u.name || '—'} <UserBadges u={u} /></span>
                    <span className="block truncate text-sm text-ink-2">{u.email}</span>
                    <span className="mt-1 block text-xs text-ink-3">
                      {u.plan ?? 'no plan'}{u.billing ? ` · ${u.billing}` : ''} · {u.telegram ? 'Telegram linked' : 'no Telegram'} · {u.channels} ch · {u.brokers ?? 0} broker · {u.open_trades} open{u.last_seen ? ` · seen ${ago(u.last_seen)}` : ''}
                    </span>
                  </span>
                  <ChevronRight className="size-5 shrink-0 text-ink-3" />
                </Link>
              </li>
            ))}
          </ul>
          {/* tablets and up: table */}
          <Card className="hidden overflow-x-auto p-0 md:block">
            <table className="w-full text-left text-sm">
              <thead className="text-ink-3">
                <tr className="border-b border-line">{['Customer', 'Plan', 'Telegram', 'Channels', 'Brokers', 'Open', 'Last seen', 'Joined'].map((h) => <th key={h} className="px-4 py-3 font-semibold">{h}</th>)}</tr>
              </thead>
              <tbody className="divide-y divide-line">
                {users.map((u) => (
                  <tr key={u.id} className="cursor-pointer hover:bg-surface-2" onClick={() => nav(`/admin/users/${u.id}`)}>
                    <td className="px-4 py-3">
                      <Link to={`/admin/users/${u.id}`} className="flex flex-wrap items-center gap-1.5 font-semibold" onClick={(e) => e.stopPropagation()}>{u.name || '—'} <UserBadges u={u} /></Link>
                      <span className="block text-ink-2">{u.email}</span>
                    </td>
                    <td className="px-4 py-3">{u.plan ?? '—'} <span className="block text-ink-3">{u.billing}</span></td>
                    <td className="px-4 py-3">{u.telegram ? <Badge tone="up">linked</Badge> : <Badge>no</Badge>}</td>
                    <td className="px-4 py-3 tabular">{u.channels}</td>
                    <td className="px-4 py-3 tabular">{u.brokers ?? 0}</td>
                    <td className="px-4 py-3 tabular">{u.open_trades}</td>
                    <td className="px-4 py-3 text-ink-3">{u.last_seen ? ago(u.last_seen) : '—'}</td>
                    <td className="px-4 py-3 text-ink-3">{ago(u.joined)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>
        </>
      )}
    </>
  )
}

type UserDetail = {
  user: { id: string; name: string | null; email: string; role: string; founding?: number | null; status: string; paused: boolean; joined: string; last_seen?: string | null }
  billing: { plan: string; status: string; period_end: string | null; provider: string | null } | null
  telegram: { id: string; status: string; linked: boolean; phone_hint: string | null; last_error: string | null; since: string }[]
  channels: { id: string; title: string; username: string | null; active: boolean; mode: string | null; enabled: boolean | null; sizing_mode: string | null }[]
  brokers: { id: string; connector: string; label: string | null; enabled: boolean; since: string }[]
  trades: { id: string; symbol: string; side: string | null; volume: number | null; open_price: number | null; close_price: number | null; status: string; profit: number | null; channel: string | null; opened_at: string }[]
  stats: { open: number; orders_30d: number; failed_30d: number; profit_30d: number }
  audit: { id: number; action: string; actor: string; entity: string | null; created_at?: string; at?: string }[]
}

function Row({ k, children }: { k: string; children: ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 py-2 text-sm">
      <span className="text-ink-3">{k}</span>
      <span className="min-w-0 text-right font-semibold break-words">{children}</span>
    </div>
  )
}

/** One customer, everything support needs, plus the actions an admin takes. */
export function AdminUser() {
  const { id } = useParams()
  const { user } = useSession()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['admin', 'user', id], queryFn: () => api<UserDetail>(`admin/users/${id}`) })
  const [err, setErr] = useState('')
  const act = useMutation({
    mutationFn: ({ path, body }: { path: string; body: object }) => post(`admin/users/${id}/${path}`, body),
    onSuccess: () => {
      setErr('')
      qc.invalidateQueries({ queryKey: ['admin', 'user', id] })
      qc.invalidateQueries({ queryKey: ['admin', 'users'] })
    },
    onError: (e) => setErr((e as Error).message),
  })
  if (!q.data) return <Spinner />
  const d = q.data
  const u = d.user
  const run = (path: string, body: object, confirm?: string) => (!confirm || window.confirm(confirm)) && act.mutate({ path, body })
  return (
    <>
      <Link to="/admin/users" className="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-3 hover:text-ink"><ArrowLeft className="size-4" /> Customers</Link>
      <div className="mb-5">
        <h1 className="flex flex-wrap items-center gap-2 text-2xl font-bold tracking-tight">{u.name || u.email} <UserBadges u={{ ...u, plan: d.billing?.plan }} /></h1>
        <p className="text-ink-2 break-all">{u.email} · joined {ago(u.joined)}{u.last_seen ? ` · seen ${ago(u.last_seen)}` : ''}{user?.role === 'superadmin' && <> · <Link to={`/admin/activity?user=${u.id}`} className="font-semibold text-brand">all copy activity</Link></>}</p>
      </div>

      <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        {[['Open trades', d.stats.open], ['Orders (30d)', d.stats.orders_30d], ['Failed (30d)', d.stats.failed_30d], ['P/L (30d)', d.stats.profit_30d.toFixed(2)]].map(([k, v]) => (
          <Card key={k} className="p-3">
            <span className="block text-xs text-ink-3">{k}</span>
            <span className={clsx('mt-1 block text-2xl font-bold tabular', k === 'Failed (30d)' && Number(v) > 0 && 'text-warn')}>{v}</span>
          </Card>
        ))}
      </div>

      <Card className="mb-4">
        <span className="mb-3 block font-semibold">Actions</span>
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
          {u.paused ? (
            <Button variant="soft" busy={act.isPending} onClick={() => run('pause', { on: false })}><Play className="size-4" /> Resume copying</Button>
          ) : (
            <Button variant="soft" busy={act.isPending} onClick={() => run('pause', { on: true }, 'Pause copying for this customer? Open trades are left alone.')}><OctagonX className="size-4" /> Pause copying</Button>
          )}
          {u.status === 'active' ? (
            <Button variant="danger" busy={act.isPending} onClick={() => run('status', { status: 'suspended' }, 'Suspend this customer? They are signed out and no new trades are copied.')}>Suspend account</Button>
          ) : (
            <Button busy={act.isPending} onClick={() => run('status', { status: 'active' })}>Reactivate account</Button>
          )}
          <Button variant="soft" busy={act.isPending} onClick={() => run('trial', { days: 7 }, 'Add 7 days to this customer\'s current period?')}>+7 days trial</Button>
          {u.role !== 'superadmin' && <Button variant="soft" busy={act.isPending} onClick={() => run('role', { role: u.role === 'admin' ? 'customer' : 'admin' }, u.role === 'admin' ? 'Remove admin access?' : 'Give this person full admin access?')}>
            {u.role === 'admin' ? 'Remove admin' : 'Make admin'}
          </Button>}
        </div>
        <div className="mt-2"><ErrorNote>{err}</ErrorNote></div>
      </Card>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card>
          <span className="mb-1 block font-semibold">Plan</span>
          {d.billing ? (
            <div className="divide-y divide-line">
              <Row k="Plan">{d.billing.plan}</Row>
              <Row k="Status">{d.billing.status}</Row>
              <Row k="Current period ends">{d.billing.period_end ? new Date(d.billing.period_end).toLocaleDateString() : '—'}</Row>
              <Row k="Paid via">{d.billing.provider ?? '—'}</Row>
            </div>
          ) : <p className="text-sm text-ink-3">No plan yet.</p>}
        </Card>
        <Card>
          <span className="mb-1 block font-semibold">Telegram</span>
          {d.telegram.length ? (
            <div className="divide-y divide-line">
              {d.telegram.map((t) => (
                <Row key={t.id} k={`···${t.phone_hint ?? '—'}`}>
                  <Badge tone={t.linked ? 'up' : t.status === 'revoked' ? 'neutral' : 'down'}>{t.linked ? 'linked' : t.status}</Badge>
                  {t.last_error && <span className="block text-xs font-normal text-down">{t.last_error}</span>}
                </Row>
              ))}
            </div>
          ) : <p className="text-sm text-ink-3">Never connected.</p>}
        </Card>
        <Card>
          <span className="mb-1 block font-semibold">Channels ({d.channels.length})</span>
          {d.channels.length ? (
            <div className="divide-y divide-line">
              {d.channels.map((c) => (
                <Row key={c.id} k={c.title}>
                  <span className="text-ink-2">{c.mode ?? 'no rule'}{c.sizing_mode ? ` · ${c.sizing_mode}` : ''}</span>
                  {c.enabled === false && <Badge>off</Badge>}
                  {!c.active && <Badge tone="down">not read</Badge>}
                </Row>
              ))}
            </div>
          ) : <p className="text-sm text-ink-3">Follows no channels.</p>}
        </Card>
        <Card>
          <span className="mb-1 block font-semibold">Brokers</span>
          {d.brokers.length ? (
            <div className="divide-y divide-line">
              {d.brokers.map((b) => (
                <Row key={b.id} k={b.label || b.connector}>{b.connector} {!b.enabled && <Badge>off</Badge>}</Row>
              ))}
            </div>
          ) : <p className="text-sm text-ink-3">No broker yet.</p>}
        </Card>
      </div>

      <h2 className="mt-6 mb-2 text-lg font-bold">Recent trades</h2>
      {d.trades.length ? (
        <Card className="p-0">
          <ul className="divide-y divide-line text-sm">
            {d.trades.map((t) => (
              <li key={t.id} className="flex flex-wrap items-center gap-x-3 gap-y-0.5 px-4 py-3">
                <span className="font-semibold">{t.side?.toUpperCase()} {t.symbol}</span>
                <span className="text-ink-2 tabular">{t.volume} @ {t.open_price}</span>
                <Badge tone={t.status === 'open' ? 'brand' : (t.profit ?? 0) >= 0 ? 'up' : 'down'}>{t.status === 'open' ? 'open' : (t.profit ?? 0).toFixed(2)}</Badge>
                <span className="ml-auto text-ink-3">{t.channel ?? ''} · {ago(t.opened_at)}</span>
              </li>
            ))}
          </ul>
        </Card>
      ) : <p className="text-sm text-ink-3">No trades yet.</p>}

      <h2 className="mt-6 mb-2 text-lg font-bold">Activity</h2>
      {d.audit.length ? (
        <Card className="p-0">
          <ul className="divide-y divide-line text-sm">
            {d.audit.map((e) => (
              <li key={e.id} className="flex flex-wrap gap-x-3 px-4 py-2.5">
                <span className="font-semibold">{e.action}</span>
                <span className="text-ink-3">{e.actor === u.id ? 'by customer' : `by ${e.actor}`}</span>
                <span className="ml-auto text-ink-3">{ago(e.at ?? e.created_at ?? '')}</span>
              </li>
            ))}
          </ul>
        </Card>
      ) : <p className="text-sm text-ink-3">Nothing logged.</p>}
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

type Check = { group: string; name: string; state: 'ok' | 'warn' | 'fail'; detail: string }
type SystemInfo = {
  checks: Check[]
  workers: { name: string; healthy: boolean; status: Record<string, unknown> | null }[]
  my_telegram: { linked: boolean; status: string; phone_hint: string | null; last_error: string | null } | null
}
type TgTest = { status: string; request_id?: string; error?: string | null; info?: { name?: string; username?: string | null; live?: boolean } | null }

const STATE_ICON = { ok: CheckCircle2, warn: CircleAlert, fail: CircleX }
const STATE_TONE = { ok: 'text-up', warn: 'text-warn', fail: 'text-down' }

/** Is everything running and configured, plus one-click tests. Values of secrets are never shown. */
export function AdminSystem() {
  const q = useQuery({ queryKey: ['admin', 'system'], queryFn: () => api<SystemInfo>('admin/system'), refetchInterval: 5000 })
  const [tg, setTg] = useState<TgTest | null>(null)
  const [tgErr, setTgErr] = useState('')
  const [tgBusy, setTgBusy] = useState(false)
  const [parse, setParse] = useState<any>(null)
  const [parseErr, setParseErr] = useState('')

  async function testTelegram() {
    setTgBusy(true)
    setTgErr('')
    setTg(null)
    try {
      let r = await post<TgTest>('admin/system/telegram-test', {})
      for (let i = 0; r.status === 'pending' && r.request_id && i < 10; i++) {
        await new Promise((ok) => setTimeout(ok, 1500))
        r = await api<TgTest>(`telegram/requests/${r.request_id}`)
      }
      setTg(r)
    } catch (e) {
      setTgErr((e as Error).message)
    } finally {
      setTgBusy(false)
    }
  }
  async function testParse() {
    setParseErr('')
    try {
      setParse(await post('admin/parse-preview', { text: 'XAUUSD BUY 2348-2350\nSL 2340\nTP 2360 / 2375' }))
    } catch (e) {
      setParseErr((e as Error).message)
    }
  }

  if (!q.data) return <Spinner />
  const groups = [...new Set(q.data.checks.map((c) => c.group))]
  const fails = q.data.checks.filter((c) => c.state === 'fail').length
  const warns = q.data.checks.filter((c) => c.state === 'warn').length
  const me = q.data.my_telegram
  return (
    <>
      <H right={<Badge tone={fails ? 'down' : warns ? 'warn' : 'up'}>{fails ? `${fails} failing` : warns ? `${warns} to check` : 'all good'}</Badge>}>System</H>
      <p className="mb-4 text-ink-2">What is running and how it is configured. Secrets show only as set or missing, never their values. Refreshes every 5 seconds.</p>

      <h2 className="mb-2 text-lg font-bold">Tests</h2>
      <div className="mb-6 grid grid-cols-1 gap-3 md:grid-cols-2">
        <Card className="min-w-0">
          <span className="block font-semibold">Telegram connection</span>
          <span className="mb-3 block text-sm text-ink-2">
            Sends a test note to <b>your own</b> Telegram Saved Messages through the engine. Proves the API keys, your session and the reader all work.
          </span>
          {me?.linked ? (
            <Button busy={tgBusy} onClick={testTelegram}>
              <Send className="size-4" /> Send test to my Saved Messages
            </Button>
          ) : (
            <p className="text-sm">
              Your admin account has no Telegram linked yet. <Link className="font-semibold text-brand" to="/start/telegram">Connect Telegram</Link>, then come back.
            </p>
          )}
          {tg && (
            <div className="mt-3 text-sm">
              {tg.status === 'test_ok' ? (
                <span className="text-up">
                  ✓ Sent to {tg.info?.name || 'your account'}{tg.info?.username ? ` (@${tg.info.username})` : ''}'s Saved Messages
                  {tg.info?.live ? ', through the live reader.' : '. The reader is not connected in this engine, so a one-off session was used.'}
                </span>
              ) : tg.status === 'pending' ? (
                <span className="text-warn">No answer from the engine yet. Check that the intake worker and the event consumer are running (see below).</span>
              ) : (
                <span className="text-down">{tg.error || tg.status}</span>
              )}
            </div>
          )}
          <div className="mt-2"><ErrorNote>{tgErr}</ErrorNote></div>
        </Card>
        <Card className="min-w-0">
          <span className="block font-semibold">Signal parser</span>
          <span className="mb-3 block text-sm text-ink-2">Parses a sample gold signal through the engine. Nothing is stored or traded.</span>
          <Button variant="soft" onClick={testParse}>
            <FlaskConical className="size-4" /> Parse a sample signal
          </Button>
          {parse && (
            <p className="mt-3 text-sm">
              <Badge tone={parse.status === 'parsed' ? 'up' : 'down'}>{parse.status}</Badge>{' '}
              {parse.signal ? `${parse.signal.kind} ${parse.signal.side} ${parse.signal.symbol}, SL ${parse.signal.sl}, TP ${parse.signal.tps?.join(' / ')}` : parse.reason}{' '}
              <Link className="font-semibold text-brand" to="/admin/parser">Open parser lab</Link>
            </p>
          )}
          <div className="mt-2"><ErrorNote>{parseErr}</ErrorNote></div>
        </Card>
      </div>

      {groups.map((g) => (
        <section key={g} className="mb-5">
          <h2 className="mb-2 text-lg font-bold">{g}</h2>
          <Card className="p-0">
            <ul className="divide-y divide-line text-sm">
              {q.data!.checks.filter((c) => c.group === g).map((c) => {
                const Icon = STATE_ICON[c.state]
                return (
                  <li key={c.name} className="flex items-start gap-3 px-4 py-3">
                    <Icon className={clsx('mt-0.5 size-4 shrink-0', STATE_TONE[c.state])} aria-label={c.state} />
                    <span className="flex min-w-0 flex-1 flex-col gap-0.5 sm:flex-row sm:gap-4">
                      <span className="font-semibold sm:w-64 sm:shrink-0">{c.name}</span>
                      <span className="min-w-0 break-words text-ink-2">{c.detail}</span>
                    </span>
                  </li>
                )
              })}
            </ul>
          </Card>
        </section>
      ))}
    </>
  )
}
