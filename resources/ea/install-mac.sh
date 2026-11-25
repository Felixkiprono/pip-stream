#!/bin/bash
# PipStream one-click setup for MetaTrader __MT__ on a Mac. Run: curl -fsSL <link> | bash
# It puts the PipStream EA into MetaTrader, allows it to reach __URL__, and starts MetaTrader
# with PipStream on a chart. It links itself to your PipStream account: no key, no code.
# It never reads or changes your MetaTrader login or password.
#
# Works with MetaQuotes' own MetaTrader app, broker-branded copies of it, CrossOver bottles,
# PlayOnMac and plain Wine. The whole script sits in main() so bash reads all of it before
# running anything: nothing it starts can swallow the rest of the script from the pipe.
#
# For support and tests (all optional): PS_HOME (instead of $HOME), PS_APPS (app folders,
# colon separated), PS_PREFIX (a Wine prefix to use), PS_SYMBOL (chart symbol), PS_BUILD_WAIT (seconds),
# PS_NONINTERACTIVE=1 (never wait for Enter, take the first MetaTrader found).

main() {
set -u
shopt -s nullglob nocaseglob nocasematch
URL="__URL__"
TOKEN="__TOKEN__"
MT="__MT__"
H="${PS_HOME:-$HOME}"
APPS="${PS_APPS:-/Applications:$H/Applications}"

say() { printf '\n\033[1m%s\033[0m\n' "$1"; }
note() { printf '   %s\n' "$1"; }
ask() {  # ask "question" default -> $REPLY (the default when nobody can answer)
  REPLY="${2:-}"
  if [ -z "${PS_NONINTERACTIVE:-}" ] && { : </dev/tty; } 2>/dev/null; then
    read -r -p "$1" REPLY </dev/tty || REPLY="${2:-}"
    [ -n "$REPLY" ] || REPLY="${2:-}"
  fi
}
fail() {
  printf '\n\033[31m%s\033[0m\n' "$1"
  [ -n "${2:-}" ] && printf '%s\n' "$2"
  printf '\nNothing in MetaTrader was changed by this step. Need help? Reply to any PipStream email with this message.\n'
  ask "Press Enter to close. " ""
  exit 1
}
# C:\ path of a file inside a Wine prefix (MetaTrader only understands Windows paths)
winpath() { local p="${1#"$2"/drive_c/}"; printf 'C:\\%s' "$(printf '%s' "$p" | tr '/' '\\')"; }
lower() { printf '%s' "$1" | tr '[:upper:]' '[:lower:]'; }
# origin.txt (UTF-16) names the program folder a data folder belongs to
origin() { iconv -f UTF-16 -t UTF-8 "$1" 2>/dev/null | tr -d '\r\n\000' | sed -e 's/[[:space:]]*$//' -e 's#\\*$##'; }

if [ "$MT" = "5" ]; then
  EXE="terminal64.exe"; EDITOR="metaeditor64.exe"; MQL="MQL5"; EXT="ex5"; SRC="mq5"; OTHER="4"; OTHER_EXE="terminal.exe"
elif [ "$MT" = "4" ]; then
  EXE="terminal.exe"; EDITOR="metaeditor.exe"; MQL="MQL4"; EXT="ex4"; SRC="mq4"; OTHER="5"; OTHER_EXE="terminal64.exe"
else
  fail "This setup link is broken (no MetaTrader version in it). Get a new one in PipStream > Brokers."
fi

say "PipStream: setting up MetaTrader $MT"

# ---- 1. find MetaTrader: every Wine prefix, every program folder with the terminal in it
PREFIXES=()
add_prefix() { [ -d "${1%/}/drive_c" ] && PREFIXES+=("${1%/}"); }
[ -n "${PS_PREFIX:-}" ] && add_prefix "$PS_PREFIX"
for p in "$H/Library/Application Support/net.metaquotes.wine.metatrader$MT" \
         "$H/Library/Application Support"/*/ \
         "$H/Library/Application Support/CrossOver/Bottles"/*/ \
         "$H/Library/PlayOnMac/wineprefix"/*/ \
         "$H/.wine"; do
  p="${p%/}"
  case " ${PREFIXES[*]-} " in *" $p "*) continue ;; esac   # already listed
  add_prefix "$p"
done

installs() {  # installs <exe> -> lines "prefix|program folder"
  local pf d
  for pf in "${PREFIXES[@]-}"; do
    [ -n "$pf" ] || continue
    for d in "$pf/drive_c/Program Files"/*/ "$pf/drive_c/Program Files (x86)"/*/; do
      [ -f "$d$1" ] && printf '%s|%s\n' "$pf" "${d%/}"
    done
  done
}
FOUND=()
while IFS= read -r l; do [ -n "$l" ] && FOUND+=("$l"); done < <(installs "$EXE")

if [ ${#FOUND[@]} -eq 0 ]; then
  if [ -n "$(installs "$OTHER_EXE")" ]; then
    fail "This Mac has MetaTrader $OTHER, but this setup link is for MetaTrader $MT." \
      "In PipStream > Brokers, choose MetaTrader $OTHER, copy the new command and run that one instead."
  fi
  fail "Couldn't find MetaTrader $MT on this Mac." \
"Install MetaTrader $MT from your broker's website (or metatrader$MT.com), open it once and log in, then run this again.
If you run MetaTrader in CrossOver or Wine, open it there once first.
Looked in: ${PREFIXES[*]:-no Wine or MetaTrader folders found under $H/Library/Application Support}"
fi

PICK="${FOUND[0]}"
if [ ${#FOUND[@]} -gt 1 ]; then
  say "You have more than one MetaTrader $MT. Which one should PipStream use?"
  for i in "${!FOUND[@]}"; do note "$((i + 1)). ${FOUND[$i]#*|}"; done
  ask "Number (Enter for 1): " "1"
  case "$REPLY" in ''|*[!0-9]*) fail "\"$REPLY\" isn't one of the numbers above." ;; esac
  [ "$REPLY" -ge 1 ] && [ "$REPLY" -le ${#FOUND[@]} ] || fail "\"$REPLY\" isn't one of the numbers above."
  PICK="${FOUND[$((REPLY - 1))]}"
fi
PREFIX="${PICK%%|*}"
PROG="${PICK#*|}"
PROG_WIN="$(winpath "$PROG" "$PREFIX")"
note "MetaTrader $MT: $PROG"

# ---- 2. its data folders: under Wine usually the program folder itself, sometimes
#         users/<you>/AppData/Roaming/MetaQuotes/Terminal/<id> (origin.txt names the program folder)
TARGETS=()
want="$(lower "$PROG_WIN")"
for o in "$PREFIX/drive_c/users"/*/"AppData/Roaming/MetaQuotes/Terminal"/*/origin.txt \
         "$PREFIX/drive_c/users"/*/"Application Data/MetaQuotes/Terminal"/*/origin.txt; do
  [ "$(lower "$(origin "$o")")" = "$want" ] && TARGETS+=("$(dirname "$o")")
done
if [ -d "$PROG/$MQL" ] || [ ${#TARGETS[@]} -eq 0 ]; then TARGETS=("$PROG" "${TARGETS[@]-}"); fi
CLEAN=(); for t in "${TARGETS[@]}"; do [ -n "$t" ] && CLEAN+=("$t"); done; TARGETS=("${CLEAN[@]}")

# ---- 3. the Windows layer that runs it: CrossOver, the MetaTrader app's own Wine, or Wine
WINE=""; WINE_ARGS=(); APP=""
IFS=':' read -r -a APP_DIRS <<< "$APPS"
case "$PREFIX" in
  */CrossOver/Bottles/*)
    for a in "${APP_DIRS[@]}"; do
      w="$a/CrossOver.app/Contents/SharedSupport/CrossOver/bin/wine"
      if [ -x "$w" ]; then WINE="$w"; WINE_ARGS=(--bottle "$(basename "$PREFIX")"); APP="$a/CrossOver.app"; break; fi
    done ;;
esac
if [ -z "$WINE" ]; then
  # MetaTrader 5.app / MetaTrader 4.app or a broker's copy ("Exness MetaTrader 5.app"); the
  # one named after this version first
  best=""; any=""
  for a in "${APP_DIRS[@]}"; do
    for app in "$a"/*.app; do
      case "$(basename "$app")" in *metatrader*|*mt4*|*mt5*|*trader*) ;; *) continue ;; esac
      for w in "$app/Contents/SharedSupport/wine/bin/wine64" "$app/Contents/SharedSupport/wine/bin/wine" \
               "$app/Contents/SharedSupport/wine/bin/wine32on64" "$app"/Contents/SharedSupport/*/wine/bin/wine64 \
               "$app"/Contents/SharedSupport/*/bin/wine64 "$app"/Contents/SharedSupport/*/bin/wine \
               "$app"/Contents/Resources/*/bin/wine64 "$app"/Contents/Resources/*/bin/wine; do
        [ -x "$w" ] || continue
        case "$(basename "$app")" in
          *" $MT.app"|*"mt$MT"*|*"metatrader$MT"*|*"metatrader $MT"*) [ -n "$best" ] || best="$app|$w" ;;
          *) [ -n "$any" ] || any="$app|$w" ;;
        esac
        break
      done
    done
  done
  hit="${best:-$any}"
  if [ -n "$hit" ]; then APP="${hit%%|*}"; WINE="${hit#*|}"; fi
