import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from './api'

const put = vi.fn()
vi.mock('./api', async (orig) => ({ ...(await orig<typeof import('./api')>()), put: (...a: unknown[]) => put(...a) }))
const { liveQuestion, saveRule } = await import('./rules')

const needsConfirm = () =>
  new ApiError(409, 'This is a real-money account (Exness 81234567). Copy signals into it with real money?', {}, {
    needs_live_confirm: true,
    account: { company: 'Exness', login: '81234567', server: 'Exness-MT5Real8', balance: 250, currency: 'USD' },
  })

describe('saveRule', () => {
  beforeEach(() => put.mockReset())

  it('saves straight away when no confirmation is needed', async () => {
    put.mockResolvedValueOnce({})
    const ask = vi.fn()
    expect(await saveRule('ch1', { style: 'balanced', live: true }, ask)).toBe(true)
    expect(ask).not.toHaveBeenCalled()
    expect(put).toHaveBeenCalledTimes(1)
  })

  it('asks before real money and resends with confirm_live only on yes', async () => {
    put.mockRejectedValueOnce(needsConfirm()).mockResolvedValueOnce({})
    const ask = vi.fn((_q: string) => true)
    expect(await saveRule('ch1', { style: 'balanced', live: true }, ask)).toBe(true)
    expect(ask.mock.calls[0][0]).toContain('Exness account 81234567')
    expect(put).toHaveBeenLastCalledWith('channels/ch1/rule', { style: 'balanced', live: true, confirm_live: true })
  })

  it('sends nothing more when the customer says no', async () => {
    put.mockRejectedValueOnce(needsConfirm())
    expect(await saveRule('ch1', { live: true }, () => false)).toBe(false)
    expect(put).toHaveBeenCalledTimes(1)
  })

  it('passes other errors through', async () => {
    put.mockRejectedValueOnce(new ApiError(402, 'Copying into a real-money account needs the Pro plan.'))
    await expect(saveRule('ch1', { live: true }, () => true)).rejects.toThrow('Pro plan')
  })
})

describe('liveQuestion', () => {
  it('names the account and balance and says losses are real', () => {
    const q = liveQuestion('Real money?', { company: 'Exness', login: '1', balance: 1000, currency: 'USD' })
    expect(q).toContain('Exness account 1')
    expect(q).toContain('Balance: 1,000 USD')
    expect(q).toContain('Losses are real')
  })
})
