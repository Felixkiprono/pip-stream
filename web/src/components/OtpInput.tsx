import clsx from 'clsx'
import { useEffect, useRef } from 'react'

/** One box per digit: auto-advance, backspace goes back, paste fills all, and the phone's
 *  keyboard can autofill it (autocomplete=one-time-code). Calls onComplete when full. */
export function OtpInput({
  length = 5,
  value,
  onChange,
  onComplete,
  error,
  disabled,
}: {
  length?: number
  value: string
  onChange: (v: string) => void
  onComplete?: (v: string) => void
  error?: boolean
  disabled?: boolean
}) {
  const refs = useRef<(HTMLInputElement | null)[]>([])
  useEffect(() => {
    refs.current[Math.min(value.length, length - 1)]?.focus()
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  function set(next: string) {
    const clean = next.replace(/\D/g, '').slice(0, length)
    onChange(clean)
    refs.current[Math.min(clean.length, length - 1)]?.focus()
    if (clean.length === length) onComplete?.(clean)
  }

  return (
    <div className={clsx('flex justify-between gap-2', error && 'anim-shake')} role="group" aria-label="Login code">
      {Array.from({ length }, (_, i) => (
        <input
          key={i}
          ref={(el) => {
            refs.current[i] = el
          }}
          aria-label={`Digit ${i + 1}`}
          inputMode="numeric"
          autoComplete={i === 0 ? 'one-time-code' : 'off'}
          disabled={disabled}
          maxLength={i === 0 ? length : 1}
          value={value[i] ?? ''}
          onFocus={(e) => e.target.select()}
          onChange={(e) => {
            const v = e.target.value.replace(/\D/g, '')
            if (v.length > 1) return set(v) // pasted or autofilled
            const arr = Array.from({ length }, (_, k) => value[k] ?? '')
            arr[i] = v
            set(arr.join(''))
          }}
          onKeyDown={(e) => {
            if (e.key === 'Backspace' && !value[i] && i > 0) {
              e.preventDefault()
              set(value.slice(0, i - 1))
            }
          }}
          onPaste={(e) => {
            e.preventDefault()
            set(e.clipboardData.getData('text'))
          }}
          className={clsx(
            'h-16 w-full min-w-0 rounded-2xl border-2 bg-surface text-center text-[28px] font-semibold tabular outline-none transition',
            error ? 'border-down text-down' : value[i] ? 'border-brand' : 'border-line',
            'focus:border-brand focus:ring-4 focus:ring-brand/15',
          )}
        />
      ))}
    </div>
  )
}
