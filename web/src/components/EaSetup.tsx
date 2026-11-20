import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, Check, CheckCircle2, Copy, Download, Loader2 } from 'lucide-react'
import { useState } from 'react'
import { api } from '../lib/api'
import type { Broker } from '../lib/types'
import { Troubleshoot, useElapsed, usePairStatus } from './EaPair'

/** Step-by-step install of the PipStream EA, with the key (shown once) and live status. */
export function EaSetup({ broker, eaKey }: { broker: Broker; eaKey?: string | null }) {
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
      <ol className="space-y-4">
        <Li n={1} title={`Download the PipStream EA for MetaTrader ${mt}`}>
          <a
            href={`/ea/PipStream.mq${mt}`}
            download
            className="mt-2 inline-flex min-h-11 items-center gap-2 rounded-2xl bg-brand px-4 text-sm font-semibold text-brand-ink"
          >
            <Download className="size-4" /> PipStream.mq{mt}
          </a>
          <p className="mt-2 text-sm text-ink-2">
            In MetaTrader: <b>File → Open Data Folder → MQL{mt} → Experts</b>, put the file there, then right-click <b>Expert Advisors → Refresh</b> in
            the Navigator. If it doesn't appear, open it in MetaEditor and press <b>Compile</b>.
          </p>
        </Li>
        <Li n={2} title="Allow it to reach PipStream">
          <p className="text-sm text-ink-2">
            <b>Tools → Options → Expert Advisors</b>: tick <b>Allow {mt === 4 ? 'automated trading' : 'algorithmic trading'}</b> and{' '}
            <b>Allow WebRequest for listed URL</b>, then add this address:
          </p>
          <CopyRow value={eaUrl} />
        </Li>
        <Li n={3} title="Put it on one chart and paste your key">
          <p className="text-sm text-ink-2">
            Drag <b>PipStream</b> from the Navigator onto any chart (just one). In <b>Inputs</b>, paste your key into the first box (the server address
            is already filled in). Make sure <b>{mt === 4 ? 'AutoTrading' : 'Algo Trading'}</b> is on in the toolbar.
          </p>
          {eaKey ? (
            <>
              <CopyRow value={eaKey} secret />
              <p className="mt-1 text-xs text-ink-3">Shown only now. Lost it? Make a new key from this broker's menu; the old one stops working.</p>
            </>
          ) : (
            <p className="mt-1 text-sm text-ink-3">Use the key you saved when you added this account, or make a new one.</p>
          )}
        </Li>
        <Li n={4} title={`Keep MetaTrader ${mt} open`}>
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
  const [copied, setCopied] = useState(false)
  return (
    <div className="mt-2 flex min-w-0 items-center gap-2 rounded-xl border border-line bg-surface px-3 py-2">
      <code className={`min-w-0 flex-1 truncate font-mono text-sm ${secret ? 'select-all' : ''}`}>{value}</code>
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
