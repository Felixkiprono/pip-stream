import { useQuery } from '@tanstack/react-query'
import { CircleCheck, Smartphone } from 'lucide-react'
import { useState } from 'react'
import { useSearchParams } from 'react-router'
import { Page } from '../../components/AppShell'
import { Button, ErrorNote, Spinner } from '../../components/ui'
import { api } from '../../lib/api'
import type { Billing as B } from '../../lib/types'
import { checkout, PlanCards } from '../onboarding/Plan'

export default function Billing() {
  const [params] = useSearchParams()
  const q = useQuery({ queryKey: ['billing'], queryFn: () => api<B>('billing'), refetchInterval: params.get('paid') ? 3000 : false })
  const [pick, setPick] = useState('pro')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  if (!q.data) return <Spinner />
  const cur = q.data.current
  const canBuy = q.data.plans.find((p) => p.id === pick)?.price && cur?.plan !== pick
  return (
    <Page title="Plan">
      {params.get('paid') && (
        <p className="anim-rise flex items-center gap-2 rounded-2xl bg-up-soft p-4 font-semibold text-up">
          <CircleCheck className="size-5" /> {cur?.plan === 'pro' ? 'Payment received. Pro is on.' : 'Confirming your payment…'}
        </p>
      )}
      {cur && (
        <p className="text-[15px] text-ink-2">
          You're on <b className="text-ink">{cur.plan_name}</b>
          {cur.until && ` until ${new Date(cur.until).toLocaleDateString()}`}.
        </p>
      )}
      <PlanCards billing={q.data} pick={pick} setPick={setPick} />
      <ErrorNote>{err}</ErrorNote>
      {canBuy && (
        <Button
          block
          busy={busy}
          onClick={async () => {
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
          <Smartphone className="size-4" /> Pay with M-Pesa or card
        </Button>
      )}
    </Page>
  )
}
