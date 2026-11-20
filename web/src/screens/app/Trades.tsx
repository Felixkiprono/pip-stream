import { useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { useState } from 'react'
import { Page } from '../../components/AppShell'
import { Card, Spinner } from '../../components/ui'
import { api } from '../../lib/api'
import type { Position } from '../../lib/types'
import { PositionRow } from './Home'

export default function Trades() {
  const [tab, setTab] = useState<'open' | 'closed'>('open')
  const q = useQuery({ queryKey: ['trades'], queryFn: () => api<{ trades: Position[] }>('trades'), refetchInterval: 15_000 })
  const rows = (q.data?.trades ?? []).filter((t) => t.status === tab)
  return (
    <Page title="Trades">
      <div className="grid grid-cols-2 rounded-2xl bg-surface-2 p-1">
        {(['open', 'closed'] as const).map((t) => (
          <button key={t} onClick={() => setTab(t)} className={clsx('h-10 rounded-xl text-[15px] font-semibold capitalize', tab === t ? 'bg-surface shadow-card' : 'text-ink-3')}>
            {t}
          </button>
        ))}
      </div>
      {q.isLoading ? (
        <Spinner />
      ) : (
        <Card className="py-1">
          {rows.length ? (
            <ul className="divide-y divide-line">{rows.map((p) => <PositionRow key={p.id} p={p} />)}</ul>
          ) : (
            <p className="py-8 text-center text-ink-3">No {tab} trades yet.</p>
          )}
        </Card>
      )}
    </Page>
  )
}
