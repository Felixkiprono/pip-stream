import { useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { TriangleAlert } from 'lucide-react'
import { useState } from 'react'
import { Page } from '../../components/AppShell'
import { Badge, Button, Card, ErrorNote, Spinner } from '../../components/ui'
import { api, post } from '../../lib/api'
import type { FailedOrder, Position } from '../../lib/types'
import { PositionRow, stamp } from './Home'

const TABS = { open: 'Open', closed: 'Closed', failed: "Didn't copy" } as const

function FailedRow({ o }: { o: FailedOrder }) {
  return (
    <li className="flex items-start gap-3 py-3">
      <span className="grid size-10 shrink-0 place-items-center rounded-2xl bg-down-soft text-down">
        <TriangleAlert className="size-5" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="flex items-center gap-2 font-semibold">
          {o.symbol ?? '—'}
          {o.side && <span className="text-xs font-bold text-ink-3 uppercase">{o.side}</span>}
          {o.volume != null && <span className="text-sm font-medium text-ink-3 tabular">{o.volume}</span>}
          <span className="text-xs font-medium text-ink-3">{o.action}</span>
        </span>
        <span className="block text-sm text-down">{o.error ?? o.status}</span>
        <span className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-3 tabular">
          {o.account.practice ? <Badge>Practice</Badge> : <Badge tone="brand">{o.account.label ?? 'Broker'}</Badge>}
          <span>{stamp(o.created_at)}</span>
          {o.latency_ms != null && <span>{o.latency_ms} ms</span>}
        </span>
      </span>
    </li>
  )
}

function CloseButton({ p, onDone }: { p: Position; onDone: (err?: string) => void }) {
  const [busy, setBusy] = useState(false)
  return (
    <Button
      variant="danger"
      busy={busy}
      className="min-h-10 shrink-0 px-3 text-sm"
      onClick={async () => {
        setBusy(true)
        try {
          await post(`trades/${p.id}/close`)
          onDone()
        } catch (e) {
          onDone((e as Error).message)
        } finally {
          setBusy(false)
        }
      }}
    >
      Close
    </Button>
  )
}

export default function Trades() {
  const qc = useQueryClient()
  const [err, setErr] = useState('')
  const [closingAll, setClosingAll] = useState(false)
  const done = (e?: string) => {
    setErr(e ?? '')
    qc.invalidateQueries({ queryKey: ['trades'] })
    qc.invalidateQueries({ queryKey: ['home'] })
  }
  const [tab, setTab] = useState<keyof typeof TABS>('open')
  const q = useQuery({
    queryKey: ['trades'],
    queryFn: () => api<{ trades: Position[]; failed: FailedOrder[] }>('trades'),
    refetchInterval: 15_000,
  })
  const rows = (q.data?.trades ?? []).filter((t) => t.status === tab)
  const failed = q.data?.failed ?? []
  const practiceOpen = (q.data?.trades ?? []).filter((t) => t.status === 'open' && t.account?.practice).length
  return (
    <Page title="Trades">
      <div className="grid grid-cols-3 rounded-2xl bg-surface-2 p-1">
        {(Object.keys(TABS) as (keyof typeof TABS)[]).map((t) => (
          <button key={t} onClick={() => setTab(t)} className={clsx('h-10 rounded-xl text-[15px] font-semibold', tab === t ? 'bg-surface shadow-card' : 'text-ink-3')}>
            {TABS[t]}
          </button>
        ))}
      </div>
      <ErrorNote>{err}</ErrorNote>
      {tab === 'open' && practiceOpen > 1 && (
        <Button
          variant="soft"
          block
          busy={closingAll}
          onClick={async () => {
            setClosingAll(true)
            try {
              await post('trades/close-practice')
              done()
            } catch (e) {
              done((e as Error).message)
            } finally {
              setClosingAll(false)
            }
          }}
        >
          Close all {practiceOpen} practice trades
        </Button>
      )}
      {q.isLoading ? (
        <Spinner />
      ) : (
        <Card className="py-1">
          {tab === 'failed' ? (
            failed.length ? (
              <ul className="divide-y divide-line">{failed.map((o) => <FailedRow key={o.id} o={o} />)}</ul>
            ) : (
              <p className="py-8 text-center text-ink-3">Every trade copied so far.</p>
            )
          ) : rows.length ? (
            <ul className="divide-y divide-line">{rows.map((p) => (
                <PositionRow key={p.id} p={p} action={p.status === 'open' && p.account?.practice ? <CloseButton p={p} onDone={done} /> : undefined} />
              ))}</ul>
          ) : (
            <p className="py-8 text-center text-ink-3">No {tab} trades yet.</p>
          )}
        </Card>
      )}
    </Page>
  )
}
