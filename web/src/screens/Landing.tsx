import clsx from 'clsx'
import {
  ArrowRight, BarChart3, BellRing, Brain, Check, ChevronDown, Cpu, Eye, Gauge, KeyRound, Landmark, Lock, MessageSquareText, OctagonX,
  CheckCircle2, FlaskConical, QrCode, Radio, Reply, Scale, ShieldCheck, Smartphone, Sparkles, Zap,
} from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { useEffect, useState, type ReactNode } from 'react'
import { Link, Navigate } from 'react-router'
import { BetaPill, ThemeButton } from '../components/Plan'
import { Logo } from '../components/ui'
import { api } from '../lib/api'
import { isAdmin, nextStep, useSession } from '../lib/session'

/** "7 of 10 founding spots left": the first members are free for life. Hidden once they're gone. */
function FoundingSpots() {
  const q = useQuery({ queryKey: ['founding'], queryFn: () => api<{ slots: number; left: number }>('founding'), staleTime: 60_000 })
  if (!q.data || q.data.left <= 0) return null
  return (
    <Link to="/signup" className="mt-4 inline-flex items-center gap-2 rounded-full bg-warn-soft px-3 py-1.5 text-sm font-semibold text-warn">
      <Sparkles className="size-4" /> {q.data.left} of {q.data.slots} founding spots left: free for life, every feature
    </Link>
  )
}

/** pips.stream public landing page. Signed-in people go straight to their app. */
export default function Landing() {
  const { user } = useSession()
  if (user) return <Navigate to={isAdmin(user) ? '/admin' : nextStep(user)} replace />

  return (
    <div className="min-h-dvh overflow-x-clip bg-bg">
      <Nav />
      <Hero />
      <Strip />
      <Flow />
      <Understands />
      <Setup />
      <TryIt />
      <Safety />
      <Features />
      <Pricing />
      <Faq />
      <FinalCta />
      <Footer />
    </div>
  )
}

/* ------------------------------------------------------------------ shared bits */

function Section({ id, eyebrow, title, sub, children, className }: { id?: string; eyebrow?: string; title: ReactNode; sub?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section id={id} className={clsx('scroll-mt-20 px-5 py-16 md:py-24', className)}>
      <div className="mx-auto max-w-6xl">
        <div className="max-w-2xl">
          {eyebrow && <p className="text-sm font-semibold tracking-wide text-brand uppercase">{eyebrow}</p>}
          <h2 className="mt-2 text-[30px] leading-tight font-bold tracking-tight text-balance md:text-[42px]">{title}</h2>
          {sub && <p className="mt-3 text-[17px] leading-relaxed text-ink-2">{sub}</p>}
        </div>
        <div className="mt-10 md:mt-14">{children}</div>
      </div>
    </section>
  )
}

function Cta({ children = 'Start free on practice money', className }: { children?: ReactNode; className?: string }) {
  return (
    <Link to="/signup" className={clsx('inline-flex min-h-13 items-center justify-center gap-2 rounded-2xl bg-brand px-6 text-[16px] font-semibold text-brand-ink shadow-card transition hover:brightness-110', className)}>
      {children} <ArrowRight className="size-4" />
    </Link>
  )
}

/* ------------------------------------------------------------------ nav + hero */

function Nav() {
  return (
    <header className="sticky top-0 z-40 border-b border-line/60 bg-bg/80 backdrop-blur-xl safe-top">
      <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-2 px-5">
        <span className="flex items-center gap-2"><Logo size={28} /><BetaPill /></span>
        <nav className="flex items-center gap-1">
          <a href="#how" className="hidden px-3 text-sm font-semibold text-ink-2 hover:text-ink md:block">How it works</a>
          <a href="#safety" className="hidden px-3 text-sm font-semibold text-ink-2 hover:text-ink md:block">Safety</a>
          <a href="#pricing" className="hidden px-3 text-sm font-semibold text-ink-2 hover:text-ink md:block">Pricing</a>
          <ThemeButton />
          <Link to="/login" className="px-3 text-sm font-semibold text-ink-2 hover:text-ink">Sign in</Link>
          <Link to="/signup" className="hidden rounded-full bg-brand px-4 py-2 text-sm font-semibold text-brand-ink sm:block">Start free</Link>
        </nav>
      </div>
    </header>
  )
}

