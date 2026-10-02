<#
  Local dev server for GooberBox.
  Starts MySQL (if not already running) and the PHP built-in server.
  Usage:  powershell -ExecutionPolicy Bypass -File D:\html\tools\serve.ps1
  Then open http://localhost:8000  (Ctrl+C stops the PHP server; MySQL keeps running)
#>
param([int]$Port = 8000)
$ErrorActionPreference = 'Stop'

$Html = 'D:\html'
$Data = 'D:/mysql-data'
$MysqldExe = 'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe'
$Php = Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages" -Recurse -Filter php.exe -ErrorAction SilentlyContinue |
        Where-Object { $_.FullName -match 'PHP.PHP.8' } | Select-Object -First 1 -ExpandProperty FullName
if (-not $Php) { throw "php.exe not found" }

# Start MySQL if port 3306 isn't open
$mysqlUp = $false
try { $c = New-Object Net.Sockets.TcpClient; $c.Connect('127.0.0.1', 3306); $mysqlUp = $c.Connected; $c.Close() } catch {}
if (-not $mysqlUp) {
    Write-Host "Starting MySQL..." -ForegroundColor Cyan
    Start-Process -FilePath $MysqldExe -ArgumentList "--datadir=$Data", "--port=3306" -WindowStyle Hidden
    for ($i = 0; $i -lt 20; $i++) {
        Start-Sleep -Milliseconds 700
        try { $c = New-Object Net.Sockets.TcpClient; $c.Connect('127.0.0.1', 3306); if ($c.Connected) { $c.Close(); $mysqlUp = $true; break } } catch {}
    }
    if (-not $mysqlUp) { throw "MySQL did not start; check D:\mysql-data" }
}
Write-Host "MySQL is up (127.0.0.1:3306)." -ForegroundColor Green

# Put ffmpeg/ffprobe on PATH for the dev server (video generation shells out to them)
$ffdir = Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages" -Recurse -Filter ffmpeg.exe -ErrorAction SilentlyContinue |
         Select-Object -First 1 -ExpandProperty DirectoryName
if ($ffdir) { $env:PATH = "$ffdir;$env:PATH"; Write-Host "ffmpeg on PATH ($ffdir)." -ForegroundColor Green }
else { Write-Host "WARNING: ffmpeg not found; video generation will fail." -ForegroundColor Yellow }

Write-Host "PHP dev server: http://localhost:$Port  (Ctrl+C to stop)" -ForegroundColor Green
& $Php -S "localhost:$Port" -t $Html
