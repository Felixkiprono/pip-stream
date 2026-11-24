import { useQuery, useQueryClient } from '@tanstack/react-query'
import { KeyRound, Mail, Send } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Badge, Button, Card, ErrorNote, Field, Spinner } from '../../components/ui'
import { ApiError, api, post, put } from '../../lib/api'
import { useSession } from '../../lib/session'
import { H } from './Admin'

type MailInfo = { source: 'admin' | 'env' | 'none'; key_hint: string | null; from_address: string; from_name: string; test_to: string | null; mailer: string }

/** Superadmin: Resend API key and sender for every email PipStream sends. The key is write-only. */
export default function AdminEmail() {
  const { user } = useSession()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['admin', 'mail'], queryFn: () => api<MailInfo>('admin/mail') })
  const [f, setF] = useState({ resend_key: '', from_address: '', from_name: '', test_to: '' })
  const [err, setErr] = useState<ApiError | null>(null)
  const [saved, setSaved] = useState(false)
  const [busy, setBusy] = useState(false)
  const [test, setTest] = useState<{ ok: boolean; text: string } | null>(null)
  const [testBusy, setTestBusy] = useState(false)

  useEffect(() => {
    if (q.data) setF((x) => ({ ...x, from_address: q.data.from_address ?? '', from_name: q.data.from_name ?? '', test_to: q.data.test_to ?? '' }))
  }, [q.data])

  async function save(body: Record<string, unknown>) {
    setBusy(true)
    setErr(null)
    setSaved(false)
    try {
      const r = await put<MailInfo>('admin/mail', { from_address: f.from_address, from_name: f.from_name, test_to: f.test_to || null, ...body })
      qc.setQueryData(['admin', 'mail'], r)
      setF((x) => ({ ...x, resend_key: '' }))
      setSaved(true)
    } catch (e) {
      setErr(e as ApiError)
    } finally {
      setBusy(false)
    }
  }

  async function sendTest() {
    setTestBusy(true)
    setTest(null)
    try {
      const r = await post<{ to: string }>('admin/mail/test', {})
      setTest({ ok: true, text: `Sent to ${r.to}. Check that inbox (and spam).` })
    } catch (e) {
      setTest({ ok: false, text: (e as Error).message })
    } finally {
      setTestBusy(false)
    }
  }

  if (user?.role !== 'superadmin') return <p className="text-ink-2">Only the superadmin can change email settings.</p>
  if (!q.data) return <Spinner />
  const d = q.data
  return (
    <>
      <H right={<Badge tone={d.source === 'none' ? 'warn' : 'up'}>{d.source === 'none' ? 'not set up' : 'sending'}</Badge>}>Email</H>
      <p className="mb-4 max-w-2xl text-ink-2">
        PipStream sends password resets, welcome emails and "connected" notices through <a className="font-semibold text-brand" href="https://resend.com" target="_blank" rel="noreferrer">Resend</a>.
        The API key is stored encrypted and never shown again after you save it.
      </p>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card className="space-y-4">
          <span className="flex items-center gap-2 font-semibold"><KeyRound className="size-4" /> Resend API key</span>
          <p className="text-sm text-ink-2">
            {d.source === 'admin' && <>Saved here: <b className="tabular">{d.key_hint}</b>.</>}
            {d.source === 'env' && <>Using <code>RESEND_API_KEY</code> from .env ({d.key_hint}). A key saved here takes over.</>}
            {d.source === 'none' && <>No key yet, so no email is sent. Create one at resend.com → API Keys (Sending access is enough).</>}
          </p>
          <Field label={d.source === 'admin' ? 'Replace key' : 'API key'} type="password" autoComplete="off" placeholder="re_…" value={f.resend_key}
            onChange={(e) => setF({ ...f, resend_key: e.target.value })} error={err?.field('resend_key')} />
          <Field label="From address" type="email" value={f.from_address} onChange={(e) => setF({ ...f, from_address: e.target.value })} error={err?.field('from_address')}
            hint="Must be on a domain you verified at resend.com/domains, e.g. hello@pips.stream." required />
          <Field label="From name" value={f.from_name} onChange={(e) => setF({ ...f, from_name: e.target.value })} error={err?.field('from_name')} required />
          <Field label="Send all email to (testing only)" type="email" value={f.test_to} onChange={(e) => setF({ ...f, test_to: e.target.value })} error={err?.field('test_to')}
            hint="Before your domain is verified, Resend only delivers to your own account email. Leave empty in production." />
          {err && !Object.keys(err.errors).length && <ErrorNote>{err.message}</ErrorNote>}
          {saved && <p className="text-sm font-medium text-up">Saved. New emails use these settings.</p>}
          <div className="flex flex-wrap gap-2">
            <Button busy={busy} onClick={() => save(f.resend_key ? { resend_key: f.resend_key.trim() } : {})}>Save</Button>
            {d.source === 'admin' && (
              <Button variant="soft" busy={busy} onClick={() => confirm('Remove the saved Resend key?') && save({ clear_key: true })}>Remove saved key</Button>
            )}
          </div>
        </Card>

        <Card className="space-y-3 self-start">
          <span className="flex items-center gap-2 font-semibold"><Mail className="size-4" /> Test</span>
          <p className="text-sm text-ink-2">Sends a test email to {d.test_to || user.email} right now, so any error from Resend shows here.</p>
          <Button variant="soft" busy={testBusy} disabled={d.source === 'none'} onClick={sendTest}><Send className="size-4" /> Send test email</Button>
          {test && <p className={test.ok ? 'text-sm text-up' : 'text-sm text-down'}>{test.text}</p>}
          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 border-t border-line pt-3 text-sm">
            <dt className="text-ink-3">Mailer</dt><dd className="font-semibold">{d.mailer}</dd>
            <dt className="text-ink-3">Sending as</dt><dd className="min-w-0 break-words font-semibold">{d.from_name} &lt;{d.from_address}&gt;</dd>
          </dl>
          <p className="text-xs text-ink-3">Emails sent: welcome (with the founding-member note for the first 10), password reset, Telegram connected, broker connected, plan activated, and failed-trade alerts for customers who turned email alerts on.</p>
        </Card>
      </div>
    </>
  )
}
