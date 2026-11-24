import { useQuery, useQueryClient } from '@tanstack/react-query'
import { createContext, useContext, type ReactNode } from 'react'
import { api } from './api'
import type { User } from './types'

const Ctx = createContext<{ user: User | null; loading: boolean; refresh: () => Promise<unknown> }>({
  user: null,
  loading: true,
  refresh: async () => {},
})

export function SessionProvider({ children }: { children: ReactNode }) {
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['me'], queryFn: () => api<{ user: User | null }>('auth/me'), staleTime: 30_000 })
  return (
    <Ctx.Provider value={{ user: q.data?.user ?? null, loading: q.isLoading, refresh: () => qc.invalidateQueries({ queryKey: ['me'] }) }}>
      {children}
    </Ctx.Provider>
  )
}

export const useSession = () => useContext(Ctx)

/** Admins and the superadmin both see the admin console. */
export const isAdmin = (u?: User | null) => u?.role === 'admin' || u?.role === 'superadmin'

/** Where a signed-in customer should land: the first unfinished onboarding step, else home. */
export function nextStep(u: User): string {
  const s = u.onboarding.steps
  if (!s.telegram) return '/start/telegram'
  if (!s.channels) return '/start/channels'
  if (!s.style) return '/start/style'
  return '/app'
}
