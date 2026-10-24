# MCP

[![Version](https://img.shields.io/badge/Symcon-PHP--Bibliothek-purple.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Product](https://img.shields.io/badge/Symcon%20Version-8.1-blue.svg)](https://www.symcon.de/produkt/)

Stellt Model Context Protocol (MCP) Funktionalität für Symcon bereit. Diese Bibliothek ermöglicht die Integration mit externen KI-Tools wie LibreChat, Claude und anderen MCP-kompatiblen Anwendungen und ermöglicht ihnen, über eine standardisierte Schnittstelle mit Symcon zu interagieren.

Folgende Module beinhaltet diese Bibliothek:

- MCP ([Dokumentation](MCP))

---

## Inhaltsverzeichnis

1. [Überblick](#überblick)
2. [Installation und Einrichtung](#installation-und-einrichtung)
3. [Verwendbare MCP-Funktionen](#verwendbare-mcp-funktionen)

---

## Überblick

Dieses Modul bietet eine Model Context Protocol (MCP) Brücke zwischen IP-Symcon und externen Anwendungen. Es besteht aus zwei Komponenten:

- **MCP-Modul**: Symcon Modul zur Bereitstellung von MCP Funktionen
- **TypeScript MCP-Server** (`lib/TS-Server/`): Standalone Node.js-Server, der als Brücke zwischen Symcon und externen MCP-Clients fungiert

Mit diesem Modul können externe Tools (wie ChatGPT-Plugins, LibreChat-Instanzen etc.) auf Symcon-Funktionen zugreifen, um:
- Variablen abzufragen und zu schalten
- Objekte zu suchen und zu verwalten
- Historische Daten abzurufen
- Das System zu überwachen

---

## Installation und Einrichtung

### 1. Kern-Instanz in Symcon erstellen

Siehe Moduldokumentation [MCP](MCP)

Das Modul registriert automatisch einen Webhook unter der Route `/hook/mcp` in Symcon.

#### 1.2 TypeScript MCP-Brücke starten

Die MCP-Brücke in `lib/TS-Server/` verbindet die MCP-Anfragen mit der Symcon-Instanz und leitet diese im korrekten MCP-Format weiter.

Dieser wird per Kommandozeile gestartet. Die Brücke muss nicht auf dem gleichen Rechner wie Symcon laufen. Soll die Brücke auf einem anderen Rechner laufen, dann muss dort ebenfalls der Inhalt des Modul, z.B. per Direktdownload via GitHub, vorhanden sein.

**Voraussetzungen:**
- [Node.js](https://nodejs.org/)

**Installation:**

Ausgehend vom Modulverzeichnis

```bash
cd lib/TS-Server
npm install
```

**Server starten:**

```bash
npm start --symcon-url=http://192.168.0.34:3777
```

**Hinweise zu den Parametern:**
- `--symcon-url`: Die URL, unter der Ihre Symcon-Instanz erreichbar ist (Standard: `http://127.0.0.1:3777`, also gleicher Rechner)
- `--port`: Der Port, auf dem der MCP-Server läuft (Standard: `3000`)

Der Server sollte folgende Meldung ausgeben:
```
Starting TS MCP Server
Symcon Hook URL: http://192.168.0.34:3777/hook/mcp/
MCP Server will start on port: 3000
```

### 2. Integration mit externem Tool am Beispiel von LibreChat

Andere Tools mit MCP Unterstützung sind ebenfalls möglich. Hier ist die Einrichtung für LibreChat vorgestellt. Andere Anwendungen werden vergleichbar eingerichtet.

#### LibreChat herunterladen

Falls git installiert ist, kann das Repository geklont werden:
```
git clone https://github.com/danny-avila/LibreChat
```

Alternativ kann unter https://github.com/danny-avila/LibreChat unter Code -> Download ZIP das Tool heruntergeladen und anschließend entpackt werden.

#### LibreChat starten

Der offiziellen Anleitung folgen, z.B. [hier](https://www.librechat.ai/docs/local/docker)

Zusätzlich die librechat.example.yaml kopieren und librechat.yaml nennen. In der Datei folgenden Abschnitt hinzufügen (oder die # davor entfernen):
```
mcpSettings:
  allowedDomains:
    - 'http://host.docker.internal:3000'
```

Und zum Abschluß die librechat.yaml aktivieren, indem die docker-compose.override.yml.example kopiert und in docker-compose.override.yml umbenannt wird. In dieser Datei folgenden Abschnitt hinzufügen (oder die # davor entfernen):
```
services:

# USE LIBRECHAT CONFIG FILE
  api:
    volumes:
    - type: bind
      source: ./librechat.yaml
      target: /app/librechat.yaml
```

Im Browser http://localhost:3080/ öffnen

#### Konto erstellen

![Konto erstellen](imgs/1-konto-erstellen.png)

Kontodaten eingeben und bestätigen. Die Daten bleiben nur bei der lokalen LibreChat Installation. Die E-Mail-Adresse muss nicht verifiziert werden.

#### Einloggen

![Einloggen](imgs/2-einloggen.png)

Die im vorherigen Schritt gewählten Zugangsdaten eingeben und auf "Fortfahren" klicken.

#### Modell auswählen und konfigurieren

![Modellauswahl öffnen](imgs/3-anbieter-zahnrad.png)

Oben die Modellauswahl öffnen und beim gewünschten Anbieter auf das Zahnrad klicken.

![API-Key eingeben](imgs/4-api-key.png)

Im Dialog den API-Key des Anbieters eingeben und mit "Absenden" bestätigen.

Für verschiedene Anbieter kann diese Konfiguration abweichen. Sollte der gewünschte Anbieter nicht in der Liste vorhanden sein, kann die Konfiguration von LibreChat diesbezüglich erweitert werden.

![Modell wählen](imgs/5-modell-waehlen.png)

Unter dem Anbieter das genaue Modell auswählen und per Klick bestätigen. Bei der Wahl des Modells ist wichtig, dass dies Tool Aufrufe unterstützt. Im Zweifelsfall erfüllen dies aber alle moderneren Modelle.

#### MCP einrichten

![MCP Einstellungen öffnen](imgs/6-mcp-einstellungen.png)

MCP Einstellungen per Klick auf das MCP Icon öffnen.

![MCP Server hinzufügen](imgs/mcp-server-hinzu.png)

Auf das Plus für einen neuen MCP Server klicken.

![MCP Server konfigurieren](imgs/7-mcp-konfigurieren.png)

Im Dialog die Daten des bereiten MCP-Servers eingeben:
- Name: beliebig, z.B. "symcon"
- MCP-Server-URL: http://host.docker.internal:3000/mcp (eventuell anderer Port, falls dies so konfiguriert wurde)
- Transport: Streamable HTTPS
- Authentifizierung: No Auth
- Ich vertraue dieser Anwendung: Bestätigen

Danach auf "Erstellen" klicken

#### MCP aktivieren

![MCP aktivieren](imgs/8-mcp-aktivieren.png)

Unter "MCP Server" bei der Chateingabe den Haken bei symcon aktivieren.

#### Loslegen

Der Symcon MCP Server ist nun einsatzbereit und kann verwendet werden.

---

## Verwendbare MCP-Funktionen

Das Modul stellt folgende MCP Funktionen zur Verfügung:

### Steuerungsfunktionen

#### `switch-boolean` - Variable schalten
Schaltet eine boolesche Symcon-Variable auf einen bestimmten Wert (true/false).

**Parameter**
- `variableID` (Integer): Die ID der Variable in Symcon
- `value` (Boolean): Der neue Wert (true oder false)

**Rückgabe**
- `success` (Boolean): Bestätigung ob erfolgreich

---

### Abfragefunktionen

#### `get-value` - Wert einer Variable auslesen
Holt den aktuellen Wert einer Symcon-Variable. Gibt standardmäßig den formatierten Wert zurück, ermöglicht aber auch die Abfrage von Rohdaten

**Parameter**
- `objectID` (Integer): Die ID der Variable
- `raw` (Boolean, optional): true für Rohdaten statt formatierter Werte

**Rückgabe**
- `value`: Der aktuelle Wert der Variable

---

#### `get-name` - Namen auslesen
Gibt den Namen eines Symcon-Objekts zurück.

**Parameter**
- `objectID` (Integer): Die ID des Objekts

**Rückgabe**
- `name` (String): Der Name des Objekts

---

#### `get-object` - Detaillierte Objektinformationen
Holt vollständige Informationen zu einem Symcon-Objekt (entspricht [IPS_GetObject](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/objektverwaltung/ips-getobject/)).

**Parameter**
- `objectID` (Integer): Die ID des Objekts

**Rückgabe**
- `info` (Objekt): Infos analog zur Rückgabe von IPS_GetObject

---

#### `get-children` - Unterobjekte auslesen
Listet alle IDs untergeordneter Objekte eines Objekts auf.

**Parameter**
- `objectID` (Integer): Die ID des Objekts

**Rückgabe**
- `children` (Liste): Liste der IDs aller untergeordneter Objekte

---

### Suchfunktionen

#### `find-objects` - Flexible Objektsuche
Durchsucht den gesamten Objektbaum nach verschiedenen Kriterien. Unterstützt mehrere optionale Filter, die kombiniert werden können:

**Filteroptionen:**
- **name**: Suchtext im Objektnamen (Teilmatch, case-insensitive)
  - *Beispiel:* `"Temperatur"` findet "Wohnzimmer Temperatur", "Außentemperatur"
  
- **types**: Array von Objekttypen, es werden nur Objekte mit einem passenden Objekttyp zurückgegeben

- **usage**: Filter nach Funktion, mögliche Werte:
  - `"temperature"` = nur Temperaturvariablen

- **lastUpdate**: Filter nach letzter Änderung
  - `from`: Unix-Timestamp (in Sekunden)
  - `to`: Unix-Timestamp (in Sekunden)

- **limit**: Maximale Anzahl zurückgegebener Objekte (1 bis 200, Standard 50)

**Rückgabe**
- `objects` (Liste): Kompakte Treffer mit
  - `ObjectID`, `ObjectName`, `ObjectType`, `ParentID`
  - `LocationPath`: Namen aller übergeordneten Objekte, Wurzel zuerst (z.B. "Haus / Erdgeschoss / Wohnzimmer"). Raumnamen stehen meist hier und nicht im Objektnamen
- `totalMatches` (Integer): Anzahl aller Treffer, auch über `limit` hinaus
- `truncated` (Boolean): true, wenn nicht alle Treffer zurückgegeben wurden. Dann Filter verfeinern oder `limit` erhöhen

Ungültige Filterwerte werden als Fehler gemeldet. Vollständige Informationen zu einem Treffer liefert `get-object`.

---

#### `get-snapshot` - Kompletter System-Snapshot
Holt einen Snapshot des gesamten Symcon-Systems mit Daten zu allen Objekten.

**Warnung:** Diese Funktion gibt sehr große Datenmengen zurück und sollte sparsam verwendet werden. Bisher konnte darüber hinaus kein Modell den Snapshot sinnvoll nutzen.

**Rückgabe**
- `snapshot` (Objekt): Der gesamte Snapshot des Systems

---

### Zeit- und Historyfunktionen

#### `current-time` - Aktuelle Uhrzeit
Gibt die aktuelle Systemzeit in Unix-Timestamp und lesbarem Format zurück.

**Rückgabe**
- `timestamp` (Integer): Unix-Timestamp in Sekunden
- `formatted` (String): ISO 8601 Format (z.B. "2025-03-19T14:30:45Z")

---

#### `get-logged-data` - Historische Daten
Holt die Änderungshistorie einer archivierten Variable in einem Zeitbereich, neueste Werte zuerst.

**Parameter**
- `variableID` (Integer): Die Variable. Sie muss existieren und Werte im Archiv haben. Nach dem Abschalten des Loggings bleiben die bisher geloggten Werte abrufbar
- `period` (String, optional): Relativer Zeitraum, den der Server in seiner Zeitzone auflöst: `today`, `yesterday`, `last-24h`, `this-week`, `last-week`, `last-7-days`, `this-month`, `last-month`. Wochen beginnen am Montag, `last-24h` und `last-7-days` rechnen ab jetzt zurück. Nicht mit `from`/`to` kombinierbar
- `from` (String oder Zahl, optional): Beginn als ISO 8601 (z.B. "2026-07-10T00:00:00"), relativer Ausdruck (z.B. "-7 days", "yesterday") oder Unix-Sekunden. Standard: Beginn des Archivs
- `to` (String oder Zahl, optional): Ende, gleiche Formate. Standard (oder 0): jetzt
- `limit` (Integer, optional): Maximale Anzahl Einträge (1 bis 5000, Standard 1000)

Beide Grenzen sind inklusive. Abgeschlossene Zeiträume wie `yesterday` enden daher um 23:59:59. Der zu Beginn des Zeitraums gültige Wert kann vor `from` geloggt worden sein.

**Rückgabe**
- `data` (Liste) mit Einträgen:
  - `TimeStamp`: Unix-Timestamp
  - `Value`: Der gespeicherte Wert
  - `Duration`: Wie lange dieser Wert gültig war (in Sekunden)
  - `FormattedTime`: ISO 8601 Format für Lesbarkeit
- `resolvedFrom`, `resolvedTo` (String): Der tatsächlich abgefragte Zeitraum im ISO 8601 Format
- `loggingActive` (Boolean): false, wenn das Logging der Variable abgeschaltet wurde. Die Daten enden dann mit dem letzten geloggten Wert. Dessen `Duration` zählt trotzdem bis jetzt weiter, der aktuelle Wert kann abweichen (siehe `get-value`)
- `truncated` (Boolean): true, wenn im Zeitraum ältere Werte über `limit` hinaus existieren
- `nextTo` (Integer, nur bei `truncated`): Wert für `to`, um die nächste ältere Seite abzurufen

**Seitenweise abrufen**
1. `{"variableID": 18543, "period": "last-week", "limit": 100}` liefert bis zu 100 der neuesten Werte, bei weiteren Werten mit `truncated: true` und `nextTo`
2. `{"variableID": 18543, "from": "<resolvedFrom>", "to": <nextTo>, "limit": 100}` liefert die nächsten Werte
3. Wiederholen, bis `truncated` false ist

Werte mit gleichem Zeitstempel werden nie auf zwei Seiten verteilt. Haben alle Werte einer Seite denselben Zeitstempel, fehlt `nextTo`, dann `limit` erhöhen.

Fehlende Objekte, Objekte, die keine Variable sind, und Variablen ohne Logging und ohne Archivwerte werden mit eigener Fehlermeldung gemeldet, ebenso ungültige Zeitangaben (z.B. Millisekunden oder `from` nach `to`).

---

#### `get-aggregated-data` - Aggregierte Daten
Holt aggregierte Werte einer archivierten Variable (Mittelwert, Minimum und Maximum je Zeitabschnitt), neueste zuerst. Für lange Zeiträume und Fragen nach Minimum, Maximum, Durchschnitt oder Verbrauch besser geeignet als `get-logged-data`.

**Parameter**
- `variableID` (Integer): Die Variable (Boolean, Integer oder Float). Sie muss existieren und vom Archiv geloggt werden. String-Variablen lassen sich nicht aggregieren
- `level` (Integer): Aggregationsstufe `0` = Stunde, `1` = Tag, `2` = Woche (ab Montag), `3` = Monat, `4` = Jahr, `5` = 5 Minuten, `6` = 1 Minute
- `period`, `from`, `to` (optional): Zeitraum wie bei `get-logged-data`
- `limit` (Integer, optional): Maximale Anzahl Buckets (1 bis 5000, Standard 1000)

Jeder Bucket, der den Zeitraum berührt, wird vollständig geliefert: `from` wird auf den Beginn seines Buckets zurückgesetzt (Symcon selbst liefert nur Buckets, die im Zeitraum beginnen). Beide Grenzen sind inklusive, ein Bucket, der genau bei `to` beginnt, ist enthalten. `{"variableID": 18543, "level": 1, "period": "yesterday"}` liefert genau einen Tages-Bucket.

**Rückgabe**
- `data` (Liste) mit Buckets:
  - `TimeStamp`: Beginn des Buckets (der älteste Bucket beginnt mit dem ersten geloggten Wert)
  - `Duration`: Abgedeckte Zeit in Sekunden. Der neueste Bucket kann noch laufen
  - `Avg`, `Min`, `Max`: Zeitgewichteter Mittelwert, Minimum, Maximum. Bei Boolean ist `Avg` der Zeitanteil von true (0 bis 1)
  - `MinTime`, `MaxTime`: Zeitpunkte von Minimum und Maximum
  - `FormattedTime`, `FormattedMinTime`, `FormattedMaxTime`: Die Zeitpunkte im ISO 8601 Format
- `resolvedFrom` (String): Beginn des ersten Buckets im ISO 8601 Format
- `resolvedTo` (String): Ende des abgefragten Zeitraums im ISO 8601 Format
- `aggregationType` (String): `standard` oder `counter`. Bei Zählern ist `Avg` die Summe der Zuwächse im Bucket (z.B. Verbrauch), `Min`/`Max` sind der kleinste und größte Zuwachs
- `loggingActive` (Boolean): false, wenn das Logging der Variable abgeschaltet wurde. Die Buckets enden dann mit dem letzten geloggten Wert, der aktuelle Wert kann abweichen (siehe `get-value`). Vorhandene Buckets bleiben erhalten, Symcon aggregiert aber nicht mehr, Buckets können daher auch fehlen oder unvollständig sein. Die geloggten Werte liefert `get-logged-data`
- `truncated` (Boolean): true, wenn im Zeitraum ältere Buckets über `limit` hinaus existieren
- `nextTo` (Integer, nur bei `truncated`): Wert für `to`, um die nächste ältere Seite abzurufen (mit `from` = `resolvedFrom`)

Fehlende Objekte, Objekte, die keine Variable sind, String-Variablen, Variablen ohne Logging, ungültige Stufen und ungültige Zeitangaben werden mit eigener Fehlermeldung gemeldet.

---

#### `get-status-log` - Systemprotokolle
Holt Meldungen aus dem Symcon-Statusprotokoll.

**Parameter**
- `types` (Liste, optional): Eine Liste von Meldungstypen, die abgefragt werden sollen:
    - `0` = Standard-Meldungen
    - `1` = Erfolgs-Meldungen
    - `2` = Mitteilungen
    - `3` = Warnungen
    - `4` = Fehler
    - `5` = Debug-Meldungen
    - `6` = Benutzerdefinierte Meldungen

**Rückgabe**
- `data` (Liste) Meldungen als Objekt codiert:
  - `TimeStamp`: Zeitpunkt
  - `Sender`: Komponente, die die Meldung verursacht hat
  - `Message`: Die Meldung
  - `Type`: Der Meldungstyp

---

### Verwaltungsfunktionen

#### `rename-object` - Objekt umbenennen
Ändert den Namen eines Symcon-Objekts.

**Parameter**
- `objectID` (Integer): Die ID des Objekts
- `newName` (String): Der neue Name

**Rückgabe**
- `success` (Boolean): Erfolgsmeldung
