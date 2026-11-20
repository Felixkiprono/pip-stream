import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, Check, CheckCircle2, Copy, Download, Loader2 } from 'lucide-react'
import { useState } from 'react'
import { api } from '../lib/api'
import type { Broker } from '../lib/types'
import { QuickSetup } from './QuickSetup'
import { copyText } from './EaPair'
import { CodeForm, InstallHint, Troubleshoot, useElapsed, usePairStatus } from './EaPair'

/** Step-by-step install of the PipStream EA, with the key (shown once) and live status. */
export function EaSetup({ broker, eaKey, onLinked }: { broker: Broker; eaKey?: string | null; onLinked: (b: Broker) => void }) {
  const [showKey, setShowKey] = useState(!!eaKey)
  const mt = broker.platform === 'mt4' ? 4 : 5
  const eaUrl = usePairStatus(false).data?.ea_url ?? window.location.origin
  const waited = useElapsed()
  const q = useQuery({
    queryKey: ['broker', broker.id],
    queryFn: () => api<{ broker: Broker }>(`brokers/${broker.id}`),
    refetchInterval: (query) => (query.state.data?.broker.status?.state === 'connected' ? 10000 : 2000),
    initialData: { broker },
  })
  const st = q.data.broker.status

  return (
    <div className="space-y-4">
      {st?.state !== 'connected' && <QuickSetup mt={mt} brokerId={broker.id} onLinked={onLinked} />}
      <p className="text-sm font-semibold text-ink-3">Or by hand:</p>
      <ol className="space-y-4">
        <Li n={1} title={`Download the PipStream EA for MetaTrader ${mt}`}>
          <a
            href={`/ea/PipStream.mq${mt}`}
            download
            className="mt-2 inline-flex min-h-11 items-center gap-2 rounded-2xl bg-brand px-4 text-sm font-semibold text-brand-ink"
          >
            <Download className="size-4" /> PipStream.mq{mt}
          </a>
          <div className="mt-2 space-y-2">
            <InstallHint mt={mt} source />
          </div>
        </Li>
        <Li n={2} title="Allow it to reach PipStream">
          <p className="text-sm text-ink-2">
            <b>Tools → Options → Expert Advisors</b>: tick <b>Allow {mt === 4 ? 'automated trading' : 'algorithmic trading'}</b> and{' '}
            <b>Allow WebRequest for listed URL</b>, then add this address:
          </p>
          <CopyRow value={eaUrl} />
        </Li>
        <Li n={3} title="Drag PipStream onto one chart">
          <p className="text-sm text-ink-2">
            Leave the settings as they are (the server address is already filled in), press OK, and turn on <b>{mt === 4 ? 'AutoTrading' : 'Algo Trading'}</b>{' '}
            in the toolbar. A 6-digit code appears in big gold text on the chart.
          </p>
        </Li>
        <Li n={4} title="Type the code here">
          <CodeForm brokerId={broker.id} onLinked={onLinked} />
          {showKey ? (
            <div className="mt-3 space-y-1">
              <p className="text-sm text-ink-2">Or paste this key into the first box of the EA's Inputs instead of using a code:</p>
              {eaKey ? (
                <>
                  <CopyRow value={eaKey} secret />
                  <p className="text-xs text-ink-3">Shown only now. Lost it? Make a new key below; the old one stops working.</p>
                </>
              ) : (
                <p className="text-sm text-ink-3">Use the key you saved when you added this account, or make a new one below.</p>
              )}
            </div>
          ) : (
            <button type="button" className="mt-3 text-sm font-semibold text-ink-3 underline" onClick={() => setShowKey(true)}>
              Prefer to paste a key into the EA?
            </button>
          )}
        </Li>
        <Li n={5} title={`Keep MetaTrader ${mt} open`}>
          <p className="text-sm text-ink-2">
            Trades are placed by your terminal, so it must stay running: on your computer, a VPS, or MetaQuotes' built-in hosting (right-click your account in
            the Navigator → <b>Register a Virtual Server</b>).
          </p>
        </Li>
      </ol>

      <div className="rounded-2xl border border-line bg-surface-2 p-3">
        {st?.state === 'connected' ? (
          <p className="flex items-start gap-2 font-semibold text-up">
            <CheckCircle2 className="mt-0.5 size-5 shrink-0" />
            <span>
              Connected
              {st.account && (
                <span className="block text-sm font-normal text-ink-2">
                  {st.account.company ? `${st.account.company} · ` : ''}
                  {st.account.login} · balance {st.account.balance?.toLocaleString()} {st.account.currency}
                </span>
              )}
            </span>
          </p>
        ) : st?.state === 'error' ? (
          <p className="flex items-start gap-2 text-sm font-semibold text-down">
            <AlertTriangle className="mt-0.5 size-4 shrink-0" /> {st.detail}
          </p>
        ) : (
          <p className="flex items-center gap-2 text-sm text-ink-2">
            <Loader2 className="size-4 animate-spin text-brand" /> Waiting for your terminal to check in at {eaUrl}…
          </p>
        )}
        {st?.state !== 'connected' && waited > 20 && <Troubleshoot mt={mt} eaUrl={eaUrl} />}
      </div>
    </div>
  )
}

function Li({ n, title, children }: { n: number; title: string; children: React.ReactNode }) {
  return (
    <li className="flex gap-3">
      <span className="grid size-7 shrink-0 place-items-center rounded-full bg-brand-soft text-sm font-bold text-brand">{n}</span>
      <div className="min-w-0 flex-1">
        <h3 className="font-semibold">{title}</h3>
        <div className="mt-1">{children}</div>
      </div>
    </li>
  )
}

function CopyRow({ value, secret }: { value: string; secret?: boolean }) {
  const [copied, setCopied] = useState<null | 'ok' | 'fail'>(null)
  return (
    <div className="mt-2 flex min-w-0 items-center gap-2 rounded-xl border border-line bg-surface px-3 py-2">
      <code className={`min-w-0 flex-1 truncate font-mono text-sm ${secret ? 'select-all' : ''}`}>{value}</code>
      <button
        type="button"
        className="inline-flex shrink-0 items-center gap-1 text-sm font-semibold text-brand"
        onClick={async () => {
          setCopied((await copyText(value)) ? 'ok' : 'fail')
          setTimeout(() => setCopied(null), 2500)
        }}
      >
        {copied === 'ok' ? <Check className="size-4" /> : <Copy className="size-4" />}{' '}
        {copied === 'ok' ? 'Copied' : copied === 'fail' ? 'Select it and press Cmd/Ctrl+C' : 'Copy'}
      </button>
    </div>
  )
}
