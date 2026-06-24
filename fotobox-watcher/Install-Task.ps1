# Registriert den Fotobox-Watcher als Scheduled Task mit Crash-Restart.
# Einmalig auf dem Fotobox-PC ausführen (als Administrator empfohlen).

$ScriptPath = 'C:\Fotobox\_uploader\Upload-Watcher.ps1'

if (-not (Test-Path $ScriptPath)) {
    Write-Host "FEHLER: $ScriptPath nicht gefunden. Erst Upload-Watcher.ps1 dorthin kopieren." -ForegroundColor Red
    exit 1
}

$Action  = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$ScriptPath`""

$Trigger1 = New-ScheduledTaskTrigger -AtStartup
$Trigger2 = New-ScheduledTaskTrigger -AtLogOn

$Settings = New-ScheduledTaskSettingsSet `
    -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) `
    -ExecutionTimeLimit ([TimeSpan]::Zero) `
    -StartWhenAvailable -DontStopOnIdleEnd -AllowStartIfOnBatteries

$Principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" `
    -LogonType Interactive -RunLevel Limited

Register-ScheduledTask -TaskName 'FotoboxUploadWatcher' `
    -Action $Action -Trigger $Trigger1,$Trigger2 -Settings $Settings -Principal $Principal -Force

Write-Host "✅ Task 'FotoboxUploadWatcher' registriert." -ForegroundColor Green
Write-Host "Starte ihn jetzt sofort zum Testen mit:"
Write-Host "  Start-ScheduledTask -TaskName 'FotoboxUploadWatcher'"