fi
if [ -z "$WINE" ]; then
  for w in wine64 wine; do
    if command -v "$w" >/dev/null 2>&1; then WINE="$(command -v "$w")"; break; fi
  done
fi
run_win() { WINEPREFIX="$PREFIX" WINEDEBUG=-all "$WINE" ${WINE_ARGS[@]+"${WINE_ARGS[@]}"} "$@" </dev/null; }

# ---- 4. close MetaTrader if it is open: it only picks up a new EA and startup file when it starts
PAT="(^|[/\\\\ ])$(printf '%s' "$EXE" | sed 's/\./\\./g')"   # (^|[/\ ])terminal64\.exe
running() { command -v pgrep >/dev/null 2>&1 && pgrep -f "$PAT" >/dev/null 2>&1; }
if running; then
  say "MetaTrader $MT is open. It will close and reopen by itself (your open trades are not touched)."
  ask "Press Enter to continue, or Ctrl+C to stop. " ""
  [ -n "$APP" ] && command -v osascript >/dev/null 2>&1 && osascript -e "quit app \"$(basename "$APP" .app)\"" </dev/null >/dev/null 2>&1
  for _ in $(seq 1 20); do running || break; sleep 1; done
  pkill -f "$PAT" >/dev/null 2>&1; sleep 1
