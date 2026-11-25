import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronRight, CreditCard, KeyRound, LogOut, Mail, Send, Shield, Wallet } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Link, useNavigate } from 'react-router'
import { Page, Section } from '../../components/AppShell'
import { PlanCard, ThemeSwitch } from '../../components/Plan'
import { InstallCard } from '../../components/Install'
import { Button, Card, ErrorNote, Field, Toggle } from '../../components/ui'
import { api, ApiError, post, put, settle } from '../../lib/api'
import { isAdmin, useSession } from '../../lib/session'
import type { TgStatus } from '../../lib/types'

type S = { name: string; email: string; alerts: { telegram: boolean; email: boolean }; bot_username: string | null }

function Row({ icon, title, sub, right, to }: { icon: ReactNode; title: string; sub?: ReactNode; right?: ReactNode; to?: string }) {
  const body = (
    <>
      <span className="grid size-10 shrink-0 place-items-center rounded-2xl bg-surface-2 text-ink-2">{icon}</span>
      <span className="min-w-0 flex-1">
        <span className="block font-semibold">{title}</span>
        {sub && <span className="block truncate text-sm text-ink-3">{sub}</span>}
      </span>
      {right ?? (to && <ChevronRight className="size-5 text-ink-3" />)}
    </>
  )
  return <li>{to ? <Link to={to} className="flex items-center gap-3 py-3">{body}</Link> : <div className="flex items-center gap-3 py-3">{body}</div>}</li>
}

/** Change password while signed in. A "your password was changed" email follows. */
function PasswordCard() {
  const [open, setOpen] = useState(false)
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState(false)
  const [err, setErr] = useState<ApiError | null>(null)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErr(null)
    try {
      await put('settings/password', { current_password: current, password: next })
      setDone(true)
      setOpen(false)
      setCurrent('')
      setNext('')
    } catch (e) {
      setErr(e as ApiError)
    } finally {
      setBusy(false)
    }
  }

  if (!open)
    return (
      <Card className="py-0">
        <ul>
          <Row
            icon={<KeyRound className="size-5" />}
            title="Password"
            sub={done ? 'Changed. We emailed you a confirmation.' : 'Change the password you sign in with'}
            right={<button className="text-sm font-semibold text-brand" onClick={() => { setOpen(true); setDone(false) }}>Change</button>}
          />
        </ul>
      </Card>
    )
  return (
    <Card>
      <form onSubmit={submit} className="space-y-3">
        <Field label="Current password" type="password" autoComplete="current-password" value={current} onChange={(e) => setCurrent(e.target.value)} error={err?.field('current_password')} required />
        <Field label="New password" type="password" autoComplete="new-password" value={next} onChange={(e) => setNext(e.target.value)} error={err?.field('password')} hint="At least 8 characters." required />
        {err && !Object.keys(err.errors).length && <ErrorNote>{err.message}</ErrorNote>}
        <div className="flex flex-wrap items-center gap-2">
          <Button type="submit" busy={busy}>Save new password</Button>
          <Button type="button" variant="ghost" onClick={() => { setOpen(false); setErr(null) }}>Cancel</Button>
          <Link to="/forgot-password" className="ml-auto text-sm font-semibold text-brand">Forgot it?</Link>
        </div>
      </form>
    </Card>
  )
}

