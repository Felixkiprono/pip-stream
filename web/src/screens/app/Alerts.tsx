import { useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { ChevronRight, CircleAlert, CircleCheck, CircleSlash, Info } from 'lucide-react'
import { Link } from 'react-router'
import { useEffect } from 'react'
import { Page } from '../../components/AppShell'
import { Card, Spinner } from '../../components/ui'
import { api, post } from '../../lib/api'
import { ago } from '../../lib/format'
import type { Alert } from '../../lib/types'

const ICON: Record<string, [any, string]> = {
  trade_opened: [CircleCheck, 'text-up bg-up-soft'],
  trade_modified: [CircleCheck, 'text-brand bg-brand-soft'],
  trade_closed: [CircleCheck, 'text-brand bg-brand-soft'],
  position_closed: [CircleCheck, 'text-brand bg-brand-soft'],
  trade_failed: [CircleAlert, 'text-down bg-down-soft'],
  signal_skipped: [CircleSlash, 'text-warn bg-warn-soft'],
}

export default function Alerts() {
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['alerts'], queryFn: () => api<{ alerts: Alert[] }>('alerts') })
  useEffect(() => {
    if (q.data?.alerts.some((a) => !a.read)) post('alerts/read').then(() => qc.invalidateQueries({ queryKey: ['home'] }))
  }, [q.data, qc])
  return (
    <Page title="Alerts">
      {q.isLoading ? (
        <Spinner />
      ) : q.data?.alerts.length ? (
        <Card className="py-1">
          <ul className="divide-y divide-line">
            {q.data.alerts.map((a) => {
              const [Icon, tone] = ICON[a.kind] ?? [Info, 'text-ink-2 bg-surface-2']
              return (
                <li key={a.id}>
                  <Link to={`/app/alerts/${a.id}`} className="-mx-2 flex items-center gap-3 rounded-2xl px-2 py-3 hover:bg-surface-2 active:bg-surface-2">
                  <span className={clsx('grid size-10 shrink-0 place-items-center rounded-2xl', tone)}>
                    <Icon className="size-5" />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="flex items-baseline justify-between gap-2">
                      <span className={clsx('font-semibold', !a.read && 'text-brand')}>{a.title}</span>
                      <span className="shrink-0 text-xs text-ink-3">{ago(a.at)}</span>
                    </span>
                    <span className="block text-sm text-ink-2 tabular">{a.body}</span>
                  </span>
                  <ChevronRight className="size-5 shrink-0 text-ink-3" />
                  </Link>
                </li>
              )
            })}
          </ul>
        </Card>
      ) : (
        <p className="rounded-3xl bg-surface-2 p-6 text-center text-ink-2">Every copied trade, close and skipped signal shows up here.</p>
      )}
    </Page>
  )
}
