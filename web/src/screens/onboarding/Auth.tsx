import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { Button, ErrorNote, Field, Step } from '../../components/ui'
import { ApiError, post } from '../../lib/api'
import { nextStep, useSession } from '../../lib/session'
import type { User } from '../../lib/types'

export default function Auth({ mode }: { mode: 'signup' | 'login' }) {
  const nav = useNavigate()
  const { refresh } = useSession()
  const [f, setF] = useState({ name: '', email: '', password: '' })
  const [err, setErr] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const signup = mode === 'signup'

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErr(null)
    try {
      const { user } = await post<{ user: User }>(signup ? 'auth/register' : 'auth/login', signup ? f : { email: f.email, password: f.password })
      await refresh()
      nav(user.role === 'admin' ? '/admin' : nextStep(user), { replace: true })
    } catch (e) {
      setErr(e as ApiError)
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} className="contents">
      <Step
        step={signup ? 1 : undefined}
        back="/"
        title={signup ? 'Create your account' : 'Welcome back'}
        subtitle={signup ? 'It takes a minute. You start with a free trial and practice money.' : 'Sign in to see your trades.'}
        action={
          <div className="space-y-3">
            <Button type="submit" block busy={busy}>
              {signup ? 'Create account' : 'Sign in'}
            </Button>
            <p className="text-center text-sm text-ink-3">
              {signup ? (
                <>
                  Have an account? <Link className="font-semibold text-brand" to="/login">Sign in</Link>
                </>
              ) : (
                <>
                  New here? <Link className="font-semibold text-brand" to="/signup">Create an account</Link>
                </>
              )}
            </p>
          </div>
        }
      >
        {signup && (
          <Field label="Your name" autoComplete="name" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} error={err?.field('name')} required />
        )}
        <Field label="Email" type="email" inputMode="email" autoComplete="email" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} error={err?.field('email')} required />
        <Field
          label="Password"
          type="password"
          autoComplete={signup ? 'new-password' : 'current-password'}
          value={f.password}
          onChange={(e) => setF({ ...f, password: e.target.value })}
          error={err?.field('password')}
          hint={signup ? 'At least 8 characters.' : undefined}
          required
        />
        {err && !Object.keys(err.errors).length && <ErrorNote>{err.message}</ErrorNote>}
      </Step>
    </form>
  )
}
