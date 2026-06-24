# Fotobox-Watcher — überwacht D:\bilder und lädt neue Fotos automatisch
# an den Party-Foto-Server (Raspberry Pi) hoch.
$ErrorActionPreference = 'Stop'

$WatchDir   = 'D:\bilder'
$DoneDir    = Join-Path $WatchDir '_uploaded'
$ErrorDir   = Join-Path $WatchDir '_failed'
$StateDir   = 'C:\Fotobox\_uploader\state'
$StateFile  = Join-Path $StateDir 'uploaded.json'
$LogDir     = 'C:\Fotobox\_uploader\logs'
$LogFile    = Join-Path $LogDir ("upload_{0}.log" -f (Get-Date -Format 'yyyyMMdd'))

$EndpointUrl   = 'http://192.168.2.2/upload.php?source=fotobox'
$PollInterval  = 3
$StableChecks  = 2
$MaxRetries    = 20   # danach Datei nach _failed verschieben

New-Item -ItemType Directory -Force -Path $WatchDir,$DoneDir,$ErrorDir,$StateDir,$LogDir | Out-Null

function Write-Log {
    param([string]$Message, [string]$Level = 'INFO')
    $line = "{0:yyyy-MM-dd HH:mm:ss}  [{1}]  {2}" -f (Get-Date), $Level, $Message
    Add-Content -Path $LogFile -Value $line -Encoding UTF8
}

function Load-State {
    if (Test-Path $StateFile) {
        try { return (Get-Content $StateFile -Raw | ConvertFrom-Json -AsHashtable) }
        catch { Write-Log "State-Datei korrupt, starte leer: $_" 'WARN'; return @{} }
    }
    return @{}
}
function Save-State($state) { $state | ConvertTo-Json | Set-Content -Path $StateFile -Encoding UTF8 }

function Test-FileReady($path) {
    try { $fs = [System.IO.File]::Open($path, 'Open', 'Read', 'None'); $fs.Close(); return $true }
    catch { return $false }
}

Add-Type -AssemblyName System.Net.Http

function Send-Photo {
    param([string]$FilePath)
    $client = New-Object System.Net.Http.HttpClient
    $client.Timeout = [TimeSpan]::FromSeconds(20)
    try {
        $content = New-Object System.Net.Http.MultipartFormDataContent
        $bytes   = [System.IO.File]::ReadAllBytes($FilePath)
        $byteContent = New-Object System.Net.Http.ByteArrayContent($bytes)
        $mime = switch ([IO.Path]::GetExtension($FilePath).ToLower()) {
            '.png'  { 'image/png' }
            '.gif'  { 'image/gif' }
            '.webp' { 'image/webp' }
            default { 'image/jpeg' }
        }
        $byteContent.Headers.ContentType = [System.Net.Http.Headers.MediaTypeHeaderValue]::Parse($mime)
        $content.Add($byteContent, 'foto', [IO.Path]::GetFileName($FilePath))

        $resp = $client.PostAsync($EndpointUrl, $content).GetAwaiter().GetResult()
        $body = $resp.Content.ReadAsStringAsync().GetAwaiter().GetResult()

        if ($resp.IsSuccessStatusCode) {
            $json = $body | ConvertFrom-Json
            if ($json.ok) { return @{ Success = $true; Body = $body } }
            return @{ Success = $false; Body = $body }
        }
        return @{ Success = $false; Body = "HTTP $($resp.StatusCode): $body" }
    } catch {
        return @{ Success = $false; Body = $_.Exception.Message }
    } finally { $client.Dispose() }
}

Write-Log "Watcher gestartet. Überwache $WatchDir"
$pending = @{}
$retries = @{}
$state = Load-State

while ($true) {
    try {
        $files = Get-ChildItem -Path $WatchDir -File |
                 Where-Object { $_.Extension -match '\.(jpg|jpeg|png|gif|webp|heic|heif)$' }

        foreach ($f in $files) {
            if ($state.ContainsKey($f.Name)) { continue }

            $key = $f.Name
            if (-not $pending.ContainsKey($key)) {
                $pending[$key] = @{ Size = $f.Length; Stable = 0 }
                continue
            }
            if ($pending[$key].Size -eq $f.Length) { $pending[$key].Stable++ }
            else { $pending[$key].Size = $f.Length; $pending[$key].Stable = 0 }

            if ($pending[$key].Stable -ge $StableChecks -and (Test-FileReady $f.FullName)) {
                Write-Log "Lade hoch: $($f.Name) ($($f.Length) Bytes)"
                $result = Send-Photo -FilePath $f.FullName

                if ($result.Success) {
                    $state[$key] = (Get-Date).ToString('s')
                    Save-State $state
                    Move-Item -Path $f.FullName -Destination (Join-Path $DoneDir $f.Name) -Force
                    $pending.Remove($key); $retries.Remove($key)
                    Write-Log "Erfolg: $($f.Name) -> $($result.Body)"
                } else {
                    $retries[$key] = ($retries[$key] + 1)
                    Write-Log "Fehlgeschlagen (Versuch $($retries[$key])): $($f.Name) -> $($result.Body)" 'WARN'
                    if ($retries[$key] -ge $MaxRetries) {
                        Move-Item -Path $f.FullName -Destination (Join-Path $ErrorDir $f.Name) -Force
                        $pending.Remove($key); $retries.Remove($key)
                        Write-Log "Aufgegeben nach $MaxRetries Versuchen, verschoben nach _failed: $($f.Name)" 'ERROR'
                    }
                }
            }
        }

        $existingNames = $files.Name
        foreach ($k in @($pending.Keys)) { if ($k -notin $existingNames) { $pending.Remove($k) } }

    } catch {
        Write-Log "Unerwarteter Fehler in Hauptschleife: $($_.Exception.Message)" 'ERROR'
    }

    Start-Sleep -Seconds $PollInterval
}
