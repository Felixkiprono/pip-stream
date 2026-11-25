import clsx from 'clsx'
import { ChevronLeft, Loader2 } from 'lucide-react'
import { useId } from 'react'
import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode } from 'react'
import { useNavigate } from 'react-router'
import { hue, initials } from '../lib/format'

/** The PipStream mark: a P whose stem is a candlestick, with a gold coin for the pip. */
export function Mark({ size = 28, className }: { size?: number; className?: string }) {
  const id = useId().replace(/:/g, '')
  return (
    <svg width={size} height={size} viewBox="0 0 64 64" aria-hidden className={clsx('shrink-0', className)}>
      <defs>
        <linearGradient id={`${id}b`} x1="0" y1="64" x2="64" y2="0" gradientUnits="userSpaceOnUse"><stop offset="0" stopColor="#1d4ed8" /><stop offset=".55" stopColor="#2f6bff" /><stop offset="1" stopColor="#14d6a4" /></linearGradient>
        <linearGradient id={`${id}g`} x1="0" y1="41" x2="0" y2="53" gradientUnits="userSpaceOnUse"><stop offset="0" stopColor="#ffd25a" /><stop offset="1" stopColor="#f5a300" /></linearGradient>
      </defs>
      <rect width="64" height="64" rx="16" fill={`url(#${id}b)`} />
      <path d="M23.5 9.5v8M23.5 46v8.5" stroke="#fff" strokeWidth="3.5" strokeLinecap="round" />
      <rect x="18" y="16.5" width="11" height="31" rx="3" fill="#fff" />
      <path d="M27 20.5h8.5a8.75 8.75 0 0 1 0 17.5H27" stroke="#fff" strokeWidth="7.5" fill="none" />
      <circle cx="46.5" cy="47" r="5.5" fill={`url(#${id}g)`} />
    </svg>
  )
}

export function Logo({ size = 28 }: { size?: number }) {
  return (
    <span className="inline-flex items-center gap-2 font-bold tracking-tight">
      <Mark size={size} />
      <span style={{ fontSize: size * 0.62, letterSpacing: '-0.02em' }}>PipStream</span>
    </span>
  )
}

type BtnProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'primary' | 'ghost' | 'soft' | 'danger' | 'tg'
  busy?: boolean
  block?: boolean
}
export function Button({ variant = 'primary', busy, block, className, children, disabled, ...rest }: BtnProps) {
  return (
    <button
      {...rest}
      disabled={disabled || busy}
      className={clsx(
        'relative inline-flex min-h-12 items-center justify-center gap-2 rounded-2xl px-5 text-[15px] font-semibold transition active:scale-[.98] disabled:opacity-50',
        block && 'w-full',
        variant === 'primary' && 'bg-brand text-brand-ink shadow-card',
        variant === 'tg' && 'bg-tg text-white shadow-card',
        variant === 'soft' && 'bg-brand-soft text-brand',
        variant === 'ghost' && 'text-ink-2 hover:bg-surface-2',
        variant === 'danger' && 'bg-down-soft text-down',
        className,
      )}
    >
      {busy && <Loader2 className="size-4 animate-spin" />}
      {children}
    </button>
  )
}

export function Field({ label, hint, error, className, ...rest }: InputHTMLAttributes<HTMLInputElement> & { label: string; hint?: ReactNode; error?: string }) {
  return (
    <label className={clsx('block', className)}>
      <span className="mb-1.5 block text-sm font-medium text-ink-2">{label}</span>
      <input
        {...rest}
        className={clsx(
          'h-13 w-full rounded-2xl border bg-surface px-4 text-[16px] outline-none transition placeholder:text-ink-3 focus:border-brand focus:ring-4 focus:ring-brand/15',
          error ? 'border-down' : 'border-line',
        )}
      />
      {error ? <span className="mt-1.5 block text-sm text-down">{error}</span> : hint && <span className="mt-1.5 block text-sm text-ink-3">{hint}</span>}
    </label>
  )
}

export function Card({ className, children, ...rest }: { className?: string; children: ReactNode } & React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div {...rest} className={clsx('rounded-3xl border border-line bg-surface p-4 shadow-card', className)}>
      {children}
    </div>
  )
}

