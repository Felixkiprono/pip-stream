# PipStream one-click setup for MetaTrader __MT__ on Windows. Run in PowerShell: irm <link> | iex
# It puts the PipStream EA into MetaTrader, allows it to reach __URL__, and starts MetaTrader
# with PipStream on a chart. It links itself to your PipStream account: no key, no code.
# It never reads or changes your MetaTrader login or password.
#
# Everything runs inside one script block, so a problem never closes your PowerShell window
# and no setting is left behind in your session.
& {
$ErrorActionPreference = 'Stop'
$Url = '__URL__'; $Token = '__TOKEN__'; $MT = '__MT__'
if ($MT -eq '5') { $Exe = 'terminal64.exe'; $Editor = 'metaeditor64.exe'; $Mql = 'MQL5'; $Ext = 'ex5'; $Src = 'mq5'; $Other = '4'; $OtherExe = 'terminal.exe' }
else { $Exe = 'terminal.exe'; $Editor = 'metaeditor.exe'; $Mql = 'MQL4'; $Ext = 'ex4'; $Src = 'mq4'; $Other = '5'; $OtherExe = 'terminal64.exe' }
function Say($m) { Write-Host "`n$m" -ForegroundColor Cyan }
function Note($m) { Write-Host "   $m" }
function Fail($m, $how = '') {
  Write-Host "`n$m" -ForegroundColor Red
  if ($how) { Write-Host $how }
  Read-Host 'Press Enter to close' | Out-Null
  throw 'PipStream setup stopped'
}
try {
  # older Windows PowerShell speaks TLS 1.0 by default
  try { [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12 } catch {}
  $ProgressPreference = 'SilentlyContinue'

  Say "PipStream: setting up MetaTrader $MT"
  # Program folders: from each data folder's origin.txt, plus the usual install places (portable installs)
  function Find-Installs($exeName) {
    $found = [ordered]@{}
    Get-ChildItem "$env:APPDATA\MetaQuotes\Terminal" -Directory -ErrorAction SilentlyContinue | ForEach-Object {
      $origin = Join-Path $_.FullName 'origin.txt'
      if (Test-Path $origin) {
        $prog = (Get-Content $origin -Raw -Encoding Unicode).Trim().TrimEnd('\')
        if ((Test-Path (Join-Path $prog $exeName)) -and -not $found.Contains($prog.ToLower())) {
          $found[$prog.ToLower()] = [pscustomobject]@{ Prog = $prog; Data = @() }
        }
        if ($found.Contains($prog.ToLower())) { $found[$prog.ToLower()].Data += $_.FullName }
      }
    }
    foreach ($root in @($env:ProgramFiles, ${env:ProgramFiles(x86)}, "$env:LOCALAPPDATA\Programs") | Where-Object { $_ }) {
      Get-ChildItem $root -Directory -ErrorAction SilentlyContinue | ForEach-Object {
        if ((Test-Path (Join-Path $_.FullName $exeName)) -and -not $found.Contains($_.FullName.ToLower())) {
          $found[$_.FullName.ToLower()] = [pscustomobject]@{ Prog = $_.FullName; Data = @() }
        }
      }
    }
    return @($found.Values)
  }
  $installs = Find-Installs $Exe
  if ($installs.Count -eq 0) {
    if ((Find-Installs $OtherExe).Count -gt 0) {
      Fail "This computer has MetaTrader $Other, but this setup link is for MetaTrader $MT." "In PipStream > Brokers, choose MetaTrader $Other, copy the new command and run that one instead."
    }
    Fail "Couldn't find MetaTrader $MT on this computer." "Install it from your broker's website, open it once and log in, then run this again."
  }
  $t = $installs[0]
  if ($installs.Count -gt 1) {
    Say "You have more than one MetaTrader $MT. Which one should PipStream use?"
    for ($i = 0; $i -lt $installs.Count; $i++) { Write-Host "  $($i + 1). $($installs[$i].Prog)" }
    $answer = Read-Host 'Number (Enter for 1)'
    if (-not $answer) { $answer = '1' }
    $n = 0
    if (-not [int]::TryParse($answer, [ref]$n) -or $n -lt 1 -or $n -gt $installs.Count) { Fail "'$answer' isn't one of the numbers above." }
    $t = $installs[$n - 1]
  }
  Note "MetaTrader ${MT}: $($t.Prog)"
  # Where MetaTrader keeps its files: the AppData data folder(s), or the program folder when portable
  $targets = @($t.Data)
  if ((Test-Path (Join-Path $t.Prog $Mql)) -or $targets.Count -eq 0) { $targets = @($t.Prog) + $targets }

  $running = Get-Process -ErrorAction SilentlyContinue | Where-Object { $_.Path -and $_.Path -ieq (Join-Path $t.Prog $Exe) }
  if ($running) {
    Say "MetaTrader $MT is open. It will close and reopen by itself (your open trades are not touched)."
    Read-Host 'Press Enter to continue, or Ctrl+C to stop' | Out-Null
    $running | ForEach-Object { $_.CloseMainWindow() | Out-Null }
    $running | Wait-Process -Timeout 20 -ErrorAction SilentlyContinue
    $running | Stop-Process -Force -ErrorAction SilentlyContinue
  }

  Say '1/3  Installing the PipStream EA'
  $tmp = Join-Path $env:TEMP ("pipstream-" + [guid]::NewGuid().ToString('N'))
  New-Item -ItemType Directory -Force $tmp | Out-Null
  $built = $null
  try {
    Invoke-WebRequest "$Url/ea/PipStream.$Ext" -OutFile "$tmp\PipStream.$Ext" -UseBasicParsing
    if ((Get-Item "$tmp\PipStream.$Ext").Length -gt 0) { $built = "$tmp\PipStream.$Ext" }
  } catch {}
  try { Invoke-WebRequest "$Url/ea/PipStream.$Src" -OutFile "$tmp\PipStream.$Src" -UseBasicParsing }
  catch { Fail "Couldn't download PipStream from $Url." 'Check your internet connection and try again.' }
  foreach ($d in $targets) {
    try { New-Item -ItemType Directory -Force (Join-Path $d "$Mql\Experts"), (Join-Path $d "$Mql\Files") | Out-Null }
    catch { Fail "Can't write to $d." 'Run PowerShell as the same Windows user that uses MetaTrader, then try again.' }
    Copy-Item "$tmp\PipStream.$Src" (Join-Path $d "$Mql\Experts\PipStream.$Src") -Force
  }
  if (-not $built) {
    $editor = Join-Path $t.Prog $Editor
    if (-not (Test-Path $editor)) { Fail "MetaEditor is missing from $($t.Prog), so the EA can't be built." "Reinstall MetaTrader $MT from your broker, then run this again." }
    $build = $targets | Where-Object { Test-Path (Join-Path $_ "$Mql\Include") } | Select-Object -First 1
    if (-not $build) { $build = $targets[0] }
    $out = Join-Path $build "$Mql\Experts\PipStream.$Ext"
    $before = if (Test-Path $out) { (Get-Item $out).LastWriteTime } else { [datetime]::MinValue }
    Note 'Building it with MetaEditor (takes up to a minute)...'
    $edArgs = @("/compile:`"$(Join-Path $build "$Mql\Experts\PipStream.$Src")`"", '/log')
    if (Test-Path (Join-Path $build "$Mql\Include")) { $edArgs += "/inc:`"$(Join-Path $build $Mql)`"" }
    Start-Process $editor -ArgumentList $edArgs -Wait -WindowStyle Hidden
    if (-not ((Test-Path $out) -and (Get-Item $out).LastWriteTime -gt $before)) {
      Fail 'Building the EA failed.' "Open MetaTrader, press F4 (MetaEditor), open Experts > PipStream.$Src, press Compile, then run this again."
    }
    $built = $out
  }
  foreach ($d in $targets) {
    $dest = Join-Path $d "$Mql\Experts\PipStream.$Ext"
    if ($built -ne $dest) { Copy-Item $built $dest -Force }
  }

  Say '2/3  Linking it to your PipStream account'
  foreach ($d in $targets) {
    [IO.File]::WriteAllText((Join-Path $d "$Mql\Files\PipStream.url"), $Url)
    [IO.File]::WriteAllText((Join-Path $d "$Mql\Files\PipStream.pair"), $Token)
  }

  # a chart on a symbol this broker really has (Exness: EURUSDm)
  $sym = $null
  foreach ($d in $targets) {
    $names = @()
    $names += Get-ChildItem (Join-Path $d 'bases\*\history\EURUSD*') -Directory -ErrorAction SilentlyContinue | ForEach-Object { $_.Name }
    $names += Get-ChildItem (Join-Path $d 'history\*\EURUSD*.hst') -File -ErrorAction SilentlyContinue | ForEach-Object { $_.BaseName -replace '(1|5|15|30|60|240|1440|10080|43200)$', '' }
    foreach ($nm in $names) { if ($nm.Length -le 10 -and (-not $sym -or $nm.Length -lt $sym.Length)) { $sym = $nm } }
  }
  if (-not $sym) { $sym = 'EURUSD' }

  Say "3/3  Starting MetaTrader with PipStream on a $sym chart"
  New-Item -ItemType Directory -Force (Join-Path $t.Prog 'config') -ErrorAction SilentlyContinue | Out-Null
  $ini = Join-Path $t.Prog 'config\pipstream-start.ini'
  if (-not (Test-Path (Split-Path $ini))) { $ini = Join-Path $tmp 'pipstream-start.ini' }
  if ($MT -eq '5') {
    $lines = '[Experts]', 'AllowLiveTrading=1', 'Enabled=1', 'AllowDllImport=0', 'Account=0', 'Profile=0', 'WebRequest=1', "WebRequestUrl=$Url",
             '[StartUp]', 'Expert=PipStream', "Symbol=$sym", 'Period=H1'
    $startArgs = "/config:`"$ini`""
  } else {
    # MT4's startup file has no sections, and takes its path without /config:
    $lines = 'ExpertsEnable=true', 'ExpertsDllImport=false', 'ExpertsExpImport=false', 'ExpertsTrades=true', 'Expert=PipStream', "Symbol=$sym", 'Period=H1'
    $startArgs = "`"$ini`""
  }
  [IO.File]::WriteAllText($ini, (($lines -join "`r`n") + "`r`n"), [Text.Encoding]::ASCII)
  try { Set-Clipboard -Value $Url } catch {}
  Start-Process (Join-Path $t.Prog $Exe) -ArgumentList $startArgs -WorkingDirectory $t.Prog
  # MetaTrader reads the startup file once; left behind, an auto-update restart would reuse it
  Start-Sleep -Seconds 20
  Remove-Item $ini -ErrorAction SilentlyContinue
  Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue

  Say "Done. MetaTrader $MT is opening with PipStream on a $sym chart."
  foreach ($d in $targets) { Note "EA installed in: $d\$Mql\Experts" }
  Write-Host ''
  Write-Host 'If MetaTrader asks you to log in, use your broker login as usual.'
  Write-Host 'One thing MetaTrader may still ask you to do yourself (first time only): Tools > Options > Expert Advisors,'
  Write-Host "tick 'Allow algorithmic trading' and 'Allow WebRequest for listed URL', paste $Url (already copied) and press OK."
  Write-Host "The chart then says 'PipStream ... Connected', and PipStream shows 'Connected' in your browser."
  Write-Host 'On a real-money account PipStream asks you to confirm in the app before it opens any trade.'
  Read-Host 'Press Enter to close' | Out-Null
} catch {
  if ("$_" -ne 'PipStream setup stopped') {
    Write-Host "`nPipStream setup hit a problem: $_" -ForegroundColor Red
    Write-Host 'Nothing else was changed. Try again, or reply to any PipStream email with this message.'
    Read-Host 'Press Enter to close' | Out-Null
  }
}
}
