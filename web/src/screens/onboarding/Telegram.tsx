import { Check, Eye, EyeOff, KeyRound, Lock, MessageCircle, QrCode, ShieldCheck, Unplug } from 'lucide-react'
import { useEffect, useState, type ReactNode } from 'react'
import { Link, useNavigate } from 'react-router'
import { OtpInput } from '../../components/OtpInput'
import { COUNTRIES, PhoneInput, toE164, type Country } from '../../components/PhoneInput'
import { Button, ErrorNote, Field, Step } from '../../components/ui'
import { ApiError, api, post, settle } from '../../lib/api'
import { useSession } from '../../lib/session'
import type { TgStatus } from '../../lib/types'

const PHONE_KEY = 'ps.tg.phone'
const SENT_KEY = 'ps.tg.sent'
type Delivery = NonNullable<TgStatus['info']>
const remember = (p: string, info?: Delivery | null) => {
  try {
    sessionStorage.setItem(PHONE_KEY, p)
    sessionStorage.setItem(SENT_KEY, JSON.stringify(info ?? {}))
  } catch {}
}
const recallSent = (): Delivery => {
  try {
    return JSON.parse(sessionStorage.getItem(SENT_KEY) ?? '{}')
  } catch {
    return {}
  }
}

/** Where Telegram put the code, in words. */
function whereSent(d: Delivery, masked: ReactNode): ReactNode {
  switch (d.via) {
    case 'sms':
      return <>Telegram sent a code by <b className="text-ink">SMS</b> to {masked}.</>
    case 'email':
      return <>Telegram sent the code to your <b className="text-ink">login email</b>{d.email ? <> <b className="text-ink">{d.email}</b></> : null}. Check your inbox and spam.</>
    case 'call':
    case 'missed_call':
      return <>Telegram is <b className="text-ink">calling</b> {masked}. {d.via === 'missed_call' ? 'The code is the last digits of the calling number.' : 'Listen for the code.'}</>
    case 'fragment':
      return <>The code went to <b className="text-ink">Fragment</b> (fragment.com), because this is an anonymous number.</>
    case 'setup_email':
      return <>Telegram wants this account to set up a <b className="text-ink">login email</b> first. Open Telegram → Settings → Privacy and Security, add a login email, then try again.</>
    default:
      return (
        <>
          Telegram sent a login code to your Telegram app for {masked}. Open the chat called <b className="text-ink">“Telegram”</b> (blue tick), on any device where you are logged in.
        </>
      )
  }
}
const NEXT_LABEL: Record<string, string> = { sms: 'Send it by SMS instead', call: 'Call me instead', missed_call: 'Call me instead', email: 'Send it by email', fragment: 'Send via Fragment' }
const recall = () => {
  try {
    return sessionStorage.getItem(PHONE_KEY) ?? ''
  } catch {
    return ''
  }
}

/** Telegram's paper plane in a soft pulsing ring. */
export function TgArt({ done = false, icon }: { done?: boolean; icon?: React.ReactNode }) {
  return (
    <span className={`grid size-24 place-items-center rounded-full ${done ? 'bg-up anim-pop' : 'bg-tg anim-pulse'} text-white shadow-card`}>
      {done ? (
        <Check className="size-12" strokeWidth={3} />
      ) : (
        icon ?? (
          <svg width="46" height="46" viewBox="0 0 24 24" fill="currentColor" aria-hidden>
            <path d="M21.9 4.6 18.7 19.7c-.2 1.1-.9 1.3-1.8.8l-4.9-3.6-2.4 2.3c-.3.3-.5.5-1 .5l.3-5 9.1-8.2c.4-.4-.1-.6-.6-.2L6.2 13.4l-4.8-1.5c-1-.3-1.1-1 .2-1.5L20.4 3.2c.9-.3 1.7.2 1.5 1.4Z" />
          </svg>
        )
      )}
    </span>
  )
}

async function tg(path: string, body: unknown): Promise<TgStatus> {
  return settle(await post<TgStatus>(path, body))
}

