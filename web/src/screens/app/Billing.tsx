import { useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { Check, CircleCheck, Crown, Infinity as InfinityIcon, Minus, ShieldCheck, Smartphone, Sparkles } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { useSearchParams } from 'react-router'
import { Page } from '../../components/AppShell'
import { Button, ErrorNote, Spinner } from '../../components/ui'
import { api } from '../../lib/api'
import { useSession } from '../../lib/session'
import type { Billing as B, PlanInfo } from '../../lib/types'
import { checkout } from '../onboarding/Plan'

const fmt = (n: number, cur: string) => `${cur} ${n.toLocaleString()}`

/** The plan you're on, big and clear: what it is, how long, what you use of it. */
function CurrentPlan({ plan }: { plan: PlanInfo }) {
  const premium = plan.tier === 'premium'
  const trialTotal = 14
  const used = plan.days_left != null ? Math.min(100, Math.round((100 * (trialTotal - plan.days_left)) / trialTotal)) : 0
  // Same card for both: gold for Pro/Lifetime, green for the free trial.
  const t = premium
    ? { bg: 'bg-[linear-gradient(135deg,#1a1205,#3a2608_55%,#6b4a10)]', accent: 'text-[#f5c542]', icon: 'bg-[#f5c542]/20', badge: 'bg-[#f5c542] text-[#2a1d05]', bar: 'bg-[#f5c542]' }
    : { bg: 'bg-[linear-gradient(135deg,#04140c,#0a2e1c_55%,#11613a)]', accent: 'text-[#34d399]', icon: 'bg-[#34d399]/20', badge: 'bg-[#34d399] text-[#042014]', bar: 'bg-[#34d399]' }
  const Deco = premium ? Crown : Sparkles
  return (
    <section className={clsx('relative overflow-hidden rounded-[28px] p-5 text-white md:p-7', t.bg)}>
      <Deco className={clsx('pointer-events-none absolute -top-6 -right-6 size-40 rotate-12 opacity-15', t.accent)} />
      <div className="relative flex flex-wrap items-start gap-4">
        <span className={clsx('order-first grid size-14 shrink-0 place-items-center rounded-2xl sm:order-none', t.icon, t.accent)}>
          {plan.lifetime ? <InfinityIcon className="size-7" /> : premium ? <Crown className="size-7 fill-current" /> : <Sparkles className="size-7" />}
        </span>
        <div className="w-full min-w-0 sm:w-auto sm:flex-1">
          <p className={clsx('text-xs font-semibold tracking-widest uppercase', t.accent)}>Your plan</p>
          <h2 className="mt-0.5 text-2xl font-bold tracking-tight md:text-3xl">
            {plan.lifetime ? 'Lifetime member' : premium ? plan.name : 'Free trial'}
          </h2>
          <p className="mt-1 text-[15px] text-white/75">
            {plan.lifetime
              ? 'Free forever, with everything in Pro. Thank you for being here from the start.'
              : premium
                ? plan.until ? `Renews on ${new Date(plan.until).toLocaleDateString(undefined, { day: 'numeric', month: 'long' })}.` : 'Active.'
                : plan.days_left != null
                  ? `${plan.days_left} of ${trialTotal} days left. Copy every signal to practice money, then go live with Pro.`
                  : 'Copy every signal to practice money.'}
          </p>
        </div>
        <span className={clsx('order-first ml-auto rounded-full px-3 py-1 text-xs font-bold sm:order-none sm:ml-0', t.badge)}>
          {plan.lifetime ? 'Free forever' : premium ? 'Active' : 'Trial'}
        </span>
      </div>

      {!premium && plan.days_left != null && (
        <div className="relative mt-5">
          <div className="h-2 overflow-hidden rounded-full bg-white/10"><div className={clsx('h-full rounded-full', t.bar)} style={{ width: `${used}%` }} /></div>
        </div>
      )}

      <dl className="relative mt-5 grid grid-cols-3 gap-2 rounded-2xl bg-white/10 p-3 text-center">
        {[
          ['Broker accounts', `${plan.usage.brokers} / ${plan.limits.brokers}`],
          ['Live trading', plan.limits.live ? 'On' : 'Pro'],
          ['Channels copied', `${plan.usage.channels}`],
        ].map(([k, v]) => (
          <div key={k}>
            <dt className="text-[11px] text-white/60">{k}</dt>
            <dd className="text-lg font-bold tabular">{v}</dd>
          </div>
        ))}
      </dl>
    </section>
  )
}

type Feature = [string, ReactNode, ReactNode]

/** Free vs Pro, feature by feature. Lifetime members see Pro as what they have. */
function Compare({ billing, plan, onUpgrade, busy }: { billing: B; plan: PlanInfo; onUpgrade: () => void; busy: boolean }) {
  const free = billing.plans.find((p) => p.price === 0)
  const pro = billing.plans.find((p) => p.price > 0)
  if (!free || !pro) return null
  const premium = plan.tier === 'premium'
  const yes = <Check className="mx-auto size-5 text-up" strokeWidth={3} aria-label="included" />
  const no = <Minus className="mx-auto size-5 text-ink-3" aria-label="not included" />
  const rows: Feature[] = [
    ['Copy channels & groups', 'Unlimited', 'Unlimited'],
    ['Practice account (no real money)', yes, yes],
    ['Live copy to your MT5 or Deriv', no, yes],
    ['Broker accounts', String(free.brokers), String(pro.brokers)],
    ['Per-channel risk & daily loss limits', no, yes],
    ['Trade alerts (app & Telegram)', yes, yes],
    ['Length', '14 days', 'Monthly'],
  ]
  return (
    <section className="overflow-hidden rounded-[28px] border border-line bg-surface">
      <div className="grid grid-cols-[1.4fr_1fr_1fr] items-end gap-2 border-b border-line p-4 md:p-5">
        <span className="text-sm font-semibold text-ink-3">Compare plans</span>
        {[free, pro].map((p) => {
          const current = (p.price === 0 && !premium) || (p.price > 0 && premium)
          return (
            <div key={p.id} className="text-center">
              <span className="flex items-center justify-center gap-1 font-bold">{p.price > 0 && <Crown className="size-4 fill-current text-warn" />}{p.price > 0 && plan.lifetime ? 'Pro / Lifetime' : p.name}</span>
              <span className="block text-sm text-ink-2 tabular">{p.price ? <>{fmt(p.price, p.currency)}<span className="text-xs text-ink-3"> /mo</span></> : 'Free'}</span>
              {current && <span className="mt-1 inline-block rounded-full bg-up-soft px-2 py-0.5 text-[10px] font-bold text-up uppercase">{plan.lifetime && p.price ? 'Yours free' : 'Current'}</span>}
            </div>
          )
        })}
      </div>
      <ul className="divide-y divide-line">
        {rows.map(([label, a, b]) => (
          <li key={label} className="grid grid-cols-[1.4fr_1fr_1fr] items-center gap-2 px-4 py-3 text-sm md:px-5">
            <span className="text-ink-2">{label}</span>
            <span className="text-center font-semibold">{a}</span>
            <span className={clsx('text-center font-semibold', premium && 'rounded-lg bg-warn-soft/50 py-1')}>{b}</span>
          </li>
        ))}
      </ul>
      {!premium && (
        <div className="border-t border-line p-4 md:p-5">
          {billing.can_pay ? (
            <Button block busy={busy} onClick={onUpgrade}>
              <Smartphone className="size-4" /> Upgrade to Pro: {fmt(pro.price, pro.currency)}/month, M-Pesa or card
            </Button>
          ) : (
            <p className="text-center text-sm text-ink-3">Payments open soon. Keep practicing for now.</p>
          )}
          <p className="mt-2 flex items-center justify-center gap-1.5 text-xs text-ink-3"><ShieldCheck className="size-3.5" /> Cancel any time. Your practice history stays.</p>
        </div>
      )}
    </section>
  )
}

export default function Billing() {
  const [params] = useSearchParams()
  const { user } = useSession()
  const q = useQuery({ queryKey: ['billing'], queryFn: () => api<B>('billing'), refetchInterval: params.get('paid') ? 3000 : false })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  if (!q.data || !user?.plan) return <Spinner />
  const pro = q.data.plans.find((p) => p.price > 0)

  async function upgrade() {
    if (!pro) return
    setBusy(true)
    setErr('')
    try {
      await checkout(pro.id)
    } catch (e) {
      setErr((e as Error).message)
      setBusy(false)
    }
  }

  return (
    <Page title="Plan & billing">
      {params.get('paid') && (
        <p className="anim-rise flex items-center gap-2 rounded-2xl bg-up-soft p-4 font-semibold text-up">
          <CircleCheck className="size-5" /> {user.plan.tier === 'premium' ? 'Payment received. Pro is on.' : 'Confirming your payment…'}
        </p>
      )}
      <div className="grid gap-4 lg:grid-cols-[1fr_1.15fr] lg:items-start">
        <CurrentPlan plan={user.plan} />
        <Compare billing={q.data} plan={user.plan} onUpgrade={upgrade} busy={busy} />
      </div>
      <ErrorNote>{err}</ErrorNote>
    </Page>
  )
}
