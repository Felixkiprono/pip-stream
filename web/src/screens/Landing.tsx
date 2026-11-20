import { ArrowRight, BellRing, Check, Gauge, Lock, Radio, ShieldCheck, Smartphone, Zap } from 'lucide-react'
import { Link, Navigate } from 'react-router'
import { Logo } from '../components/ui'
import { nextStep, useSession } from '../lib/session'

/** pip.stream public landing page. Signed-in people go straight to their app. */
export default function Landing() {
  const { user } = useSession()
  if (user) return <Navigate to={user.role === 'admin' ? '/admin' : nextStep(user)} replace />

  return (
    <div className="min-h-dvh bg-bg">
      <header className="sticky top-0 z-30 border-b border-line/60 bg-bg/85 backdrop-blur-lg safe-top">
        <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-5">
          <Logo size={28} />
          <nav className="flex items-center gap-2">
            <a href="#how" className="hidden px-3 text-sm font-semibold text-ink-2 sm:block">How it works</a>
            <a href="#pricing" className="hidden px-3 text-sm font-semibold text-ink-2 sm:block">Pricing</a>
            <Link to="/login" className="px-3 text-sm font-semibold text-ink-2">Sign in</Link>
            <Link to="/signup" className="rounded-full bg-brand px-4 py-2 text-sm font-semibold text-brand-ink">Start free</Link>
          </nav>
        </div>
      </header>

      {/* hero */}
      <section className="mx-auto grid max-w-6xl items-center gap-10 px-5 pt-10 pb-16 md:grid-cols-2 md:pt-20">
        <div className="anim-rise">
          <span className="inline-flex items-center gap-2 rounded-full bg-brand-soft px-3 py-1 text-sm font-semibold text-brand">
            <Radio className="size-4" /> Telegram signals, copied for you
          </span>
          <h1 className="mt-4 text-[40px] leading-[1.05] font-bold tracking-tight text-balance md:text-[56px]">
            Your signal channel posts. <span className="text-brand">Your trade is already open.</span>
          </h1>
          <p className="mt-4 max-w-[46ch] text-[17px] leading-relaxed text-ink-2">
            PipStream reads the Telegram channels you already follow and places each signal on your
            MetaTrader 5 or Deriv account within seconds, sized to the risk you choose.
          </p>
          <div className="mt-7 flex flex-col gap-3 sm:flex-row">
            <Link to="/signup" className="inline-flex min-h-13 items-center justify-center gap-2 rounded-2xl bg-brand px-6 text-[16px] font-semibold text-brand-ink shadow-card">
              Start free on practice money <ArrowRight className="size-4" />
            </Link>
            <a href="#how" className="inline-flex min-h-13 items-center justify-center rounded-2xl border border-line bg-surface px-6 text-[16px] font-semibold">
              See how it works
            </a>
          </div>
          <p className="mt-4 text-sm text-ink-3">14 days free · no card · works on any phone</p>
        </div>
        <PhoneDemo />
      </section>

      {/* proof strip */}
      <section className="border-y border-line bg-surface">
        <div className="mx-auto grid max-w-6xl grid-cols-2 gap-6 px-5 py-8 md:grid-cols-4">
          {[
            ['Seconds', 'from post to order'],
            ['0.5–2%', 'risk per signal, your pick'],
            ['MT5 + Deriv', 'plus a free practice account'],
            ['M-Pesa', 'or card to upgrade'],
          ].map(([n, l]) => (
            <div key={l}>
              <div className="text-2xl font-bold tabular">{n}</div>
              <div className="text-sm text-ink-3">{l}</div>
            </div>
          ))}
        </div>
      </section>

      {/* how it works */}
      <section id="how" className="mx-auto max-w-6xl scroll-mt-20 px-5 py-16">
        <h2 className="text-[30px] font-bold tracking-tight">Set up in three minutes</h2>
        <ol className="mt-8 grid gap-4 md:grid-cols-3">
          {[
            ['Connect Telegram', 'Enter your number and the code Telegram sends you. No bots to add, no channel admin needed.'],
            ['Pick your channels', 'We list the channels you already follow. Tap the ones that post signals.'],
            ['Choose your trade size', 'Careful, Balanced or Bold. Start on practice money and go live when you trust it.'],
          ].map(([t, d], i) => (
            <li key={t} className="rounded-3xl border border-line bg-surface p-5">
              <span className="grid size-9 place-items-center rounded-full bg-brand text-sm font-bold text-white">{i + 1}</span>
              <h3 className="mt-4 text-lg font-bold">{t}</h3>
              <p className="mt-1 text-[15px] text-ink-2">{d}</p>
            </li>
          ))}
        </ol>
      </section>

      {/* features */}
      <section className="bg-surface">
        <div className="mx-auto grid max-w-6xl gap-x-10 gap-y-8 px-5 py-16 sm:grid-cols-2 lg:grid-cols-3">
          {[
            [Zap, 'Fast and exact', 'Entries, stop loss, every take profit, "move SL to entry" and "close half" follow-ups are all copied.'],
            [Gauge, 'Sized to your risk', 'Every trade is sized from your balance and the signal\'s stop loss, with your own lot cap.'],
            [ShieldCheck, 'Safety limits', 'Max open trades and a daily loss limit per channel. One tap pauses everything.'],
            [Lock, 'Locked credentials', 'Your broker password is sealed so only the trading engine can open it. Not even our staff can read it.'],
            [BellRing, 'Alerts in Telegram', 'A message for every trade, close and skipped signal, with the reason.'],
            [Smartphone, 'An app on your phone', 'Install PipStream to your home screen straight from the browser. No app store.'],
          ].map(([Icon, t, d]: any) => (
            <div key={t} className="flex gap-4">
              <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-brand-soft text-brand"><Icon className="size-5" /></span>
              <div>
                <h3 className="font-bold">{t}</h3>
                <p className="mt-1 text-[15px] text-ink-2">{d}</p>
              </div>
            </div>
          ))}
        </div>
      </section>

      {/* pricing */}
      <section id="pricing" className="mx-auto max-w-6xl scroll-mt-20 px-5 py-16">
        <h2 className="text-[30px] font-bold tracking-tight">Simple pricing</h2>
        <div className="mt-8 grid gap-4 md:grid-cols-2">
          {[
            { name: 'Free trial', price: 'Free', sub: '14 days', perks: ['Copy every channel to practice money', 'Trade alerts in Telegram', 'No card needed'], cta: 'Start free' },
            { name: 'Pro', price: 'KES 2,500', sub: 'per month', perks: ['Copy to your real MT5 or Deriv account', 'Up to 3 broker accounts', 'Per-channel risk and daily loss limits', 'Pay with M-Pesa or card'], cta: 'Start free, upgrade later', hot: true },
          ].map((p) => (
            <div key={p.name} className={`rounded-3xl border-2 bg-surface p-6 ${p.hot ? 'border-brand' : 'border-line'}`}>
              <div className="flex items-baseline justify-between">
                <h3 className="text-xl font-bold">{p.name}</h3>
                <div className="text-right"><span className="text-2xl font-bold">{p.price}</span> <span className="text-sm text-ink-3">{p.sub}</span></div>
              </div>
              <ul className="mt-5 space-y-2">
                {p.perks.map((x) => <li key={x} className="flex gap-2 text-[15px] text-ink-2"><Check className="mt-0.5 size-4 shrink-0 text-up" /> {x}</li>)}
              </ul>
              <Link to="/signup" className={`mt-6 flex min-h-12 items-center justify-center rounded-2xl font-semibold ${p.hot ? 'bg-brand text-brand-ink' : 'bg-brand-soft text-brand'}`}>{p.cta}</Link>
            </div>
          ))}
        </div>
      </section>

      {/* faq */}
      <section className="mx-auto max-w-3xl px-5 pb-16">
        <h2 className="text-[30px] font-bold tracking-tight">Questions</h2>
        <div className="mt-6 divide-y divide-line rounded-3xl border border-line bg-surface">
          {[
            ['Can PipStream read my private chats?', 'No. It reads only the channels you pick. You can disconnect any time, in the app or from Telegram\'s Devices list.'],
            ['Do I need to be the channel admin?', 'No. If you can see the channel in your Telegram, PipStream can copy it.'],
            ['Which brokers work?', 'Any broker on MetaTrader 5 (Exness, IC Markets, XM, HFM and more), and Deriv. Everyone also gets a free practice account.'],
            ['What if a post is not a clear signal?', 'Nothing is traded. PipStream only acts on posts it reads with high confidence, and tells you when it skips one.'],
            ['Is trading risky?', 'Yes. Signals can lose money and past results do not guarantee future ones. Start on practice money and only trade what you can afford to lose.'],
          ].map(([q, a]) => (
            <details key={q} className="group p-5">
              <summary className="cursor-pointer list-none font-semibold">{q}</summary>
              <p className="mt-2 text-[15px] text-ink-2">{a}</p>
            </details>
          ))}
        </div>
      </section>

      <section className="px-5 pb-20">
        <div className="mx-auto max-w-6xl rounded-[32px] bg-brand px-6 py-12 text-center text-white">
          <h2 className="text-[30px] font-bold tracking-tight text-balance">Let your channel do the clicking.</h2>
          <p className="mt-2 opacity-90">Free for 14 days on practice money.</p>
          <Link to="/signup" className="mt-6 inline-flex min-h-13 items-center gap-2 rounded-2xl bg-white px-6 font-semibold text-brand">
            Get started <ArrowRight className="size-4" />
          </Link>
        </div>
      </section>

      <footer className="border-t border-line safe-bottom">
        <div className="mx-auto flex max-w-6xl flex-col gap-2 px-5 py-8 text-sm text-ink-3 sm:flex-row sm:justify-between">
          <span>© {new Date().getFullYear()} PipStream · pip.stream</span>
          <span>Trading involves risk of loss. PipStream does not give financial advice.</span>
        </div>
      </footer>
    </div>
  )
}

