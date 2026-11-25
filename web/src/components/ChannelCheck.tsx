import { useQuery } from '@tanstack/react-query'
import { CheckCircle2, CircleX, Copy, Loader2, RotateCcw, Send } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router'
import { api, ApiError, post } from '../lib/api'
import { ago } from '../lib/format'
import type { MyChannel } from '../lib/types'
import { Button, ErrorNote, Spinner } from './ui'

type Check = { id: string; ok: boolean; label: string; detail: string | null }
type Health = {
  checks: Check[]
  ok: boolean
  last_post_at: string | null
  can_place: boolean
  place_live: boolean
  recent: { id: string; status: 'parsed' | 'rejected'; at: string; posted_at: string | null; text: string; read_as: string }[]
}
type Leg = { symbol: string; side: string; volume: number; sl: number | null; tp: number | null; price: number | null; order_type: string }
export type ParsedSig = {
  kind: 'open' | 'modify' | 'close' | 'options'
  symbol: string | null
  side: 'buy' | 'sell' | null
  entry: { type: string; price: number | null; from: number | null; to: number | null } | null
  sl: number | null
  sl_pips: number | null
  tps: number[]
  tp_pips: number[]
  raw_text: string
}
export type SelfTest = {
  id: string
  code: string
  state: 'waiting' | 'received' | 'planned' | 'wrong_channel' | 'expired'
  done: boolean
  place: boolean
  expires_at: string | null
  arrived: { posted_at: string | null; received_at: string | null; seconds: number | null; edited: boolean } | null
  wrong_channel: string | null
  parse: { status: 'ping' | 'parsed' | 'rejected'; text: string; confidence: number | null; signal: ParsedSig | null } | null
  plan: { status: 'would_copy' | 'placed' | 'skipped' | 'no_rule'; text: string; legs: Leg[]; signal_id: string | null; mode: string | null } | null
}