export default function Settings() {
  const nav = useNavigate()
  const qc = useQueryClient()
  const { user, refresh } = useSession()
  const s = useQuery({ queryKey: ['settings'], queryFn: () => api<S>('settings') })
  const tg = useQuery({ queryKey: ['telegram'], queryFn: () => api<{ account: { linked: boolean; phone_hint: string | null } | null }>('telegram') })
  const set = async (body: object) => {
    qc.setQueryData(['settings'], await put<S>('settings', body))
  }
  const linked = tg.data?.account?.linked

  return (
    <Page title="Settings">
      <Card className="flex items-center gap-3">
        <span className="grid size-12 place-items-center rounded-full bg-brand text-lg font-bold text-white">{(user?.name ?? user?.email ?? '?')[0].toUpperCase()}</span>
        <span className="min-w-0">
          <span className="block font-semibold">{user?.name}</span>
          <span className="block truncate text-sm text-ink-3">{user?.email}</span>
        </span>
      </Card>

      <Section title="Sign-in">
        <PasswordCard />
      </Section>

      <Section title="Your plan">
        <PlanCard plan={user?.plan} />
      </Section>

      <Section title="MetaTrader EA">
        <Card>
          <p className="text-[15px] text-ink-2">
            Copy trades into your own MetaTrader 4 or 5 with the free PipStream EA. Add your account under Brokers to get your personal key and the install steps.
          </p>
          <div className="mt-3 flex flex-wrap gap-2">
            <Link to="/app/brokers" className="inline-flex min-h-11 items-center rounded-2xl bg-brand px-4 text-sm font-semibold text-brand-ink">
              Set up MetaTrader
            </Link>
            <a href="/ea/PipStream.mq5" download className="inline-flex min-h-11 items-center rounded-2xl bg-brand-soft px-4 text-sm font-semibold text-brand">
              EA for MT5
            </a>
            <a href="/ea/PipStream.mq4" download className="inline-flex min-h-11 items-center rounded-2xl bg-brand-soft px-4 text-sm font-semibold text-brand">
              EA for MT4
            </a>
          </div>
        </Card>
      </Section>

      <Section title="Appearance">
        <ThemeSwitch />
      </Section>

      <Section title="Connections">
        <Card className="py-0">
          <ul className="divide-y divide-line">
            <Row
              icon={<Send className="size-5 text-tg" />}
              title="Telegram"
              sub={linked ? `Connected · ···${tg.data?.account?.phone_hint}` : 'Not connected'}
              right={
                linked ? (
                  <button
                    className="text-sm font-semibold text-down"
                    onClick={async () => {
                      if (!window.confirm('Disconnect Telegram? Copying stops for all your channels.')) return
                      settle(await post<TgStatus>('telegram/disconnect')).finally(() => {
                        qc.invalidateQueries({ queryKey: ['telegram'] })
                        refresh()
                      })
                    }}
                  >
                    Disconnect
                  </button>
                ) : (
                  <Link to="/start/telegram" className="text-sm font-semibold text-brand">Connect</Link>
                )
              }
            />
            <Row icon={<Wallet className="size-5" />} title="Brokers" sub="Practice, MetaTrader 4/5 (EA download), Deriv" to="/app/brokers" />
            <Row icon={<CreditCard className="size-5" />} title="Plan and billing" to="/app/billing" />
          </ul>
        </Card>
      </Section>

      <Section title="Alerts">
        <Card className="py-0">
          <ul className="divide-y divide-line">
            <Row
              icon={<Send className="size-5" />}
              title="Telegram alerts"
              sub={s.data?.alerts.telegram ? 'From the PipStream bot' : 'One tap to get trade alerts in Telegram'}
              right={
                <Toggle
                  label="Telegram alerts"
                  on={!!s.data?.alerts.telegram}
                  onChange={async (on) => {
                    if (!on) return set({ telegram_alerts: false })
                    const { url } = await post<{ url: string }>('settings/telegram-alerts')
                    window.location.href = url
                  }}
                />
              }
            />
            <Row icon={<Mail className="size-5" />} title="Email for failed trades" right={<Toggle label="Email alerts" on={!!s.data?.alerts.email} onChange={(on) => set({ email_alerts: on })} />} />
          </ul>
        </Card>
      </Section>

      <InstallCard />

      <Card className="py-0">
        <ul className="divide-y divide-line">
          {isAdmin(user) && <Row icon={<Shield className="size-5" />} title="Admin console" to="/admin" />}
          <li>
            <button
              className="flex w-full items-center gap-3 py-3 font-semibold text-down"
              onClick={async () => {
                await post('auth/logout')
                qc.clear()
                nav('/', { replace: true })
              }}
            >
              <span className="grid size-10 place-items-center rounded-2xl bg-down-soft">
                <LogOut className="size-5" />
              </span>
              Sign out
            </button>
          </li>
        </ul>
      </Card>
      <p className="pb-4 text-center text-xs text-ink-3">PipStream · pips.stream</p>
    </Page>
  )
}
