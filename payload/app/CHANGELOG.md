# Changelog

## 1.0.2 — 2026-09-28
- Öffentliche Standalone-Freigabe: FFXI Center ist keine Voraussetzung mehr.
- Echter `start`/`stop`/`restart`/`open`-Lifecycle mit eigenem Standalone-Owner, PID-/Starttime-Prüfung und Crash-Watchdog.
- Fremde Prozesse auf API-/Web-Ports werden weiterhin nie übernommen oder beendet.
- Optional vorhandene FFXI-Center-Instanzen können denselben Multi-Owner-Lifecycle weiterhin nutzen.
- Installer akzeptiert Installationen ohne `FFXI-Center/ffxi_center.py` und überspringt die Center-Patch-Stufe dann vollständig.
- Last-Known-Good-Verhalten bleibt unverändert: FFXI selbst muss zum Browsen/Planen nicht laufen.
- Public README, MIT-Lizenz und sanitizte Screenshots dokumentieren den eigenständigen Betrieb.
- Neue Regressionen prüfen Standalone-Vertrag, Start/Restart/Stop, SIGKILL-Cleanup und Fremdport-Sicherheit ohne installierten FFXI Center.

## 1.0.1 — 2026-09-05
- FINAL 08.09.2026: live bestätigten RC3c-Produktstand unverändert eingefroren; alle Pflicht-Release-Gates geschlossen, nur Release-/QA-Metadaten finalisiert.
- RC3c Farmplan-UX: „Nach Bezugsweg / Inhalt gruppiert“ ist jetzt eine vollbreite kompakte Routenliste statt einer unruhigen Kachelwand; Typ/Kurztitel/Status sind scanbar, Ressourcen und vollständiger Quelltext bleiben per Aufklappen erreichbar.
- RC3b Live-UX: Im normalen kompakten Itemdetail werden JSE-Cape-`Aug/Item`/`Max Aug`-Referenzmatrizen nicht mehr als hunderte BG-Wiki-Karten gerendert; echte Augment-Aktionen und Bezugswege bleiben sichtbar, vollständige Quelle/Expertansicht bleibt erreichbar.
- Regression-Repair gegen den tatsächlich live getesteten 0.8-Stand statt vereinfachter 1.0-Zwischenpfade.
- Augmentparser liest `augment_strings`, Path, Rank, Trial und `raw_extra_hex` aus dem realen LuAshitacast-Export.
- Physische Item-Zuordnung ist one-to-one; eine Augmentquelle kann nicht mehr auf mehrere gleiche Exemplare übertragen werden.
- Inventartabelle zeigt gleiche Items an unterschiedlichen Container-/Slot-Positionen getrennt und behält ihre jeweiligen Augments.
- Auto-Refresh bewahrt Suchtext, Eingabefokus und Cursor/Selektion; überlappende Refreshes können keine ältere Antwort mehr zurückrendern.
- Dashboard-Revision berücksichtigt den tatsächlichen Augmentinhalt, daher werden reine Augmentänderungen sichtbar.
- Webserver nutzt `public` als echtes Document-Root; gp10-Assets und Service Worker werden korrekt ausgeliefert.
- FFXI-Center-Lifecycle nutzt pro Center-Instanz Owner-Tokens; erst der letzte Owner beendet die von Gear Center gestarteten Dienste. Fremde/vorher laufende Dienste werden nicht übernommen.
- Normale Navigation bleibt auf exakt sieben nutzerorientierte Bereiche begrenzt; Advanced ist eine separate Expertendiagnose statt einer zweiten Hauptnavigation.
- Retired Combat-Capture-Metadaten werden aus Gear-Center-API/Performance-Cache gefiltert; keine Combat-/Live-Test-Endpunkte.
- Neue Regressionstests decken Augmentformat, Duplicate-Matching, augment-only Refresh, physische Inventarzeilen und Multi-Center-Lifecycle ab.
- Historische 0.1–0.8-Funktionen wurden als verbindlicher Feature-Contract wieder angebunden: Item-Katalog/BG-Wiki, Acquisition Routes, Progressionsketten/-ressourcen, LAC-Sets, freie Ziele/Notizen, To-Dos, Snapshot-Diff, Coverage/Latest-Tier und 22-Job-Target-QA.
- Farmplan-Items und abhängige Ziele sind wieder echte Drill-downs statt Sackgassen; BG-Wiki-Bezugswissen wird direkt in den Farmplan angereichert.
- Schlüsselitems werden wieder aus dem Live-State geführt und unter Charakter angezeigt.
- Alle normalen Nutzerpfade folgen einem No-Dead-End-Vertrag: Items, Ziele, Farmressourcen, Währungen, leere Filter und unbekannte Datenzustände bieten kontextuelle Weiteraktionen.
- Der Installer führt nach begonnenen Änderungen bei Fehlern automatisch den Vorher-Rollback aus; dieser Pfad wird separat als Regression getestet.

## 1.0.0 — 2026-09-02
- Erste Konsolidierung. Dieses Paket enthielt beim Packaging Regressionen gegenüber dem live getesteten 0.8-Stand; 1.0.1 ersetzt es als korrigierte Release-Linie.
- Live-getesteten 0.8-Stand zu einem einzigen Release konsolidiert.
- Eine Hauptnavigation mit sieben nutzerorientierten Bereichen; doppelte PHP-/Embedded-Menüs entfernt.
- Gearplanung auf Set-/Varianten-Auswahl umgebaut: komplette Variante oder alle Varianten einer Set-Gruppe als Ziel.
- Technische Target-/Coverage-/Quellenprüfung aus dem normalen Bedienweg entfernt und unter Datenstatus/Advanced gebündelt.
- Inventarsuche behält Suchtext, Filterung, Fokus und Cursor über automatische Refreshes; unveränderte Snapshots erzeugen keinen Voll-Render.
- Farmplan zählt Shared Gear über mehrere Ziele nur einmal und trennt Empfehlungen strikt von persönlichen Zielen.
- Charakteransicht bündelt Währungen und Progression ohne zweite Navigationsebene.
- Combat-/GearLab-Capture-Workflow aus Gear Center entfernt; Gear Lab bleibt read-only für modellierte Vergleichsergebnisse.
- Eigener 1.0-API-/Runtime-Layer mit `/api/v1`, Dashboard-Endpunkt und 0.5–0.8-Kompatibilitätsaliasen.
- FFXI Center startet Webserver/API automatisch und beendet beide beim Schließen.
- Login-Autosync für GearplanerBridge/Points-Exports; normaler Betrieb benötigt keine manuellen Server-/Snapshot-Befehle.
- Alte 0.5–0.8-Runtimes werden nach erfolgreicher Acceptance ins Backup archiviert.