function Hero() {
  return (
    <section className="relative px-5 pt-12 pb-16 md:pt-20 md:pb-24">
      {/* soft colour wash behind the hero */}
      <div aria-hidden className="anim-glow pointer-events-none absolute inset-x-0 -top-24 -z-0 mx-auto h-[520px] max-w-5xl bg-[radial-gradient(closest-side,color-mix(in_oklab,var(--brand)_28%,transparent),transparent),radial-gradient(closest-side_at_80%_60%,color-mix(in_oklab,var(--up)_22%,transparent),transparent)] blur-2xl" />
      <div className="relative mx-auto grid max-w-6xl items-center gap-12 md:grid-cols-[1.05fr_1fr]">
        <div className="anim-rise">
          <span className="inline-flex items-center gap-2 rounded-full border border-line bg-surface px-3 py-1 text-sm font-semibold text-ink-2">
            <span className="relative flex size-2"><span className="absolute inline-flex size-full animate-ping rounded-full bg-up opacity-70" /><span className="relative inline-flex size-2 rounded-full bg-up" /></span>
            Telegram signals → your broker, automatically
          </span>
          <h1 className="mt-5 text-[42px] leading-[1.02] font-bold tracking-tight text-balance md:text-[64px]">
            The channel posts.
            <br />
            <span className="bg-gradient-to-r from-brand to-up bg-clip-text text-transparent">Your trade is already open.</span>
          </h1>
          <p className="mt-5 max-w-[46ch] text-[18px] leading-relaxed text-ink-2">
            PipStream watches the Telegram channels and groups you follow, understands each signal, sizes it to your risk and places it on
            MetaTrader 5, Deriv or a free practice account, in well under a second.
          </p>
          <div className="mt-8 flex flex-col gap-3 sm:flex-row">
            <Cta />
            <a href="#how" className="inline-flex min-h-13 items-center justify-center gap-2 rounded-2xl border border-line bg-surface px-6 text-[16px] font-semibold hover:bg-surface-2">
              See the flow <ChevronDown className="size-4" />
            </a>
          </div>
          <p className="mt-4 text-sm text-ink-3">14 days free · no card · works on any phone · in beta</p>
          <FoundingSpots />
        </div>
        <LiveDemo />
      </div>
    </section>
  )
}

const DEMO_STEPS = [
  { ms: 0, label: 'Posted in the channel' },
  { ms: 29, label: 'Read & understood' },
  { ms: 98, label: 'Sized to your 1% risk' },
  { ms: 124, label: 'Filled at your broker' },
]

/** A post turning into a trade, replayed on a loop with real timings from our engine. */
function LiveDemo() {
  const [step, setStep] = useState(0)
  useEffect(() => {
    const t = setTimeout(() => setStep((s) => (s + 1) % 6), step === 5 ? 3200 : step === 0 ? 1400 : 900)
    return () => clearTimeout(t)
  }, [step])
  const shown = Math.min(step, 4)
  return (
    <div className="anim-rise relative mx-auto w-full max-w-[400px]" style={{ animationDelay: '120ms' }}>
      <div className="anim-float rounded-[32px] border border-line bg-surface p-4 shadow-[0_30px_80px_-30px_rgb(0_0_0/0.45)] md:p-5">
        {/* telegram post */}
        <div className="flex gap-2.5">
          <span className="grid size-9 shrink-0 place-items-center rounded-full bg-tg text-xs font-bold text-white">GS</span>
          <div className="min-w-0 flex-1 rounded-2xl rounded-tl-md bg-surface-2 px-3.5 py-2.5">
            <span className="block text-xs font-semibold text-tg">Gold Signals Room</span>
            <p className="mt-0.5 font-mono text-[13px] leading-relaxed">
              🟢 XAUUSD BUY 2348-2350
              <br />
              SL 2340
              <br />
              TP 1 ➜ 2360 · TP 2 ➜ 2375
            </p>
            <span className="block text-right text-[10px] text-ink-3">14:02:11</span>
          </div>
        </div>

        {/* understood chips */}
        <div className={clsx('mt-4 flex flex-wrap gap-1.5 transition-all duration-500', shown >= 1 ? 'opacity-100' : 'translate-y-2 opacity-0')}>
          {['BUY', 'XAUUSD', 'entry 2348–2350', 'SL 2340', 'TP1 2360', 'TP2 2375'].map((c, i) => (
            <span key={c} className={clsx('rounded-full px-2.5 py-1 text-xs font-semibold', i === 0 ? 'bg-up-soft text-up' : 'bg-brand-soft text-brand')} style={{ transitionDelay: `${i * 50}ms` }}>{c}</span>
          ))}
        </div>

        {/* steps */}
        <ol className="mt-4 space-y-2">
          {DEMO_STEPS.map((s, i) => (
            <li key={s.label} className={clsx('flex items-center gap-2.5 text-sm transition-all duration-500', i <= shown ? 'opacity-100' : 'opacity-25')}>
              <span className={clsx('grid size-5 shrink-0 place-items-center rounded-full', i <= shown ? 'bg-up text-white' : 'bg-surface-2')}>
                {i <= shown && <Check className="size-3" strokeWidth={3} />}
              </span>
              <span className="flex-1 font-medium">{s.label}</span>
              <span className="font-mono text-xs text-ink-3">+{s.ms} ms</span>
            </li>
          ))}
        </ol>

        {/* the trade */}
        <div className={clsx('mt-4 overflow-hidden rounded-2xl bg-gradient-to-br from-brand to-[#3b5bff] p-4 text-white transition-all duration-500', shown >= 3 ? 'opacity-100' : 'scale-[.98] opacity-0')}>
          <div className="flex items-center justify-between">
            <span className="text-sm font-bold">Bought 0.11 XAUUSD</span>
            <span className="rounded-full bg-white/20 px-2 py-0.5 text-[11px] font-semibold">copied in 124 ms</span>
          </div>
          <div className="mt-2 grid grid-cols-2 gap-2 text-xs">
            <span className="rounded-lg bg-white/15 px-2 py-1.5">TP1 · 0.06 lot @ 2349</span>
            <span className="rounded-lg bg-white/15 px-2 py-1.5">TP2 · 0.05 lot @ 2349</span>
          </div>
        </div>
        <p className={clsx('mt-3 flex items-center gap-1.5 text-xs text-ink-3 transition-opacity duration-500', step === 5 ? 'opacity-100' : 'opacity-0')}>
          <Reply className="size-3.5" /> Later, “Move SL to entry” is copied too.
        </p>
      </div>
    </div>
  )
}