export function Avatar({ title, seed, size = 44 }: { title: string; seed?: string | number; size?: number }) {
  const h = hue(seed ?? title)
  return (
    <span
      className="inline-flex shrink-0 items-center justify-center rounded-full font-semibold"
      style={{ width: size, height: size, fontSize: size * 0.36, background: `hsl(${h} 70% 92%)`, color: `hsl(${h} 55% 32%)` }}
      aria-hidden
    >
      {initials(title)}
    </span>
  )
}

export function Badge({ tone = 'neutral', children }: { tone?: 'neutral' | 'up' | 'down' | 'warn' | 'brand'; children: ReactNode }) {
  return (
    <span
      className={clsx(
        'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold',
        tone === 'neutral' && 'bg-surface-2 text-ink-2',
        tone === 'up' && 'bg-up-soft text-up',
        tone === 'down' && 'bg-down-soft text-down',
        tone === 'warn' && 'bg-warn-soft text-warn',
        tone === 'brand' && 'bg-brand-soft text-brand',
      )}
    >
      {children}
    </span>
  )
}

export function Toggle({ on, onChange, label, disabled }: { on: boolean; onChange: (v: boolean) => void; label: string; disabled?: boolean }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={on}
      aria-label={label}
      disabled={disabled}
      onClick={() => onChange(!on)}
      className={clsx('relative h-8 w-13 shrink-0 rounded-full transition disabled:opacity-50', on ? 'bg-up' : 'bg-line')}
    >
      <span className={clsx('absolute top-1 size-6 rounded-full bg-white shadow transition-all', on ? 'left-6' : 'left-1')} />
    </button>
  )
}

export function Spinner({ label }: { label?: string }) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 py-16 text-ink-3">
      <Loader2 className="size-6 animate-spin" />
      {label && <span className="text-sm">{label}</span>}
    </div>
  )
}

export function ErrorNote({ children }: { children: ReactNode }) {
  if (!children) return null
  return <p className="anim-rise rounded-2xl bg-down-soft px-4 py-3 text-sm font-medium text-down">{children}</p>
}

/** Full-height onboarding step: back arrow, progress bar, content, sticky action at the bottom. */
export function Step({
  step,
  of = 7,
  title,
  subtitle,
  art,
  children,
  action,
  back = true,
}: {
  step?: number
  of?: number
  title: ReactNode
  subtitle?: ReactNode
  art?: ReactNode
  children?: ReactNode
  action?: ReactNode
  back?: boolean | string
}) {
  const nav = useNavigate()
  return (
    <div className="mx-auto flex min-h-dvh max-w-md flex-col px-5 safe-top">
      <header className="flex h-14 items-center gap-3">
        {back ? (
          <button
            aria-label="Back"
            onClick={() => (typeof back === 'string' ? nav(back) : nav(-1))}
            className="-ml-2 grid size-10 place-items-center rounded-full text-ink-2 hover:bg-surface-2"
          >
            <ChevronLeft className="size-6" />
          </button>
        ) : (
          <span className="size-10" />
        )}
        {step !== undefined && (
          <div className="flex flex-1 gap-1.5" aria-label={`Step ${step} of ${of}`}>
            {Array.from({ length: of }, (_, i) => (
              <span key={i} className={clsx('h-1.5 flex-1 rounded-full transition-colors', i < step ? 'bg-brand' : 'bg-line')} />
            ))}
          </div>
        )}
        <span className="size-10" />
      </header>
      <main className="anim-rise flex flex-1 flex-col pb-4">
        {art && <div className="mt-4 mb-6 flex justify-center">{art}</div>}
        <h1 className="text-[28px] leading-tight font-bold tracking-tight">{title}</h1>
        {subtitle && <p className="mt-2 text-[16px] leading-relaxed text-ink-2">{subtitle}</p>}
        <div className="mt-6 flex flex-1 flex-col gap-4">{children}</div>
      </main>
      {action && <footer className="sticky bottom-0 -mx-5 bg-gradient-to-t from-bg via-bg to-transparent px-5 pt-4 safe-bottom">{action}</footer>}
    </div>
  )
}
