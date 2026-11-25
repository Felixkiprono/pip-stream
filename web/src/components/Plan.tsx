import clsx from 'clsx'
import { Award, Crown, FlaskConical, Monitor, Moon, Sun } from 'lucide-react'
import { Link } from 'react-router'
import type { PlanInfo } from '../lib/types'
import { useTheme, type Theme } from '../lib/theme'
import { Card } from './ui'

/** "BETA" pill shown next to the logo while PipStream is in beta. */
export function BetaPill({ className }: { className?: string }) {
  return (
    <span className={clsx('inline-flex items-center gap-1 rounded-full bg-warn-soft px-2 py-0.5 text-[10px] font-bold tracking-wider text-warn uppercase', className)} title="PipStream is in beta">
      <FlaskConical className="size-3" /> Beta
    </span>
  )
}

/** Crown for paying and lifetime members. */
export function CrownMark({ plan, className }: { plan?: PlanInfo | null; className?: string }) {
  if (plan?.tier !== 'premium') return null
  return <Crown className={clsx('size-4 fill-current text-warn', className)} aria-label={`${plan.name} member`} />
}

/** "Founding member #3": one of the first customers, free for life with every feature. */
export function FoundingBadge({ number, className }: { number?: number | null; className?: string }) {
  if (!number) return null
  return (
    <span className={clsx('inline-flex items-center gap-1 rounded-full bg-coin px-2.5 py-0.5 text-xs font-bold', className)} title="One of PipStream's first members: free for life, every feature unlocked">
      <Award className="size-3.5" /> Founding member #{number}
    </span>
  )
}

export function PlanBadge({ plan }: { plan?: PlanInfo | null }) {
  if (!plan) return null
  if (plan.founding) return <FoundingBadge number={plan.founding} />
  const premium = plan.tier === 'premium'
  return (
    <span className={clsx('inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold', premium ? 'bg-warn-soft text-warn' : 'bg-surface-2 text-ink-2')}>
      {premium && <Crown className="size-3.5 fill-current" />}
      {plan.lifetime ? 'Lifetime' : premium ? plan.name : 'Free'}
    </span>
  )
}

function Meter({ label, used, max }: { label: string; used: number; max: number }) {
  const pct = max > 0 ? Math.min(100, Math.round((100 * used) / max)) : 0
  return (
    <div>
      <div className="mb-1 flex justify-between text-xs">
        <span className="text-ink-3">{label}</span>
        <span className="font-semibold tabular">{used} / {max}</span>
      </div>
      <div className="h-2 overflow-hidden rounded-full bg-surface-2" role="meter" aria-valuenow={used} aria-valuemax={max} aria-label={label}>
        <div className={clsx('h-full rounded-full', pct >= 100 ? 'bg-warn' : 'bg-brand')} style={{ width: `${pct}%` }} />
      </div>
    </div>
  )
}

/** The customer's plan: tier, crown, what it allows and how much they use. */
export function PlanCard({ plan }: { plan?: PlanInfo | null }) {
  if (!plan) return null
  const premium = plan.tier === 'premium'
  return (
    <Card className={clsx('space-y-3', premium && 'border-warn/40')}>
      <div className="flex items-start gap-3">
        <span className={clsx('grid size-11 shrink-0 place-items-center rounded-2xl', premium ? 'bg-warn-soft text-warn' : 'bg-surface-2 text-ink-3')}>
          <Crown className={clsx('size-6', premium && 'fill-current')} />
        </span>
        <span className="min-w-0 flex-1">
          <span className="flex flex-wrap items-center gap-2 font-bold">
            {plan.founding ? 'Founding member' : plan.lifetime ? 'Lifetime member' : premium ? `${plan.name} member` : 'Free plan'}
            <PlanBadge plan={plan} />
          </span>
          <span className="block text-sm text-ink-2">
            {plan.founding
              ? `You were #${plan.founding} to join: free for life, with every feature unlocked.`
              : plan.lifetime
              ? 'Free for life, with every Pro feature.'
              : premium
                ? plan.until ? `Renews ${new Date(plan.until).toLocaleDateString()}.` : 'Active.'
                : plan.days_left != null
                  ? `${plan.days_left} day${plan.days_left === 1 ? '' : 's'} left on your free trial.`
                  : 'Practice trading with no time limit.'}
          </span>
        </span>
      </div>
      <Meter label="Real broker accounts" used={plan.usage.brokers} max={plan.limits.brokers} />
      <div className="flex items-center justify-between text-sm">
        <span className="text-ink-3">Live trading with real money</span>
        <span className={clsx('font-semibold', plan.limits.live ? 'text-up' : 'text-ink-3')}>{plan.limits.live ? 'Included' : 'Pro only'}</span>
      </div>
      <div className="flex items-center justify-between text-sm">
        <span className="text-ink-3">Channels & groups copied</span>
        <span className="font-semibold tabular">{plan.usage.channels} · no limit</span>
      </div>
      {!premium && (
        <Link to="/app/billing" className="flex min-h-11 items-center justify-center gap-2 rounded-2xl bg-warn-soft text-sm font-semibold text-warn">
          <Crown className="size-4" /> Upgrade to Pro
        </Link>
      )}
    </Card>
  )
}

const THEMES: { id: Theme; label: string; icon: typeof Sun }[] = [
  { id: 'light', label: 'Light', icon: Sun },
  { id: 'dark', label: 'Dark', icon: Moon },
  { id: 'system', label: 'Auto', icon: Monitor },
]

/** Light / Dark / Auto (follows the device). */
export function ThemeSwitch({ compact }: { compact?: boolean }) {
  const [theme, setTheme] = useTheme()
  return (
    <div className="grid grid-cols-3 rounded-xl bg-surface-2 p-1 text-sm font-semibold" role="radiogroup" aria-label="Theme">
      {THEMES.map((t) => (
        <button
          key={t.id}
          role="radio"
          aria-checked={theme === t.id}
          onClick={() => setTheme(t.id)}
          className={clsx('flex items-center justify-center gap-1.5 rounded-lg px-2 py-1.5', theme === t.id ? 'bg-surface text-ink shadow-sm' : 'text-ink-3')}
        >
          <t.icon className="size-4" />
          {!compact && t.label}
        </button>
      ))}
    </div>
  )
}

/** One-tap light/dark flip for headers. */
export function ThemeButton() {
  const [, setTheme, dark] = useTheme()
  return (
    <button onClick={() => setTheme(dark ? 'light' : 'dark')} className="grid size-10 place-items-center rounded-full text-ink-2 hover:bg-surface-2" aria-label={dark ? 'Switch to light theme' : 'Switch to dark theme'}>
      {dark ? <Sun className="size-5" /> : <Moon className="size-5" />}
    </button>
  )
}
