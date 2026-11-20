import { useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { Check, RefreshCw, Search, Users } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import { Avatar, Badge, Button, ErrorNote, Spinner, Step } from '../../components/ui'
import { api, post, settle } from '../../lib/api'
import { useSession } from '../../lib/session'
import type { TgChannel, TgStatus } from '../../lib/types'

export default function Channels() {
  const nav = useNavigate()
  const qc = useQueryClient()
  const { refresh } = useSession()
  const [params] = useSearchParams()
  const fromApp = params.get('from') === 'app'
  const [q, setQ] = useState('')
  const [picked, setPicked] = useState<Set<number>>(new Set())
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const list = useQuery({
    queryKey: ['tg-channels'],
    queryFn: async () => settle(await api<TgStatus>('telegram/channels')),
    retry: false,
  })
  const channels = list.data?.channels ?? []
  const shown = useMemo(() => {
    const s = q.trim().toLowerCase()
    const l = s ? channels.filter((c) => c.title.toLowerCase().includes(s) || c.username?.toLowerCase().includes(s)) : channels
    // channels other PipStream users already copy first: they are known to parse well
    return [...l].sort((a, b) => Number(!!b.followed) - Number(!!a.followed) || (b.followers ?? 0) - (a.followers ?? 0))
  }, [channels, q])
  const fresh = [...picked].filter((id) => !channels.find((c) => c.tg_chat_id === id)?.followed)

  function toggle(c: TgChannel) {
    if (c.followed) return
    const n = new Set(picked)
    n.has(c.tg_chat_id) ? n.delete(c.tg_chat_id) : n.add(c.tg_chat_id)
    setPicked(n)
  }

  async function reload() {
    qc.setQueryData(['tg-channels'], undefined)
    await qc.fetchQuery({ queryKey: ['tg-channels'], queryFn: async () => settle(await api<TgStatus>('telegram/channels?refresh=1')) })
  }

  async function follow() {
    setBusy(true)
    setErr('')
    try {
      await post('channels/follow', {
        channels: fresh.map((id) => {
          const c = channels.find((x) => x.tg_chat_id === id)!
          return { tg_chat_id: c.tg_chat_id, title: c.title, username: c.username }
        }),
      })
      await refresh()
      qc.invalidateQueries({ queryKey: ['tg-channels'] })
      qc.invalidateQueries({ queryKey: ['channels'] })
      nav(fromApp ? '/app/channels' : '/start/broker')
    } catch (e) {
      setErr((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  const already = channels.filter((c) => c.followed).length
  return (
    <Step
      step={fromApp ? undefined : 3}
      back={fromApp ? '/app/channels' : false}
      title="Which channels should we copy?"
      subtitle="These are the channels on your Telegram. Pick the ones that post trading signals."
      action={
        <Button block busy={busy} disabled={!fresh.length && !already} onClick={() => (fresh.length ? follow() : nav(fromApp ? '/app/channels' : '/start/broker'))}>
          {fresh.length ? `Copy ${fresh.length} channel${fresh.length > 1 ? 's' : ''}` : already ? 'Continue' : 'Pick at least one'}
        </Button>
      }
    >
      <div className="sticky top-0 z-10 -mx-5 bg-bg px-5 pb-2">
        <label className="flex h-12 items-center gap-2 rounded-2xl border border-line bg-surface px-4 focus-within:border-brand">
          <Search className="size-5 text-ink-3" />
          <input className="min-w-0 flex-1 bg-transparent text-[16px] outline-none" placeholder="Search your channels" value={q} onChange={(e) => setQ(e.target.value)} />
          <button aria-label="Refresh list from Telegram" onClick={reload} className="text-ink-3">
            <RefreshCw className={clsx('size-5', list.isFetching && 'animate-spin')} />
          </button>
        </label>
      </div>
      <ErrorNote>{err || (list.error as Error | null)?.message}</ErrorNote>
      {list.isLoading ? (
        <Spinner label="Reading your Telegram channels…" />
      ) : !channels.length ? (
        <p className="rounded-3xl bg-surface-2 p-5 text-center text-ink-2">
          No channels yet. Join your signal channel in Telegram, then tap refresh.
        </p>
      ) : (
        <ul className="-mx-1 space-y-1.5">
          {shown.map((c, i) => {
            const on = c.followed || picked.has(c.tg_chat_id)
            return (
              <li key={c.tg_chat_id} className="anim-rise" style={{ animationDelay: `${Math.min(i, 10) * 30}ms` }}>
                <button
                  onClick={() => toggle(c)}
                  aria-pressed={on}
                  className={clsx(
                    'flex w-full items-center gap-3 rounded-2xl border p-3 text-left transition active:scale-[.99]',
                    on ? 'border-brand bg-brand-soft' : 'border-transparent bg-surface',
                  )}
                >
                  <Avatar title={c.title} seed={c.tg_chat_id} />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate font-semibold">{c.title}</span>
                    <span className="flex items-center gap-2 text-sm text-ink-3">
                      {c.username && <span className="truncate">@{c.username}</span>}
                      {!!c.followers && (
                        <Badge tone="brand">
                          <Users className="size-3" />
                          {c.followers} copying
                        </Badge>
                      )}
                    </span>
                  </span>
                  <span className={clsx('grid size-7 shrink-0 place-items-center rounded-full border-2 transition', on ? 'border-brand bg-brand text-white' : 'border-line')}>
                    {on && <Check className="size-4 anim-pop" strokeWidth={3} />}
                  </span>
                </button>
              </li>
            )
          })}
        </ul>
      )}
    </Step>
  )
}
