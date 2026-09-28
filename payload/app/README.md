# FFXI Gear Center 1.0.2

FFXI Gear Center ist die gemeinsame Oberfläche für Inventar, Gear-Empfehlungen, persönliche Ziele, Farmplanung und Gear-Lab-Vergleiche.

## Normaler Ablauf

FFXI Center ist ab 1.0.2 **optional**. Der öffentliche Standard ist der eigenständige Gear-Center-Lifecycle:

1. **Gear Center starten:** `~/Games/FFXI/scripts/gear-center-services.sh start`
2. **Oberfläche öffnen:** `~/Games/FFXI/scripts/gear-center-services.sh open`
3. **FFXI bei Bedarf starten.** GearplanerBridge und Points aktualisieren dann die lokalen Daten automatisch.
4. **Gear Center beenden:** `~/Games/FFXI/scripts/gear-center-services.sh stop`

Ohne laufendes FFXI bleibt der letzte vollständige lokale Datenstand für Inventar und Planung verfügbar. Wenn FFXI Center separat vorhanden ist, kann dessen bestehender Owner-Lifecycle dieselben Dienste weiterhin sicher teilen; er ist jedoch keine Voraussetzung mehr.

## Oberfläche

Die Hauptnavigation hat bewusst nur sieben Punkte: **Übersicht, Inventar, Gearplanung, Farmplan, Gear-Vergleich, Charakter, Datenstatus**.

In der Gearplanung ist eine Empfehlung zunächst unverbindlich. Eine einzelne Variante ist ein komplettes empfohlenes Set für genau diese Situation. Mit **Diese Variante als Ziel** wird sie persönlich. **Alle Varianten als Ziele** übernimmt auf Wunsch alle Varianten einer Set-Gruppe. Technische Verifikationsdaten liegen nicht im Hauptweg, sondern in aufklappbaren Details bzw. unter Datenstatus.

Der Farmplan verarbeitet ausschließlich explizit gesetzte persönliche Ziele. Wiederverwendbares Gear wird über mehrere Ziele nicht doppelt gezählt; unbekannte Bestände bleiben unbekannt und werden nicht zu null gemacht.

### Kein-Sackgassen-Prinzip

Die normale Oberfläche ist als zusammenhängender Arbeitsfluss gebaut: Items führen zu Itemdetails, BG-Wiki, Bezugsweg, Progression und Materialien; Ziele führen zurück zur passenden Set-Variante; Farmressourcen führen zu Item- oder Währungsbestand und zu den betroffenen Zielen. Leere Such-/Filterzustände, noch fehlende Live-Daten oder unaufgelöste Quellen bieten immer eine konkrete nächste Aktion. Persönliche Ziele können jederzeit wieder mit **Ziel entfernen** gelöscht werden.

## Erhaltener Funktionsumfang aus 0.1–0.8

Die 1.0.2-Standalone-Freigabe baut auf der 1.0.1-Konsolidierung auf und entfernt keine bewusst entwickelte Funktion. Die frühere Versionsfolge ist in der finalen Oberfläche nur neu geordnet:

- **Inventar / Itemwissen:** physische FFXI-Instanzen, GearFinder-Historie, gemeinsamer Item-Katalog und anklickbare Itemdetails.
- **BG-Wiki / Bezugswege:** gecachte kanonische Seiten, strukturierte Bezugsdaten, alternative Routen, Progressionsketten sowie Materialien und Währungen.
- **Persönliche Planung:** LuAshitacast-Sets, zusätzliche Item-/Set-Ziele und Notizen, To-Dos mit einzelnen Schritten sowie Snapshot-Änderungen.
- **Guide-Katalog:** 22 Jobs, Job → Set → Variante, technische Identity-/Route-/Augment-/Freshness-/Chain-Prüfung in der Expertendiagnose.
- **Live State:** 17 Container, Non-Equip-Items, Schlüsselitems, Currency I/II, Points-Overlay und physische Augment-Zuordnung.
- **Freshness / Progression:** Coverage, Latest-Tier/Post-2024-Audit und die bereits modellierten Upgrade-Systeme bleiben erhalten.
- **Gear Lab / Kastra:** theoretische Vergleiche read-only. Nur der später ausdrücklich gestrichene Combat-Capture-Zweig fehlt.
- **Farmplan:** ausschließlich persönliche Ziele, Shared Gear nur einmal, Unknown bleibt Unknown; Items und abhängige Ziele führen direkt zu ihren Details.

## 1.0.2 Regression-Schutz

Die Inventaransicht arbeitet mit physischen Instanzen statt nur mit Item-ID/Name. Das Augment-Overlay akzeptiert das tatsächliche Live-Exportformat und ordnet jede Quelle höchstens einem Exemplar zu. Auto-Refresh erhält Fokus und Cursor; die Dashboard-Revision enthält den Augmentinhalt. Der gemeinsame Lifecycle verwendet Owner-Tokens sowohl für den eigenständigen Standalone-Owner als auch für optional vorhandene FFXI-Center-Prozesse; erst der letzte Owner beendet die von Gear Center gestarteten Dienste.

## Datenquellen

- **GearplanerBridge:** physisches Inventar, Non-Equip-Items, Container/Slots, equipped-Mapping, Roh-/Augmentdaten sowie Currency-Pakete.
- **Points:** bestehende Points-Logik wird wiederverwendet; der kleine read-only Export bleibt für Sparks/Accolades und Progressionswerte maßgeblich, wenn er frisch zum Charakter passt.
- **Gear Lab:** wird read-only als vorhandene Modell-/Optimizer-Quelle genutzt. Der frühere zusätzliche Combat-Capture-Workflow gehört nicht zu Gear Center 1.0.2.

Falls einzelne Currency-Felder nach dem Login noch fehlen, zeigt **Datenstatus** konkret den Hinweis, das normale FFXI-Currencies-Menü einmal zu öffnen. Es werden keine Werte erfunden.

## Technische Ansicht

Die alte umfangreiche PHP-/QA-Oberfläche ist nicht Teil der normalen Navigation. Für Wartung ist sie unter `/advanced.php?view=sources` erreichbar. Coverage und Parser-QA bleiben dort verfügbar und nutzen die bereits erzeugten Caches.

## Installation

Die 1.0.2-Release-ZIP enthält `install-1.0.sh`. Der Installer sichert den bisherigen Stand, erhält Datenbank/Cache/Ziele, konsolidiert alte Gearplaner-Runtimes und installiert den eigenständigen Lifecycle. Ist FFXI Center vorhanden, wird dessen Integration zusätzlich angeboten; fehlt es, wird dieser Schritt sauber übersprungen. Sobald Änderungen begonnen haben, löst ein Installationsfehler automatisch den Rollback auf den unmittelbar zuvor gesicherten Zustand aus.
