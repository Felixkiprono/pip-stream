"""The Windows one-line installer (resources/ea/install-windows.ps1). No PowerShell in CI, so
these check the parts that broke before or would hurt most: it must never close the customer's
PowerShell window, must start MT4 and MT5 each the way they expect, and must say clearly when
the link is for the other MetaTrader."""
import pathlib
import re

PS1 = (pathlib.Path(__file__).resolve().parents[2] / "resources/ea/install-windows.ps1").read_text()
CODE = "\n".join(l for l in PS1.splitlines() if not l.lstrip().startswith("#"))


def test_runs_in_one_script_block_and_never_exits_the_window():
    assert CODE.lstrip().startswith("& {") and CODE.rstrip().endswith("}")
    assert not re.search(r"\bexit\b", CODE), "exit inside irm | iex closes the customer's PowerShell"
    assert CODE.count("{") == CODE.count("}")


def test_placeholders_the_server_fills_in():
    for p in ("__URL__", "__TOKEN__", "__MT__"):
        assert p in CODE


def test_mt5_and_mt4_startup_files():
    assert "'[StartUp]', 'Expert=PipStream', \"Symbol=$sym\"" in CODE
    assert '$startArgs = "/config:`"$ini`""' in CODE                     # MT5: /config:<file>
    mt4 = next(l for l in CODE.splitlines() if "'ExpertsEnable=true'" in l)
    assert "[" not in mt4.split("=", 1)[1]                               # MT4: no [sections]
    assert '$startArgs = "`"$ini`""' in CODE                              # MT4: just the path


def test_wrong_metatrader_and_missing_metatrader_are_explained():
    assert "but this setup link is for MetaTrader $MT" in CODE
    assert "Couldn't find MetaTrader $MT on this computer" in CODE


def test_build_waits_for_metaeditor_and_checks_the_result():
    assert "-Wait" in CODE and "LastWriteTime -gt $before" in CODE
    assert "$args" not in CODE.replace("$startArgs", "").replace("$edArgs", "")  # $args is reserved in PowerShell