function Strip() {
  return (
    <section className="border-y border-line bg-surface px-5">
      <div className="mx-auto grid max-w-6xl grid-cols-2 gap-6 py-8 md:grid-cols-4">
        {[
          ['~150 ms', 'from post to order (median)'],
          ['Channels + groups', 'any you can see in Telegram'],
          ['MT5 · Deriv', 'plus a free practice account'],
          ['M-Pesa', 'or card when you go Pro'],
        ].map(([n, l]) => (
          <div key={l}>
            <div className="text-xl font-bold md:text-2xl">{n}</div>
            <div className="text-sm text-ink-3">{l}</div>
          </div>
        ))}
      </div>
    </section>
  )
}

/* ------------------------------------------------------------------ the flow */

const STAGES: { icon: typeof Radio; title: string; short: string; detail: ReactNode; example: ReactNode; color: string }[] = [
  {
    icon: MessageSquareText, title: 'A channel posts', short: 'Signal goes out', color: 'var(--tg)',
    detail: 'Your signal provider posts as usual, in a channel or a group. Nothing changes for them: no bot to add, no admin rights needed.',
    example: <code className="font-mono text-[13px]">GOLD SELL 2440 · SL 2450 · TP 2430 / 2420</code>,
  },
  {
    icon: Eye, title: 'PipStream sees it', short: 'Read-only, instantly', color: 'var(--brand)',
    detail: 'Your own Telegram, connected once by QR or code, receives the post the same instant you would. PipStream only reads the chats you picked. It never posts, never reads private chats.',
    example: <span>New post in <b>FX Signal Desk</b> · received in ~20 ms</span>,
  },
  {
    icon: Brain, title: 'Understands it', short: 'Signal or just chatter?', color: '#8b5cf6',
    detail: 'The parser pulls out the symbol, direction, entry or range, stop loss and every take-profit, even with emojis, arrows and “TP 1 ➜” numbering. “Good morning traders” is ignored, not traded.',
    example: <span><b className="text-down">SELL</b> XAUUSD · entry 2440 · SL 2450 · TP 2430, 2420 · <b className="text-up">95% sure</b></span>,
  },
  {
    icon: Scale, title: 'Sizes it to you', short: 'Your risk, your rules', color: 'var(--warn)',
    detail: 'Every trade is sized from your balance and the stop loss: careful, balanced or bold. Your lot cap, max open trades, daily loss limit and pause button are checked first.',
    example: <span>Balance $10,000 · risk 1% · SL 10 pts → <b>0.10 lot</b>, split per TP</span>,
  },
  {
    icon: Landmark, title: 'Places it', short: 'At your broker', color: 'var(--up)',
    detail: 'The order goes to your MetaTrader 5 or Deriv account, or the practice account while you build trust. One trade per take-profit, each with its own TP and the shared stop loss.',
    example: <span>Sold 0.05 + 0.05 XAUUSD @ 2440 · <b className="text-up">filled in 124 ms</b></span>,
  },
  {
    icon: BellRing, title: 'Follows up', short: 'Updates & alerts', color: 'var(--down)',
    detail: 'Replies like “Move SL to entry”, “Close half” or “Close all” change the right trades. You get an alert for each step, and every trade opens into the full story: post, decision, timing, result.',
    example: <span><Reply className="inline size-3.5" /> “Close half now” → closed TP1 leg · alert sent</span>,
  },
]

