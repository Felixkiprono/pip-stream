import { useEffect, useState } from 'react'

export type Theme = 'system' | 'light' | 'dark'
const KEY = 'ps.theme'

function read(): Theme {
  try {
    const t = localStorage.getItem(KEY)
    return t === 'light' || t === 'dark' ? t : 'system'
  } catch {
    return 'system'
  }
}

function apply(t: Theme) {
  const root = document.documentElement
  if (t === 'system') delete root.dataset.theme
  else root.dataset.theme = t
  const dark = t === 'dark' || (t === 'system' && matchMedia('(prefers-color-scheme: dark)').matches)
  document.querySelectorAll('meta[name="theme-color"]').forEach((m) => m.setAttribute('content', dark ? '#0b1220' : '#f6f7fb'))
}

/** Light / dark / follow the device; remembered on this device. */
export function useTheme(): [Theme, (t: Theme) => void, boolean] {
  const [theme, set] = useState<Theme>(read)
  const [dark, setDark] = useState(() => document.documentElement.dataset.theme === 'dark' || (read() === 'system' && matchMedia('(prefers-color-scheme: dark)').matches))
  useEffect(() => {
    apply(theme)
    try {
      theme === 'system' ? localStorage.removeItem(KEY) : localStorage.setItem(KEY, theme)
    } catch {}
    const mq = matchMedia('(prefers-color-scheme: dark)')
    const upd = () => setDark(theme === 'dark' || (theme === 'system' && mq.matches))
    upd()
    mq.addEventListener('change', upd)
    window.dispatchEvent(new Event('ps-theme'))
    return () => mq.removeEventListener('change', upd)
  }, [theme])
  useEffect(() => {
    const sync = () => set(read())
    window.addEventListener('ps-theme', sync)
    return () => window.removeEventListener('ps-theme', sync)
  }, [])
  return [theme, set, dark]
}
