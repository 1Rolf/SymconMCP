# SymconMCP — guarded fork

## Änderungen gegenüber Upstream

| Bereich | Upstream | Fork |
|---|---|---|
| Schreibzugriff | `switch-boolean` auf **jede** Variable, ungeprüft | Modus-Schalter + Metadaten-Policy, deny-by-default |
| `rename-object` | vorhanden (Konfig-Schreibpfad) | **entfernt** |
| `get-snapshot` | Komplettabzug (bis 20 MB) | **entfernt** (Kontext-Bombe fürs LLM) |
| Historie | nur Rohwerte, ohne Limit-Parameter | `limit`-Parameter + neues Tool `get-aggregated-data` |
| Nachvollziehbarkeit | keine | Begründungspflicht (`reason`) + Audit-Log (JSONL) inkl. altem Wert |
| Introspektion | keine | `get-write-policy` (read-only Vorabprüfung) |

## Sicherheitskonzept (3 Schichten)

1. **Modus-Schalter** `OFF → ADVISORY → ACTIVE`
   - `OFF`: Schreib-Tools weder gelistet noch aufrufbar.
   - `ADVISORY`: Schreibversuche werden Policy-geprüft und vollständig
     auditiert, aber **nie ausgeführt**. Sammelt Evidenz für die Beförderung
     advisory → autonom.
   - `ACTIVE`: Ausführung nur bei Policy-Freigabe.
2. **Metadaten-Policy, deny-by-default.** Schaltbar ist eine Variable nur mit
   explizitem menschlichem Grant in der zentralen Registry (JSON-Medienobjekt):
   `reviewed=true` **und** `safety=false` **und** `llm_write="allowed"`.
   Fehlender Eintrag oder fehlendes Feld ⇒ deny. Das deckt bewusst auch Geräte
   ab, deren Kritikalität an der Geräteklasse unsichtbar ist (generische
   Steckdose vor einem Heizstab, nackte Objektvariable ohne Modul).
3. **Harte GUID-Deny-List** (nur Zusatznetz): Modulklassen wie Heizung/Schloss/
   Alarm sind unabhängig von Metadaten nie schreibbar.

Jeder Schreibversuch (DENIED / ADVISORY / EXECUTED) landet mit altem Wert,
gewünschtem Wert, LLM-Begründung und Policy-Entscheid im Symcon-Meldungslog und
optional in einem JSONL-Medienobjekt (Revert-Info inklusive).

## Setup

1. Repo forken, `MCP/module.php` und `MCP/form.json` ersetzen, Modul in Symcon
   aktualisieren.
2. Medienobjekt (Dokument) anlegen, Inhalt aus `llm_metadata.example.json`
   übernehmen und anpassen; in der Instanz als *Metadata registry* wählen.
3. Optional zweites Medienobjekt als *Audit log* wählen.
4. GUID-Deny-List füllen (Modul-GUIDs eurer Heizungs-/Schloss-/Alarm-Module).
5. **Hook absichern:** `/hook/mcp` nur im LAN/VPN erreichbar machen und in der
   WebHook-Verwaltung mit Zugangsdaten schützen. Das Modul selbst enthält
   keine Authentifizierung.
6. Rollout: mit `OFF` starten (Read-only-Betrieb für erste Versuche), dann
   `ADVISORY` (Vorschläge einsammeln und Audit-Log prüfen), erst danach
   `ACTIVE`.

## Registry-Pflege

Vorgesehen ist: das LLM *generiert* Registry-Einträge (aus Objektbaum, Namen,
Profilen, Historie), ein Mensch *reviewt* — erst das Setzen von
`reviewed=true` macht schreibrelevante Felder wirksam. Ein Konsistenz-Checker
(verwaiste ObjektIDs, ungetaggte geloggte Variablen) ist als separates Skript
geplant; verwaiste Einträge sind wegen deny-by-default ungefährlich.