fi

# ---- 5. the EA itself: a ready-built one if the server has it, else build it from source here
say "1/3  Installing the PipStream EA"
TMP="$(mktemp -d)"
EX_OK=""
if curl -fsS "$URL/ea/PipStream.$EXT" -o "$TMP/PipStream.$EXT" 2>/dev/null && [ -s "$TMP/PipStream.$EXT" ] \
   && [ "$(head -c 2 "$TMP/PipStream.$EXT")" != "<!" ]; then
  EX_OK="$TMP/PipStream.$EXT"
fi
curl -fsS "$URL/ea/PipStream.$SRC" -o "$TMP/PipStream.$SRC" 2>/dev/null && grep -q "PipStream" "$TMP/PipStream.$SRC" \
  || fail "Couldn't download PipStream from $URL." "Check your internet connection and try again."
for t in "${TARGETS[@]}"; do
  mkdir -p "$t/$MQL/Experts" "$t/$MQL/Files" || fail "Can't write to $t." "Make sure MetaTrader is installed for your user, then try again."
  cp "$TMP/PipStream.$SRC" "$t/$MQL/Experts/PipStream.$SRC"
done
if [ -z "$EX_OK" ]; then
  [ -n "$WINE" ] || fail "Couldn't find the Windows layer MetaTrader $MT runs on, so the EA can't be built here." \
    "Open MetaTrader, press F4 (MetaEditor), open Experts > PipStream.$SRC, press Compile, then run this again."
  # build where the standard library is (MT5 needs Include/Trade/Trade.mqh)
  BUILD="${TARGETS[0]}"
  for t in "${TARGETS[@]}"; do [ -d "$t/$MQL/Include" ] && { BUILD="$t"; break; }; done
  [ -f "$PROG/$EDITOR" ] || fail "MetaEditor ($EDITOR) is missing from $PROG, so the EA can't be built." \
    "Reinstall MetaTrader $MT from your broker, open it once, then run this again."
  note "Building it with MetaEditor (takes up to a minute)..."
  MARK="$TMP/mark"; touch "$MARK"; sleep 1
  SRC_WIN="$(winpath "$BUILD/$MQL/Experts/PipStream.$SRC" "$PREFIX")"
  INC=(); [ -d "$BUILD/$MQL/Include" ] && INC=("/inc:$(winpath "$BUILD/$MQL" "$PREFIX")")
  (cd "$PROG" && run_win "$PROG_WIN\\$EDITOR" "/compile:$SRC_WIN" ${INC[@]+"${INC[@]}"} "/log" >/dev/null 2>&1) &
  for _ in $(seq 1 "${PS_BUILD_WAIT:-90}"); do
    [ -f "$BUILD/$MQL/Experts/PipStream.$EXT" ] && [ "$BUILD/$MQL/Experts/PipStream.$EXT" -nt "$MARK" ] && break
    sleep 1
  done
  if ! { [ -f "$BUILD/$MQL/Experts/PipStream.$EXT" ] && [ "$BUILD/$MQL/Experts/PipStream.$EXT" -nt "$MARK" ]; }; then
    why=""
    [ -f "$BUILD/$MQL/Experts/PipStream.log" ] && why="$(iconv -f UTF-16 -t UTF-8 "$BUILD/$MQL/Experts/PipStream.log" 2>/dev/null | grep -i "error" | head -3)"
    fail "Building the EA failed${why:+: $why}" \
      "Open MetaTrader, press F4 (MetaEditor), open Experts > PipStream.$SRC, press Compile, then run this again."
  fi
  EX_OK="$BUILD/$MQL/Experts/PipStream.$EXT"
