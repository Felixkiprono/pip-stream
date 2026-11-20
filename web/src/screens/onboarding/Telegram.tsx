import { Check, Eye, EyeOff, KeyRound, Lock, MessageCircle, ShieldCheck, Unplug } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { OtpInput } from '../../components/OtpInput'
import { COUNTRIES, PhoneInput, toE164, type Country } from '../../components/PhoneInput'
import { Button, ErrorNote, Field, Step } from '../../components/ui'
import { ApiError, post, settle } from '../../lib/api'
import { useSession } from '../../lib/session'
import type { TgStatus } from '../../lib/types'

const PHONE_KEY = 'ps.tg.phone'
const remember = (p: string) => {
  try {
    sessionStorage.setItem(PHONE_KEY, p)
  } catch {}
}
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
        remember(phone)
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
      subtitle="Use the number of the Telegram account that follows your signal channels."
      action={
        <Button variant="tg" block busy={busy} disabled={!ready} onClick={send}>
          Send me a code
        </Button>
      }
    >
      <PhoneInput country={country} setCountry={setCountry} value={local} setValue={setLocal} error={err} onEnter={send} />
      <ul className="mt-2 space-y-3 rounded-3xl bg-surface-2 p-4 text-[15px] text-ink-2">
        <li className="flex gap-3">
          <ShieldCheck className="size-5 shrink-0 text-up" />
          PipStream only reads the channels you pick. Never your chats.
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
  const [code, setCode] = useState('')
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [wait, setWait] = useState(45)
  useEffect(() => {
    if (wait <= 0) return
    const t = setTimeout(() => setWait(wait - 1), 1000)
    return () => clearTimeout(t)
  }, [wait])

  async function submit(c = code) {
    if (c.length < 5 || busy) return
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

  async function resend() {
    setWait(45)
    setErr('')
    try {
      const s = await tg('telegram/phone', { phone })
      if (s.status !== 'code_sent') setErr(s.error ?? 'Could not send a new code.')
    } catch (e) {
      setErr((e as Error).message)
    }
  }

  const masked = phone ? `${phone.slice(0, -9)} ••• ••${phone.slice(-3)}` : 'your number'
  return (
    <Step
      step={2}
      art={<TgArt icon={<MessageCircle className="size-11" />} />}
      title="Check your Telegram app"
      subtitle={
        <>
          Telegram just sent a login code to your app for <b className="whitespace-nowrap text-ink">{masked}</b>. It arrives in the chat called “Telegram”.
        </>
      }
      action={
        <div className="space-y-2">
          <a href="tg://" className="flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl bg-brand-soft text-[15px] font-semibold text-brand">
            Open Telegram
          </a>
          <Button block variant="ghost" busy={busy} disabled={code.length < 5} onClick={() => submit()}>
            {busy ? 'Checking…' : 'Continue'}
          </Button>
        </div>
      }
    >
      <OtpInput value={code} onChange={setCode} onComplete={submit} error={!!err} disabled={busy} />
      <ErrorNote>{err}</ErrorNote>
      <div className="flex items-center justify-between text-[15px]">
        <Link to="/start/telegram" className="font-semibold text-brand">
          Change number
        </Link>
        {wait > 0 ? (
          <span className="text-ink-3 tabular">New code in 0:{String(wait).padStart(2, '0')}</span>
        ) : (
          <button onClick={resend} className="font-semibold text-brand">
            Send a new code
          </button>
        )}
      </div>
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
      subtitle="Nice. Next, pick the signal channels you want copied."
      action={
        <Button block onClick={() => nav('/start/channels', { replace: true })}>
          Pick my channels
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
