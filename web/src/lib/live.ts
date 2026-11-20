import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef } from 'react'
import { api } from './api'

// Screens that show live data. When the server's fingerprint changes, these refetch.
const LIVE_KEYS = [['home'], ['trades'], ['alerts'], ['channels'], ['stats'], ['me']]

/**
 * Live updates for the customer app: polls /pulse (a cheap fingerprint of the customer's
 * signals, trades and alerts) every 2 s while the tab is visible, and refetches the screens
 * only when something changed. New signals and trades appear within ~2 s, no refresh needed.
 */
export function useLiveUpdates(intervalMs = 2000) {
  const qc = useQueryClient()
  const last = useRef<string | null>(null)
  const pulse = useQuery({
    queryKey: ['pulse'],
    queryFn: () => api<{ v: string }>('pulse'),
    refetchInterval: intervalMs,
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: true,
    retry: false,
    gcTime: 0,
  })
  const v = pulse.data?.v
  useEffect(() => {
    if (!v) return
    if (last.current !== null && last.current !== v) {
      for (const key of LIVE_KEYS) qc.invalidateQueries({ queryKey: key })
    }
    last.current = v
  }, [v, qc])
}