function Flow() {
  const [active, setActive] = useState(0)
  const [auto, setAuto] = useState(true)
  useEffect(() => {
    if (!auto) return
    const t = setInterval(() => setActive((a) => (a + 1) % STAGES.length), 3800)
    return () => clearInterval(t)
  }, [auto])
  const pick = (i: number) => {
    setAuto(false)
    setActive(i)
  }
  const s = STAGES[active]
  return (
    <Section id="how" eyebrow="How it works" title="From a Telegram post to an open trade, in six steps" sub="Exactly what happens to every signal. The whole chain usually takes less time than it takes you to read the post.">
      {/* desktop: horizontal pipeline */}
      <div className="hidden md:block">
        <div className="relative grid grid-cols-6 gap-3">
          <div aria-hidden className="absolute top-8 right-[8%] left-[8%] h-0.5 bg-line">
            <span className="flow-dot-x size-2.5 rounded-full bg-brand shadow-[0_0_12px_var(--brand)]" />
          </div>
          {STAGES.map((st, i) => (
            <button key={st.title} onClick={() => pick(i)} className="group relative flex flex-col items-center text-center" aria-pressed={i === active}>
              <span
                className={clsx('relative z-10 grid size-16 place-items-center rounded-2xl border-2 bg-surface transition-all duration-300', i === active ? 'scale-110 shadow-card' : 'border-line group-hover:-translate-y-0.5')}
                style={i === active ? { borderColor: st.color, color: st.color } : { color: 'var(--ink-3)' }}
              >
                <st.icon className="size-7" />
              </span>
              <span className={clsx('mt-3 text-sm font-bold', i === active ? 'text-ink' : 'text-ink-2')}>{st.title}</span>
              <span className="text-xs text-ink-3">{st.short}</span>
            </button>
          ))}
        </div>
        <div key={active} className="anim-rise mx-auto mt-10 grid max-w-4xl gap-6 rounded-[28px] border border-line bg-surface p-7 shadow-card md:grid-cols-[1.3fr_1fr]">
          <div>
            <span className="text-xs font-bold tracking-wider uppercase" style={{ color: s.color }}>Step {active + 1} of 6</span>
            <h3 className="mt-1 text-2xl font-bold">{s.title}</h3>
            <p className="mt-2 text-[16px] leading-relaxed text-ink-2">{s.detail}</p>
          </div>
          <div className="self-center rounded-2xl bg-surface-2 p-4 text-sm text-ink-2">
            <span className="mb-1 block text-[11px] font-semibold tracking-wider text-ink-3 uppercase">Example</span>
            {s.example}
          </div>
        </div>
        <div className="mt-5 flex justify-center gap-1.5">
          {STAGES.map((_, i) => (
            <button key={i} onClick={() => pick(i)} aria-label={`Step ${i + 1}`} className={clsx('h-1.5 rounded-full transition-all', i === active ? 'w-8 bg-brand' : 'w-3 bg-line')} />
          ))}
        </div>
      </div>

      {/* phones: vertical timeline, every step readable */}
      <ol className="relative space-y-4 md:hidden">
        <div aria-hidden className="absolute top-6 bottom-6 left-[27px] w-0.5 bg-line">
          <span className="flow-dot-y size-2.5 rounded-full bg-brand shadow-[0_0_12px_var(--brand)]" />
        </div>
        {STAGES.map((st, i) => (
          <li key={st.title} className="relative flex gap-4">
            <span className="z-10 grid size-14 shrink-0 place-items-center rounded-2xl border-2 bg-surface" style={{ borderColor: st.color, color: st.color }}>
              <st.icon className="size-6" />
            </span>
            <div className="min-w-0 flex-1 rounded-2xl border border-line bg-surface p-4">
              <span className="text-[11px] font-bold tracking-wider uppercase" style={{ color: st.color }}>Step {i + 1}</span>
              <h3 className="text-lg font-bold">{st.title}</h3>
              <p className="mt-1 text-[15px] leading-relaxed text-ink-2">{st.detail}</p>
              <div className="mt-3 rounded-xl bg-surface-2 p-3 text-sm text-ink-2">{st.example}</div>
            </div>
          </li>
        ))}
      </ol>
    </Section>
  )
}

