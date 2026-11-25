# PipStream brand

## The mark

A **P** whose stem is a **candlestick** (forex), with a **gold coin** as the pip (crypto), on a tile that flows from deep blue to teal (the "stream").

| File | Use |
|---|---|
| `svg/mark.svg`, `png/mark-*.png` | App icon, avatar, anywhere square |
| `svg/logo.svg`, `png/logo-64.png`, `png/logo-128.png` | Mark + wordmark on light backgrounds |
| `svg/logo-dark.svg`, `png/logo-dark-*.png` | Mark + wordmark on dark backgrounds |
| `svg/logo-stacked*.svg`, `png/logo-stacked*.png` | Splash, social posts, slides |
| `svg/logo-mono.svg`, `svg/mark-mono.svg` | One colour (`currentColor`), for print or stamps |
| `svg/mark-night.svg`, `svg/mark-glyph.svg` | Navy tile or no tile, gradient P |
| `favicon.ico` (16, 32, 48), `svg/favicon.svg` | Browser tab (live copies in `public/`) |
| `png/apple-touch-icon.png` (180), `png/icon-192.png`, `png/icon-512.png`, `png/maskable-512.png` | Phone home screen and PWA (live copies in `public/icons/`) |
| `png/bot-avatar-640.png` | Telegram bot profile photo (Telegram crops it to a circle) |
| `png/bot-description-640x360.png` | Telegram "What can this bot do?" picture |

Rules: keep clear space of a quarter of the mark around it, never recolour the coin, and don't put the colour tile on a busy photo. The wordmark is Inter Display Bold, converted to outlines, so no font is needed. `build.py` regenerates every SVG.

## Theme: "Midnight market"

Forex blue for trust, a blue-to-teal stream for movement, crypto gold for premium. Tokens live in `web/src/styles.css` and switch with light and dark mode; every text colour meets WCAG AA on its surface.

| Token | Light | Dark | Used for |
|---|---|---|---|
| `--bg` | `#f5f7fb` | `#060b16` | Page |
| `--surface` | `#ffffff` | `#0d1526` | Cards |
| `--ink` / `--ink-2` / `--ink-3` | `#0b1220` / `#485267` / `#697389` | `#eef2fa` / `#a9b3c8` / `#7d88a1` | Text, strong to quiet |
| `--brand` | `#2563ff` | `#4479ff` | Buttons, links, focus |
| `--accent` | `#0a9b8a` | `#14d6a4` | The stream's teal end |
| `--up` / `--down` | `#0a8a5c` / `#d63a45` | `#2fd296` / `#ff6b74` | Profit and loss, buy and sell |
| `--gold` / `--gold-ink` | `#f5a300` / `#8a5a00` | `#ffc940` / `#ffd25a` | Premium, lifetime, founding members |
| `--violet` | `#6d4aff` | `#9d86ff` | Second chart series |

Helpers: `.bg-stream` (the blue-to-teal card behind white text, as on "Copying is on"), `.text-stream` (gradient headline text), `.bg-coin` (gold badge). Type is Inter with tabular numbers for prices (`.tabular`). Corners are generous (cards 24 to 28px, buttons fully rounded), and every screen does one thing with one main button.

## Telegram bot: @pip_stream_bot

Paste these into **@BotFather** (`/mybots` → PipStream bot → Edit Bot).

**Name** (`Edit Name`)
```
PipStream
```

**About** (`Edit About`, 106 of 120 characters; shows on the bot's profile)
```
Trade alerts, daily P&L and one-tap pause for your PipStream account. Copy Telegram signals at pips.stream
```

**Description** (`Edit Description`, 418 of 512; shows in an empty chat under "What can this bot do?")
```
Hi! I'm the official PipStream bot.

I keep you posted on your copy trading, right here in Telegram:
• A message when a trade opens, hits TP or SL, or closes
• A heads-up if MetaTrader goes offline or your plan is ending
• A short summary at the end of each day

Pause or resume copying with one tap, any time.

Tap Start, then open pips.stream to link your account. I will never ask for your password or broker login.
```

**Description picture** (`Edit Description Picture`): `png/bot-description-640x360.png`

**Profile photo** (`Edit Botpic`): `png/bot-avatar-640.png`

**Commands** (`Edit Commands`, paste as one message)
```
start - Link your PipStream account
status - Is copying on? Your broker and channels
trades - Your open trades
pnl - Today's profit and loss
pause - Stop copying new signals
resume - Start copying again
closeall - Close all open trades
help - What I can do
```

**Welcome message** (sent after /start links the account)
```
You're connected! 🎉

From now on I'll message you here when a trade opens or closes, if anything needs your attention, and with a short summary each evening.

Handy buttons:
/status – is copying on?
/trades – your open trades
/pnl – today's profit and loss
/pause – stop copying new signals

Need help? Just reply to this chat and the PipStream team will answer.
```

**Welcome message, not linked yet** (someone opens the bot without the link from the app)
```
Hi! I'm the PipStream bot. 👋

To get your trade alerts here, open the PipStream app at pips.stream and tap "Connect alerts bot". That is all it takes.

New to PipStream? We copy the Telegram signals you follow to your broker automatically. Start free on practice money at pips.stream
```

**Voice**: short sentences, everyday words ("trade opened", "pause copying"), no jargon like webhook or lot sizing unless the person used it first. Money is always shown with its currency, and real-money actions always get a confirm button.
