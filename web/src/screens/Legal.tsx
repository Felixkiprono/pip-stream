import clsx from 'clsx'
import { TriangleAlert } from 'lucide-react'
import { useEffect, type ReactNode } from 'react'
import { Link, NavLink } from 'react-router'
import { ThemeButton } from '../components/Plan'
import { Logo } from '../components/ui'

/**
 * Terms of Service, Privacy Policy and Risk Disclosure. Generic text, to be reviewed by a lawyer.
 * When the text changes in a way customers must agree to again, bump LEGAL_UPDATED here and
 * 'terms_version' in config/pipstream.php (that value is stored on each user at sign-up).
 */
export const LEGAL_UPDATED = '25 November 2026'
const CONTACT = 'support@pips.stream'

export type LegalDoc = 'terms' | 'privacy' | 'risk'

const TITLES: Record<LegalDoc, string> = { terms: 'Terms of Service', privacy: 'Privacy Policy', risk: 'Risk Disclosure' }

export const legalLinks: [LegalDoc, string][] = [['terms', 'Terms'], ['privacy', 'Privacy'], ['risk', 'Risk Disclosure']]

export default function Legal({ doc }: { doc: LegalDoc }) {
  useEffect(() => {
    document.title = `${TITLES[doc]} · PipStream`
    window.scrollTo(0, 0)
    return () => { document.title = 'PipStream' }
  }, [doc])

  return (
    <div className="min-h-dvh bg-bg">
      <header className="sticky top-0 z-40 border-b border-line/60 bg-bg/80 backdrop-blur-xl safe-top">
        <div className="mx-auto flex h-16 max-w-3xl items-center justify-between gap-2 px-5">
          <Link to="/" aria-label="PipStream home"><Logo size={26} /></Link>
          <span className="flex items-center gap-1">
            <ThemeButton />
            <Link to="/signup" className="rounded-full bg-brand px-4 py-2 text-sm font-semibold text-brand-ink">Start free</Link>
          </span>
        </div>
      </header>

      <main className="mx-auto max-w-3xl px-5 pt-8 pb-20">
        <nav className="flex flex-wrap gap-2" aria-label="Legal documents">
          {(['terms', 'privacy', 'risk'] as LegalDoc[]).map((d) => (
            <NavLink key={d} to={`/${d}`}
              className={({ isActive }) => clsx('rounded-full border px-3.5 py-1.5 text-sm font-semibold', isActive ? 'border-brand bg-brand-soft text-brand' : 'border-line text-ink-2 hover:text-ink')}>
              {TITLES[d]}
            </NavLink>
          ))}
        </nav>
        <h1 className="mt-8 text-[32px] leading-tight font-bold tracking-tight md:text-[40px]">{TITLES[doc]}</h1>
        <p className="mt-2 text-sm text-ink-3">Last updated {LEGAL_UPDATED}</p>
        <div className="mt-8 space-y-8 text-[16px] leading-relaxed text-ink-2">
          {doc === 'terms' ? <Terms /> : doc === 'privacy' ? <Privacy /> : <Risk />}
        </div>
        <p className="mt-12 border-t border-line pt-6 text-sm text-ink-3">
          Questions about this page? Email <a className="font-semibold text-brand" href={`mailto:${CONTACT}`}>{CONTACT}</a>.
        </p>
      </main>
    </div>
  )
}

function S({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="space-y-3">
      <h2 className="text-[20px] font-bold tracking-tight text-ink">{title}</h2>
      {children}
    </section>
  )
}

function L({ children }: { children: ReactNode }) {
  return <ul className="list-disc space-y-1.5 pl-5">{children}</ul>
}

function RiskBox() {
  return (
    <div className="flex gap-3 rounded-2xl border border-warn/40 bg-warn-soft p-4 text-ink" role="note">
      <TriangleAlert className="mt-0.5 size-5 shrink-0 text-warn" />
      <p className="text-[15px]">
        <b>Trading forex, gold and CFDs is high risk.</b> You can lose some or all of your money. PipStream does not give financial advice.
        Only trade money you can afford to lose.
      </p>
    </div>
  )
}

/* ------------------------------------------------------------------ Terms of Service */