export function TgPhone() {
  const nav = useNavigate()
  const saved = recall()
  const [country, setCountry] = useState<Country>(() => COUNTRIES.find((c) => saved.startsWith(`+${c.dial}`)) ?? COUNTRIES[0])
  const [local, setLocal] = useState(() => (saved ? saved.replace(`+${country.dial}`, '') : ''))
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const digits = local.replace(/\D/g, '').replace(/^0+/, '')
  const ready = digits.length >= Math.min(country.len, 7)

  async function send() {
    if (!ready || busy) return
    setBusy(true)
    setErr('')
    const phone = toE164(country, local)
    try {
      const s = await tg('telegram/phone', { phone })
      if (s.status === 'code_sent') {
        remember(phone, s.info)
        nav('/start/code')
      } else setErr(s.error ?? 'Telegram did not send a code. Try again.')
    } catch (e) {
      setErr((e as ApiError).field?.('phone') ?? (e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Step
      step={2}
      back={false}
      art={<TgArt />}
      title="Connect your Telegram"
      subtitle="Use the number of the Telegram account that is in your signal channels and groups."
      action={
        <Button variant="tg" block busy={busy} disabled={!ready} onClick={send}>
          Send me a code
        </Button>
      }
    >
      <PhoneInput country={country} setCountry={setCountry} value={local} setValue={setLocal} error={err} onEnter={send} />
      <Link to="/start/qr" className="flex min-h-12 items-center justify-center gap-2 rounded-2xl border border-line text-[15px] font-semibold text-ink-2 hover:bg-surface-2">
        <QrCode className="size-5" /> Log in with a QR code instead
      </Link>
      <ul className="mt-2 space-y-3 rounded-3xl bg-surface-2 p-4 text-[15px] text-ink-2">
        <li className="flex gap-3">
          <ShieldCheck className="size-5 shrink-0 text-up" />
          PipStream only reads the channels and groups you pick. Never your private chats.
        </li>
        <li className="flex gap-3">
          <Lock className="size-5 shrink-0 text-up" />
          Your login is locked in a sealed box only the reader can open.
        </li>
        <li className="flex gap-3">
          <Unplug className="size-5 shrink-0 text-up" />
          Disconnect any time, here or in Telegram's Devices list.
        </li>
      </ul>
    </Step>
  )
}

export function TgCode() {
  const nav = useNavigate()
  const { refresh } = useSession()
  const phone = recall()
  const [sent, setSent] = useState<Delivery>(recallSent)
  const len = sent.length && sent.length >= 4 && sent.length <= 8 ? sent.length : 5
  const [code, setCode] = useState('')
  const [err, setErr] = useState('')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [wait, setWait] = useState(() => Math.min(Math.max(sent.timeout ?? 30, 20), 120))
  useEffect(() => {
    if (wait <= 0) return
    const t = setTimeout(() => setWait(wait - 1), 1000)
    return () => clearTimeout(t)
  }, [wait])

  async function submit(c = code) {
    if (c.length < len || busy) return
    setBusy(true)
    setErr('')
    try {
      const s = await tg('telegram/code', { code: c })
      if (s.status === 'linked') {
        await refresh()
        nav('/start/linked', { replace: true })
      } else if (s.status === 'password_needed') nav('/start/password')
      else {
        setErr(s.error ?? 'That code did not work.')
        setCode('')
      }
    } catch (e) {
      setErr((e as Error).message)
      setCode('')
    } finally {
      setBusy(false)
    }
  }

  // Same login, Telegram's next delivery method (SMS / call). Falls back to a fresh request if
  // the pending login expired on the server.
  async function resend() {
    setErr('')
    setNote('')
    try {
      let s = await tg('telegram/resend', {})
      if (s.status === 'error' && /login timed out/i.test(s.error ?? '')) s = await tg('telegram/phone', { phone })
      if (s.status === 'code_sent') {
        remember(phone, s.info)
        setSent(s.info ?? {})
        setCode('')
        setNote('New code on its way.')
        setWait(Math.min(Math.max(s.info?.timeout ?? 30, 20), 120))
      } else setErr(s.error ?? 'Could not send a new code.')
    } catch (e) {
      setErr((e as Error).message)
    }
  }

  const masked = phone ? `${phone.slice(0, -9)} ••• ••${phone.slice(-3)}` : 'your number'
  return (
    <Step
      step={2}
      art={<TgArt icon={<MessageCircle className="size-11" />} />}
      title={sent.via === 'email' ? 'Check your email' : sent.via === 'sms' ? 'Check your messages' : 'Check your Telegram app'}
      subtitle={whereSent(sent, <b className="whitespace-nowrap text-ink">{masked}</b>)}
      action={
        <div className="space-y-2">
          <a href="tg://" className="flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl bg-brand-soft text-[15px] font-semibold text-brand">
            Open Telegram
          </a>
          <Button block variant="ghost" busy={busy} disabled={code.length < len} onClick={() => submit()}>
            {busy ? 'Checking…' : 'Continue'}
          </Button>
        </div>
      }
    >
      {sent.stand_in && (
        <p className="rounded-2xl bg-warn-soft p-3 text-sm text-warn">
          Test mode: this server runs a stand-in Telegram, so no real code is sent. Enter <b>12345</b>.
        </p>
      )}
      <OtpInput length={len} value={code} onChange={setCode} onComplete={submit} error={!!err} disabled={busy} />
      <ErrorNote>{err}</ErrorNote>
      {note && <p className="text-sm text-up">{note}</p>}
      <Link to="/start/qr" className="flex items-center gap-2 rounded-2xl bg-surface-2 p-3 text-sm text-ink-2">
        <QrCode className="size-5 shrink-0 text-brand" />
        <span>No code arriving? <b className="text-brand">Log in with a QR code</b> from the Telegram app instead.</span>
      </Link>
      <div className="flex items-center justify-between text-[15px]">
        <Link to="/start/telegram" className="font-semibold text-brand">
          Change number
        </Link>
        {wait > 0 ? (
          <span className="text-ink-3 tabular">New code in 0:{String(wait).padStart(2, '0')}</span>
        ) : (
          <button onClick={resend} className="font-semibold text-brand">
            {(sent.next && NEXT_LABEL[sent.next]) || 'Send a new code'}
          </button>
        )}
      </div>
    </Step>
  )
}

/** Log in by scanning a QR with the Telegram app: no login code involved. */
export function TgQr() {
  const nav = useNavigate()
  const { refresh } = useSession()
  const [st, setSt] = useState<TgStatus | null>(null)
  const [err, setErr] = useState('')
  const [run, setRun] = useState(0)
  useEffect(() => {
    let alive = true
    setErr('')
    setSt(null)
    ;(async () => {
      try {
        let s = await post<TgStatus>('telegram/qr', {})
        const rid = s.request_id
        const until = Date.now() + 5 * 60_000
        while (alive) {
          if (s.status !== 'pending') setSt(s)
          if (s.status === 'linked') {
            await refresh()
            nav('/start/linked', { replace: true })
            return
          }
          if (s.status === 'password_needed') return nav('/start/password')
          if (s.status === 'error') return
          if (!rid || Date.now() > until) return setErr('The QR expired. Show a new one.')
          await new Promise((ok) => setTimeout(ok, 1000))
          s = await api<TgStatus>(`telegram/requests/${rid}`)
        }
      } catch (e) {
        if (alive) setErr((e as Error).message)
      }
    })()
    return () => {
      alive = false
    }
  }, [run]) // eslint-disable-line react-hooks/exhaustive-deps

  const qr = st?.status === 'qr' ? st.info : null
  const failed = err || (st?.status === 'error' ? st.error ?? 'Telegram refused the login.' : '')
  return (
    <Step
      step={2}
      back="/start/telegram"
      title="Scan with Telegram"
      subtitle="On your phone, open Telegram → Settings → Devices → Link Desktop Device, and point the camera at this code."
      action={
        failed ? (
          <Button block variant="tg" onClick={() => setRun((n) => n + 1)}>Show a new QR code</Button>
        ) : qr?.url ? (
          <a href={qr.url} className="flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl bg-brand-soft text-[15px] font-semibold text-brand md:hidden">
            Telegram is on this phone? Tap to log in
          </a>
        ) : undefined
      }
    >
      <div className="grid place-items-center">
        <div className="grid size-64 place-items-center rounded-3xl bg-white p-3 shadow-card">
          {qr?.qr ? <img src={qr.qr} alt="Telegram login QR code" className="size-full" /> : failed ? <QrCode className="size-16 text-ink-3" /> : <span className="text-sm text-ink-3">Getting a code from Telegram…</span>}
        </div>
      </div>
      <ErrorNote>{failed}</ErrorNote>
      {qr && <p className="text-center text-sm text-ink-3">The code refreshes by itself every 30 seconds. Keep this page open.</p>}
      <ol className="space-y-2 rounded-3xl bg-surface-2 p-4 text-[15px] text-ink-2">
        <li>1. Open Telegram on your phone.</li>
        <li>2. Settings → Devices → <b className="text-ink">Link Desktop Device</b>.</li>
        <li>3. Scan this code. If you have a two-step password, we ask for it next.</li>
      </ol>
    </Step>
  )
}

export function TgPassword() {
  const nav = useNavigate()
  const { refresh } = useSession()
  const [pw, setPw] = useState('')
  const [show, setShow] = useState(false)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)

  async function submit(e?: React.FormEvent) {
    e?.preventDefault()
    if (!pw || busy) return
    setBusy(true)
    setErr('')
    try {
      const s = await tg('telegram/password', { password: pw })
      if (s.status === 'linked') {
        setPw('')
        await refresh()
        nav('/start/linked', { replace: true })
      } else setErr(s.error ?? 'That password did not work.')
    } catch (e) {
      setErr((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} className="contents">
      <Step
        step={2}
        art={<TgArt icon={<KeyRound className="size-11" />} />}
        title="One more lock"
        subtitle="Your Telegram has two-step verification on. Enter that password, the one you set in Telegram, not your PipStream password."
        action={
          <Button type="submit" variant="tg" block busy={busy} disabled={!pw}>
            Unlock
          </Button>
        }
      >
        <div className="relative">
          <Field
            label="Two-step verification password"
            type={show ? 'text' : 'password'}
            autoComplete="off"
            autoFocus
            value={pw}
            onChange={(e) => setPw(e.target.value)}
            error={err || undefined}
          />
          <button
            type="button"
            aria-label={show ? 'Hide password' : 'Show password'}
            onClick={() => setShow(!show)}
            className="absolute top-8 right-2 grid size-10 place-items-center rounded-full text-ink-3"
          >
            {show ? <EyeOff className="size-5" /> : <Eye className="size-5" />}
          </button>
        </div>
        <p className="text-sm text-ink-3">Forgot it? Telegram can reset it from Settings → Privacy and Security → Two-Step Verification.</p>
      </Step>
    </form>
  )
}

export function TgLinked() {
  const nav = useNavigate()
  useEffect(() => {
    const t = setTimeout(() => nav('/start/channels', { replace: true }), 2200)
    return () => clearTimeout(t)
  }, [nav])
  return (
    <Step
      step={2}
      back={false}
      art={<TgArt done />}
      title="Telegram connected"
      subtitle="Nice. Next, pick the signal channels and groups you want copied."
      action={
        <Button block onClick={() => nav('/start/channels', { replace: true })}>
          Pick my channels and groups
        </Button>
      }
    >
      <Confetti />
    </Step>
  )
}

export function Confetti() {
  const colors = ['var(--brand)', 'var(--up)', 'var(--tg)', 'var(--warn)']
  return (
    <div className="pointer-events-none fixed inset-0 overflow-hidden motion-reduce:hidden" aria-hidden>
      {Array.from({ length: 28 }, (_, i) => (
        <span
          key={i}
          className="absolute -top-4 block h-3 w-2 rounded-sm"
          style={{
            left: `${(i * 37) % 100}%`,
            background: colors[i % 4],
            animation: `fall ${2.2 + (i % 5) * 0.35}s ${(i % 7) * 0.08}s cubic-bezier(.3,.6,.5,1) forwards`,
          }}
        />
      ))}
    </div>
  )
}