/* ------------------------------------------------------------------ parser examples */

const SAMPLES = [
  {
    tab: 'Classic',
    post: 'XAUUSD BUY 2348-2350\nSL 2340\nTP 2360 / 2375',
    out: [['Direction', 'BUY', 'up'], ['Symbol', 'XAUUSD'], ['Entry', '2348 – 2350'], ['Stop loss', '2340'], ['Take profits', '2360 · 2375']],
    note: 'Two trades: one per take-profit.',
  },
  {
    tab: 'Emoji & arrows',
    post: '#XAUUSD\nDirection: SELL 📉\nEntry: 2440.00\nSL - 2450.00\nTP 1 ➜ 2430.00\nTP 2 ➜ 2420.00\nTP 3 ➜ 2405.00',
    out: [['Direction', 'SELL', 'down'], ['Symbol', 'XAUUSD'], ['Entry', 'Market (~2440)'], ['Stop loss', '2450'], ['Take profits', '2430 · 2420 · 2405']],
    note: '“TP 1 ➜” numbering is read as labels, not prices.',
  },
  {
    tab: 'Pips',
    post: 'EURUSD BUY LIMIT 1.0850\nSL 30 pips\nTP 60 pips',
    out: [['Direction', 'BUY LIMIT', 'up'], ['Symbol', 'EURUSD'], ['Entry', '1.0850 (limit)'], ['Stop loss', '1.0820'], ['Take profits', '1.0910']],
    note: 'Pip distances are turned into prices.',
  },
  {
    tab: 'Follow-up',
    post: '↪︎ reply to the GOLD signal\nMove SL to entry and close half 💰',
    out: [['Kind', 'Follow-up'], ['Applies to', 'the trades from that signal'], ['Action', 'SL → entry, close 50%']],
    note: 'Replies update the right trades, not new ones.',
  },
  {
    tab: 'Just chatter',
    post: 'Good morning traders ☀️\nBig week ahead, stay disciplined!',
    out: [['Result', 'Not a signal', 'warn'], ['Action', 'Nothing traded']],
    note: 'Unclear posts are skipped and you are told why.',
  },
] as const

function Understands() {
  const [i, setI] = useState(0)
  const s = SAMPLES[i]
  return (
    <section className="bg-surface px-5 py-16 md:py-24">
      <div className="mx-auto max-w-6xl">
        <div className="max-w-2xl">
          <p className="text-sm font-semibold tracking-wide text-brand uppercase">Reads real channels</p>
          <h2 className="mt-2 text-[30px] leading-tight font-bold tracking-tight md:text-[42px]">Messy posts in. Clean trades out.</h2>
          <p className="mt-3 text-[17px] text-ink-2">Every channel writes signals differently. PipStream handles the common styles, and only trades when it's sure.</p>
        </div>
        <div className="mt-8 flex gap-2 overflow-x-auto pb-1" role="tablist">
          {SAMPLES.map((x, n) => (
            <button key={x.tab} role="tab" aria-selected={n === i} onClick={() => setI(n)} className={clsx('shrink-0 rounded-full px-4 py-2 text-sm font-semibold transition', n === i ? 'bg-ink text-bg' : 'bg-surface-2 text-ink-2 hover:text-ink')}>
              {x.tab}
            </button>
          ))}
        </div>
        <div key={i} className="anim-rise mt-6 grid items-stretch gap-4 md:grid-cols-[1fr_auto_1fr]">
          <div className="rounded-[24px] border border-line bg-bg p-5">
            <span className="text-xs font-semibold tracking-wider text-ink-3 uppercase">The post</span>
            <pre className="mt-3 font-mono text-[14px] leading-relaxed whitespace-pre-wrap">{s.post}</pre>
          </div>
          <div className="grid place-items-center text-brand">
            <span className="grid size-12 place-items-center rounded-full bg-brand-soft"><Sparkles className="size-5" /></span>
          </div>
          <div className="rounded-[24px] border border-line bg-bg p-5">
            <span className="text-xs font-semibold tracking-wider text-ink-3 uppercase">What PipStream understood</span>
            <dl className="mt-3 divide-y divide-line">
              {s.out.map(([k, v, tone]) => (
                <div key={k} className="flex justify-between gap-4 py-2 text-sm">
                  <dt className="text-ink-3">{k}</dt>
                  <dd className={clsx('text-right font-semibold', tone === 'up' && 'text-up', tone === 'down' && 'text-down', tone === 'warn' && 'text-warn')}>{v}</dd>
                </div>
              ))}
            </dl>
            <p className="mt-3 text-sm text-ink-2">{s.note}</p>
          </div>
        </div>
      </div>
    </section>
  )
}

