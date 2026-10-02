<#
.SYNOPSIS
  Build and deploy GooberBox: D:\html (readable source) -> obfuscate -> D:\goober -> Elastic Beanstalk.

.DESCRIPTION
  1. Copies the deployable parts of D:\html into tools\build\src (no .env, tools, vendor, tests, EB/Claude config).
  2. Obfuscates PHP with yakpro-po (tools\goober-yakpro.cnf: names kept, ifs/loops/strings obfuscated,
     statements shuffled). bootstrap.php and loadenv.php stay readable, as they always have in goober.
  3. Obfuscates JS/*.js with javascript-obfuscator (via npx).
  4. Mirrors the result into D:\goober. goober's .git, .elasticbeanstalk, vendor and local-only files
     (*.sql, *.deb, *.sh, .env, .gitignore) are never touched.
  5. Runs `eb deploy` from D:\goober (skip with -NoDeploy).

  Make every code change in D:\html, never in D:\goober. goober only holds build output.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File D:\html\tools\deploy.ps1 -NoDeploy   # build + sync only
  powershell -ExecutionPolicy Bypass -File D:\html\tools\deploy.ps1             # build + sync + deploy
#>
param(
    [switch]$NoDeploy,
    [string]$Environment = 'gooberbox'
)

$ErrorActionPreference = 'Stop'

$Html   = 'D:\html'
$Goober = 'D:\goober'
$Tools  = Join-Path $Html 'tools'
$Build  = Join-Path $Tools 'build'
$Src    = Join-Path $Build 'src'
$Out    = Join-Path $Build 'out'
$Php    = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $Php) { $Php = "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe" }
$Eb     = (Get-Command eb -ErrorAction SilentlyContinue).Source
if (-not $Eb) { $Eb = "$env:APPDATA\Python\Python314\Scripts\eb.exe" }

function Step($msg) { Write-Host "`n==> $msg" -ForegroundColor Cyan }

function Invoke-Robocopy([string[]]$RoboArgs) {
    & robocopy @RoboArgs | Out-Null
    # robocopy: 0-7 = success (files copied/extra/mismatched), 8+ = failure
    if ($LASTEXITCODE -ge 8) { throw "robocopy failed ($LASTEXITCODE): $($RoboArgs -join ' ')" }
}

# --- 1. Stage source ------------------------------------------------------
Step "Staging source from $Html"
if (Test-Path $Build) { Remove-Item $Build -Recurse -Force }
Invoke-Robocopy @($Html, $Src, '/E', '/NFL', '/NDL', '/NJH', '/NJS',
    '/XD', (Join-Path $Html 'tools'), (Join-Path $Html 'vendor'), (Join-Path $Html 'tests'),
           (Join-Path $Html '.elasticbeanstalk'), (Join-Path $Html '.claude'), (Join-Path $Html '.git'), (Join-Path $Html 'logs'),
    '/XF', '.env', '*.log')

# --- 2. Obfuscate PHP -----------------------------------------------------
Step "Obfuscating PHP with yakpro-po"
$srcFwd = $Src -replace '\\', '/'
$cnf = Join-Path $Build 'yakpro.cnf'
@"
<?php
// YAK Pro - Php Obfuscator: Config File
include '$(($Tools -replace '\\','/'))/goober-yakpro.cnf';
`$conf->t_keep = array('$srcFwd/bootstrap.php', '$srcFwd/loadenv.php');
?>
"@ | Set-Content $cnf -Encoding ascii
# Pass forward-slash paths: yakpro runs escapeshellcmd() on the source arg, which mangles Windows backslashes.
$outFwd = $Out -replace '\\', '/'
& $Php (Join-Path $Tools 'yakpro-po\yakpro-po.php') --config-file $cnf $srcFwd -o $outFwd
if ($LASTEXITCODE -ne 0) { throw "yakpro-po failed ($LASTEXITCODE)" }
$Dist = Join-Path $Out 'yakpro-po\obfuscated'

# Keep bootstrap.php and loadenv.php readable, as goober always has. yakpro's own t_keep
# mismatches on Windows path separators, so copy the plain versions over the obfuscated ones.
foreach ($keep in 'bootstrap.php', 'loadenv.php') {
    Copy-Item (Join-Path $Src $keep) (Join-Path $Dist $keep) -Force
}

# Every obfuscated file must still parse
$bad = @()
Get-ChildItem $Dist -Recurse -Filter *.php | ForEach-Object {
    & $Php -l $_.FullName *> $null
    if ($LASTEXITCODE -ne 0) { $bad += $_.FullName }
}
if ($bad.Count) { throw "Obfuscated PHP failed lint:`n$($bad -join "`n")" }
Write-Host "  $((Get-ChildItem $Dist -Recurse -Filter *.php).Count) PHP files obfuscated and lint-clean"

# --- 3. Obfuscate JS ------------------------------------------------------
Step "Obfuscating JS with javascript-obfuscator"
Get-ChildItem (Join-Path $Dist 'JS') -Filter *.js -ErrorAction SilentlyContinue | ForEach-Object {
    & npx --yes javascript-obfuscator $_.FullName --output $_.FullName | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "javascript-obfuscator failed on $($_.Name)" }
    Write-Host "  $($_.Name)"
}

# --- 4. Mirror into goober ------------------------------------------------
Step "Syncing build into $Goober"
Invoke-Robocopy @($Dist, $Goober, '/MIR', '/NFL', '/NDL', '/NJH', '/NJS',
    '/XD', (Join-Path $Goober '.git'), (Join-Path $Goober '.elasticbeanstalk'), (Join-Path $Goober 'vendor'),
           (Join-Path $Goober 'yakpro-po'), (Join-Path $Goober 'misc'),
    '/XF', '.env', '.gitignore', '*.sql', '*.deb', '*.deb.1', '*.sh', '*.log', '*.zip')
Write-Host "  goober now matches the build (git status there shows what changed)"

# --- 5. Deploy ------------------------------------------------------------
if ($NoDeploy) {
    Step "Skipping deploy (-NoDeploy). To deploy: cd $Goober; eb deploy $Environment"
    return
}
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
Push-Location $Goober
try {
    # goober deploys via git (sc: git), so commit the build to make the deploy deterministic.
    # Local commit only -- pushing to GitHub is left to you.
    Step "Committing build in $Goober"
    & git add -A
    & git diff --cached --quiet
    if ($LASTEXITCODE -ne 0) {
        & git commit -m "Build $stamp (obfuscated from D:\html)`n`nCo-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>" | Out-Null
        Write-Host "  committed"
    } else {
        Write-Host "  nothing changed since last build"
    }

    Step "Deploying $Goober to Elastic Beanstalk environment '$Environment'"
    & $Eb deploy $Environment --label "build-$stamp" --timeout 20
    if ($LASTEXITCODE -ne 0) { throw "eb deploy failed ($LASTEXITCODE)" }
} finally {
    Pop-Location
}
Step "Done. To publish source to GitHub: cd $Goober; git push"
