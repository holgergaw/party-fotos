# Fotobox-Watcher

Überwacht `D:\bilder` auf dem Fotobox-PC (Windows 10 Home) und lädt neue Fotos automatisch per HTTP an `http://192.168.2.2/upload.php?source=fotobox` hoch — sie erscheinen dann im "Fotobox"-Tab der Galerie.

## Installation auf dem Fotobox-PC

1. Diesen Ordner (`fotobox-watcher/`) auf den Fotobox-PC kopieren, z. B. per USB-Stick.
2. `Upload-Watcher.ps1` nach `C:\Fotobox\_uploader\Upload-Watcher.ps1` kopieren:
   ```powershell
   New-Item -ItemType Directory -Force -Path 'C:\Fotobox\_uploader'
   Copy-Item .\Upload-Watcher.ps1 'C:\Fotobox\_uploader\Upload-Watcher.ps1'
   ```
3. Task Scheduler einrichten (einmalig, am besten als Administrator):
   ```powershell
   .\Install-Task.ps1
   ```
4. Zum sofortigen Testen den Task direkt starten:
   ```powershell
   Start-ScheduledTask -TaskName 'FotoboxUploadWatcher'
   ```
5. Testfoto in `D:\bilder` legen — nach ca. 6–9 Sekunden sollte es in `D:\bilder\_uploaded` verschoben sein und auf dem Pi unter `gallery.html` (Tab "Fotobox") auftauchen.

## Live-Log beobachten

```powershell
Get-Content "C:\Fotobox\_uploader\logs\upload_$(Get-Date -Format yyyyMMdd).log" -Tail 20 -Wait
```

## Troubleshooting

- **Task läuft nicht nach Neustart:** `Get-ScheduledTask -TaskName 'FotoboxUploadWatcher' | Get-ScheduledTaskInfo` prüfen (LastRunTime/LastTaskResult).
- **Upload schlägt dauerhaft fehl:** Pi-Erreichbarkeit prüfen — `Test-NetConnection 192.168.2.2 -Port 80`.
- **Dateien bleiben in `D:\bilder` liegen:** Log-Datei ansehen, meist Netzwerkproblem oder MIME-Typ wird vom Server abgelehnt.
- **Datei wurde fälschlich schon als hochgeladen markiert:** Eintrag in `C:\Fotobox\_uploader\state\uploaded.json` löschen, Datei zurück nach `D:\bilder` verschieben.
