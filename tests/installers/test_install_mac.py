"""The Mac one-line installer (resources/ea/install-mac.sh), run for real against fake Macs.

Each test builds a home folder that looks like a Mac with MetaTrader in a Wine prefix (the
MetaQuotes app, a CrossOver bottle, MT4 with an AppData data folder, ...), a fake Wine that
"compiles" by writing the .ex file and records how MetaTrader was started, and a tiny web
server standing in for PipStream. The script is fed through a pipe, exactly like
`curl -fsSL <link> | bash`.

Run: pytest -q tests/installers
"""
from __future__ import annotations

import http.server
import os
import pathlib
import stat
import subprocess
import threading
import time

import pytest

ROOT = pathlib.Path(__file__).resolve().parents[2]
SCRIPT = (ROOT / "resources/ea/install-mac.sh").read_text()
TOKEN = "pst_" + "A" * 40

FAKE_WINE = r"""#!/bin/bash
# fake Wine: logs each call; "compiles" by writing the .ex file next to the source
echo "WINEPREFIX=$WINEPREFIX ARGS=$*" >> "$PS_TEST_LOG"
for a in "$@"; do
  case "$a" in
    /compile:*)
      p="${a#/compile:}"; p="${p#C:\\}"; p="$WINEPREFIX/drive_c/$(printf '%s' "$p" | tr '\\' '/')"
      [ -n "${PS_TEST_BREAK_BUILD:-}" ] && exit 1
      printf 'built' > "${p%.*}.ex${p##*.mq}" ;;
  esac
done
"""


class Server(http.server.ThreadingHTTPServer):
    prebuilt: bool = False


class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def do_GET(self):
        name = self.path.rsplit("/", 1)[-1]
        if name in ("PipStream.mq4", "PipStream.mq5"):
            body = f"// PipStream EA source {name}\n".encode()
        elif name in ("PipStream.ex4", "PipStream.ex5") and self.server.prebuilt:
            body = b"\x00prebuilt"
        else:
            self.send_response(404)
            self.end_headers()
            return
        self.send_response(200)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


@pytest.fixture
def server():
    s = Server(("127.0.0.1", 0), Handler)
    threading.Thread(target=s.serve_forever, daemon=True).start()
    yield s
    s.shutdown()


def exe(path: pathlib.Path, body: str = "#!/bin/bash\nexit 0\n") -> pathlib.Path:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(body)
    path.chmod(path.stat().st_mode | stat.S_IEXEC)
    return path


class Mac:
    def __init__(self, tmp: pathlib.Path):
        self.home = tmp / "home"
        self.apps = tmp / "Applications"
        self.bin = tmp / "bin"
        self.log = tmp / "wine.log"
        self.home.mkdir()
        self.apps.mkdir()
        self.log.touch()
        for tool in ("pgrep", "pkill", "osascript", "pbcopy", "xattr", "open"):
            exe(self.bin / tool, "#!/bin/bash\nexit 1\n" if tool == "pgrep" else "#!/bin/bash\nexit 0\n")

    def prefix(self, name: str = "net.metaquotes.wine.metatrader5", crossover: bool = False) -> pathlib.Path:
        base = self.home / "Library/Application Support"
        p = base / "CrossOver/Bottles" / name if crossover else base / name
        (p / "drive_c/users/me").mkdir(parents=True)
        return p

    def terminal(self, prefix: pathlib.Path, mt: int, folder: str | None = None, portable: bool = True) -> pathlib.Path:
        prog = prefix / "drive_c/Program Files" / (folder or f"MetaTrader {mt}")
        exe(prog / ("terminal64.exe" if mt == 5 else "terminal.exe"))
        exe(prog / ("metaeditor64.exe" if mt == 5 else "metaeditor.exe"))
        if portable:
            (prog / f"MQL{mt}/Include").mkdir(parents=True)
        return prog

    def app(self, name: str = "MetaTrader 5.app") -> pathlib.Path:
        return exe(self.apps / name / "Contents/SharedSupport/wine/bin/wine64", FAKE_WINE)

    def run(self, server, mt: int, **env) -> subprocess.CompletedProcess:
        url = f"http://127.0.0.1:{server.server_address[1]}"
        script = SCRIPT.replace("__URL__", url).replace("__TOKEN__", TOKEN).replace("__MT__", str(mt))
        e = {"PATH": f"{self.bin}:/usr/bin:/bin", "HOME": str(self.home), "PS_HOME": str(self.home),
             "PS_APPS": str(self.apps), "PS_NONINTERACTIVE": "1", "PS_BUILD_WAIT": "8", "PS_TEST_LOG": str(self.log), **env}
        # fed through a pipe, like curl | bash
        return subprocess.run(["bash"], input=script, text=True, capture_output=True, env=e, timeout=120)

    def launched(self, timeout: float = 10) -> str:
        """The command line MetaTrader was started with (the launch runs in the background)."""
        end = time.time() + timeout
        while time.time() < end:
            lines = [l for l in self.log.read_text().splitlines() if "/compile:" not in l]
            if lines:
                return lines[-1]
            time.sleep(0.1)
        return ""


