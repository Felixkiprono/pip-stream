import { useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { Check, Copy, Download } from 'lucide-react'
import { useState } from 'react'
import type { ApiError } from '../lib/api'
import { post } from '../lib/api'
import type { Broker } from '../lib/types'
import { Button, ErrorNote } from './ui'

/** Ready-built EA if we have one, else the source (which MetaTrader compiles on first open). */
function useEaFile(mt: 4 | 5) {
  return useQuery({
    queryKey: ['ea-file', mt],
    staleTime: Infinity,
    queryFn: async () => {
      const built = `/ea/PipStream.ex${mt}`
      try {
        const r = await fetch(built, { method: 'HEAD' })
        if (r.ok && !(r.headers.get('content-type') ?? '').includes('text/html')) return built
      } catch {
        /* fall back to the source */
      }
      return `/ea/PipStream.mq${mt}`
    },
  })
}

/** Connect MetaTrader in 4 steps: download, allow the address, drop on a chart, type the code. */
export function EaPair({ onPaired }: { onPaired: (b: Broker) => void }) {
  const [mt, setMt] = useState<4 | 5>(5)
  const file = useEaFile(mt).data ?? `/ea/PipStream.mq${mt}`
  const origin = window.location.origin
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<ApiError | null>(null)

  async function pair(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErr(null)
    try {
      const r = await post<{ broker: Broker }>('brokers/pair', { code: code.replace(/\D/g, '') })
      onPaired(r.broker)
    } catch (e) {
      setErr(e as ApiError)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 gap-2 rounded-2xl bg-surface-2 p-1" role="radiogroup" aria-label="Platform">
        {([5, 4] as const).map((p) => (
          <button
            key={p}
            type="button"
            role="radio"
            aria-checked={mt === p}
            onClick={() => setMt(p)}
            className={clsx('min-h-11 rounded-xl text-sm font-semibold', mt === p ? 'bg-surface text-ink shadow-card' : 'text-ink-3')}
          >
            MetaTrader {p}
          </button>
        ))}
      </div>

      <ol className="space-y-5">
        <Li n={1} title="Download PipStream and open it">
          <a href={file} download className="inline-flex min-h-11 items-center gap-2 rounded-2xl bg-brand px-4 text-sm font-semibold text-brand-ink">
            <Download className="size-4" /> {file.split('/').pop()}
          </a>
          <p className="mt-2 text-sm text-ink-2">
            In MetaTrader {mt}: <b>File → Open Data Folder → MQL{mt} → Experts</b>, drop the file in, then right-click <b>Expert Advisors → Refresh</b>.
          </p>
        </Li>
        <Li n={2} title="Allow PipStream once">
          <p className="text-sm text-ink-2">
            <b>Tools → Options → Expert Advisors</b>: tick <b>Allow {mt === 4 ? 'automated' : 'algorithmic'} trading</b> and <b>Allow WebRequest for listed URL</b>,
            and add:
          </p>
          <CopyRow value={origin} />
        </Li>
        <Li n={3} title="Drag PipStream onto any chart">
          <p className="text-sm text-ink-2">
            Press OK, and turn on <b>{mt === 4 ? 'AutoTrading' : 'Algo Trading'}</b> in the toolbar. A 6-digit code appears at the top left of the chart.
            {origin !== 'https://pip.stream' && (
              <>
                {' '}
                (Testing locally? In <b>Inputs</b>, set <b>PipStreamUrl</b> to <b>{origin}</b>.)
              </>
            )}
          </p>
        </Li>
        <Li n={4} title="Type the code here">
          <form onSubmit={pair} className="flex gap-2">
            <input
              inputMode="numeric"
              autoComplete="one-time-code"
              placeholder="123 456"
              maxLength={7}
              value={code}
              onChange={(e) => setCode(e.target.value.replace(/[^\d ]/g, ''))}
              aria-label="Code from your chart"
              className="min-h-12 w-full min-w-0 rounded-2xl border border-line bg-surface px-4 text-center font-mono text-xl tracking-[0.3em] tabular"
            />
            <Button type="submit" busy={busy} disabled={code.replace(/\D/g, '').length !== 6}>
              Link
            </Button>
          </form>
          {err && <ErrorNote>{err.field('code') ?? err.message}</ErrorNote>}
        </Li>
      </ol>
    </div>
  )
}

function Li({ n, title, children }: { n: number; title: string; children: React.ReactNode }) {
  return (
    <li className="flex gap-3">
      <span className="grid size-7 shrink-0 place-items-center rounded-full bg-brand-soft text-sm font-bold text-brand">{n}</span>
      <div className="min-w-0 flex-1 space-y-2">
        <h3 className="font-semibold">{title}</h3>
        {children}
      </div>
    </li>
  )
}

export function CopyRow({ value }: { value: string }) {
  const [copied, setCopied] = useState(false)
  return (
    <div className="flex min-w-0 items-center gap-2 rounded-xl border border-line bg-surface px-3 py-2">
      <code className="min-w-0 flex-1 truncate font-mono text-sm select-all">{value}</code>
      <button
        type="button"
        className="inline-flex shrink-0 items-center gap-1 text-sm font-semibold text-brand"
        onClick={async () => {
          try {
            await navigator.clipboard.writeText(value)
            setCopied(true)
            setTimeout(() => setCopied(false), 1500)
          } catch {
            /* clipboard blocked: the text is selectable */
          }
        }}
      >
        {copied ? <Check className="size-4" /> : <Copy className="size-4" />} {copied ? 'Copied' : 'Copy'}
      </button>
    </div>
  )
}
