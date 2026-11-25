import { useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { ArrowLeft, Bot as BotIcon, CheckCircle2, CircleAlert, KeyRound, Link2, Megaphone, MessageCircle, Send } from 'lucide-react'
import { Fragment, useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router'
import { Badge, Button, Card, ErrorNote, Field, Spinner, Toggle } from '../../components/ui'
import { ApiError, api, post, put } from '../../lib/api'
import { ago } from '../../lib/format'
import { useSession } from '../../lib/session'
import { H } from './Admin'

type Check = { ok: boolean; problem: string | null; username?: string; name?: string; pending?: number; last_error?: string | null; at?: string }
type BotInfo = {
  source: 'admin' | 'env' | 'none'; token_hint: string | null; username: string; link: string; webhook_url: string | null
  ops_alerts: boolean; check: Check | null; commands: { command: string; description: string }[]
  linked_customers: number; me_linked: boolean; me_link: string
}
type Segment = { id: string; label: string; count: number }
type Broadcast = { id: number; segment: string; text: string; total: number; sent: number; failed: number; created_at: string; finished_at: string | null }

function Textarea({ value, onChange, placeholder, rows = 4 }: { value: string; onChange: (v: string) => void; placeholder?: string; rows?: number }) {
  return (
    <textarea
      value={value}
      rows={rows}
      placeholder={placeholder}
      onChange={(e) => onChange(e.target.value)}
      className="w-full rounded-2xl border border-line bg-surface px-4 py-3 text-[16px] outline-none transition placeholder:text-ink-3 focus:border-brand focus:ring-4 focus:ring-brand/15"
    />
  )
}

/** Superadmin: the @pip_stream_bot token, webhook check, operator alerts, and broadcasts. */
export default function AdminBot() {
  const { user } = useSession()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['admin', 'bot'], queryFn: () => api<BotInfo>('admin/bot') })
  const [token, setToken] = useState('')
  const [err, setErr] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState('')
  const [note, setNote] = useState<{ ok: boolean; text: string } | null>(null)

  async function run(what: string, fn: () => Promise<BotInfo | unknown>, done?: string) {
    setBusy(what)
    setErr(null)
    setNote(null)
    try {
      const r = await fn()
      if (r && typeof r === 'object' && 'source' in (r as object)) qc.setQueryData(['admin', 'bot'], r)
      if (done) setNote({ ok: true, text: done })
    } catch (e) {
      setErr(e as ApiError)
    } finally {
      setBusy('')
    }
  }

  if (user?.role !== 'superadmin') return <p className="text-ink-2">Only the superadmin can set up the bot.</p>
  if (!q.data) return <Spinner />
  const d = q.data
  const c = d.check
  return (
    <>
      <H right={<Badge tone={d.source === 'none' ? 'warn' : c?.ok ? 'up' : 'down'}>{d.source === 'none' ? 'not set up' : c?.ok ? 'connected' : 'needs a look'}</Badge>}>Telegram bot</H>
      <p className="mb-4 max-w-2xl text-ink-2">
        <a className="font-semibold text-brand" href={d.link} target="_blank" rel="noreferrer">@{d.username}</a> is PipStream's official line to customers:
        trade and health alerts, buttons to check trades and pause copying, the daily summary, and support chats. {d.linked_customers} customer{d.linked_customers === 1 ? ' has' : 's have'} connected it.
      </p>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card className="space-y-4">
          <span className="flex items-center gap-2 font-semibold"><KeyRound className="size-4" /> Bot token</span>
          <p className="text-sm text-ink-2">
            {d.source === 'admin' && <>Saved here: <b className="tabular">{d.token_hint}</b>.</>}
            {d.source === 'env' && <>Using <code>PS_TELEGRAM_BOT_TOKEN</code> from .env ({d.token_hint}). A token saved here takes over.</>}
            {d.source === 'none' && <>In Telegram, open @BotFather, send /mybots, pick @{d.username}, tap API Token and paste it here. Saving connects the webhook and the command menu.</>}
          </p>
          <Field label={d.source === 'none' ? 'Token' : 'Replace token'} type="password" autoComplete="off" placeholder="123456789:AAE…" value={token}
            onChange={(e) => setToken(e.target.value)} error={err?.field('token')} />
          <div className="flex flex-wrap gap-2">
            <Button busy={busy === 'save'} disabled={!token.trim()} onClick={() => run('save', () => put<BotInfo>('admin/bot', { token: token.trim() }).then((r) => { setToken(''); return r }), 'Saved. The bot is connected.')}>Save and connect</Button>
            {d.source !== 'none' && <Button variant="soft" busy={busy === 'hook'} onClick={() => run('hook', () => post<BotInfo>('admin/bot/webhook'), 'Webhook connected.')}><Link2 className="size-4" /> Connect webhook</Button>}
            {d.source === 'admin' && <Button variant="ghost" busy={busy === 'clear'} onClick={() => confirm('Remove the saved token? The bot stops answering until a new one is saved.') && run('clear', () => put<BotInfo>('admin/bot', { clear_token: true }))}>Remove</Button>}
          </div>
          {err && !Object.keys(err.errors).length && <ErrorNote>{err.message}</ErrorNote>}
          {note && <p className={note.ok ? 'text-sm font-medium text-up' : 'text-sm text-down'}>{note.text}</p>}
        </Card>

        <Card className="space-y-3 self-start">
          <span className="flex items-center gap-2 font-semibold"><BotIcon className="size-4" /> Status</span>
          {!c && <p className="text-sm text-ink-2">Add the token to see the status.</p>}
          {c && (
            <div className={clsx('flex items-start gap-2 rounded-2xl p-3 text-sm', c.ok ? 'bg-up-soft text-up' : 'bg-down-soft text-down')}>
              {c.ok ? <CheckCircle2 className="mt-0.5 size-4 shrink-0" /> : <CircleAlert className="mt-0.5 size-4 shrink-0" />}
              <span>{c.ok ? `Connected: Telegram delivers messages to pips.stream${c.pending ? ` (${c.pending} waiting)` : ''}.` : c.problem}</span>
            </div>
          )}
          {d.webhook_url && <p className="break-all text-xs text-ink-3">Webhook: {d.webhook_url.replace(/telegram\/.+$/, 'telegram/••••')}</p>}
          <div className="space-y-2 border-t border-line pt-3">
            <p className="text-sm text-ink-2">{d.me_linked ? 'Your own Telegram is connected to the bot.' : 'Connect your own Telegram to get ops alerts and preview broadcasts.'}</p>
            <div className="flex flex-wrap gap-2">
              {!d.me_linked && <a href={d.me_link} target="_blank" rel="noreferrer"><Button variant="tg"><Send className="size-4" /> Connect my Telegram</Button></a>}
              {d.me_linked && <Button variant="soft" busy={busy === 'test'} onClick={() => run('test', () => post('admin/bot/test'), 'Sent. Check Telegram.')}>Send me a test</Button>}
              <Button variant="ghost" onClick={() => qc.invalidateQueries({ queryKey: ['admin', 'bot'] })}>Refresh</Button>
            </div>
          </div>
          <div className="flex items-center justify-between gap-3 border-t border-line pt-3">
            <span className="text-sm"><b className="block">Ops alerts on Telegram</b><span className="text-ink-3">Watchdog alerts (workers down, errors, customers' MetaTrader offline) also go to admins who connected the bot, not only email.</span></span>
            <Toggle label="Ops alerts on Telegram" on={d.ops_alerts} onChange={(on) => run('ops', () => put<BotInfo>('admin/bot', { ops_alerts: on }))} />
          </div>
        </Card>
      </div>

      <Broadcasts meLinked={d.me_linked} />

      <Card className="mt-4">
        <span className="mb-2 block font-semibold">Commands customers can type</span>
        <p className="mb-3 text-sm text-ink-2">Set in Telegram's menu automatically when the token is saved. Customers mostly tap the big buttons instead.</p>
        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
          {d.commands.map((x) => <Fragment key={x.command}><dt className="font-semibold">/{x.command}</dt><dd className="text-ink-2">{x.description}</dd></Fragment>)}
        </dl>
      </Card>
    </>
  )
}

function Broadcasts({ meLinked }: { meLinked: boolean }) {
  const q = useQuery({ queryKey: ['admin', 'bot', 'segments'], queryFn: () => api<{ segments: Segment[]; broadcasts: Broadcast[] }>('admin/bot/segments'), refetchInterval: 5000 })
  const [segment, setSegment] = useState('linked')
  const [text, setText] = useState('')
  const [busy, setBusy] = useState('')
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null)
  const seg = q.data?.segments.find((s) => s.id === segment)

  async function send(test: boolean) {
    if (!test && !confirm(`Send this to ${seg?.count ?? 0} people (${seg?.label})? It can't be taken back.`)) return
    setBusy(test ? 'test' : 'send')
    setMsg(null)
    try {
      await post('admin/bot/broadcast', { segment, text, test })
      setMsg({ ok: true, text: test ? 'Preview sent to your Telegram.' : 'Sending now. Progress shows below.' })
      if (!test) setText('')
      q.refetch()
    } catch (e) {
      setMsg({ ok: false, text: (e as Error).message })
    } finally {
      setBusy('')
    }
  }

  return (
    <Card className="mt-4 space-y-3">
      <span className="flex items-center gap-2 font-semibold"><Megaphone className="size-4" /> Broadcast</span>
      <p className="text-sm text-ink-2">News, maintenance or offers, straight to customers' Telegram. Keep it short and friendly.</p>
      <div className="flex flex-wrap gap-2">
        {q.data?.segments.map((s) => (
          <button key={s.id} onClick={() => setSegment(s.id)}
            className={clsx('rounded-full border px-3 py-1.5 text-sm font-semibold', segment === s.id ? 'border-brand bg-brand-soft text-brand' : 'border-line text-ink-2')}>
            {s.label} <span className="tabular opacity-70">{s.count}</span>
          </button>
        ))}
      </div>
      <Textarea value={text} onChange={setText} placeholder="Hi! Quick note from PipStream: …" />
      <div className="flex flex-wrap items-center gap-2">
        <Button busy={busy === 'send'} disabled={text.trim().length < 3 || !seg?.count} onClick={() => send(false)}><Send className="size-4" /> Send to {seg?.count ?? 0}</Button>
        <Button variant="soft" busy={busy === 'test'} disabled={text.trim().length < 3 || !meLinked} onClick={() => send(true)}>Preview on my Telegram</Button>
        <span className="text-xs text-ink-3 tabular">{text.length}/3500</span>
      </div>
      {msg && <p className={msg.ok ? 'text-sm text-up' : 'text-sm text-down'}>{msg.text}</p>}
      {!!q.data?.broadcasts.length && (
        <ul className="divide-y divide-line border-t border-line">
          {q.data.broadcasts.map((b) => (
            <li key={b.id} className="py-2 text-sm">
              <span className="line-clamp-2">{b.text}</span>
              <span className="text-xs text-ink-3">{q.data.segments.find((s) => s.id === b.segment)?.label ?? b.segment} · {ago(b.created_at)} · {b.finished_at ? `sent ${b.sent} of ${b.total}` : `sending… ${b.sent} of ${b.total}`}{b.failed ? ` · ${b.failed} failed` : ''}</span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

// ---------------------------------------------------------------- support inbox (every admin)
type Conv = { chat_id: number; text: string; direction: 'in' | 'out'; created_at: string; unread: number; first_name: string | null; username: string | null; user_id: string | null; name: string | null; email: string | null }
type Msg = { id: number; direction: 'in' | 'out'; kind: string; text: string; created_at: string; admin_id: string | null }

export function AdminSupport() {
  const q = useQuery({ queryKey: ['admin', 'support'], queryFn: () => api<{ conversations: Conv[]; unread: number }>('admin/support'), refetchInterval: 10000 })
  if (!q.data) return <Spinner />
  return (
    <>
      <H right={q.data.unread ? <Badge tone="warn">{q.data.unread} new</Badge> : undefined}>Support</H>
      <p className="mb-4 max-w-2xl text-ink-2">What customers wrote to the PipStream bot. Your reply goes to their Telegram, from the bot.</p>
      {!q.data.conversations.length && <Card><p className="text-ink-2">No messages yet. When a customer types in the bot, it shows here.</p></Card>}
      <Card className="py-0">
        <ul className="divide-y divide-line">
          {q.data.conversations.map((c) => (
            <li key={c.chat_id}>
              <Link to={`/admin/support/${c.chat_id}`} className="flex items-center gap-3 py-3">
                <span className="grid size-10 shrink-0 place-items-center rounded-2xl bg-surface-2 text-ink-2"><MessageCircle className="size-5" /></span>
                <span className="min-w-0 flex-1">
                  <span className="flex items-center gap-2 font-semibold">{c.name || c.first_name || 'Telegram user'} {!c.user_id && <Badge>not connected</Badge>}</span>
                  <span className="block truncate text-sm text-ink-3">{c.direction === 'out' ? 'You: ' : ''}{c.text}</span>
                </span>
                <span className="shrink-0 text-right text-xs text-ink-3">{ago(c.created_at)}{c.unread > 0 && <span className="mt-1 block"><Badge tone="brand">{c.unread}</Badge></span>}</span>
              </Link>
            </li>
          ))}
        </ul>
      </Card>
    </>
  )
}

export function AdminSupportChat() {
  const { chat } = useParams()
  const qc = useQueryClient()
  const q = useQuery({
    queryKey: ['admin', 'support', chat],
    queryFn: () => api<{ chat: { chat_id: number; first_name: string | null; username: string | null; blocked: boolean; user: { id: string; name: string; email: string } | null }; messages: Msg[] }>(`admin/support/${chat}`),
    refetchInterval: 8000,
  })
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const end = useRef<HTMLDivElement>(null)
  useEffect(() => end.current?.scrollIntoView({ block: 'end' }), [q.data?.messages.length])

  async function send() {
    setBusy(true)
    setErr('')
    try {
      await post(`admin/support/${chat}`, { text })
      setText('')
      qc.invalidateQueries({ queryKey: ['admin', 'support'] })
    } catch (e) {
      setErr((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  if (!q.data) return <Spinner />
  const c = q.data.chat
  return (
    <>
      <Link to="/admin/support" className="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-3 hover:text-ink"><ArrowLeft className="size-4" /> Support</Link>
      <h1 className="text-2xl font-bold tracking-tight">{c.user?.name || c.first_name || 'Telegram user'}</h1>
      <p className="mb-4 text-ink-2 break-all">
        {c.user ? <Link className="font-semibold text-brand" to={`/admin/users/${c.user.id}`}>{c.user.email}</Link> : 'Not connected to a PipStream account'}
        {c.username && ` · @${c.username}`}{c.blocked && ' · blocked the bot'}
      </p>
      <Card className="mb-3 max-h-[60dvh] space-y-2 overflow-y-auto">
        {q.data.messages.map((m) => (
          <div key={m.id} className={clsx('flex', m.direction === 'out' ? 'justify-end' : 'justify-start')}>
            <div className={clsx('max-w-[85%] whitespace-pre-wrap rounded-2xl px-3 py-2 text-[15px]', m.direction === 'out' ? 'bg-brand text-brand-ink' : 'bg-surface-2')}>
              {m.text}
              <span className={clsx('mt-1 block text-[11px]', m.direction === 'out' ? 'opacity-75' : 'text-ink-3')}>{m.kind === 'broadcast' ? 'broadcast · ' : ''}{ago(m.created_at)}</span>
            </div>
          </div>
        ))}
        <div ref={end} />
      </Card>
      <Card className="space-y-2">
        <Textarea value={text} onChange={setText} rows={3} placeholder="Type your reply…" />
        <div className="flex items-center gap-2">
          <Button busy={busy} disabled={!text.trim() || c.blocked} onClick={send}><Send className="size-4" /> Send on Telegram</Button>
          <ErrorNote>{err}</ErrorNote>
        </div>
      </Card>
    </>
  )
}

/** On a customer's page: send them a message in the bot. */
export function MessageCustomer({ userId }: { userId: string }) {
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null)
  return (
    <Card className="mb-4 space-y-2">
      <span className="flex items-center gap-2 font-semibold"><Send className="size-4" /> Message on Telegram</span>
      <Textarea value={text} onChange={setText} rows={2} placeholder="Hi! I noticed your MetaTrader went offline…" />
      <div className="flex flex-wrap items-center gap-2">
        <Button variant="soft" busy={busy} disabled={!text.trim()} onClick={async () => {
          setBusy(true)
          setMsg(null)
          try {
            await post(`admin/users/${userId}/message`, { text })
            setText('')
            setMsg({ ok: true, text: 'Sent. Their reply shows in Support.' })
          } catch (e) {
            setMsg({ ok: false, text: (e as Error).message })
          } finally {
            setBusy(false)
          }
        }}>Send</Button>
        {msg && <span className={msg.ok ? 'text-sm text-up' : 'text-sm text-down'}>{msg.text}</span>}
      </div>
    </Card>
  )
}