@pytest.fixture
def mac(tmp_path):
    return Mac(tmp_path)


def test_mt5_metaquotes_app_builds_links_and_starts_on_the_brokers_symbol(mac, server):
    pf = mac.prefix()
    prog = mac.terminal(pf, 5)
    (prog / "bases/Exness-MT5Trial8/history/EURUSDm").mkdir(parents=True)     # Exness names it EURUSDm
    (prog / "bases/Exness-MT5Trial8/history/EURUSDm_raw").mkdir(parents=True)
    mac.app()

    r = mac.run(server, 5)
    assert r.returncode == 0, r.stdout + r.stderr
    mql = prog / "MQL5"
    assert (mql / "Experts/PipStream.mq5").read_text().startswith("// PipStream EA source")
    assert (mql / "Experts/PipStream.ex5").read_text() == "built"
    assert (mql / "Files/PipStream.url").read_text() == f"http://127.0.0.1:{server.server_address[1]}"
    assert (mql / "Files/PipStream.pair").read_text() == TOKEN
    compile_call = next(l for l in mac.log.read_text().splitlines() if "/compile:" in l)
    assert r"C:\Program Files\MetaTrader 5\metaeditor64.exe" in compile_call
    assert r"/inc:C:\Program Files\MetaTrader 5\MQL5" in compile_call

    ini = (prog / "config/pipstream-start.ini").read_bytes().decode()
    assert "[StartUp]\r\nExpert=PipStream\r\nSymbol=EURUSDm\r\nPeriod=H1" in ini
    assert "AllowLiveTrading=1" in ini
    launch = mac.launched()
    assert r"C:\Program Files\MetaTrader 5\terminal64.exe /config:C:\Program Files\MetaTrader 5\config\pipstream-start.ini" in launch
    assert f"WINEPREFIX={pf}" in launch
    assert "on a EURUSDm chart" in r.stdout


def test_mt4_with_an_appdata_data_folder_gets_the_ea_there_and_mt4_startup_format(mac, server):
    pf = mac.prefix("net.metaquotes.wine.metatrader4")
    prog = mac.terminal(pf, 4, portable=False)            # program folder has no MQL4 of its own
    data = pf / "drive_c/users/me/AppData/Roaming/MetaQuotes/Terminal/9D15457EC01AD10E06A932AAC616DC32"
    (data / "MQL4").mkdir(parents=True)
    (data / "origin.txt").write_bytes("C:\\Program Files\\MetaTrader 4".encode("utf-16"))
    other = pf / "drive_c/users/me/AppData/Roaming/MetaQuotes/Terminal/0000"   # another terminal's data
    (other / "MQL4").mkdir(parents=True)
    (other / "origin.txt").write_bytes("C:\\Program Files\\Other MT4".encode("utf-16"))
    (data / "history/Exness-Trial7").mkdir(parents=True)
    for f in ("EURUSDm60.hst", "EURUSDm1440.hst", "EURUSDm_x5.hst"):
        (data / "history/Exness-Trial7" / f).write_text("")
    mac.app("MetaTrader 4.app")

    r = mac.run(server, 4)
    assert r.returncode == 0, r.stdout + r.stderr
    assert (data / "MQL4/Experts/PipStream.ex4").exists()
    assert (data / "MQL4/Files/PipStream.pair").read_text() == TOKEN
    assert not (other / "MQL4/Experts").exists()           # never touches another terminal
    ini = (prog / "config/pipstream-start.ini").read_bytes().decode()
    assert "[" not in ini                                    # MT4: no sections
    assert "ExpertsEnable=true\r\n" in ini and "Expert=PipStream\r\nSymbol=EURUSDm\r\n" in ini
    launch = mac.launched()
    assert r"C:\Program Files\MetaTrader 4\terminal.exe C:\Program Files\MetaTrader 4\config\pipstream-start.ini" in launch
    assert "/config:" not in launch