function Terms() {
  return (
    <>
      <p>
        These Terms of Service (the “Terms”) are an agreement between you and PipStream (“PipStream”, “we”, “us”) for your use of the website at
        pips.stream, the PipStream web app, the PipStream Expert Advisor for MetaTrader and any related service (together, the “Service”).
        By creating an account or using the Service you agree to these Terms, our <Link className="font-semibold text-brand" to="/privacy">Privacy Policy</Link> and
        our <Link className="font-semibold text-brand" to="/risk">Risk Disclosure</Link>. If you do not agree, do not use the Service.
      </p>
      <RiskBox />

      <S title="1. What PipStream does">
        <p>
          PipStream is a software tool. It reads trading signals from Telegram channels and groups that <b>you</b> choose, interprets them,
          sizes them using risk settings that <b>you</b> set, and places the resulting orders on a trading account that <b>you</b> connect
          (a practice account, a MetaTrader account or another supported broker).
        </p>
        <p>
          PipStream does not create, sell, recommend or check the quality of any signal or signal provider. We are not a broker, dealer,
          investment adviser, portfolio manager or fund, and we never hold your money. Your trading account stays with your broker.
        </p>
      </S>

      <S title="2. Not financial advice">
        <p>
          Nothing in the Service, on our website, in our emails or in our support messages is financial, investment, legal or tax advice, or a
          recommendation to buy or sell anything. You alone decide which channels to follow, which account to connect, how much to risk and whether
          to trade with real money. If you need advice, speak to a licensed professional in your country.
        </p>
      </S>

      <S title="3. Who can use PipStream">
        <L>
          <li>You must be at least 18 years old and able to enter a binding contract.</li>
          <li>You must be allowed to trade leveraged products, and to use automated trading tools, where you live. It is your responsibility to check this.</li>
          <li>You must follow your broker’s terms and Telegram’s terms. Some brokers or channels do not allow automated copying; if so, do not use PipStream with them.</li>
          <li>You must not use the Service if you are barred from doing so under any sanctions or other law.</li>
        </L>
      </S>

      <S title="4. Your account">
        <L>
          <li>Give accurate details and keep your email address up to date.</li>
          <li>Keep your password private. You are responsible for everything done with your account.</li>
          <li>Tell us straight away at {CONTACT} if you think someone else has used your account.</li>
          <li>One person per account. Do not share, sell or transfer your account.</li>
        </L>
      </S>

      <S title="5. Connecting Telegram and your broker">
        <p>
          To use PipStream you link your Telegram account and connect a trading account. You authorise us to:
        </p>
        <L>
          <li>read messages in the Telegram channels and groups you select, only to find and follow trading signals. PipStream does not post, send messages or read your private chats;</li>
          <li>use the broker login details or Expert Advisor connection you give us to place, change and close orders on your account, following your settings;</li>
          <li>keep the records needed to show you your signals, trades and alerts.</li>
        </L>
        <p>
          You can end this at any time: pause copying in the app, remove a broker, end the PipStream session in Telegram (Settings → Devices) or
          delete your account. Orders already sent to your broker may still be open when you do this; closing them is up to you.
        </p>
      </S>

      <S title="6. Your responsibilities when trading">
        <L>
          <li>Check every setting before you go live, including risk per trade, lot sizes, daily loss limits and the accounts each channel copies to.</li>
          <li>Start on practice money first, and watch the results before trading with real money.</li>
          <li>Monitor your trading account. Do not rely on PipStream as your only way to see or manage open positions.</li>
          <li>Make sure you have enough margin and understand your broker’s leverage, spreads, swaps, commissions and stop-out rules.</li>
        </L>
      </S>

      <S title="7. The Service can fail">
        <p>
          Automated copying depends on many things outside our control, including Telegram, your broker, MetaTrader, your computer or VPS,
          internet connections, data providers and the way each channel writes its signals. As a result:
        </p>
        <L>
          <li>a signal may be missed, delayed, read wrongly, copied more than once, or copied at a different price, size, stop loss or take profit than intended;</li>
          <li>edits, cancellations or follow-up messages from a channel may not be picked up;</li>
          <li>orders may be rejected, partly filled or slipped by your broker;</li>
          <li>the Service may be unavailable for maintenance, updates or outages.</li>
        </L>
        <p>
          The Service is provided “as is” and “as available”. To the fullest extent the law allows, we make no promise that it will be accurate,
          uninterrupted, error-free, profitable or fit for any particular purpose.
        </p>
      </S>

      <S title="8. Plans, trials and payments">
        <L>
          <li>Some features need a paid plan. Prices, limits and what each plan includes are shown in the app before you pay.</li>
          <li>Payments are handled by our payment provider (Paystack). We do not see or store your full card or mobile-money details.</li>
          <li>Paid plans renew for the period shown unless you cancel before the renewal date. Cancelling stops future charges; it does not refund the current period unless the law requires it.</li>
          <li>Free trials, founding-member or lifetime offers can be changed or ended for new sign-ups at any time, but we will honour what you already have.</li>
          <li>We may change prices with notice. A new price applies from your next billing period.</li>
        </L>
      </S>

      <S title="9. Acceptable use">
        <p>You must not:</p>
        <L>
          <li>break any law, or use the Service to trade for others without the licences that requires;</li>
          <li>copy channels you are not allowed to access, or resell or republish signals or other people’s content;</li>
          <li>try to access other people’s accounts or data, probe or attack our systems, or get around limits and security;</li>
          <li>reverse-engineer, copy or resell the Service, except where the law allows it;</li>
          <li>overload the Service, or use bots or scrapers on it, other than the PipStream Expert Advisor.</li>
        </L>
      </S>

      <S title="10. Limitation of liability">
        <p>
          To the fullest extent the law allows, PipStream and its owners, staff and partners are <b>not liable for any trading losses</b>, lost profits,
          missed opportunities, or indirect or consequential loss, however caused, including losses from signals you chose to follow, from how a
          signal was read or copied, from delays or outages, or from your broker or Telegram.
        </p>
        <p>
          If we are found liable to you in any other way, our total liability is limited to the amount you paid us for the Service in the
          3 months before the event that caused the claim. Nothing in these Terms limits liability that cannot be limited by law.
        </p>
      </S>

      <S title="11. Indemnity">
        <p>
          You agree to cover any claim, loss or cost (including reasonable legal fees) that we face because you broke these Terms, broke the law, or
          broke the terms of your broker, Telegram or a signal provider.
        </p>
      </S>

      <S title="12. Suspending or ending your account">
        <p>
          You can stop using PipStream and ask us to delete your account at any time. We may suspend or close your account, or pause copying, if you
          break these Terms, if we need to protect you, other users or the Service, or if the law requires it. Where it is reasonable, we will tell
          you first. Sections 2, 7, 10, 11 and 14 continue after your account ends.
        </p>
      </S>

      <S title="13. Changes to the Service and these Terms">
        <p>
          We may change, add or remove features. We may also update these Terms. If a change is significant, we will tell you by email or in the app
          before it takes effect and may ask you to accept the new version. If you keep using the Service after the change, you accept it.
        </p>
      </S>

      <S title="14. Law and disputes">
        <p>
          These Terms are governed by the laws of Kenya. If a dispute arises, please contact us first so we can try to solve it informally. If we
          cannot, the courts of Kenya will have jurisdiction, unless the law of the country you live in gives you the right to bring a claim there.
        </p>
      </S>

      <S title="15. Contact">
        <p>Email {CONTACT}.</p>
      </S>
    </>
  )
}

