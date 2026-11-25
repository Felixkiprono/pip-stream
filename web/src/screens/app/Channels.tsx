import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronRight, Plus, Zap } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { Page } from '../../components/AppShell'
import { ChannelCheck } from '../../components/ChannelCheck'
import { Sheet } from '../../components/Install'
import { Avatar, Badge, Button, Card, ErrorNote, Spinner, Toggle } from '../../components/ui'
import { api, del } from '../../lib/api'
import { saveRule } from '../../lib/rules'
import { ago } from '../../lib/format'
import type { Broker, MyChannel, Rule, Style } from '../../lib/types'
import { StylePicker } from '../onboarding/Style'

const MODE: Record<Rule['mode'], [string, 'brand' | 'warn' | 'up']> = {
  paper: ['Practice', 'brand'],
  shadow: ['Watch only', 'warn'],
  live: ['Live', 'up'],
}

function RuleSheet({ c, onClose, initialTab = 'settings' }: { c: MyChannel; onClose: () => void; initialTab?: 'settings' | 'test' }) {
  const [tab, setTab] = useState(initialTab)
  const qc = useQueryClient()
  const styles = useQuery({ queryKey: ['styles'], queryFn: () => api<{ styles: Style[] }>('styles') })
  const brokers = useQuery({ queryKey: ['brokers'], queryFn: () => api<{ brokers: Broker[] }>('brokers') })
  const [style, setStyle] = useState<Rule['style']>(c.rule?.style ?? 'balanced')
  const [broker, setBroker] = useState(c.rule?.broker_account_id ?? '')
  const [live, setLive] = useState(c.rule?.mode === 'live')
  const [enabled, setEnabled] = useState(c.rule?.enabled ?? true)
  const [askFirst, setAskFirst] = useState(!!c.rule?.limits?.confirm_first)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const selected = brokers.data?.brokers.find((b) => b.id === broker)
  const isPaper = selected?.connector === 'paper'

  async function save() {
    setBusy(true)
    setErr('')
    try {
      const saved = await saveRule(c.id, { style: style === 'custom' ? 'balanced' : style, broker_account_id: broker || undefined, live: live && !isPaper, enabled, confirm_first: askFirst })
      qc.invalidateQueries({ queryKey: ['channels'] })
      qc.invalidateQueries({ queryKey: ['brokers'] })
      if (saved) onClose()
    } catch (e) {
      setErr((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Sheet open onClose={onClose} title={c.title}>
      <div className="mb-3 grid grid-cols-2 gap-1 rounded-2xl bg-surface-2 p-1" role="tablist">
        {(['settings', 'test'] as const).map((t) => (
          <button key={t} role="tab" aria-selected={tab === t} onClick={() => setTab(t)} className={`rounded-xl py-2 text-sm font-semibold ${tab === t ? 'bg-surface shadow-card' : 'text-ink-3'}`}>
            {t === 'settings' ? 'Copy settings' : 'Test this channel'}
          </button>
        ))}
      </div>
      {tab === 'test' ? (
        <div className="max-h-[70dvh] overflow-y-auto pb-2">
          <ChannelCheck c={c} />
        </div>
      ) : (
      <>
      <div className="max-h-[70dvh] space-y-4 overflow-y-auto pb-2">
        <div className="flex items-center justify-between rounded-2xl bg-surface-2 p-3">
          <span className="font-semibold">Copy this channel</span>
          <Toggle on={enabled} onChange={setEnabled} label="Copy this channel" />
        </div>
        <div>
          <p className="mb-2 text-sm font-semibold text-ink-3">Send trades to</p>
          <div className="flex flex-wrap gap-2">
            {brokers.data?.brokers.map((b) => (
              <button key={b.id} onClick={() => setBroker(b.id)} className={`rounded-full border-2 px-4 py-2 text-sm font-semibold ${broker === b.id ? 'border-brand bg-brand-soft text-brand' : 'border-line'}`}>
                {b.label}
                {b.money?.kind === 'real' && <span className="ml-1.5 text-xs text-warn">Real money</span>}
                {b.money?.kind === 'demo' && <span className="ml-1.5 text-xs text-ink-3">Demo</span>}
              </button>
            ))}
            <Link to="/app/brokers" className="rounded-full border-2 border-dashed border-line px-4 py-2 text-sm font-semibold text-ink-3">
              + Broker
            </Link>
          </div>
        </div>
        {!isPaper && broker && (
          <div className="flex items-center gap-3 rounded-2xl bg-warn-soft p-3 text-warn">
            <Zap className="size-5 shrink-0" />
            <span className="flex-1 text-sm font-semibold">
              {selected?.money?.kind === 'demo' ? 'Copy into this demo account (demo money only)' : 'Live trading: real orders with real money'}
            </span>
            <Toggle on={live} onChange={setLive} label="Live trading" />
          </div>
        )}
        <div className="flex items-center justify-between gap-3 rounded-2xl bg-surface-2 p-3">
          <span>
            <span className="block font-semibold">Ask me before each trade</span>
            <span className="block text-sm text-ink-3">The PipStream bot asks "Copy it?" on Telegram. No answer in 5 minutes: skipped.</span>
          </span>
          <Toggle on={askFirst} onChange={setAskFirst} label="Ask me before each trade" />
        </div>
        <div>
          <p className="mb-2 text-sm font-semibold text-ink-3">Trade size</p>
          <StylePicker value={style} onChange={setStyle} styles={styles.data?.styles ?? []} />
        </div>
        <ErrorNote>{err}</ErrorNote>
      </div>
      <div className="mt-3 grid grid-cols-[auto_1fr] gap-2">
        <Button
          variant="danger"
          onClick={async () => {
            await del(`channels/${c.id}`)
            qc.invalidateQueries({ queryKey: ['channels'] })
            onClose()
          }}
        >
          Stop
        </Button>
        <Button busy={busy} onClick={save}>
          Save
        </Button>
      </div>
      </>
      )}
    </Sheet>
  )
}

export default function Channels() {
  const q = useQuery({ queryKey: ['channels'], queryFn: () => api<{ channels: MyChannel[] }>('channels') })
  const [open, setOpen] = useState<MyChannel | null>(null)
  return (
    <Page
      title="Channels"
      right={
        <Link to="/start/channels?from=app" className="flex h-10 items-center gap-1 rounded-full bg-brand-soft px-4 text-sm font-semibold text-brand">
          <Plus className="size-4" /> Add
        </Link>
      }
    >
      {q.isLoading ? (
        <Spinner />
      ) : q.data?.channels.length ? (
        <Card className="py-1">
          <ul className="divide-y divide-line">
            {q.data.channels.map((c) => (
              <li key={c.id}>
                <button onClick={() => setOpen(c)} className="flex w-full items-center gap-3 py-3 text-left">
                  <Avatar title={c.title} />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate font-semibold">{c.title}</span>
                    <span className="flex flex-wrap items-center gap-1.5 text-sm text-ink-3">
                      {c.rule && !c.rule.enabled ? <Badge>Off</Badge> : c.rule && <Badge tone={MODE[c.rule.mode][1]}>{MODE[c.rule.mode][0]}</Badge>}
                      {c.rule && <span className="capitalize">{c.rule.style}</span>}
                      <span>· {c.signals_7d} signals this week</span>
                      {c.last_signal_at && <span>· last {ago(c.last_signal_at)}</span>}
                    </span>
                  </span>
                  <ChevronRight className="size-5 text-ink-3" />
                </button>
              </li>
            ))}
          </ul>
        </Card>
      ) : null}
      {q.data?.channels.length ? (
        <p className="px-1 text-sm text-ink-3">Want to check a channel copies properly? Tap it, then <b>Test this channel</b>.</p>
      ) : q.isLoading ? null : (
        <p className="rounded-3xl bg-surface-2 p-6 text-center text-ink-2">You're not copying any channel yet.</p>
      )}
      {open && <RuleSheet c={open} onClose={() => setOpen(null)} />}
    </Page>
  )
}
