import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { Button, ErrorNote, Field, Step } from '../../components/ui'
import { ApiError, post } from '../../lib/api'
import { isAdmin, nextStep, useSession } from '../../lib/session'
import type { User } from '../../lib/types'

/** "Forgot password": emails a reset link. Same answer whether or not the email has an account. */
export function ForgotPassword() {
  const [email, setEmail] = useState('')
  const [sent, setSent] = useState('')
  const [err, setErr] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErr(null)
    try {
      const r = await post<{ message: string }>('auth/forgot-password', { email })
      setSent(r.message)
    } catch (e) {
      setErr(e as ApiError)
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} className="contents">
      <Step
        back="/login"
        title="Reset your password"
        subtitle="Enter the email you signed up with. We'll send you a link to choose a new password."
        action={
          <div className="space-y-3">
            <Button type="submit" block busy={busy} disabled={!!sent}>
              {sent ? 'Link sent' : 'Send reset link'}
            </Button>
            <p className="text-center text-sm text-ink-3">
              Remembered it? <Link className="font-semibold text-brand" to="/login">Sign in</Link>
            </p>
          </div>
        }
      >
        <Field label="Email" type="email" inputMode="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} error={err?.field('email')} required />
        {sent && <p className="anim-rise rounded-2xl bg-up-soft px-4 py-3 text-sm font-medium text-up">{sent} Check your inbox and spam folder.</p>}
        {err && !Object.keys(err.errors).length && <ErrorNote>{err.message}</ErrorNote>}
      </Step>
    </form>
  )
}

/** The page the reset email links to: /reset-password?token=…&email=… */
export function ResetPassword() {
  const [params] = useSearchParams()
  const nav = useNavigate()
  const { refresh } = useSession()
  const token = params.get('token') ?? ''
  const [email, setEmail] = useState(params.get('email') ?? '')
  const [password, setPassword] = useState('')
  const [err, setErr] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErr(null)
    try {
      const { user } = await post<{ user: User | null }>('auth/reset-password', { token, email, password })
      await refresh()
      nav(user ? (isAdmin(user) ? '/admin' : nextStep(user)) : '/login', { replace: true })
    } catch (e) {
      setErr(e as ApiError)
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} className="contents">
      <Step
        back="/login"
        title="Choose a new password"
        subtitle="Pick something you don't use anywhere else. You'll be signed in right after."
        action={
          <Button type="submit" block busy={busy} disabled={!token}>
            Save new password
          </Button>
        }
      >
        {!token && (
          <ErrorNote>
            This link is missing its code. <Link className="underline" to="/forgot-password">Ask for a new link</Link>.
          </ErrorNote>
        )}
        <Field label="Email" type="email" inputMode="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} error={err?.field('email')} required />
        <Field label="New password" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} error={err?.field('password')} hint="At least 8 characters." required />
        {err?.field('email')?.includes('expired') && (
          <p className="text-sm"><Link className="font-semibold text-brand" to="/forgot-password">Send me a new link</Link></p>
        )}
        {err && !Object.keys(err.errors).length && <ErrorNote>{err.message}</ErrorNote>}
      </Step>
    </form>
  )
}
