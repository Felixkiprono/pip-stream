import clsx from 'clsx'
import { Bell, CreditCard, Home, Landmark, List, Radio, Settings, Shield } from 'lucide-react'
import type { ReactNode } from 'react'
import { NavLink, Outlet } from 'react-router'
import { useLiveUpdates } from '../lib/live'
import { isAdmin, useSession } from '../lib/session'
import { BetaPill, CrownMark, PlanBadge, ThemeSwitch } from './Plan'
import { Logo } from './ui'

const TABS = [
  { to: '/app', label: 'Home', icon: Home, end: true },
  { to: '/app/trades', label: 'Trades', icon: List },
  { to: '/app/channels', label: 'Channels', icon: Radio },
  { to: '/app/alerts', label: 'Alerts', icon: Bell },
  { to: '/app/settings', label: 'Settings', icon: Settings },
]
// Reached from Settings on phones; listed in the sidebar on bigger screens.
const MORE = [
  { to: '/app/brokers', label: 'Brokers', icon: Landmark },
  { to: '/app/billing', label: 'Plan & billing', icon: CreditCard },
]

function Count({ n, className }: { n?: number; className?: string }) {
  if (!n) return null
  return <span className={clsx('min-w-4 rounded-full bg-down px-1 text-center text-[10px] leading-4 font-semibold text-white', className)}>{n > 9 ? '9+' : n}</span>
}

/**
 * Mobile first: phones get a bottom tab bar and one column. From tablet width up the tabs move
 * into a sidebar and the content gets room to breathe (screens add columns at lg).
 */
export function AppShell({ badge }: { badge?: number }) {
  const { user } = useSession()
  useLiveUpdates()
  const side = (to: string, label: string, Icon: typeof Home, end?: boolean, n?: number) => (
    <NavLink
      key={to}
      to={to}
      end={end}
      className={({ isActive }) => clsx('flex items-center gap-3 rounded-xl px-3 py-2.5 text-[15px] font-semibold', isActive ? 'bg-brand-soft text-brand' : 'text-ink-2 hover:bg-surface-2')}
    >
      <Icon className="size-5" />
      <span className="flex-1">{label}</span>
      <Count n={n} />
    </NavLink>
  )
  return (
    <div className="min-h-dvh md:grid md:grid-cols-[232px_1fr] lg:grid-cols-[256px_1fr]">
      <aside className="sticky top-0 hidden h-dvh flex-col border-r border-line bg-surface md:flex">
        <div className="flex h-16 items-center gap-2 px-5">
          <Logo size={26} />
          {user?.beta && <BetaPill />}
        </div>
        <nav className="flex flex-1 flex-col gap-1 overflow-y-auto px-3 pb-4">
          {TABS.map((t) => side(t.to, t.label, t.icon, t.end, t.label === 'Alerts' ? badge : undefined))}
          <span className="mt-4 mb-1 px-3 text-xs font-semibold tracking-wide text-ink-3 uppercase">Account</span>
          {MORE.map((t) => side(t.to, t.label, t.icon))}
          <div className="mt-auto space-y-3 pt-4">
            {isAdmin(user) && side('/admin', 'Admin console', Shield)}
            <div className="flex items-center gap-2 rounded-xl bg-surface-2 px-3 py-2.5">
              <span className="min-w-0 flex-1">
                <span className="flex items-center gap-1 truncate text-sm font-semibold">{user?.name || user?.email} <CrownMark plan={user?.plan} /></span>
                <PlanBadge plan={user?.plan} />
              </span>
            </div>
            <ThemeSwitch compact />
          </div>
        </nav>
      </aside>

      <div className="mx-auto w-full max-w-md min-w-0 pb-24 md:max-w-3xl md:px-4 md:pt-4 md:pb-10 lg:max-w-5xl lg:px-8">
        <Outlet />
      </div>

      <nav className="fixed inset-x-0 bottom-0 z-40 border-t border-line bg-surface/90 backdrop-blur-lg safe-bottom md:hidden">
        <ul className="mx-auto flex max-w-md">
          {TABS.map((t) => (
            <li key={t.to} className="flex-1">
              <NavLink
                to={t.to}
                end={t.end}
                className={({ isActive }) => clsx('relative flex flex-col items-center gap-0.5 pt-2.5 pb-1 text-[11px] font-semibold', isActive ? 'text-brand' : 'text-ink-3')}
              >
                <t.icon className="size-6" />
                {t.label}
                {t.label === 'Alerts' && <Count n={badge} className="absolute top-1.5 left-1/2 ml-2" />}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>
    </div>
  )
}

export function Page({ title, right, children }: { title: ReactNode; right?: ReactNode; children: ReactNode }) {
  return (
    <div className="anim-rise px-4 safe-top md:px-0">
      <header className="flex h-16 items-center justify-between gap-3 md:h-20">
        <h1 className="text-[26px] font-bold tracking-tight md:text-3xl">{title}</h1>
        {right}
      </header>
      <div className="space-y-4">{children}</div>
    </div>
  )
}

export function Section({ title, action, children }: { title: string; action?: ReactNode; children: ReactNode }) {
  return (
    <section>
      <div className="mb-2 flex items-center justify-between px-1">
        <h2 className="text-sm font-semibold tracking-wide text-ink-3 uppercase">{title}</h2>
        {action}
      </div>
      {children}
    </section>
  )
}
