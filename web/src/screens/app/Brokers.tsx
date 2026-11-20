import { useQuery, useQueryClient } from '@tanstack/react-query'
import { FlaskConical, Plus, Wallet } from 'lucide-react'
import { useState } from 'react'
import { Page } from '../../components/AppShell'
import { Badge, Button, Card, Spinner } from '../../components/ui'
import { api, del } from '../../lib/api'
import type { Broker } from '../../lib/types'
import { BrokerForm } from '../onboarding/Broker'

export default function Brokers() {
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['brokers'], queryFn: () => api<{ brokers: Broker[] }>('brokers') })
  const [adding, setAdding] = useState<null | 'mt5_bridge' | 'deriv_options'>(null)
  return (
    <Page title="Brokers">
      {q.isLoading ? (
        <Spinner />
      ) : (
        <Card className="py-1">
          <ul className="divide-y divide-line">
            {q.data?.brokers.map((b) => (
              <li key={b.id} className="flex items-center gap-3 py-3">
                <span className="grid size-10 place-items-center rounded-2xl bg-surface-2">{b.connector === 'paper' ? <FlaskConical className="size-5 text-brand" /> : <Wallet className="size-5" />}</span>
                <span className="min-w-0 flex-1">
                  <span className="block font-semibold">{b.label}</span>
                  <span className="block text-sm text-ink-3">{b.connector === 'paper' ? '$10,000 practice money' : `Login ${b.login_hint ?? ''}`}</span>
                </span>
                {b.connector === 'paper' ? (
                  <Badge tone="brand">Practice</Badge>
                ) : (
                  <button
                    className="text-sm font-semibold text-down"
                    onClick={async () => {
                      if (!window.confirm(`Remove ${b.label}? Its saved login is wiped.`)) return
                      await del(`brokers/${b.id}`)
                      qc.invalidateQueries({ queryKey: ['brokers'] })
                    }}
                  >
                    Remove
                  </button>
                )}
              </li>
            ))}
          </ul>
        </Card>
      )}
      {adding ? (
        <Card>
          <h2 className="mb-3 font-semibold">{adding === 'mt5_bridge' ? 'Connect MetaTrader 5' : 'Connect Deriv'}</h2>
          <BrokerForm
            kind={adding}
            onSaved={() => {
              setAdding(null)
              qc.invalidateQueries({ queryKey: ['brokers'] })
            }}
          />
        </Card>
      ) : (
        <div className="grid grid-cols-2 gap-2">
          <Button variant="soft" onClick={() => setAdding('mt5_bridge')}>
            <Plus className="size-4" /> MetaTrader 5
          </Button>
          <Button variant="soft" onClick={() => setAdding('deriv_options')}>
            <Plus className="size-4" /> Deriv
          </Button>
        </div>
      )}
    </Page>
  )
}