fi
for t in "${TARGETS[@]}"; do
  [ "$EX_OK" -ef "$t/$MQL/Experts/PipStream.$EXT" ] || cp "$EX_OK" "$t/$MQL/Experts/PipStream.$EXT"
  xattr -d com.apple.quarantine "$t/$MQL/Experts/PipStream.$EXT" >/dev/null 2>&1
done

# ---- 6. the server address and a one-time token: the EA trades it for its key on first contact
say "2/3  Linking it to your PipStream account"
for t in "${TARGETS[@]}"; do
  printf '%s' "$URL" > "$t/$MQL/Files/PipStream.url"
  printf '%s' "$TOKEN" > "$t/$MQL/Files/PipStream.pair"
done

# ---- 7. a chart on a symbol this broker has (Exness: EURUSDm), then start MetaTrader with it
SYM="${PS_SYMBOL:-}"
if [ -z "$SYM" ]; then
  cand() { local n="$1"; [ ${#n} -le 10 ] || return; if [ -z "$SYM" ] || [ ${#n} -lt ${#SYM} ]; then SYM="$n"; fi; }
  for t in "${TARGETS[@]}"; do
    for d in "$t/bases"/*/history/EURUSD*/ "$t/bases"/*/ticks/EURUSD*/; do d="${d%/}"; cand "${d##*/}"; done   # MT5
    for f in "$t/history"/*/EURUSD*.hst; do                                                                    # MT4
      n="${f##*/}"; n="${n%.hst}"; cand "$(printf '%s' "$n" | sed -E 's/(1|5|15|30|60|240|1440|10080|43200)$//')"
    done
  done
  SYM="${SYM:-EURUSD}"
fi

say "3/3  Starting MetaTrader with PipStream on a $SYM chart"
mkdir -p "$PROG/config"
INI="$PROG/config/pipstream-start.ini"
if [ "$MT" = "5" ]; then
  printf '%s\r\n' "[Experts]" "AllowLiveTrading=1" "Enabled=1" "AllowDllImport=0" "Account=0" "Profile=0" \
    "WebRequest=1" "WebRequestUrl=$URL" "[StartUp]" "Expert=PipStream" "Symbol=$SYM" "Period=H1" > "$INI"
  ARGS=("/config:$(winpath "$INI" "$PREFIX")")
else
  # MT4's startup file has no sections, and takes its path without /config:
  printf '%s\r\n' "ExpertsEnable=true" "ExpertsDllImport=false" "ExpertsExpImport=false" "ExpertsTrades=true" \
    "Expert=PipStream" "Symbol=$SYM" "Period=H1" > "$INI"
  ARGS=("$(winpath "$INI" "$PREFIX")")
fi
command -v pbcopy >/dev/null 2>&1 && printf '%s' "$URL" | pbcopy 2>/dev/null
if [ -n "$WINE" ]; then
  (cd "$PROG" && WINEPREFIX="$PREFIX" WINEDEBUG=-all nohup "$WINE" ${WINE_ARGS[@]+"${WINE_ARGS[@]}"} "$PROG_WIN\\$EXE" "${ARGS[@]}" </dev/null >/dev/null 2>&1 &)
elif [ -n "$APP" ]; then
  open -a "$APP"
else
  note "Open MetaTrader $MT yourself now, then drag PipStream from Navigator > Expert Advisors onto any chart."
fi
# MetaTrader reads the startup file once; left behind, an auto-update restart would reuse it
(sleep 45; rm -f "$INI") </dev/null >/dev/null 2>&1 &
rm -rf "$TMP"

say "Done. MetaTrader $MT is opening with PipStream on a $SYM chart."
for t in "${TARGETS[@]}"; do note "EA installed in: $t/$MQL/Experts"; done
cat <<MSG

If MetaTrader asks you to log in, use your broker login as usual.
One thing MetaTrader may still ask you to do yourself (first time only):
  Tools > Options > Expert Advisors > tick "Allow algorithmic trading" and
  "Allow WebRequest for listed URL", add $URL (already copied: just paste) and press OK.

The chart's top-left corner then says "PipStream ... Connected", and PipStream shows
"Connected" in your browser within a few seconds. On a real-money account PipStream
asks you to confirm in the app before it opens any trade.
MSG

ask "Press Enter to close. " ""
}

main "$@"
