export type Onboarding = {
  steps: { telegram: boolean; channels: boolean; broker: boolean; style: boolean }
  complete: boolean
  real_broker: boolean
  channels: number
}
export type PlanInfo = {
  id: string | null; name: string; status: string | null; tier: 'free' | 'premium'; lifetime: boolean
  founding?: number | null
  until: string | null; days_left: number | null
  limits: { brokers: number; live: boolean }
  usage: { brokers: number; channels: number }
}
export type User = { id: string; name: string | null; email: string; role: 'superadmin' | 'admin' | 'customer'; founding?: number | null; onboarding: Onboarding; plan?: PlanInfo; beta?: boolean }
export type Stats = {
  days: number
  series: { day: string; signals: number; ignored: number; opened: number; pnl: number }[]
  totals: { trades: number; open: number; closed: number; wins: number; losses: number; pnl: number; best: number | null; worst: number | null; win_rate: number | null; latency_ms: number | null }
  channels: { id: string; title: string; signals: number; ignored: number; trades: number; pnl: number }[]
  symbols: { symbol: string; trades: number; pnl: number }[]
  curve: { t: string; v: number }[]
}

export type TgStatus = {
  status: 'pending' | 'code_sent' | 'password_needed' | 'linked' | 'channels' | 'logged_out' | 'test_ok' | 'qr' | 'error'
  info?: { stand_in?: boolean; code?: string; via?: string | null; length?: number | null; email?: string | null; next?: string | null; timeout?: number | null; qr?: string; url?: string; expires?: number | null } | null
  request_id?: string
  phone_hint?: string
  error?: string
  channels?: TgChannel[]
}
export type TgChannel = {
  tg_chat_id: number
  title: string
  username?: string | null
  kind?: 'channel' | 'group'
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
  limits: Record<string, number | boolean>
}
export type MyChannel = { id: string; title: string; username?: string | null; signals_7d: number; last_signal_at: string | null; rule: Rule | null }
export type BrokerAccountState = { balance?: number; equity?: number; currency?: string; login?: string; server?: string; company?: string; positions?: number; demo?: boolean; platform?: string; ea_version?: string; trade_mode?: string }
export type BrokerStatus = { state: 'connecting' | 'connected' | 'error'; detail: string; at?: number; account?: BrokerAccountState | null; seen_s?: number }
export type Broker = { id: string; connector: 'paper' | 'ea_bridge' | 'metaapi' | 'mt5_bridge' | 'deriv_options'; label: string; enabled: boolean; connected: boolean; login_hint: string | null; platform?: 'mt4' | 'mt5' | null; status?: BrokerStatus | null; money?: BrokerMoney | null }
/** Demo or real money, as MetaTrader reports it; real accounts need a yes before PipStream opens trades. */
export type BrokerMoney = { kind: 'demo' | 'real' | 'unknown'; confirmed: boolean; needs_confirm: boolean; suffix: string | null }
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
  account?: TradeAccount; latency_ms?: number | null; filled_at?: string | null
}
export type TradeAccount = { label: string | null; practice: boolean }
export type FailedOrder = {
  id: string; action: string; status: 'failed' | 'rejected'; symbol: string | null; side: string | null; volume: number | null
  error: string | null; latency_ms: number | null; created_at: string; account: TradeAccount
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
