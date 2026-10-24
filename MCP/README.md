# MCP
Stellt MCP Funktionen zur Verwendung durch Sprachmodelle bereit

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in Symcon](#4-einrichten-der-instanzen-in-symcon)
5. [Statusvariablen](#5-statusvariablen)

### 1. Funktionsumfang

* Stellt MCP Funktionen zur Verwendung durch Sprachmodelle bereit

Tools:
- `find-objects`: Durchsucht den gesamten Objektbaum nach Typ, Name (Teilstring, Groß-/Kleinschreibung egal), Verwendung und letzter Aktualisierung. Liefert kompakte Treffer mit `LocationPath` (Namen der übergeordneten Objekte), begrenzt durch `limit` (Standard 50, max. 200), sowie `totalMatches` und `truncated`.

### 2. Voraussetzungen

- Symcon ab Version 8.1

### 3. Software-Installation

* Über das Module Control folgende URL hinzufügen: https://github.com/symcon/SymconMCP

### 4. Einrichten der Instanzen in Symcon

 Unter 'Instanz hinzufügen' kann das 'MCP'-Modul mithilfe des Schnellfilters gefunden werden.  
	- Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

Die Instanz muss nicht weiter konfiguriert werden

### 5. Statusvariablen

Die Instanz verfügt über keine Statusvariablen