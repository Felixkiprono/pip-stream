import clsx from 'clsx'
import { Bell, Home, List, Radio, Settings } from 'lucide-react'
import type { ReactNode } from 'react'
import { NavLink, Outlet } from 'react-router'

const TABS = [
  { to: '/app', label: 'Home', icon: Home, end: true },
  { to: '/app/trades', label: 'Trades', icon: List },
  { to: '/app/channels', label: 'Channels', icon: Radio },
  { to: '/app/alerts', label: 'Alerts', icon: Bell },
  { to: '/app/settings', label: 'Settings', icon: Settings },
]

export function AppShell({ badge }: { badge?: number }) {
  return (
    <div className="mx-auto min-h-dvh max-w-md">
      <div className="pb-24">
        <Outlet />
      </div>
      <nav className="fixed inset-x-0 bottom-0 z-40 border-t border-line bg-surface/90 backdrop-blur-lg safe-bottom">
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
                {t.label === 'Alerts' && !!badge && (
                  <span className="absolute top-1.5 left-1/2 ml-2 min-w-4 rounded-full bg-down px-1 text-center text-[10px] leading-4 text-white">{badge > 9 ? '9+' : badge}</span>
                )}
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
    <div className="anim-rise px-4 safe-top">
      <header className="flex h-16 items-center justify-between">
        <h1 className="text-[26px] font-bold tracking-tight">{title}</h1>
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
