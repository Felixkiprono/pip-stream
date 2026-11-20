# PipStream one-click setup for MetaTrader __MT__ on Windows. Run in PowerShell: irm <link> | iex
# It puts the PipStream EA into MetaTrader, allows it to reach __URL__, and starts MetaTrader
# with PipStream on a EURUSD chart. It links itself to your PipStream account: no key, no code.
# It never reads or changes your MetaTrader login or password.
$ErrorActionPreference = 'Stop'
$Url = '__URL__'; $Token = '__TOKEN__'; $MT = '__MT__'
if ($MT -eq '5') { $Exe = 'terminal64.exe'; $Editor = 'metaeditor64.exe'; $Mql = 'MQL5'; $Ext = 'ex5'; $Src = 'mq5' }
else { $Exe = 'terminal.exe'; $Editor = 'metaeditor.exe'; $Mql = 'MQL4'; $Ext = 'ex4'; $Src = 'mq4' }
function Say($m) { Write-Host "`n$m" -ForegroundColor Cyan }
function Fail($m) { Write-Host "`n$m" -ForegroundColor Red; Read-Host 'Press Enter to close'; exit 1 }

Say "PipStream: setting up MetaTrader $MT"
# Each MetaTrader install keeps its files in %APPDATA%\MetaQuotes\Terminal\<id>, with origin.txt naming the program folder.
$targets = @()
Get-ChildItem "$env:APPDATA\MetaQuotes\Terminal" -Directory -ErrorAction SilentlyContinue | ForEach-Object {
  $origin = Join-Path $_.FullName 'origin.txt'
  if ((Test-Path (Join-Path $_.FullName $Mql)) -and (Test-Path $origin)) {
    $prog = (Get-Content $origin -Raw -Encoding Unicode).Trim()
    if (Test-Path (Join-Path $prog $Exe)) { $targets += [pscustomobject]@{ Data = $_.FullName; Prog = $prog } }
  }
}
if ($targets.Count -eq 0) { Fail "Couldn't find MetaTrader $MT. Install it from your broker, open it once and log in, then run this again." }
$t = $targets[0]
if ($targets.Count -gt 1) {
  Say 'You have more than one MetaTrader. Which one should PipStream use?'
  for ($i = 0; $i -lt $targets.Count; $i++) { Write-Host "  $($i + 1). $($targets[$i].Prog)" }
  $n = [int](Read-Host 'Number') - 1
  if ($n -lt 0 -or $n -ge $targets.Count) { Fail 'No MetaTrader picked.' }
  $t = $targets[$n]
}

$running = Get-Process -ErrorAction SilentlyContinue | Where-Object { $_.Path -and $_.Path -like (Join-Path $t.Prog $Exe) }
if ($running) {
  Say "MetaTrader $MT is open. It will close and reopen by itself (your open trades are not touched)."
  Read-Host 'Press Enter to continue, or Ctrl+C to stop' | Out-Null
  $running | ForEach-Object { $_.CloseMainWindow() | Out-Null }
  $running | Wait-Process -Timeout 20 -ErrorAction SilentlyContinue
  $running | Stop-Process -Force -ErrorAction SilentlyContinue
}

Say '1/3  Installing the PipStream EA'
$experts = Join-Path $t.Data "$Mql\Experts"; $files = Join-Path $t.Data "$Mql\Files"
New-Item -ItemType Directory -Force $experts, $files, (Join-Path $t.Data 'config') | Out-Null
$built = Join-Path $experts "PipStream.$Ext"
try {
  Invoke-WebRequest "$Url/ea/PipStream.$Ext" -OutFile $built -UseBasicParsing
} catch {
  try { Invoke-WebRequest "$Url/ea/PipStream.$Src" -OutFile (Join-Path $experts "PipStream.$Src") -UseBasicParsing }
  catch { Fail "Couldn't download PipStream from $Url. Check your internet and try again." }
  & (Join-Path $t.Prog $Editor) "/compile:$(Join-Path $experts "PipStream.$Src")" "/log" | Out-Null
  Start-Sleep 3
  if (-not (Test-Path $built)) { Fail 'Building the EA failed. Open MetaEditor, open Experts > PipStream and press Compile, then run this again.' }
}

Say '2/3  Linking it to your PipStream account'
[IO.File]::WriteAllText((Join-Path $files 'PipStream.url'), $Url)
[IO.File]::WriteAllText((Join-Path $files 'PipStream.pair'), $Token)

Say '3/3  Starting MetaTrader with PipStream on a chart'
$ini = Join-Path $t.Data 'config\pipstream-start.ini'
"[Experts]`r`nAllowLiveTrading=1`r`nEnabled=1`r`nAllowDllImport=0`r`nAccount=0`r`nProfile=0`r`n[StartUp]`r`nExpert=PipStream`r`nSymbol=EURUSD`r`nPeriod=H1`r`n" | Set-Content $ini -Encoding Unicode
Set-Clipboard -Value $Url -ErrorAction SilentlyContinue
Start-Process (Join-Path $t.Prog $Exe) -ArgumentList "/config:`"$ini`""

Say 'Done. MetaTrader is opening with PipStream on a EURUSD chart.'
Write-Host "One thing MetaTrader only lets you do yourself (first time only): Tools > Options > Expert Advisors,"
Write-Host "tick 'Allow WebRequest for listed URL', paste $Url (already copied) and press OK."
Write-Host "Then PipStream shows 'Connected' in your browser within a few seconds."
Read-Host 'Press Enter to close' | Out-Null