/* ------------------------------------------------------------------ Privacy Policy */

function Privacy() {
  return (
    <>
      <p>
        This Privacy Policy explains what personal data PipStream (“we”, “us”) collects when you visit pips.stream or use the PipStream app,
        why we use it, who we share it with and the choices you have. We keep the data we collect to what the Service needs.
      </p>

      <S title="1. Data we collect">
        <p><b>When you visit our website (before you sign up)</b></p>
        <L>
          <li>your IP address;</li>
          <li>your approximate location (country, region and city) worked out from your IP address, using Cloudflare (which sits in front of our website) or ipinfo.io;</li>
          <li>your browser and device type, the page you landed on, the site that sent you (referrer) and any campaign tags in the link (UTM tags).</li>
        </L>
        <p>If you then create an account, this visit record is linked to your account so we can see how people find PipStream.</p>

        <p className="pt-2"><b>When you create an account</b></p>
        <L>
          <li>your name and email address;</li>
          <li>your password, which we store only as a one-way hash (we cannot read it);</li>
          <li>the version of these documents you accepted, when, and the IP address you accepted from.</li>
        </L>

        <p className="pt-2"><b>When you link Telegram</b></p>
        <L>
          <li>your phone number and the Telegram login session, so PipStream can read the channels and groups you select;</li>
          <li>the names of channels and groups you follow, and the messages posted in the channels you select (used to find and follow signals).</li>
        </L>
        <p>
          The Telegram session is <b>read-only</b> in how we use it: PipStream does not post, send messages or read your private chats. The session
          is sealed (encrypted) with keys held only by the trading engine, so the website itself cannot read it. You can end it at any time in
          Telegram → Settings → Devices.
        </p>

        <p className="pt-2"><b>When you connect a broker</b></p>
        <L>
          <li>your broker name, server, account number and login details, or the key for your PipStream Expert Advisor;</li>
          <li>account information your broker or terminal reports, such as balance, equity, currency, open positions and prices.</li>
        </L>
        <p>
          Broker passwords are <b>sealed</b> (encrypted) with keys held only by the trading engine. They are never shown again, not even to you
          or our staff. Expert Advisor keys are stored only as a hash.
        </p>

        <p className="pt-2"><b>When you use the app</b></p>
        <L>
          <li>your settings (risk, copy style, limits, alerts), the signals we read, the trades we place and their results, and alerts we send you;</li>
          <li>if you link the PipStream Telegram bot for alerts, your Telegram chat id;</li>
          <li>when you last used the app, and records of important account actions for security and support;</li>
          <li>if you pay, your plan and payment status from our payment provider. We do not receive your full card or mobile-money details.</li>
        </L>

        <p className="pt-2"><b>Cookies and browser storage</b></p>
        <p>
          We use a session cookie to keep you signed in and a security (CSRF) cookie. We store small preferences in your browser, such as light or
          dark mode, and a note that this visit was already counted. We do not use advertising cookies.
        </p>
      </S>

      <S title="2. How we use your data">
        <L>
          <li>to run the Service: read your chosen channels, interpret signals, size and place trades, and show you results and alerts;</li>
          <li>to create and secure your account, prevent fraud and abuse, and keep audit records;</li>
          <li>to send you account emails, such as welcome, password reset and security notices;</li>
          <li>to process payments and manage your plan;</li>
          <li>to understand how people find and use PipStream (for example, visits by country or referrer) and to improve the Service;</li>
          <li>to meet legal obligations and to handle disputes.</li>
        </L>
        <p>
          We use your data because it is needed to provide the Service you signed up for, because we have a legitimate interest in running and
          improving a secure service, to meet legal obligations, or, where needed, with your consent. We do not sell your personal data.
        </p>
      </S>

      <S title="3. Who we share it with">
        <p>We share data only with providers that help us run the Service, and only what they need:</p>
        <L>
          <li><b>Telegram</b>, to read the channels you selected through your linked session;</li>
          <li><b>your broker</b>, and where you use it, <b>MetaApi</b> (a MetaTrader connection service), to place and manage your trades;</li>
          <li><b>Paystack</b>, to take payments;</li>
          <li><b>Resend</b>, to send emails;</li>
          <li><b>Cloudflare</b> and <b>ipinfo.io</b>, for hosting, security and working out approximate location from an IP address;</li>
          <li>our hosting and infrastructure providers, who store data on our behalf.</li>
        </L>
        <p>
          We may also share data if the law requires it, to protect our rights or users’ safety, or as part of a sale or merger of the business (in
          which case this policy will continue to apply). Some providers are outside your country; when data moves abroad we rely on their
          safeguards and contracts.
        </p>
      </S>

      <S title="4. How long we keep it">
        <L>
          <li>Account, settings, trade and alert history: while your account is open, and for a limited time after it is closed for legal, tax and dispute purposes.</li>
          <li>Telegram sessions and broker credentials: until you remove them, unlink them or delete your account.</li>
          <li>Website visit records: for as long as they are useful for understanding traffic, and then deleted or made anonymous.</li>
          <li>Payment records: as long as tax and accounting law requires.</li>
        </L>
      </S>

      <S title="5. Security">
        <p>
          We protect your data with encryption in transit (HTTPS), sealed storage of Telegram sessions and broker passwords, hashed passwords and
          keys, access limited to staff who need it, and audit logs. No system is perfectly secure; if a breach affects you, we will tell you and the
          authorities where the law requires it.
        </p>
      </S>

      <S title="6. Your rights">
        <p>Depending on where you live (for example under Kenya’s Data Protection Act or the EU/UK GDPR), you may have the right to:</p>
        <L>
          <li>see the personal data we hold about you and get a copy;</li>
          <li>correct data that is wrong;</li>
          <li>have your data deleted, or ask us to limit or stop using it;</li>
          <li>object to how we use it, or withdraw consent you gave;</li>
          <li>complain to your data protection authority.</li>
        </L>
        <p>To use these rights, email {CONTACT}. We may need to confirm it is you before we act.</p>
      </S>

      <S title="7. Children">
        <p>PipStream is for adults only. We do not knowingly collect data from anyone under 18; if we learn we have, we will delete it.</p>
      </S>

      <S title="8. Changes">
        <p>We may update this policy. If the changes are significant we will tell you by email or in the app. The date at the top shows the latest version.</p>
      </S>
    </>
  )
}

