import { useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { Check, Smartphone } from 'lucide-react'
import { useState } from 'react'
import { useNavigate } from 'react-router'
import { Badge, Button, ErrorNote, Spinner, Step } from '../../components/ui'
import { api, post } from '../../lib/api'
import type { Billing } from '../../lib/types'

export function PlanCards({ billing, pick, setPick }: { billing: Billing; pick: string; setPick: (p: string) => void }) {
  const perks: Record<string, string[]> = {
    trial: ['Copy every channel to practice money', 'Trade alerts in Telegram', '14 days free, no card'],
    pro: ['Copy to your real MT5 or Deriv account', 'Up to 3 broker accounts', 'Per-channel risk and daily loss limits'],
  }
  return (
    <div className="space-y-2.5">
      {billing.plans.map((p) => (
        <button
          key={p.id}
          onClick={() => setPick(p.id)}
          aria-pressed={pick === p.id}
          className={clsx('w-full rounded-3xl border-2 bg-surface p-4 text-left transition', pick === p.id ? 'border-brand' : 'border-line')}
        >
          <span className="flex items-center justify-between">
            <span className="flex items-center gap-2 text-lg font-bold">
              {p.name}
              {billing.current?.plan === p.id && <Badge tone="up">Current</Badge>}
            </span>
            <span className="text-right">
              <span className="text-xl font-bold tabular">{p.price ? `${p.currency} ${p.price.toLocaleString()}` : 'Free'}</span>
              {!!p.price && <span className="block text-xs text-ink-3">per month</span>}
            </span>
          </span>
          <ul className="mt-3 space-y-1.5">
            {(perks[p.id] ?? []).map((t) => (
              <li key={t} className="flex gap-2 text-[15px] text-ink-2">
                <Check className="mt-0.5 size-4 shrink-0 text-up" /> {t}
              </li>
            ))}
          </ul>
        </button>
      ))}
    </div>
  )
}

export async function checkout(plan: string) {
  const { url } = await post<{ url: string }>('billing/checkout', { plan })
  window.location.href = url
}

export default function Plan() {
  const nav = useNavigate()
  const q = useQuery({ queryKey: ['billing'], queryFn: () => api<Billing>('billing') })
  const [pick, setPick] = useState('trial')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const paid = q.data?.plans.find((p) => p.id === pick)?.price
  return (
    <Step
      step={6}
      title="Your plan"
      subtitle="Everything is free while you practise. Go Pro when you want real trades."
      action={
        <div className="space-y-2">
          <Button
            block
            busy={busy}
            onClick={async () => {
              if (!paid) return nav('/start/done')
              setBusy(true)
              setErr('')
              try {
                await checkout(pick)
              } catch (e) {
                setErr((e as Error).message)
                setBusy(false)
              }
            }}
          >
            {paid ? (
              <>
                <Smartphone className="size-4" /> Pay with M-Pesa or card
              </>
            ) : (
              'Start my free trial'
            )}
          </Button>
          {!!paid && (
            <Button block variant="ghost" onClick={() => nav('/start/done')}>
              Maybe later
            </Button>
          )}
        </div>
      }
    >
      {q.data ? <PlanCards billing={q.data} pick={pick} setPick={setPick} /> : <Spinner />}
      <ErrorNote>{err}</ErrorNote>
    </Step>
  )
}