/* ------------------------------------------------------------------ setup, safety, features */

function Setup() {
  const steps: [typeof Radio, string, string][] = [
    [QrCode, 'Connect Telegram', 'Scan a QR from Telegram → Devices, or use your number and code. Takes 30 seconds.'],
    [Radio, 'Pick your chats', 'We list the channels and groups you already follow. Tap the ones that post signals.'],
    [FlaskConical, 'Test it with a post', 'Post a one-time test code in your channel and watch PipStream read it and build the trade, live. Nothing is traded.'],
    [Gauge, 'Choose your style', 'Careful, Balanced or Bold. Start on practice money; go live with MetaTrader or Deriv when you trust it.'],
  ]
  return (
    <Section eyebrow="Setup" title="Copying in four easy steps" sub="No bots to add to channels, no software to install, no admin rights needed.">
      <ol className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        {steps.map(([Icon, t, d], i) => (
          <li key={t} className="relative overflow-hidden rounded-[24px] border border-line bg-surface p-6">
            <span className="absolute -top-4 -right-1 text-[96px] leading-none font-black text-surface-2 select-none">{i + 1}</span>
            <span className="relative grid size-12 place-items-center rounded-2xl bg-brand-soft text-brand"><Icon className="size-6" /></span>
            <h3 className="relative mt-5 text-lg font-bold">{t}</h3>
            <p className="relative mt-1 text-[15px] text-ink-2">{d}</p>
          </li>
        ))}
      </ol>
      <div className="mt-8"><Cta>Start now, it's free</Cta></div>
    </Section>
  )
}

/** "Try before you trust": the channel self-test, shown as it looks in the app. */
function TryIt() {
  const checks: [string, string][] = [
    ['Post arrived', '1.2 s after you posted it'],
    ['Read as a signal', 'BUY XAUUSD at market · SL 4039 · TP 4064'],
    ['Would be copied', '0.05 lots of XAUUSD to your Practice account'],
  ]
  return (
    <Section
      id="test"
      eyebrow="Try before you trust"
      title="Test your channel before a single trade"
      sub="Right after you pick your channels, PipStream gives you a one-time test code. Post it in your channel, with a sample signal, and watch the post arrive, get read and turn into a practice trade. You can run the test again any time from Channels."
    >
      <div className="grid items-center gap-8 md:grid-cols-2">
        <ol className="space-y-4">
          {[
            ['Get your code', 'Tap Test this channel. You get a short code like PS-7K3QXM and a sample signal to paste.'],
            ['Post it in your channel', 'From your own Telegram, in the channel you want to copy. A post with the code is never copied to anyone else.'],
            ['See exactly what would happen', 'How fast it arrived, how it was read, and the trade size your settings give it. Nothing is traded unless you choose to.'],
            ["Can't post in the channel?", 'The test screen also lists the latest posts PipStream read from it and how each was understood.'],
          ].map(([t, d], i) => (
            <li key={t} className="flex gap-4">
              <span className="grid size-9 shrink-0 place-items-center rounded-full bg-brand-soft font-bold text-brand">{i + 1}</span>
              <span>
                <span className="block font-bold">{t}</span>
                <span className="block text-[15px] text-ink-2">{d}</span>
              </span>
            </li>
          ))}
        </ol>
        <div className="rounded-[28px] border border-line bg-surface p-5 shadow-card">
          <p className="text-xs font-semibold tracking-wider text-ink-3 uppercase">Your channel</p>
          <div className="mt-2 ml-auto max-w-[85%] rounded-2xl rounded-br-md bg-tg/10 px-3 py-2 font-mono text-[13px] whitespace-pre-wrap">
            {'PipStream test PS-7K3QXM\nXAUUSD BUY NOW 4049\nSL 4039\nTP 4064'}
          </div>
          <p className="mt-5 text-xs font-semibold tracking-wider text-ink-3 uppercase">PipStream, live</p>
          <ul className="mt-2 space-y-2 rounded-2xl bg-surface-2 p-4 text-sm">
            {checks.map(([t, d]) => (
              <li key={t} className="flex items-start gap-2">
                <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-up" />
                <span>
                  <span className="block font-semibold">{t}</span>
                  <span className="block text-ink-2">{d}</span>
                </span>
              </li>
            ))}
            <li className="flex items-center gap-2 text-ink-3">
              <FlaskConical className="size-4 text-brand" /> Preview only: no trade was opened
            </li>
          </ul>
        </div>
      </div>
    </Section>
  )
}