function Line({ ok, wait, title, detail }: { ok?: boolean; wait?: boolean; title: string; detail?: string | null }) {
  return (
    <li className="flex items-start gap-2">
      {wait ? <Loader2 className="mt-0.5 size-4 shrink-0 animate-spin text-brand" /> : ok ? <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-up" /> : <CircleX className="mt-0.5 size-4 shrink-0 text-down" />}
      <span className="min-w-0 flex-1">
        <span className="font-semibold">{title}</span>
        {detail && <span className="block text-ink-2">{detail}</span>}
      </span>
    </li>
  )
}

/** One self-test: get a code, then poll until the engine has read the post (or the code expires). */
export function useSelfTest(channelId: string, onDone?: () => void) {
  const [test, setTest] = useState<SelfTest | null>(null)
  const [sample, setSample] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const timer = useRef<number | null>(null)
  useEffect(() => () => void (timer.current && window.clearTimeout(timer.current)), [])
  useEffect(() => {
    if (timer.current) window.clearTimeout(timer.current)
    setTest(null)
  }, [channelId])

  async function start(place = false) {
    setErr('')
    setBusy(true)
    if (timer.current) window.clearTimeout(timer.current)
    try {
      const r = await post<{ test: SelfTest; sample: string }>(`channels/${channelId}/test`, { place })
      setTest(r.test)
      setSample(r.sample)
      const poll = async () => {
        try {
          const n = await api<{ test: SelfTest }>(`channels/${channelId}/test/${r.test.id}`)
          setTest(n.test)
          if (n.test.done) return onDone?.()
        } catch (e) {
          if ((e as ApiError).status === 404) return setErr('This test code has expired. Get a new one.')
        }
        timer.current = window.setTimeout(poll, 1500)
      }
      timer.current = window.setTimeout(poll, 1500)
    } catch (e) {
      setErr((e as Error).message)
    } finally {
      setBusy(false)
    }
  }
  return { test, sample, busy, err, setErr, start, reset: () => setTest(null) }
}

export function CopyBox({ text, onNew, busy }: { text: string; onNew: () => void; busy?: boolean }) {
  const [copied, setCopied] = useState(false)
  const [err, setErr] = useState('')
  useEffect(() => setCopied(false), [text])
  async function copy() {
    try {
      await navigator.clipboard.writeText(text)
      setCopied(true)
    } catch {
      setErr('Copy did not work here. Select the text and copy it by hand.')
    }
  }
  return (
    <div className="space-y-2">
      <pre className="whitespace-pre-wrap rounded-2xl border border-dashed border-brand bg-surface-2 p-3 font-mono text-sm select-all">{text}</pre>
      <div className="grid grid-cols-2 gap-2">
        <Button variant="soft" onClick={copy}>
          <Copy className="size-4" /> {copied ? 'Copied' : 'Copy'}
        </Button>
        <Button variant="ghost" busy={busy} onClick={onNew}>
          <RotateCcw className="size-4" /> New code
        </Button>
      </div>
      <ErrorNote>{err}</ErrorNote>
    </div>
  )
}

/** "Test this channel": live checks of everything copying depends on, a one-time code to post in
 *  the channel with what PipStream made of it, and the channel's latest posts. */
export function ChannelCheck({ c }: { c: MyChannel }) {
  const health = useQuery({ queryKey: ['channel-check', c.id], queryFn: () => api<Health>(`channels/${c.id}/check`), refetchInterval: 15000 })
  const [kind, setKind] = useState<'ping' | 'signal'>('signal')
  const [place, setPlace] = useState(false)
  const st = useSelfTest(c.id, () => health.refetch())
  const test = st.test
  const text = test ? testText(test.code, kind === 'signal' ? st.sample : '') : ''
  const start = () => st.start(kind === 'signal' && place)

  const h = health.data
  return (
    <div className="space-y-4">
      <div>
        <p className="mb-2 text-sm font-semibold text-ink-3">Checks</p>
        {health.isLoading ? (
          <Spinner />
        ) : h ? (
          <ul className="space-y-1.5 rounded-2xl border border-line bg-surface-2 p-3 text-sm">
            {h.checks.map((k) => (
              <Line key={k.id} ok={k.ok} title={k.label} detail={k.detail} />
            ))}
            <li className="pt-1 text-ink-3">{h.last_post_at ? `Last post read ${ago(h.last_post_at)}.` : 'No post read from this channel yet.'}</li>
          </ul>
        ) : (
          <ErrorNote>Could not run the checks. Try again.</ErrorNote>
        )}
      </div>

      <div>
        <p className="mb-1 text-sm font-semibold text-ink-3">Test with a post</p>
        <p className="mb-2 text-sm text-ink-2">
          For a channel you can post in. We give you a one-time code; post it in the channel and watch it arrive here. A post with the code is never copied to anyone else.
        </p>
        <div className="mb-2 grid grid-cols-2 gap-2">
          {(['signal', 'ping'] as const).map((k) => (
            <button key={k} onClick={() => setKind(k)} className={`rounded-2xl border-2 p-2.5 text-left text-sm ${kind === k ? 'border-brand bg-brand-soft' : 'border-line'}`}>
              <span className="block font-semibold">{k === 'signal' ? 'Signal check' : 'Delivery check'}</span>
              <span className="block text-ink-3">{k === 'signal' ? 'Code and a sample signal: see how it is read and sized' : 'Just the code: see that posts arrive'}</span>
            </button>
          ))}
        </div>
        {kind === 'signal' && h?.can_place && (
          <label className={`mb-2 flex items-start gap-2 rounded-2xl p-3 text-sm ${h.place_live ? 'bg-warn-soft text-warn' : 'bg-surface-2'}`}>
            <input type="checkbox" className="mt-1" checked={place} onChange={(e) => { setPlace(e.target.checked); st.reset() }} />
            <span>
              <span className="block font-semibold">Also place the trade</span>
              {h.place_live ? 'This channel copies live: a real order with real money will be opened.' : 'Opens it on your account like a real signal. Leave this off to only see what would be copied.'}
            </span>
          </label>
        )}
        {!test || test.state === 'expired' ? (
          <Button block busy={st.busy} onClick={start}>
            <Send className="size-4" /> Get my test code
          </Button>
        ) : (
          <div className="space-y-3">
            {kind === 'signal' && (
              <p className="text-xs text-ink-3">Or post a signal in your channel's usual style; just keep the code PS-{test.code} somewhere in it.</p>
            )}
            <CopyBox text={text} onNew={start} busy={st.busy} />
            <TestSteps t={test} />
          </div>
        )}
        <ErrorNote>{st.err}</ErrorNote>
      </div>

      {!!h?.recent.length && (
        <div>
          <p className="mb-1 text-sm font-semibold text-ink-3">Latest posts we read</p>
          <p className="mb-2 text-sm text-ink-2">Can't post in this channel? These show it is coming through and how each post was read.</p>
          <ul className="divide-y divide-line rounded-2xl border border-line text-sm">
            {h.recent.map((s) => (
              <li key={s.id}>
                <Link to={`/app/signals/${s.id}`} className="block p-3">
                  <span className="flex items-center gap-2">
                    {s.status === 'parsed' ? <CheckCircle2 className="size-4 shrink-0 text-up" /> : <CircleX className="size-4 shrink-0 text-ink-3" />}
                    <span className="min-w-0 flex-1 truncate font-semibold">{s.read_as}</span>
                    <span className="shrink-0 text-ink-3">{ago(s.posted_at ?? s.at)}</span>
                  </span>
                  {s.text && <span className="mt-0.5 block truncate text-ink-3">{s.text}</span>}
                </Link>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}

export const testText = (code: string, sample: string) => `PipStream test PS-${code}${sample ? `\n${sample}` : ''}`

export function TestSteps({ t }: { t: SelfTest }) {
  const waiting = t.state === 'waiting'
  return (
    <ul className="space-y-1.5 rounded-2xl border border-line bg-surface-2 p-3 text-sm" aria-live="polite">
      {waiting ? (
        <Line wait title="Waiting for your post…" detail={`Post the text above in the channel from Telegram. The code works for 30 minutes${t.expires_at ? ` (until ${new Date(t.expires_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })})` : ''}.`} />
      ) : t.state === 'expired' ? (
        <Line title="The code expired before we saw it" detail="Check you posted in this channel, then get a new code." />
      ) : t.state === 'wrong_channel' ? (
        <Line title="Arrived, but in another channel" detail={`We saw the code in ${t.wrong_channel ?? 'a different channel'}. Post it in this one.`} />
      ) : (
        <>
          <Line ok title="Post arrived" detail={t.arrived?.seconds != null ? `${t.arrived.seconds} s after you posted it${t.arrived.edited ? ' (edited post)' : ''}.` : null} />
          {t.parse && <Line ok={t.parse.status !== 'rejected'} title={t.parse.status === 'parsed' ? 'Read as a signal' : t.parse.status === 'ping' ? 'Delivery works' : 'Not read as a signal'} detail={t.parse.text} />}
          {t.parse?.status === 'parsed' && !t.plan && <Line wait title="Working out what would be copied…" />}
          {t.plan && (
            <Line
              ok={t.plan.status === 'would_copy' || t.plan.status === 'placed'}
              title={t.plan.status === 'placed' ? 'Trade placed' : t.plan.status === 'would_copy' ? 'Would be copied' : 'Would not be copied'}
              detail={t.plan.text}
            />
          )}
          {t.parse?.status === 'parsed' && t.parse.signal && <TradePreview signal={t.parse.signal} plan={t.plan} />}
          {t.done && t.plan?.status === 'placed' && t.plan.signal_id && (
            <li className="pl-6">
              <Link to={`/app/signals/${t.plan.signal_id}`} className="font-semibold text-brand">See the trade</Link>
            </li>
          )}
        </>
      )}
    </ul>
  )
}

const fmt = (v: number | null | undefined) => (v == null ? '—' : String(+v.toFixed(5)))

/** The practice preview: the post as written, what PipStream read from it, and the trade(s) it becomes. */
export function TradePreview({ signal: s, plan }: { signal: ParsedSig; plan: SelfTest['plan'] }) {
  const e = s.entry
  const entry = !e ? 'market' : e.type === 'range' ? `${fmt(e.from)} – ${fmt(e.to)}` : e.price != null ? `${e.type === 'market' ? '' : `${e.type} `}${fmt(e.price)}` : 'market'
  const rows: [string, string][] = [
    ['Instrument', s.symbol ?? '—'],
    ['Direction', s.side ? s.side.toUpperCase() : s.kind],
    ['Entry', entry],
    ['Stop-loss', s.sl != null ? fmt(s.sl) : s.sl_pips != null ? `${s.sl_pips} pips` : 'none'],
    ['Take-profit', s.tps.length ? s.tps.map(fmt).join(' / ') : s.tp_pips.length ? `${s.tp_pips.join(' / ')} pips` : 'none'],
  ]
  return (
    <li className="space-y-2 pt-1">
      <div className="ml-auto max-w-[85%] rounded-2xl rounded-br-md bg-tg/10 px-3 py-2 font-mono text-[13px] whitespace-pre-wrap">{s.raw_text}</div>
      <div className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 rounded-2xl bg-surface p-3">
        {rows.map(([k, v]) => (
          <span key={k} className="contents">
            <span className="text-ink-3">{k}</span>
            <span className={`font-semibold tabular ${k === 'Direction' ? (s.side === 'buy' ? 'text-up' : s.side === 'sell' ? 'text-down' : '') : ''}`}>{v}</span>
          </span>
        ))}
      </div>
      {!!plan?.legs.length && (
        <div className="space-y-1.5">
          {plan.legs.map((l, i) => (
            <div key={i} className="flex items-center gap-2 rounded-2xl border border-line bg-surface px-3 py-2">
              <span className={`rounded-lg px-2 py-0.5 text-xs font-bold ${l.side === 'buy' ? 'bg-up-soft text-up' : 'bg-down-soft text-down'}`}>{l.side?.toUpperCase()}</span>
              <span className="font-semibold tabular">{l.volume} {l.symbol}</span>
              <span className="ml-auto text-xs text-ink-3 tabular">
                {l.order_type !== 'market' && l.price ? `${l.order_type} @ ${fmt(l.price)} · ` : ''}SL {fmt(l.sl)} · TP {fmt(l.tp)}
              </span>
            </div>
          ))}
          <p className="text-xs text-ink-3">{plan.status === 'placed' ? 'Placed for real on this account.' : plan.mode === 'live' ? 'Preview only: nothing was sent to your broker.' : 'Preview only: no trade was opened.'}</p>
        </div>
      )}
    </li>
  )
}
