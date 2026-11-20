#!/bin/bash
# PipStream one-click setup for MetaTrader __MT__ on a Mac. Run: curl -fsSL <link> | bash
# It puts the PipStream EA into MetaTrader, allows it to reach __URL__, and starts MetaTrader
# with PipStream on a EURUSD chart. It links itself to your PipStream account: no key, no code.
# It never reads or changes your MetaTrader login or password.
set -u
URL="__URL__"
TOKEN="__TOKEN__"
MT="__MT__"

say() { printf '\n\033[1m%s\033[0m\n' "$1"; }
fail() { printf '\n\033[31m%s\033[0m\n' "$1"; read -r -p "Press Enter to close." _ </dev/tty; exit 1; }

if [ "$MT" = "5" ]; then
  APP="/Applications/MetaTrader 5.app"
  PREFIX="$HOME/Library/Application Support/net.metaquotes.wine.metatrader5"
  EXE="terminal64.exe"; EDITOR="metaeditor64.exe"; MQL="MQL5"; EXT="ex5"; SRC="mq5"
else
  APP="/Applications/MetaTrader 4.app"
  PREFIX="$HOME/Library/Application Support/net.metaquotes.wine.metatrader4"
  EXE="terminal.exe"; EDITOR="metaeditor.exe"; MQL="MQL4"; EXT="ex4"; SRC="mq4"
fi

say "PipStream: setting up MetaTrader $MT"
[ -d "$PREFIX" ] || fail "MetaTrader $MT isn't installed here. Install it from your broker or metatrader$MT.com, open it once, log in, then run this again."

DIR=""
for d in "$PREFIX/drive_c/Program Files/"*/ "$PREFIX/drive_c/Program Files (x86)/"*/; do
  if [ -f "$d$EXE" ] && [ -d "$d$MQL" ]; then DIR="${d%/}"; break; fi
done
[ -n "$DIR" ] || fail "Couldn't find MetaTrader $MT's folder. Open MetaTrader once, then run this again."

WINE=""
for w in wine wine64 wine32on64; do
  if [ -x "$APP/Contents/SharedSupport/wine/bin/$w" ]; then WINE="$APP/Contents/SharedSupport/wine/bin/$w"; break; fi
done

if pgrep -f "$EXE" >/dev/null 2>&1; then
  say "MetaTrader $MT is open. It will close and reopen by itself (your open trades are not touched)."
  read -r -p "Press Enter to continue, or Ctrl+C to stop. " _ </dev/tty
  osascript -e "quit app \"MetaTrader $MT\"" >/dev/null 2>&1
  for _ in $(seq 1 20); do pgrep -f "$EXE" >/dev/null 2>&1 || break; sleep 1; done
  pkill -f "$EXE" >/dev/null 2>&1; sleep 1
fi

say "1/3  Installing the PipStream EA"
cd "$DIR" || fail "Can't open $DIR"
mkdir -p "$MQL/Experts" "$MQL/Files" "config"
TMP="$(mktemp -d)"
if curl -fsS "$URL/ea/PipStream.$EXT" -o "$TMP/PipStream.$EXT" && [ "$(head -c 2 "$TMP/PipStream.$EXT")" != "<!" ]; then
  cp "$TMP/PipStream.$EXT" "$MQL/Experts/PipStream.$EXT"
else
  curl -fsS "$URL/ea/PipStream.$SRC" -o "$MQL/Experts/PipStream.$SRC" || fail "Couldn't download PipStream from $URL. Check your internet and try again."
  [ -n "$WINE" ] || fail "Couldn't find MetaTrader's built-in Windows layer to build the EA."
  WINEPREFIX="$PREFIX" "$WINE" "$EDITOR" "/compile:$MQL\\Experts\\PipStream.$SRC" "/log:$MQL\\Experts\\PipStream.log" >/dev/null 2>&1
  [ -f "$MQL/Experts/PipStream.$EXT" ] || fail "Building the EA failed. Open MetaEditor, open Experts > PipStream and press Compile, then run this again."
fi
rm -rf "$TMP"
xattr -d com.apple.quarantine "$MQL/Experts/PipStream.$EXT" >/dev/null 2>&1

say "2/3  Linking it to your PipStream account"
printf '%s' "$URL" > "$MQL/Files/PipStream.url"
printf '%s' "$TOKEN" > "$MQL/Files/PipStream.pair"

say "3/3  Allowing it to reach PipStream and starting MetaTrader"
printf '%s\r\n' "[Experts]" "AllowLiveTrading=1" "Enabled=1" "AllowDllImport=0" "WebRequest=1" "WebRequestUrl=$URL" \
  "[StartUp]" "Expert=PipStream" "Symbol=EURUSD" "Period=H1" > "config/pipstream-start.ini"
printf '%s' "$URL" | pbcopy 2>/dev/null
if [ -n "$WINE" ]; then
  (WINEPREFIX="$PREFIX" nohup "$WINE" "$EXE" "/config:config\\pipstream-start.ini" >/dev/null 2>&1 &)
else
  open -a "$APP"
fi

say "Done. MetaTrader is opening with PipStream on a EURUSD chart."
cat <<MSG
Within a few seconds PipStream shows "Connected" in your browser.

If the chart says "Add $URL under ... Allow WebRequest":
  Tools > Options > Expert Advisors > tick "Allow WebRequest for listed URL",
  add $URL (it's already copied, just paste) and press OK.

If MetaTrader asks you to log in, use your broker login as usual.
MSG

read -r -p "Press Enter to close." _ </dev/tty
