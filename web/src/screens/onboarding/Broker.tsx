import { useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { Check, FlaskConical, Lock } from 'lucide-react'
import { useState } from 'react'
import { useNavigate } from 'react-router'
import { Badge, Button, ErrorNote, Field, Step } from '../../components/ui'
import { ApiError, post } from '../../lib/api'

type Kind = 'paper' | 'mt5_bridge' | 'deriv_options'

const OPTIONS: { id: Kind; title: string; blurb: string; tag?: string }[] = [
  { id: 'paper', title: 'Practice money', blurb: 'A $10,000 practice account. Every signal is copied, nothing is real.', tag: 'Recommended to start' },
  { id: 'mt5_bridge', title: 'MetaTrader 5', blurb: 'Exness, IC Markets, XM, HFM and any broker on MT5.' },
  { id: 'deriv_options', title: 'Deriv', blurb: 'Rise/Fall and other options with your Deriv API token.' },
]

export function BrokerForm({ kind, onSaved }: { kind: Exclude<Kind, 'paper'>; onSaved: () => void }) {
  const [f, setF] = useState({ login: '', password: '', server: '', token: '' })
  const [err, setErr] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  async function save(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErr(null)
    try {
      await post('brokers', kind === 'mt5_bridge' ? { connector: kind, login: f.login, password: f.password, server: f.server } : { connector: kind, token: f.token })
      setF({ login: '', password: '', server: '', token: '' })
      onSaved()
    } catch (e) {
      setErr(e as ApiError)
    } finally {
      setBusy(false)
    }
  }
  return (
    <form onSubmit={save} className="anim-rise space-y-3">
      {kind === 'mt5_bridge' ? (
        <>
          <Field label="MT5 login number" inputMode="numeric" autoComplete="off" value={f.login} onChange={(e) => setF({ ...f, login: e.target.value })} error={err?.field('login')} />
          <Field label="Trading password" type="password" autoComplete="off" value={f.password} onChange={(e) => setF({ ...f, password: e.target.value })} error={err?.field('password')} hint="Not the investor (read-only) password." />
          <Field label="Server" placeholder="e.g. Exness-MT5Real8" autoComplete="off" value={f.server} onChange={(e) => setF({ ...f, server: e.target.value })} error={err?.field('server')} hint="Shown in MT5 under your account name." />
        </>
      ) : (
        <Field
          label="Deriv API token"
          autoComplete="off"
          value={f.token}
          onChange={(e) => setF({ ...f, token: e.target.value })}
          error={err?.field('token')}
          hint={
            <>
              Make one at app.deriv.com → Settings → API token, with <b>Trade</b> access.
            </>
          }
        />
      )}
      {err && !Object.keys(err.errors).length && <ErrorNote>{err.message}</ErrorNote>}
      <p className="flex gap-2 text-sm text-ink-3">
        <Lock className="size-4 shrink-0" /> Locked the moment you save it. Only the trading engine can open it, not the website or PipStream staff.
      </p>
      <Button type="submit" block busy={busy}>
        Connect {kind === 'mt5_bridge' ? 'MetaTrader 5' : 'Deriv'}
      </Button>
    </form>
  )
}

export default function Broker() {
  const nav = useNavigate()
  const qc = useQueryClient()
  const [pick, setPick] = useState<Kind>('paper')
  const [saved, setSaved] = useState(false)
  return (
    <Step
      step={4}
      title="Where should trades go?"
      subtitle="Start on practice money and switch to your real broker whenever you're ready."
      action={
        pick === 'paper' || saved ? (
          <Button block onClick={() => nav('/start/style')}>
            Continue
          </Button>
        ) : undefined
      }
    >
      <div className="space-y-2.5">
        {OPTIONS.map((o) => (
          <div key={o.id} className={clsx('rounded-3xl border-2 bg-surface p-4 transition', pick === o.id ? 'border-brand' : 'border-line')}>
            <button className="flex w-full items-start gap-3 text-left" onClick={() => setPick(o.id)} aria-pressed={pick === o.id}>
              <span className={clsx('mt-0.5 grid size-6 shrink-0 place-items-center rounded-full border-2', pick === o.id ? 'border-brand bg-brand text-white' : 'border-line')}>
                {pick === o.id && <Check className="size-3.5" strokeWidth={3} />}
              </span>
              <span className="flex-1">
                <span className="flex flex-wrap items-center gap-2 font-semibold">
                  {o.id === 'paper' && <FlaskConical className="size-4 text-brand" />}
                  {o.title}
                  {o.tag && <Badge tone="up">{o.tag}</Badge>}
                </span>
                <span className="mt-0.5 block text-[15px] text-ink-2">{o.blurb}</span>
              </span>
            </button>
            {pick === o.id && o.id !== 'paper' && (
              <div className="mt-4 border-t border-line pt-4">
                {saved ? (
                  <p className="flex items-center gap-2 font-semibold text-up">
                    <Check className="size-5" /> Connected. Copies stay watch-only until you turn on live trading.
                  </p>
                ) : (
                  <BrokerForm
                    kind={o.id}
                    onSaved={() => {
                      setSaved(true)
                      qc.invalidateQueries({ queryKey: ['brokers'] })
                    }}
                  />
                )}
              </div>
            )}
          </div>
        ))}
      </div>
    </Step>
  )
}
