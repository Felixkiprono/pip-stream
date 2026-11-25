import { useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { Check } from 'lucide-react'
import { useState } from 'react'
import { useNavigate } from 'react-router'
import { Button, ErrorNote, Field, Spinner, Step } from '../../components/ui'
import { api, put } from '../../lib/api'
import { useSession } from '../../lib/session'
import type { MyChannel, Rule, Style as StyleT } from '../../lib/types'

const LEVEL: Record<string, number> = { careful: 1, balanced: 2, bold: 3, fixed: 0 }

export function StylePicker({ value, onChange, styles }: { value: string; onChange: (s: Rule['style']) => void; styles: StyleT[] }) {
  return (
    <div className="space-y-2.5">
      {styles.map((s) => (
        <button
          key={s.id}
          onClick={() => onChange(s.id)}
          aria-pressed={value === s.id}
          className={clsx('flex w-full items-center gap-4 rounded-3xl border-2 bg-surface p-4 text-left transition active:scale-[.99]', value === s.id ? 'border-brand' : 'border-line')}
        >
          <span className="flex h-10 w-9 shrink-0 items-end gap-1" aria-hidden>
            {[1, 2, 3].map((n) => (
              <span
                key={n}
                className={clsx('w-2.5 rounded-full', LEVEL[s.id] >= n ? (s.id === 'bold' ? 'bg-warn' : 'bg-brand') : 'bg-line')}
                style={{ height: `${n * 33}%` }}
              />
            ))}
          </span>
          <span className="flex-1">
            <span className="block font-semibold">{s.label}</span>
            <span className="block text-[15px] text-ink-2">{s.blurb}</span>
          </span>
          <span className={clsx('grid size-6 shrink-0 place-items-center rounded-full border-2', value === s.id ? 'border-brand bg-brand text-white' : 'border-line')}>
            {value === s.id && <Check className="size-3.5" strokeWidth={3} />}
          </span>
        </button>
      ))}
    </div>
  )
}

export default function Style() {
  const nav = useNavigate()
  const qc = useQueryClient()
  const { refresh } = useSession()
  const styles = useQuery({ queryKey: ['styles'], queryFn: () => api<{ styles: StyleT[] }>('styles') })
  const chans = useQuery({ queryKey: ['channels'], queryFn: () => api<{ channels: MyChannel[] }>('channels') })
  const [style, setStyle] = useState<Rule['style']>('balanced')
  const [lots, setLots] = useState('0.01')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  async function save() {
    setBusy(true)
    setErr('')
    try {
      for (const c of chans.data?.channels ?? []) {
        await put(`channels/${c.id}/rule`, { style, fixed_lots: style === 'fixed' ? Number(lots) : undefined, broker_account_id: c.rule?.broker_account_id })
      }
      await refresh()
      qc.invalidateQueries({ queryKey: ['channels'] })
      nav('/start/plan')
    } catch (e) {
      setErr((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  const n = chans.data?.channels.length ?? 0
  return (
    <Step
      step={6}
      title="How big should each trade be?"
      subtitle={`PipStream sizes every copy from your balance and the signal's stop loss. This applies to your ${n || ''} channel${n === 1 ? '' : 's'}; you can change it per channel later.`}
      action={
        <Button block busy={busy} disabled={!n} onClick={save}>
          Use {styles.data?.styles.find((s) => s.id === style)?.label ?? 'this'} style
        </Button>
      }
    >
      {styles.isLoading ? <Spinner /> : <StylePicker value={style} onChange={setStyle} styles={styles.data?.styles ?? []} />}
      {style === 'fixed' && <Field label="Lot size on every trade" inputMode="decimal" value={lots} onChange={(e) => setLots(e.target.value)} />}
      <ErrorNote>{err}</ErrorNote>
    </Step>
  )
}
