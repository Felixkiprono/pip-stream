import { useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { Gauge, Hourglass, Radio, Target, TrendingUp } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { Section } from '../../components/AppShell'
import { ActivityChart, BarList, Legend, PnlCurve, WinRing } from '../../components/Charts'
import { Card, Spinner } from '../../components/ui'
import { api } from '../../lib/api'
import { money } from '../../lib/format'
import type { Stats } from '../../lib/types'

const RANGES = [7, 14, 30] as const

function Tile({ icon: Icon, label, value, hint, tone }: { icon: typeof Gauge; label: string; value: string; hint?: string; tone?: 'up' | 'down' }) {
  return (
    <Card className="p-3">
      <span className="flex items-center gap-1.5 text-xs text-ink-3"><Icon className="size-3.5" /> {label}</span>
      <span className={clsx('mt-1 block text-xl font-bold tabular', tone === 'up' && 'text-up', tone === 'down' && 'text-down')}>{value}</span>
      {hint && <span className="block truncate text-xs text-ink-3">{hint}</span>}
    </Card>
  )
}

/** Charts and numbers about the customer's own copying, with hints that fit where they are. */
export function Insights({ channels }: { channels: number }) {
  const [days, setDays] = useState<(typeof RANGES)[number]>(14)
  const q = useQuery({ queryKey: ['stats', days], queryFn: () => api<Stats>(`stats?days=${days}`), refetchInterval: 30_000 })
  const range = (
    <div className="flex rounded-xl bg-surface-2 p-0.5 text-xs font-semibold" role="radiogroup" aria-label="Period">
      {RANGES.map((d) => (
        <button key={d} role="radio" aria-checked={days === d} onClick={() => setDays(d)} className={clsx('rounded-lg px-2.5 py-1', days === d ? 'bg-surface text-ink shadow-sm' : 'text-ink-3')}>
          {d}d
        </button>
      ))}
    </div>
  )
  if (!q.data) return <Section title="Your stats" action={range}><Spinner /></Section>
  const s = q.data
  const t = s.totals
  const sigs = s.series.reduce((a, x) => a + x.signals, 0)
  const ignored = s.series.reduce((a, x) => a + x.ignored, 0)
  const opened = s.series.reduce((a, x) => a + x.opened, 0)

  // Where is this customer? Say the next useful thing instead of showing empty charts.
  if (!channels) {
    return (
      <Section title="Your stats">
        <Card className="flex items-center gap-3">
          <Radio className="size-6 shrink-0 text-brand" />
          <span className="flex-1 text-[15px]">Charts appear once you copy a channel or group.</span>
          <Link to="/start/channels?from=app" className="text-sm font-semibold text-brand">Pick one</Link>
        </Card>
      </Section>
    )
  }

  return (
    <Section title="Your stats" action={range}>
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-2.5 lg:grid-cols-4">
          <Tile icon={Radio} label={`Signals · ${days}d`} value={String(sigs)} hint={ignored ? `${ignored} other posts ignored` : 'from your channels'} />
          <Tile icon={TrendingUp} label={`Trades · ${days}d`} value={String(opened)} hint={`${t.open} open now`} />
          <Tile icon={Target} label="Win rate" value={t.win_rate != null ? `${t.win_rate}%` : '—'} hint={t.closed ? `${t.wins}W · ${t.losses}L${t.closed - t.wins - t.losses ? ` · ${t.closed - t.wins - t.losses} even` : ''}` : 'after trades close'} />
          <Tile icon={Gauge} label="Copy speed" value={t.latency_ms != null ? `${t.latency_ms} ms` : '—'} hint="signal → order (median)" />
        </div>

        <div className="grid gap-4 lg:grid-cols-2">
          <Card className="min-w-0 space-y-3">
            <div className="flex items-center justify-between gap-2">
              <span className="font-semibold">Activity</span>
              <Legend items={[['Signals', 'var(--brand)'], ['Ignored posts', 'var(--brand-soft)'], ['Trades', 'var(--up)']]} />
            </div>
            {sigs + ignored + opened ? (
              <ActivityChart series={s.series} />
            ) : (
              <p className="flex items-center gap-2 py-8 text-sm text-ink-3"><Hourglass className="size-4" /> No posts from your channels in the last {days} days yet.</p>
            )}
          </Card>

          <Card className="min-w-0 space-y-3">
            <div className="flex items-center justify-between">
              <span className="font-semibold">Profit / loss</span>
              <span className={clsx('font-bold tabular', t.pnl > 0 ? 'text-up' : t.pnl < 0 ? 'text-down' : 'text-ink-3')}>{t.closed ? money(t.pnl, '') : '—'}</span>
            </div>
            {s.curve.length ? (
              <div className="flex items-center gap-4">
                <WinRing wins={t.wins} losses={t.losses} />
                <div className="min-w-0 flex-1"><PnlCurve points={s.curve} /></div>
              </div>
            ) : (
              <p className="py-8 text-sm text-ink-3">
                {t.open
                  ? `${t.open} trade${t.open === 1 ? ' is' : 's are'} open. Profit and loss show here once they close: when the channel posts a close, or when SL/TP is hit.`
                  : 'Profit and loss show here once your first copied trade closes.'}
              </p>
            )}
            {t.closed > 0 && (
              <div className="grid grid-cols-2 gap-2 text-sm">
                <span className="rounded-xl bg-surface-2 px-3 py-2"><span className="block text-xs text-ink-3">Best trade</span><b className="text-up tabular">{money(t.best ?? 0, '')}</b></span>
                <span className="rounded-xl bg-surface-2 px-3 py-2"><span className="block text-xs text-ink-3">Worst trade</span><b className="text-down tabular">{money(t.worst ?? 0, '')}</b></span>
              </div>
            )}
          </Card>

          <Card className="min-w-0 space-y-3">
            <span className="block font-semibold">By channel · {days}d</span>
            <BarList rows={s.channels.map((c) => ({ label: c.title, value: c.signals, sub: `${c.signals} signals · ${c.trades} trades${c.pnl ? ` · ${money(c.pnl, '')}` : ''}` }))} />
            {s.channels.every((c) => !c.signals) && <p className="text-sm text-ink-3">Waiting for the first signal. Posts are read the second they appear.</p>}
          </Card>

          <Card className="min-w-0 space-y-3">
            <span className="block font-semibold">Most traded · {days}d</span>
            {s.symbols.length ? (
              <BarList rows={s.symbols.map((x) => ({ label: x.symbol, value: x.trades, tone: x.pnl < 0 ? 'down' : 'up', sub: `${x.trades} trade${x.trades === 1 ? '' : 's'}${x.pnl ? ` · ${money(x.pnl, '')}` : ''}` }))} />
            ) : (
              <p className="text-sm text-ink-3">No trades in this period yet.</p>
            )}
          </Card>
        </div>
      </div>
    </Section>
  )
}
