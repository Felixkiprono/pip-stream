import { CheckCircle2, CircleX, Loader2, Activity, FlaskConical, Radio } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { api, ApiError, post } from '../lib/api'
import type { Broker } from '../lib/types'
import { Button, ErrorNote } from './ui'

type Step = { name: string; ok: boolean; detail?: string; ms?: number | null }
type Test = { state: 'queued' | 'running' | 'done'; ok?: boolean; steps: Step[] }
export type Ping = { broker_id: string; label: string; online: boolean; ms: number | null; seen_s: number | null; text: string }

/** "Test connection" and, on demo accounts, "Test order": the engine runs the test, we show each step. */
export function BrokerTest({ broker, auto }: { broker: Broker; auto?: boolean }) {
  const [test, setTest] = useState<Test | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [busy, setBusy] = useState<null | 'conn' | 'order' | 'ping'>(null)
  const [ping, setPing] = useState<Ping | null>(null)
  const ea = broker.connector === 'ea_bridge'
  const timer = useRef<number | null>(null)
  useEffect(() => () => void (timer.current && window.clearTimeout(timer.current)), [])
  // straight after linking, check the connection without making the customer press anything
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => void (auto && run(false)), [])
  const demo = broker.connector === 'paper' || broker.status?.account?.demo === true

  // "Ping MetaTrader": does the terminal answer right now (a few seconds at most)
  async function doPing() {
    setErr(null)
    setBusy('ping')
    try {
      setPing((await post<{ ping: Ping }>(`brokers/${broker.id}/ping`, {})).ping)
    } catch (e) {
      setErr((e as ApiError).message)
    } finally {
      setBusy(null)
    }
  }

  async function run(order: boolean) {
    setErr(null)
    setBusy(order ? 'order' : 'conn')
    setTest({ state: 'queued', steps: [] })
    try {
      const { test_id } = await post<{ test_id: string }>(`brokers/${broker.id}/test`, { order })
      const started = Date.now()
      const poll = async () => {
        const r = await api<{ test: Test }>(`brokers/${broker.id}/test/${test_id}`)
        setTest(r.test)
        if (r.test.state === 'done') return setBusy(null)
        if (Date.now() - started > 30000) {
          setErr('No answer from the trading engine. Is it running?')
          return setBusy(null)
        }
        timer.current = window.setTimeout(poll, 500)
      }
      await poll()
    } catch (e) {
      setErr((e as ApiError).message)
      setBusy(null)
    }
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-2">
        <Button variant="soft" busy={busy === 'conn'} disabled={!!busy} onClick={() => run(false)}>
          <Activity className="size-4" /> Test connection
        </Button>
        {ea && (
          <Button variant="soft" busy={busy === 'ping'} disabled={!!busy} onClick={doPing}>
            <Radio className="size-4" /> Ping MetaTrader
          </Button>
        )}
        {demo && (
          <Button variant="soft" busy={busy === 'order'} disabled={!!busy} onClick={() => run(true)}>
            <FlaskConical className="size-4" /> Test order
          </Button>
        )}
      </div>
      {ping && <PingResult ping={ping} />}
      {demo && !test && <p className="text-xs text-ink-3">Test order opens the smallest trade on this demo account and closes it straight away.</p>}
      {test && (
        <ul className="space-y-1.5 rounded-2xl border border-line bg-surface-2 p-3 text-sm" aria-live="polite">
          {test.steps.map((s, i) => (
            <li key={i} className="flex items-start gap-2">
              {s.ok ? <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-up" /> : <CircleX className="mt-0.5 size-4 shrink-0 text-down" />}
              <span className="min-w-0 flex-1">
                <span className="font-semibold">{s.name}</span>
                {s.ms != null && <span className="text-ink-3 tabular"> · {s.ms} ms</span>}
                {s.detail && <span className="block text-ink-2">{s.detail}</span>}
              </span>
            </li>
          ))}
          {test.state !== 'done' && (
            <li className="flex items-center gap-2 text-ink-2">
              <Loader2 className="size-4 animate-spin text-brand" /> {test.steps.length ? 'Checking…' : 'Asking your terminal…'}
            </li>
          )}
          {test.state === 'done' && (
            <li className={`pt-1 font-semibold ${test.ok ? 'text-up' : 'text-down'}`}>{test.ok ? 'All good: signals will be copied here.' : 'Not ready yet: fix the red step and test again.'}</li>
          )}
        </ul>
      )}
      {err && <ErrorNote>{err}</ErrorNote>}
    </div>
  )
}

/** One ping answer, in plain words. */
export function PingResult({ ping }: { ping: Ping }) {
  return (
    <p aria-live="polite" className={`flex items-start gap-2 rounded-2xl border border-line bg-surface-2 p-3 text-sm ${ping.online ? 'text-up' : 'text-down'}`}>
      {ping.online ? <CheckCircle2 className="mt-0.5 size-4 shrink-0" /> : <CircleX className="mt-0.5 size-4 shrink-0" />}
      <span className="min-w-0 flex-1 text-ink-2">{ping.text}</span>
    </p>
  )
}
