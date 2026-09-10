<#
    Kaiki — start everything the testing week needs, on one machine.

        powershell -ExecutionPolicy Bypass -File tools\start-testing.ps1

    Five processes, because that is genuinely how many there are and finding
    that out one silence at a time is the worst way to learn it:

      1. the panel          the operator's back office
      2. the guest pages    a different host, so custom domains behave
      3. the queue worker   WITHOUT THIS NO EMAIL IS EVER SENT
      4. the scheduler      nightly departures, voucher expiry, reminders
      5. Mailpit           catches every email so nothing reaches a real person

    The worker is the one nobody expects. Invitations, booking confirmations,
    e-tickets and webhooks are all queued: with no worker they sit in the `jobs`
    table for ever and the product looks broken in a way that produces no error
    anywhere. A hundred and seventy-five of them had piled up before anybody
    noticed.

    Each starts in its own window on purpose. When something misbehaves the
    question is always "which of the five", and one merged log makes that harder
    rather than easier.
#>

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$php  = 'C:\Users\Mike\php84\php.exe'   # 8.4; the `php` on PATH is 8.3 and fails Composer's platform check

if (-not (Test-Path $php)) {
    Write-Host "PHP 8.4 not found at $php — edit this script's `$php line." -ForegroundColor Red
    exit 1
}

# --- the address other machines will use ---------------------------------
#
# Read rather than assumed: a hard-coded IP is wrong the first morning the
# router hands out a different one, and the failure looks like the firewall.
$ip = (Get-NetIPAddress -AddressFamily IPv4 |
       Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } |
       Select-Object -First 1).IPAddress

Write-Host ''
Write-Host '  Kaiki — testing environment' -ForegroundColor Cyan
Write-Host '  ---------------------------'
Write-Host "  Panel        http://${ip}:8000/app"
Write-Host "  Guest pages  http://${ip}:8001/{operator-slug}"
Write-Host "  Email        http://${ip}:8025"
Write-Host ''

# --- warn about the two things that silently break it --------------------

$envFile = Join-Path $root '.env'
if (Test-Path $envFile) {
    $envText = Get-Content $envFile -Raw

    if ($envText -match 'APP_URL=http://(127\.0\.0\.1|localhost)') {
        Write-Host '  ! APP_URL still points at this machine only.' -ForegroundColor Yellow
        Write-Host "    Every invitation link will say 127.0.0.1 and open for nobody else."
        Write-Host "    Set APP_URL=http://${ip}:8000 and KAIKI_HOSTED_HOST=${ip}:8001"
        Write-Host ''
    }
}

$rule = Get-NetFirewallRule -DisplayName 'Kaiki panel 8000' -ErrorAction SilentlyContinue
if (-not $rule) {
    Write-Host '  ! No firewall rule for port 8000.' -ForegroundColor Yellow
    Write-Host '    Colleagues will not connect. In an ADMINISTRATOR terminal, once:'
    Write-Host '    netsh advfirewall firewall add rule name="Kaiki panel 8000" dir=in action=allow protocol=TCP localport=8000 profile=private'
    Write-Host '    netsh advfirewall firewall add rule name="Kaiki guest 8001" dir=in action=allow protocol=TCP localport=8001 profile=private'
    Write-Host '    netsh advfirewall firewall add rule name="Kaiki email 8025" dir=in action=allow protocol=TCP localport=8025 profile=private'
    Write-Host ''
}

# --- start them ----------------------------------------------------------

function Start-Piece($title, $exe, $argline) {
    Start-Process -FilePath 'cmd.exe' `
        -ArgumentList '/k', "title $title && cd /d `"$root`" && `"$exe`" $argline" `
        -WindowStyle Normal
    Start-Sleep -Milliseconds 700
}

Start-Piece 'Kaiki panel (8000)'   $php 'artisan serve --host=0.0.0.0 --port=8000'
Start-Piece 'Kaiki guest (8001)'   $php 'artisan serve --host=0.0.0.0 --port=8001'
Start-Piece 'Kaiki queue worker'   $php 'artisan queue:work --tries=3 --timeout=120'
Start-Piece 'Kaiki scheduler'      $php 'artisan schedule:work'

$mailpit = Join-Path $root 'tools\mailpit.exe'
if (Test-Path $mailpit) {
    Start-Piece 'Kaiki email (8025)' $mailpit '--listen 0.0.0.0:8025 --smtp 0.0.0.0:1025'
} else {
    Write-Host '  ! tools\mailpit.exe is missing — no email will be visible.' -ForegroundColor Yellow
    Write-Host '    Download mailpit-windows-amd64.zip from github.com/axllent/mailpit/releases'
    Write-Host '    and put mailpit.exe in tools\.'
    Write-Host ''
}

Write-Host '  Five windows opened. Close them to stop.' -ForegroundColor Green
Write-Host ''
