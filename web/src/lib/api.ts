// Thin fetch wrapper for the Laravel JSON API. Same-origin session cookie plus the
// XSRF-TOKEN cookie Laravel sets, echoed back as a header.

export class ApiError extends Error {
  status: number
  errors: Record<string, string[]>
  data: any
  constructor(status: number, message: string, errors: Record<string, string[]> = {}, data: any = null) {
    super(message)
    this.status = status
    this.errors = errors
    this.data = data
  }
  field(name: string): string | undefined {
    return this.errors[name]?.[0]
  }
}

function xsrf(): string | undefined {
  const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  return m ? decodeURIComponent(m[1]) : undefined
}

let csrfReady: Promise<unknown> | null = null
function ensureCsrf() {
  // Any GET through the web middleware sets XSRF-TOKEN; /api/v1/auth/me is cheap.
  if (xsrf()) return Promise.resolve()
  csrfReady ??= fetch('/api/v1/auth/me', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
  return csrfReady
}

export async function api<T = any>(path: string, opts: { method?: string; body?: unknown } = {}): Promise<T> {
  const method = opts.method ?? (opts.body === undefined ? 'GET' : 'POST')
  if (method !== 'GET') await ensureCsrf()
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
  if (opts.body !== undefined) headers['Content-Type'] = 'application/json'
  const token = xsrf()
  if (token) headers['X-XSRF-TOKEN'] = token
  const res = await fetch(`/api/v1/${path.replace(/^\//, '')}`, {
    method,
    headers,
    credentials: 'same-origin',
    body: opts.body === undefined ? undefined : JSON.stringify(opts.body),
  })
  const data = res.headers.get('content-type')?.includes('json') ? await res.json() : null
  if (!res.ok) {
    const msg = data?.message || (res.status === 419 ? 'Your session expired. Refresh and try again.' : 'Something went wrong. Try again.')
    throw new ApiError(res.status, msg, data?.errors ?? {}, data)
  }
  return data as T
}

export const post = <T = any>(path: string, body: unknown = {}) => api<T>(path, { method: 'POST', body })
export const put = <T = any>(path: string, body: unknown = {}) => api<T>(path, { method: 'PUT', body })
export const del = <T = any>(path: string) => api<T>(path, { method: 'DELETE' })

export const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms))

/** The Telegram steps answer within a few seconds; if the engine is slower Laravel returns
 *  {status:'pending', request_id} and we poll until the real answer arrives. */
export async function settle<T extends { status: string; request_id?: string }>(first: T, timeoutMs = 40000): Promise<T> {
  let cur = first
  const until = Date.now() + timeoutMs
  while (cur.status === 'pending' && cur.request_id && Date.now() < until) {
    await sleep(700)
    cur = await api<T>(`telegram/requests/${cur.request_id}`)
  }
  if (cur.status === 'pending') throw new ApiError(504, 'Telegram is taking longer than usual. Try again in a moment.')
  return cur
}
