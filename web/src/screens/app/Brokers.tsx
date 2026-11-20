import { useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { AlertTriangle, CheckCircle2, Cpu, FlaskConical, KeyRound, Loader2, Plus, Wallet, X } from 'lucide-react'
import { useState } from 'react'
import { Page } from '../../components/AppShell'
import { EaSetup } from '../../components/EaSetup'
import { Badge, Button, Card, ErrorNote, Spinner } from '../../components/ui'
import { api, ApiError, del, post } from '../../lib/api'
import type { Broker, BrokerStatus } from '../../lib/types'
import { BrokerForm } from '../onboarding/Broker'

type Adding = null | 'ea_bridge' | 'metaapi' | 'deriv_options'

export default function Brokers() {
  const qc = useQueryClient()
  const q = useQuery({
    queryKey: ['brokers'],
    queryFn: () => api<{ brokers: Broker[] }>('brokers'),
    // while a MetaTrader account is starting up or offline, keep its status fresh
    refetchInterval: (query) => (query.state.data?.brokers.some((b) => b.status && b.status.state !== 'connected') ? 3000 : 15000),
  })
  const [adding, setAdding] = useState<Adding>(null)
  const [guide, setGuide] = useState<{ broker: Broker; key?: string | null } | null>(null)
  const refresh = () => qc.invalidateQueries({ queryKey: ['brokers'] })

  return (
    <Page title="Brokers">
      {q.isLoading ? (
        <Spinner />
      ) : (
        <Card className="py-1">
          <ul className="divide-y divide-line">
            {q.data?.brokers.map((b) => (
              <li key={b.id} className="flex flex-wrap items-center gap-3 py-3">
                <span className="grid size-10 shrink-0 place-items-center rounded-2xl bg-surface-2">
                  {b.connector === 'paper' ? <FlaskConical className="size-5 text-brand" /> : b.connector === 'ea_bridge' ? <Cpu className="size-5" /> : <Wallet className="size-5" />}
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block font-semibold">{b.label}</span>
                  <span className="block text-sm text-ink-3">{subline(b)}</span>
                  {b.status && <StatusLine s={b.status} />}
                </span>
                {b.connector === 'paper' ? (
                  <Badge tone="brand">Practice</Badge>
                ) : (
                  <span className="flex items-center gap-3">
                    {b.connector === 'ea_bridge' && (
                      <button className="text-sm font-semibold text-brand" onClick={() => setGuide({ broker: b })}>
                        Set up
                      </button>
                    )}
                    <button
                      className="text-sm font-semibold text-down"
                      onClick={async () => {
                        if (!window.confirm(`Remove ${b.label}? ${b.connector === 'ea_bridge' ? 'Its EA key stops working.' : 'Its saved login is wiped.'}`)) return
                        await del(`brokers/${b.id}`)
                        refresh()
                      }}
                    >
                      Remove
                    </button>
                  </span>
                )}
              </li>
            ))}
          </ul>
        </Card>
      )}

      {guide && (
        <Card>
          <div className="mb-4 flex items-start justify-between gap-3">
            <div>
              <h2 className="font-semibold">Set up {guide.broker.label}</h2>
              <p className="text-sm text-ink-3">The PipStream EA runs in your MetaTrader and places the trades there.</p>
            </div>
            <button aria-label="Close" className="text-ink-3" onClick={() => setGuide(null)}>
              <X className="size-5" />
            </button>
          </div>
          <EaSetup broker={guide.broker} eaKey={guide.key} />
          {!guide.key && (
            <Button
              variant="soft"
              className="mt-4"
              onClick={async () => {
                if (!window.confirm('Make a new key? The EA keeps working only after you paste the new key into it.')) return
                const r = await post<{ broker: Broker; ea_key: string }>(`brokers/${guide.broker.id}/ea-key`)
                setGuide({ broker: r.broker, key: r.ea_key })
              }}
            >
              <KeyRound className="size-4" /> Make a new key
            </Button>
          )}
        </Card>
      )}

      {adding === 'ea_bridge' ? (
        <Card>
          <h2 className="mb-1 font-semibold">Connect MetaTrader with the PipStream EA</h2>
          <p className="mb-4 text-sm text-ink-3">Free. Works with any broker. Your MetaTrader password never leaves your terminal.</p>
          <EaAddForm
            onCancel={() => setAdding(null)}
            onCreated={(broker, key) => {
              setAdding(null)
              setGuide({ broker, key })
              refresh()
            }}
          />
        </Card>
      ) : adding ? (
        <Card>
          <div className="mb-3 flex items-center justify-between">
            <h2 className="font-semibold">{adding === 'metaapi' ? 'Connect MetaTrader (hosted, no terminal needed)' : 'Connect Deriv'}</h2>
            <button aria-label="Close" className="text-ink-3" onClick={() => setAdding(null)}>
              <X className="size-5" />
            </button>
          </div>
          <BrokerForm
            kind={adding}
            onSaved={() => {
              setAdding(null)
              refresh()
            }}
          />
        </Card>
      ) : (
        !guide && (
          <div className="space-y-2">
            <button
              onClick={() => setAdding('ea_bridge')}
              className="flex w-full items-center gap-3 rounded-3xl border-2 border-brand bg-surface p-4 text-left"
            >
              <span className="grid size-10 shrink-0 place-items-center rounded-2xl bg-brand-soft text-brand">
                <Cpu className="size-5" />
              </span>
              <span className="min-w-0 flex-1">
                <span className="flex flex-wrap items-center gap-2 font-semibold">
                  MetaTrader 4 or 5 <Badge tone="up">Free · recommended</Badge>
                </span>
                <span className="block text-sm text-ink-2">Install our EA in your MetaTrader. Exness, IC Markets, XM, HFM and more.</span>
              </span>
              <Plus className="size-5 text-brand" />
            </button>
            <div className="grid grid-cols-2 gap-2">
              <Button variant="soft" onClick={() => setAdding('metaapi')}>
                <Plus className="size-4" /> MetaTrader, hosted
              </Button>
              <Button variant="soft" onClick={() => setAdding('deriv_options')}>
                <Plus className="size-4" /> Deriv
              </Button>
            </div>
          </div>
        )
      )}
    </Page>
  )
}

function subline(b: Broker) {
  if (b.connector === 'paper') return '$10,000 practice money'
  if (b.connector === 'ea_bridge') {
    const a = b.status?.account
    return a?.login ? `${a.company ? a.company + ' · ' : ''}${a.login} · ${a.balance?.toLocaleString()} ${a.currency ?? ''}` : 'Runs in your MetaTrader'
  }
  return `Login ${b.login_hint ?? ''}`
}

function EaAddForm({ onCreated, onCancel }: { onCreated: (b: Broker, key: string) => void; onCancel: () => void }) {
  const [platform, setPlatform] = useState<'mt4' | 'mt5'>('mt5')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<ApiError | null>(null)
  return (
    <div className="space-y-3">
      <div className="grid grid-cols-2 gap-2 rounded-2xl bg-surface-2 p-1" role="radiogroup" aria-label="Platform">
        {(['mt4', 'mt5'] as const).map((p) => (
          <button
            key={p}
            type="button"
            role="radio"
            aria-checked={platform === p}
            onClick={() => setPlatform(p)}
            className={clsx('min-h-11 rounded-xl text-sm font-semibold', platform === p ? 'bg-surface text-ink shadow-card' : 'text-ink-3')}
          >
            MetaTrader {p === 'mt4' ? '4' : '5'}
          </button>
        ))}
      </div>
      {err && <ErrorNote>{err.message}</ErrorNote>}
      <div className="flex gap-2">
        <Button variant="soft" onClick={onCancel}>
          Cancel
        </Button>
        <Button
          block
          busy={busy}
          onClick={async () => {
            setBusy(true)
            setErr(null)
            try {
              const r = await post<{ broker: Broker; ea_key: string }>('brokers', { connector: 'ea_bridge', platform })
              onCreated(r.broker, r.ea_key)
            } catch (e) {
              setErr(e as ApiError)
            } finally {
              setBusy(false)
            }
          }}
        >
          Get my key and the EA
        </Button>
      </div>
    </div>
  )
}

function StatusLine({ s }: { s: BrokerStatus }) {
  if (s.state === 'connected')
    return (
      <span className="mt-1 flex items-center gap-1.5 text-sm font-semibold text-up">
        <CheckCircle2 className="size-4" /> Connected
      </span>
    )
  if (s.state === 'error')
    return (
      <span className="mt-1 flex items-start gap-1.5 text-sm font-semibold text-down">
        <AlertTriangle className="mt-0.5 size-4 shrink-0" /> {s.detail || 'Could not connect'}
      </span>
    )
  return (
    <span className="mt-1 flex items-center gap-1.5 text-sm text-ink-2">
      <Loader2 className="size-4 animate-spin text-brand" /> {s.detail || 'Connecting'}
    </span>
  )
}
