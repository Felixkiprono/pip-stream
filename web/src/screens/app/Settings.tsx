import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronRight, CreditCard, LogOut, Mail, Send, Shield, Wallet } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link, useNavigate } from 'react-router'
import { Page, Section } from '../../components/AppShell'
import { PlanCard, ThemeSwitch } from '../../components/Plan'
import { InstallCard } from '../../components/Install'
import { Card, Toggle } from '../../components/ui'
import { api, post, put, settle } from '../../lib/api'
import { useSession } from '../../lib/session'
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

      <Section title="Your plan">
        <PlanCard plan={user?.plan} />
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
            <Row icon={<Wallet className="size-5" />} title="Brokers" sub="Practice, MetaTrader 5, Deriv" to="/app/brokers" />
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
          {user?.role === 'admin' && <Row icon={<Shield className="size-5" />} title="Admin console" to="/admin" />}
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
      <p className="pb-4 text-center text-xs text-ink-3">PipStream · pip.stream</p>
    </Page>
  )
}
