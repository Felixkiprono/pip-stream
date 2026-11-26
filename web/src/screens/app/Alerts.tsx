import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { ChevronLeft, ChevronRight, CircleAlert, CircleCheck, CircleSlash, Copy, Info, Plug, PlugZap } from 'lucide-react'
import { Link, useSearchParams } from 'react-router'
import { useEffect } from 'react'
import { Page } from '../../components/AppShell'
import { Button, Card, Spinner } from '../../components/ui'
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
  signal_duplicate: [Copy, 'text-ink-2 bg-surface-2'],
  ea_offline: [Plug, 'text-down bg-down-soft'],
  ea_online: [PlugZap, 'text-up bg-up-soft'],
}

export default function Alerts() {
  const qc = useQueryClient()
  // the page lives in the address (?page=2), so coming back from an alert's details lands on the same page
  const [params, setParams] = useSearchParams()
  const page = Math.max(1, Number(params.get('page')) || 1)
  const q = useQuery({
    queryKey: ['alerts', page],
    queryFn: () => api<{ alerts: Alert[]; page: number; pages: number; total: number; unread: number }>(`alerts?page=${page}`),
    placeholderData: keepPreviousData,
  })
  useEffect(() => {
    if (q.data?.unread) post('alerts/read').then(() => qc.invalidateQueries({ queryKey: ['home'] }))
  }, [q.data, qc])
  const go = (p: number) => {
    setParams(p > 1 ? { page: String(p) } : {})
    window.scrollTo({ top: 0 })
  }
  const pages = q.data?.pages ?? 1
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
          {pages > 1 && (
            <nav aria-label="Alert pages" className="flex items-center justify-between gap-2 border-t border-line py-3">
              <Button variant="soft" disabled={page <= 1 || q.isFetching} onClick={() => go(page - 1)}>
                <ChevronLeft className="size-4" /> Newer
              </Button>
              <span className="text-sm text-ink-3 tabular">Page {q.data?.page ?? page} of {pages}</span>
              <Button variant="soft" disabled={page >= pages || q.isFetching} onClick={() => go(page + 1)}>
                Older <ChevronRight className="size-4" />
              </Button>
            </nav>
          )}
        </Card>
      ) : (
        <p className="rounded-3xl bg-surface-2 p-6 text-center text-ink-2">Every copied trade, close and skipped signal shows up here.</p>
      )}
    </Page>
  )
}