def test_link_for_mt4_on_a_mac_with_only_mt5_says_so(mac, server):
    mac.terminal(mac.prefix(), 5)
    mac.app()
    r = mac.run(server, 4)
    assert r.returncode == 1
    assert "This Mac has MetaTrader 5, but this setup link is for MetaTrader 4" in r.stdout
    assert "choose MetaTrader 5" in r.stdout


def test_no_metatrader_at_all_explains_what_to_install(mac, server):
    r = mac.run(server, 5)
    assert r.returncode == 1
    assert "Couldn't find MetaTrader 5 on this Mac" in r.stdout
    assert "CrossOver or Wine" in r.stdout


def test_crossover_bottle_uses_crossovers_wine_with_the_bottle(mac, server):
    pf = mac.prefix("Exness MT5", crossover=True)
    prog = mac.terminal(pf, 5, folder="Exness MetaTrader 5")
    exe(mac.apps / "CrossOver.app/Contents/SharedSupport/CrossOver/bin/wine", FAKE_WINE)

    r = mac.run(server, 5)
    assert r.returncode == 0, r.stdout + r.stderr
    assert (prog / "MQL5/Experts/PipStream.ex5").exists()
    launch = mac.launched()
    assert r"--bottle Exness MT5 C:\Program Files\Exness MetaTrader 5\terminal64.exe" in launch
    assert "Symbol=EURUSD\r\n" in (prog / "config/pipstream-start.ini").read_bytes().decode()   # nothing better known


def test_broker_branded_app_and_two_terminals_takes_the_first_without_asking(mac, server):
    pf = mac.prefix()
    a = mac.terminal(pf, 5, folder="MetaTrader 5")
    b = mac.terminal(pf, 5, folder="FXPro MetaTrader 5")
    mac.app("FxPro MetaTrader 5.app")
    r = mac.run(server, 5)
    assert r.returncode == 0, r.stdout + r.stderr
    assert "more than one MetaTrader 5" in r.stdout
    assert sum((p / "MQL5/Experts/PipStream.ex5").exists() for p in (a, b)) == 1


def test_prebuilt_ea_needs_no_wine_and_no_build(mac, server):
    server.prebuilt = True
    prog = mac.terminal(mac.prefix(), 5)
    r = mac.run(server, 5)                                  # no app, no Wine anywhere
    assert r.returncode == 0, r.stdout + r.stderr
    assert (prog / "MQL5/Experts/PipStream.ex5").read_bytes() == b"\x00prebuilt"
    assert "Open MetaTrader 5 yourself now" in r.stdout


def test_no_wine_and_no_prebuilt_ea_says_how_to_compile_by_hand(mac, server):
    mac.terminal(mac.prefix(), 5)
    r = mac.run(server, 5)
    assert r.returncode == 1
    assert "can't be built here" in r.stdout and "press Compile" in r.stdout


def test_failed_build_is_reported_not_hidden(mac, server):
    mac.terminal(mac.prefix(), 5)
    mac.app()
    r = mac.run(server, 5, PS_TEST_BREAK_BUILD="1")
    assert r.returncode == 1
    assert "Building the EA failed" in r.stdout


def test_server_down_is_a_clear_error(mac, server):
    mac.terminal(mac.prefix(), 5)
    mac.app()
    server.shutdown()
    server.server_close()
    r = mac.run(server, 5)
    assert r.returncode == 1
    assert "Couldn't download PipStream" in r.stdout


@pytest.mark.parametrize("mt", [4, 5])
def test_script_is_valid_bash(mt):
    s = SCRIPT.replace("__URL__", "https://pips.stream").replace("__TOKEN__", TOKEN).replace("__MT__", str(mt))
    assert subprocess.run(["bash", "-n"], input=s, text=True).returncode == 0