function Safety() {
  const items: [typeof Lock, string, string][] = [
    [Eye, 'Read-only Telegram', 'PipStream only reads the chats you pick. It never posts, never reads private chats, and you can end the session any time in Telegram → Devices.'],
    [Lock, 'Sealed credentials', 'Your Telegram session and broker password are sealed with keys only the trading engine holds. The website itself can’t read them.'],
    [KeyRound, 'Signed trades', 'Every order is signed by the risk check before it reaches your broker. Nothing else in the system can place a trade.'],
    [OctagonX, 'One-tap pause', 'Pause copying any time. Daily loss limits and max open trades stop runaway days.'],
  ]
  return (
    <section id="safety" className="scroll-mt-20 px-5 py-16 md:py-24">
      <div className="mx-auto grid max-w-6xl gap-10 rounded-[32px] bg-ink px-6 py-12 text-bg md:grid-cols-[1fr_1.4fr] md:px-12 md:py-16">
        <div>
          <span className="grid size-14 place-items-center rounded-2xl bg-bg/10"><ShieldCheck className="size-7" /></span>
          <h2 className="mt-5 text-[30px] leading-tight font-bold tracking-tight md:text-[40px]">Built so your account stays yours.</h2>
          <p className="mt-3 text-[17px] opacity-75">Copy trading touches your money and your messages. We designed every step around that.</p>
        </div>
        <div className="grid gap-6 sm:grid-cols-2">
          {items.map(([Icon, t, d]) => (
            <div key={t}>
              <Icon className="size-6 opacity-90" />
              <h3 className="mt-3 font-bold">{t}</h3>
              <p className="mt-1 text-[15px] leading-relaxed opacity-75">{d}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}

function Features() {
  const f: [typeof Zap, string, string][] = [
    [Zap, 'Every detail copied', 'Entries, ranges, stop loss, every take-profit, edits and follow-ups.'],
    [BarChart3, 'Your stats, live', 'Signals, trades, win rate and P/L per channel, updating as it happens.'],
    [Cpu, 'Full trade story', 'Open any trade to see the original post, what was understood and each step with timings.'],
    [BellRing, 'Alerts', 'In the app and in Telegram: every open, close and skipped signal, with the reason.'],
    [Smartphone, 'An app on your phone', 'Install to your home screen from the browser. Light and dark mode.'],
    [Landmark, 'Your broker', 'Any MetaTrader 5 broker or Deriv, plus a free practice account for everyone.'],
  ]
  return (
    <Section eyebrow="Features" title="Everything you'd do by hand, done for you" className="bg-surface">
      <div className="grid gap-x-10 gap-y-9 sm:grid-cols-2 lg:grid-cols-3">
        {f.map(([Icon, t, d]) => (
          <div key={t} className="flex gap-4">
            <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-brand-soft text-brand"><Icon className="size-5" /></span>
            <div>
              <h3 className="font-bold">{t}</h3>
              <p className="mt-1 text-[15px] text-ink-2">{d}</p>
            </div>
          </div>
        ))}
      </div>
    </Section>
  )
}

/* ------------------------------------------------------------------ pricing, faq, footer */

function Pricing() {
  const plans = [
    { name: 'Free trial', price: 'Free', sub: '14 days', perks: ['Copy every channel & group', 'Free practice account', 'Live stats and trade details', 'Alerts in the app and Telegram'], cta: 'Start free' },
    { name: 'Pro', price: 'KES 2,500', sub: 'per month', perks: ['Everything in the trial', 'Copy to your real MT5 or Deriv', 'Up to 3 broker accounts', 'Per-channel risk & daily loss limits', 'Pay with M-Pesa or card'], cta: 'Start free, upgrade later', hot: true },
  ]
  return (
    <Section id="pricing" eyebrow="Pricing" title="Start free. Go live when you trust it." sub="PipStream is in beta: prices may change, and early members keep their deal.">
      <div className="grid gap-4 md:grid-cols-2">
        {plans.map((p) => (
          <div key={p.name} className={clsx('relative rounded-[28px] border-2 bg-surface p-7', p.hot ? 'border-brand shadow-card' : 'border-line')}>
            {p.hot && <span className="absolute -top-3 left-7 rounded-full bg-brand px-3 py-1 text-xs font-bold text-brand-ink">Most popular</span>}
            <div className="flex items-baseline justify-between gap-3">
              <h3 className="text-xl font-bold">{p.name}</h3>
              <div className="text-right"><span className="text-3xl font-bold tracking-tight">{p.price}</span> <span className="text-sm text-ink-3">{p.sub}</span></div>
            </div>
            <ul className="mt-6 space-y-2.5">
              {p.perks.map((x) => <li key={x} className="flex gap-2.5 text-[15px] text-ink-2"><Check className="mt-0.5 size-4 shrink-0 text-up" strokeWidth={3} /> {x}</li>)}
            </ul>
            <Link to="/signup" className={clsx('mt-7 flex min-h-12 items-center justify-center rounded-2xl font-semibold', p.hot ? 'bg-brand text-brand-ink' : 'bg-brand-soft text-brand')}>{p.cta}</Link>
          </div>
        ))}
      </div>
    </Section>
  )
}

function Faq() {
  const qa: [string, string][] = [
    ['Can PipStream read my private chats?', 'No. It only reads the channels and groups you pick, and it never posts anything. You can disconnect any time, in the app or from Telegram’s Devices list.'],
    ['Do I need to be the channel admin?', 'No. If you can see the channel or group in your Telegram, PipStream can copy it.'],
    ['How do I know my channel will copy properly?', 'Test it. After you pick a channel, post the one-time test code we give you in it and watch PipStream read the post and show the trade it would open, on practice money. You can test again any time from Channels.'],
    ['How fast is it?', 'Usually 100–300 ms from the post to your broker’s confirmation. Most of that is Telegram delivering the message.'],
    ['Which brokers work?', 'Any broker on MetaTrader 5 (Exness, IC Markets, XM, HFM and more) and Deriv. Everyone also gets a free practice account.'],
    ['What if a post is not a clear signal?', 'Nothing is traded. PipStream only acts on posts it reads with high confidence, and tells you when it skips one and why.'],
    ['Is trading risky?', 'Yes. Signals can lose money, and past results don’t guarantee future ones. Start on practice money and only trade what you can afford to lose.'],
  ]
  return (
    <Section title="Questions" className="pt-0 md:pt-0">
      <div className="mx-auto max-w-3xl divide-y divide-line overflow-hidden rounded-[24px] border border-line bg-surface">
        {qa.map(([q, a]) => (
          <details key={q} className="group p-5 open:bg-surface-2/50">
            <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold">
              {q}
              <ChevronDown className="size-5 shrink-0 text-ink-3 transition group-open:rotate-180" />
            </summary>
            <p className="mt-2 text-[15px] leading-relaxed text-ink-2">{a}</p>
          </details>
        ))}
      </div>
    </Section>
  )
}

function FinalCta() {
  return (
    <section className="px-5 pb-20">
      <div className="relative mx-auto max-w-6xl overflow-hidden rounded-[36px] bg-gradient-to-br from-brand to-[#3b5bff] px-6 py-14 text-center text-white md:py-20">
        <div aria-hidden className="pointer-events-none absolute -top-20 -right-20 size-72 rounded-full bg-white/10 blur-2xl" />
        <h2 className="relative text-[32px] leading-tight font-bold tracking-tight text-balance md:text-[48px]">Let your channel do the clicking.</h2>
        <p className="relative mt-3 text-[17px] opacity-90">Free for 14 days on practice money. No card.</p>
        <Link to="/signup" className="relative mt-8 inline-flex min-h-13 items-center gap-2 rounded-2xl bg-white px-7 font-semibold text-brand">
          Get started <ArrowRight className="size-4" />
        </Link>
      </div>
    </section>
  )
}

function Footer() {
  return (
    <footer className="border-t border-line safe-bottom">
      <div className="mx-auto flex max-w-6xl flex-col gap-3 px-5 py-8 text-sm text-ink-3 md:flex-row md:items-center md:justify-between">
        <span className="flex items-center gap-2"><Logo size={20} /> © {new Date().getFullYear()} · pips.stream</span>
        <span className="max-w-[60ch]">Trading involves risk of loss. PipStream copies signals you choose and does not give financial advice.</span>
      </div>
    </footer>
  )
}