/* ------------------------------------------------------------------ Risk Disclosure */

function Risk() {
  return (
    <>
      <RiskBox />
      <p>
        Please read this Risk Disclosure carefully before you use PipStream, and especially before you connect a real-money account. It does not
        list every risk. If you are not sure you understand the risks, do not trade, and get independent advice.
      </p>

      <S title="1. Forex, gold and CFDs are high-risk, leveraged products">
        <L>
          <li>Leverage means a small price move can cause a large loss compared to the money in your account. You can lose all of it quickly.</li>
          <li>Depending on your broker and where you live, you may even owe more than your deposit.</li>
          <li>Prices can jump (gap) past your stop loss, especially around news, weekends and market opens. A stop loss does not guarantee your loss is limited.</li>
          <li>Spreads, commissions, swaps and slippage add to your costs and can turn a winning trade into a losing one.</li>
          <li>Most retail traders who trade leveraged products lose money.</li>
        </L>
      </S>

      <S title="2. Signals and signal channels">
        <L>
          <li>PipStream does not create, check or recommend signals. Channels and groups are run by third parties we do not control.</li>
          <li>Signal providers can be wrong, inexperienced, careless or dishonest. Their claimed results may be selected, edited or false.</li>
          <li>Past results, including the stats PipStream shows, do not predict future results.</li>
          <li>Following several channels can multiply your exposure to the same market at the same time.</li>
        </L>
      </S>

      <S title="3. Risks of automated copying">
        <L>
          <li>Signals are written in many styles. PipStream may read a signal wrongly, for example the wrong symbol, direction, entry, stop loss or take profit, or treat a non-signal as a signal.</li>
          <li>Trades may be delayed, missed, duplicated, or filled at a different price or size from the signal or from what other members got.</li>
          <li>Edits, cancellations, “close now” or “move SL” messages from a channel may be missed or read wrongly.</li>
          <li>Telegram, your broker, MetaTrader, the Expert Advisor, your computer or VPS, the internet or PipStream itself can fail or go offline, leaving trades open without management.</li>
          <li>Lot sizing depends on your settings and on data from your broker. Wrong settings or wrong data can lead to positions that are too large.</li>
        </L>
      </S>

      <S title="4. Practice results are not real results">
        <p>
          Practice (demo) accounts do not have real slippage, liquidity, emotions or broker rejections. Results on practice money can be much better
          than the same trades with real money.
        </p>
      </S>

      <S title="5. What you can do to reduce risk">
        <L>
          <li>Start on practice money, and go live only when you understand how a channel and PipStream behave.</li>
          <li>Risk a small percentage per trade and set daily loss limits and a maximum number of open trades.</li>
          <li>Always use a stop loss, and check your broker account yourself regularly.</li>
          <li>Never trade money you need for living costs, or money you have borrowed.</li>
          <li>Know how to pause copying in PipStream and close trades directly with your broker.</li>
        </L>
      </S>

      <S title="6. Your decision, your responsibility">
        <p>
          PipStream is a tool that does what you configure it to do. It is <b>not financial advice</b>. You are solely responsible for the channels you
          follow, the accounts you connect, your settings and every trade placed on your account. To the fullest extent the law allows, PipStream is
          not responsible for any losses. See our <Link className="font-semibold text-brand" to="/terms">Terms of Service</Link> for details.
        </p>
      </S>
    </>
  )
}
