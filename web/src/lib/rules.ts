import { ApiError, put } from './api'

type LiveAccount = { label?: string; login?: string; server?: string; company?: string; balance?: number; currency?: string }

/** The question shown before copying into real money, naming the exact account. */
export function liveQuestion(message: string, a: LiveAccount = {}): string {
  const who = [a.company, a.login && `account ${a.login}`, a.server && `(${a.server})`].filter(Boolean).join(' ')
  const bal = a.balance != null ? `\nBalance: ${a.balance.toLocaleString()} ${a.currency ?? ''}` : ''
  return `${message}${who ? `\n\n${who}${bal}` : ''}\n\nEvery signal from this channel will open a real order with real money. Losses are real. Continue?`
}

/**
 * Save a channel's copy rule. A real-money account answers 409 the first time: ask the customer,
 * and only send it again (with confirm_live) if they say yes. Returns false if they said no.
 */
export async function saveRule(channelId: string, body: Record<string, unknown>, ask: (q: string) => boolean = (q) => window.confirm(q)): Promise<boolean> {
  try {
    await put(`channels/${channelId}/rule`, body)
    return true
  } catch (e) {
    if (!(e instanceof ApiError) || e.status !== 409 || !e.data?.needs_live_confirm) throw e
    if (!ask(liveQuestion(e.message, e.data.account))) return false
    await put(`channels/${channelId}/rule`, { ...body, confirm_live: true })
    return true
  }
}
