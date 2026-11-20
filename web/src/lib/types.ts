export type Onboarding = {
  steps: { telegram: boolean; channels: boolean; broker: boolean; style: boolean }
  complete: boolean
  real_broker: boolean
  channels: number
}
export type User = { id: string; name: string | null; email: string; role: 'admin' | 'customer'; onboarding: Onboarding }

export type TgStatus = {
  status: 'pending' | 'code_sent' | 'password_needed' | 'linked' | 'channels' | 'logged_out' | 'error'
  request_id?: string
  phone_hint?: string
  error?: string
  channels?: TgChannel[]
}
export type TgChannel = {
  tg_chat_id: number
  title: string
  username?: string | null
  followed?: boolean
  channel_id?: string | null
  followers?: number
}
export type Rule = {
  enabled: boolean
  mode: 'paper' | 'shadow' | 'live'
  style: 'careful' | 'balanced' | 'bold' | 'fixed' | 'custom'
  broker_account_id: string
  max_lot: number | null
  fixed_lots: number | null
  risk_pct: number | null
  stake: number | null
  limits: Record<string, number>
}
export type MyChannel = { id: string; title: string; username?: string | null; signals_7d: number; last_signal_at: string | null; rule: Rule | null }
export type Broker = { id: string; connector: 'paper' | 'mt5_bridge' | 'deriv_options'; label: string; enabled: boolean; connected: boolean; login_hint: string | null }
export type Style = { id: Rule['style']; label: string; blurb: string }
export type Plan = { id: string; name: string; price: number; currency: string; live: boolean; brokers: number }
export type Billing = {
  current: { plan: string; plan_name: string; status: string; entitled: boolean; until: string | null } | null
  plans: Plan[]
  can_pay: boolean
}
export type Position = {
  id: string; ticket: string; symbol: string; side: 'buy' | 'sell'; volume: number; open_price: number
  sl: number | null; tp: number | null; tp_index: number | null; channel: string | null; opened_at: string; profit: number | null
  status?: 'open' | 'closed'; close_price?: number | null; closed_at?: string | null
}
export type SignalRow = { id: string; channel: string | null; kind: string; symbol: string; side: string | null; at: string; summary: string }
export type Home = {
  paused: boolean
  global_pause: boolean
  onboarding: Onboarding
  telegram: { linked: boolean; status: string } | null
  today: { opened: number; failed: number; closed_profit: number }
  positions: Position[]
  signals: SignalRow[]
  unread: number
}
export type Alert = { id: string; kind: string; title: string; body: string; read: boolean; at: string; trace_id?: string }
