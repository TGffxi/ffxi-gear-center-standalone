# Currency Collector

GearplanerBridge beobachtet die FFXI-Currency-Pakete 0x113 und 0x118 und speichert die bekannten Felder im gemeinsamen Snapshot. Der Katalog umfasst 191 benannte Currency-/Stored-Resource-Felder.

Nach Login werden automatische Exporte ausgelöst. Inventar-/Currency-Änderungen erzeugen weitere Snapshots. Wenn FFXI die beiden Currency-Pakete in einer Sitzung noch nicht geliefert hat, zeigt Gear Center unter **Datenstatus** den Hinweis, das normale Currencies-Menü einmal zu öffnen. Unbekannte Felder bleiben unbekannt.
