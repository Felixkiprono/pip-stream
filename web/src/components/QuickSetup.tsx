import { useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { CheckCircle2, Loader2, Terminal, Zap } from 'lucide-react'
import { useEffect, useState } from 'react'
import { api, ApiError, post } from '../lib/api'
import type { Broker } from '../lib/types'
import { CopyRow, isMac } from './EaPair'
import { Button, ErrorNote } from './ui'

type Commands = { broker: Broker; mac: string; windows: string }

/**
 * One-line setup: the customer pastes one command into Terminal / PowerShell. It installs the EA,
 * allows our address, restarts MetaTrader with PipStream on a chart and links itself (no key, no code).
 */
export function QuickSetup({ mt, brokerId, onLinked }: { mt: 4 | 5; brokerId?: string; onLinked: (b: Broker) => void }) {
  const [os, setOs] = useState<'mac' | 'windows'>(isMac ? 'mac' : 'windows')
  const [cmd, setCmd] = useState<Commands | null>(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<ApiError | null>(null)

  const id = cmd?.broker.id
  const status = useQuery({
    queryKey: ['broker', id],
    queryFn: () => api<{ broker: Broker }>(`brokers/${id}`),
    enabled: !!id,
    refetchInterval: 2000,
  })
  const linked = status.data?.broker.status?.state === 'connected'
  useEffect(() => {
    if (linked && status.data) onLinked(status.data.broker)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [linked])

  async function get() {
    setBusy(true)
    setErr(null)
    try {
      setCmd(
        await post<Commands>('brokers/installer', {
          platform: mt === 4 ? 'mt4' : 'mt5',
          broker_id: brokerId ?? cmd?.broker.id,
        }),
      )
    } catch (e) {
      setErr(e as ApiError)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-3 rounded-2xl border-2 border-brand bg-brand-soft/40 p-4">
      <div className="flex items-start gap-3">
        <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-brand text-brand-ink">
          <Zap className="size-5" />
        </span>
        <div>
          <h3 className="font-semibold">Quick setup: one command, about a minute</h3>
          <p className="text-sm text-ink-2">Installs PipStream in MetaTrader {mt}, allows it, reopens MetaTrader with it on a chart, and links it here.</p>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-2 rounded-2xl bg-surface-2 p-1" role="radiogroup" aria-label="Computer">
        {(['mac', 'windows'] as const).map((o) => (
          <button
            key={o}
            type="button"
            role="radio"
            aria-checked={os === o}
            onClick={() => setOs(o)}
            className={clsx('min-h-11 rounded-xl text-sm font-semibold', os === o ? 'bg-surface text-ink shadow-card' : 'text-ink-3')}
          >
            {o === 'mac' ? 'Mac' : 'Windows'}
          </button>
        ))}
      </div>

      {!cmd ? (
        <Button block busy={busy} onClick={get}>
          <Terminal className="size-4" /> Get my setup command
        </Button>
      ) : (
        <>
          <ol className="list-decimal space-y-1 pl-5 text-sm text-ink-2">
            {os === 'mac' ? (
              <li>
                Open <b>Terminal</b>: press <b>Cmd+Space</b>, type <b>Terminal</b>, press Enter.
              </li>
            ) : (
              <li>
                Open <b>PowerShell</b>: press the <b>Windows key</b>, type <b>PowerShell</b>, press Enter.
              </li>
            )}
            <li>Copy this, paste it there and press Enter. Follow what it says.</li>
          </ol>
          <CopyRow value={os === 'mac' ? cmd.mac : cmd.windows} />
          <p className="text-xs text-ink-3">Works once, for the next 24 hours. It only touches MetaTrader's PipStream files, never your login or password.</p>
          <div className="rounded-xl bg-surface p-3 text-sm" aria-live="polite">
            {linked ? (
              <p className="flex items-center gap-2 font-semibold text-up">
                <CheckCircle2 className="size-5" /> Connected
              </p>
            ) : (
              <p className="flex items-center gap-2 text-ink-2">
                <Loader2 className="size-4 animate-spin text-brand" /> Waiting for MetaTrader to link…
              </p>
            )}
          </div>
        </>
      )}
      {err && <ErrorNote>{err.message}</ErrorNote>}
    </div>
  )
}