/** A phone showing a channel post turning into an open trade. */
function PhoneDemo() {
  return (
    <div className="anim-rise mx-auto w-full max-w-[320px]" style={{ animationDelay: '120ms' }}>
      <div className="rounded-[44px] border-[10px] border-ink bg-surface p-4 shadow-card">
        <div className="mx-auto mb-4 h-1.5 w-16 rounded-full bg-line" />
        <div className="rounded-2xl bg-surface-2 p-3">
          <div className="flex items-center gap-2 text-sm font-semibold"><span className="grid size-7 place-items-center rounded-full bg-tg text-xs text-white">GS</span> Gold Signals Room</div>
          <p className="mt-2 font-mono text-[13px] leading-relaxed">XAUUSD BUY 2348-2350<br />SL 2340<br />TP 2360 / 2375</p>
        </div>
        <div className="my-3 flex items-center justify-center gap-2 text-xs font-semibold text-ink-3"><Zap className="size-4 text-warn" /> copied automatically</div>
        <div className="rounded-2xl bg-brand p-3 text-white">
          <div className="text-sm font-bold">Bought 0.06 XAUUSD</div>
          <div className="text-xs opacity-85">@ 2349 · SL 2340 · TP 2360 / 2375</div>
        </div>
        <div className="mt-3 space-y-2">
          {['Risk 1% · Balanced', 'Max 5 open trades', 'Daily loss limit on'].map((t) => (
            <div key={t} className="flex items-center gap-2 text-sm text-ink-2"><Check className="size-4 text-up" /> {t}</div>
          ))}
        </div>
      </div>
    </div>
  )
}
