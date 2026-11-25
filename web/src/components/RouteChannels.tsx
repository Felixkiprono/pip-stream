import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowRightLeft, X } from 'lucide-react'
import { useState } from 'react'
import { api } from '../lib/api'
import { saveRule } from '../lib/rules'
import type { Broker, MyChannel } from '../lib/types'
import { Button, ErrorNote } from './ui'

/** Right after linking a broker: channels still copy into Practice, so offer to point them here in one tap. */
export function RouteChannels({ broker, onDone }: { broker: Broker; onDone: () => void }) {
  const qc = useQueryClient()
  const channels = useQuery({ queryKey: ['channels'], queryFn: () => api<{ channels: MyChannel[] }>('channels') })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const elsewhere = (channels.data?.channels ?? []).filter((c) => c.rule?.broker_account_id !== broker.id)
  if (!channels.data || elsewhere.length === 0) return null
  const demo = broker.status?.account?.demo

  async function move() {
    setBusy(true)
    setErr('')
    try {
      // one question for the account, not one per channel
      let yes: boolean | null = null
      const ask = (q: string) => (yes ??= window.confirm(q))
      for (const c of elsewhere) {
        const saved = await saveRule(c.id, {
          style: c.rule?.style === 'custom' || !c.rule ? 'balanced' : c.rule.style,
          broker_account_id: broker.id,
          live: true,
          enabled: true,
        }, ask)
        if (!saved) break
      }
      qc.invalidateQueries({ queryKey: ['brokers'] })
      if (yes === false) return
      qc.invalidateQueries({ queryKey: ['channels'] })
      onDone()
    } catch (e) {
      setErr((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-3 rounded-2xl border-2 border-brand bg-brand-soft/40 p-4">
      <div className="flex items-start gap-3">
        <ArrowRightLeft className="mt-0.5 size-5 shrink-0 text-brand" />
        <div className="min-w-0 flex-1">
          <h3 className="font-semibold">Copy your signals into {broker.label}?</h3>
          <p className="text-sm text-ink-2">
            {elsewhere.length === 1 ? `${elsewhere[0].title} still copies` : `${elsewhere.length} channels still copy`} into another account. Switch{' '}
            {elsewhere.length === 1 ? 'it' : 'them'} here so new signals become orders in your MetaTrader.
            {demo === false && <b> This looks like a real-money account: orders will use real money.</b>}
            {demo === true && ' This is a demo account, so only demo money is used.'}
          </p>
        </div>
        <button aria-label="Not now" className="text-ink-3" onClick={onDone}>
          <X className="size-5" />
        </button>
      </div>
      <div className="flex gap-2">
        <Button busy={busy} onClick={move}>
          Copy signals here
        </Button>
        <Button variant="soft" onClick={onDone}>
          Not now
        </Button>
      </div>
      {err && <ErrorNote>{err}</ErrorNote>}
    </div>
  )
}
