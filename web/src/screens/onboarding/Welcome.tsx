import { BellRing, Radio, ShieldCheck } from 'lucide-react'
import { Link, Navigate } from 'react-router'
import { Logo } from '../../components/ui'
import { isAdmin, nextStep, useSession } from '../../lib/session'

export default function Welcome() {
  const { user } = useSession()
  if (user) return <Navigate to={isAdmin(user) ? '/admin' : nextStep(user)} replace />
  return (
    <div className="mx-auto flex min-h-dvh max-w-md flex-col px-5 safe-top">
      <header className="flex h-16 items-center">
        <Logo />
      </header>
      <main className="anim-rise flex flex-1 flex-col">
        <div className="relative mt-4 overflow-hidden rounded-[28px] bg-brand p-6 text-white shadow-card">
          <svg className="absolute -right-6 -bottom-4 opacity-25" width="220" height="140" viewBox="0 0 220 140" aria-hidden>
            <path d="M0 120 L40 90 L70 104 L110 60 L140 74 L190 20 L220 30" stroke="#fff" strokeWidth="6" fill="none" strokeLinecap="round" />
          </svg>
          <p className="text-sm font-medium opacity-80">Telegram signals, copied for you</p>
          <h1 className="mt-2 text-[32px] leading-[1.1] font-bold tracking-tight">Follow a channel. PipStream places the trade.</h1>
          <p className="mt-3 max-w-[30ch] text-[15px] opacity-90">The moment your signal channel posts, the trade opens on your broker, sized to your risk.</p>
        </div>
        <ul className="mt-6 space-y-4">
          {[
            [Radio, 'Uses the channels you already follow', 'Sign in with Telegram and pick them. No bots to add.'],
            [ShieldCheck, 'Starts on practice money', 'Watch it copy for free before you connect a real account.'],
            [BellRing, 'Tells you every move', 'An alert for every trade, right in Telegram.'],
          ].map(([Icon, t, d]: any) => (
            <li key={t} className="flex gap-3">
              <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-brand-soft text-brand">
                <Icon className="size-5" />
              </span>
              <span>
                <span className="block font-semibold">{t}</span>
                <span className="block text-[15px] text-ink-2">{d}</span>
              </span>
            </li>
          ))}
        </ul>
      </main>
      <footer className="sticky bottom-0 -mx-5 space-y-2 bg-gradient-to-t from-bg via-bg to-transparent px-5 pt-6 safe-bottom">
        <Link to="/signup" className="flex min-h-13 w-full items-center justify-center rounded-2xl bg-brand text-[16px] font-semibold text-brand-ink shadow-card">
          Get started free
        </Link>
        <Link to="/login" className="flex min-h-12 w-full items-center justify-center rounded-2xl text-[15px] font-semibold text-ink-2">
          I already have an account
        </Link>
      </footer>
    </div>
  )
}
