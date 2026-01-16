Diese Dokumentation bietet eine detaillierte technische und funktionale Beschreibung des IP-Symcon Moduls RemoteSync.

Dokumentation: RemoteSync (RS)
Version: 1.2
Autor: Artur Fischer
Präfix: RS
Ziel: Hochperformante, bidirektionale Synchronisierung von Variablen-Strukturen zwischen entfernten IP-Symcon Systemen.

1. Funktionsbeschreibung
Das Modul spiegelt eine lokale Variablen-Struktur auf ein entferntes (Remote) Symcon-System. Im Gegensatz zu einfachen RPC-Lösungen nutzt RemoteSync eine Batch-Verarbeitung, um die Netzwerklast zu minimieren und die Konsistenz zu erhöhen.

Kern-Features:
Batch-Processing: Änderungen werden gesammelt und nach einem Debounce-Timer (200ms) in einem einzigen Paket übertragen.
Automatische Struktur-Replikation: Kategorien und Instanzen werden am Zielsystem automatisch nachgebaut.
Profil-Replikation: Lokale Variablenprofile (inkl. Icons, Farben und Assoziationen) werden auf dem Remote-System automatisch erstellt.
Rücksteuerung (Reverse Control): Remote-Variablen können Befehle (RequestAction) zurück an das lokale System senden.
Sicherheits-Integration: Nutzt das externe Secrets Manager (SEC) Modul zur sicheren Handhabung von Zugangsdaten.
2. Voraussetzungen
Secrets Manager (SEC): Muss auf dem lokalen System installiert sein, um die Zugangsdaten zum Remote-Server zu verwalten.
Remote-Zugriff: Der Remote-Server muss über seine JSON-RPC API erreichbar sein.
Secrets Manager (Remote): Falls die Rücksteuerung genutzt werden soll, muss auch auf dem Remote-System ein Secrets-Modul mit den Zugangsdaten des lokalen Systems vorhanden sein.
3. Installation & Ersteinrichtung
Modul hinzufügen: Das Repository in IP-Symcon hinzufügen und eine RemoteSync Instanz erstellen.
Authentifizierung:
Wähle unter “Local Secrets Module” deine SEC-Instanz aus.
Klicke auf “Übernehmen” (Apply), um die Liste der verfügbaren Server-Schlüssel zu laden.
Wähle den Ziel-Server aus.
Anker setzen:
Local Root Object: Wähle die Kategorie/Instanz, deren Inhalt gespiegelt werden soll.
Remote Data Target ID: Gib die ID einer Kategorie auf dem Remote-Server an, in der die Daten landen sollen.
Remote Script Home ID: Gib die ID einer Kategorie auf dem Remote-Server an, in der die Steuerungs-Scripte abgelegt werden sollen (Shared Folder).
Remote-Setup: Klicke auf den Button “Install/Update Remote Scripts”. Dies installiert den Receiver und das Gateway auf dem Zielsystem.
Variablen wählen: Markiere in der Liste “Objects to Sync” die gewünschten Variablen und aktiviere sie.
4. Parameterisierung (Konfiguration)
Sektion: Remote Mirror Configuration
Debug Mode: Aktiviert detaillierte Protokolle im Symcon-Log (Meldungsfenster).
Use Auto-Create: Wenn aktiv, werden fehlende Kategorien/Variablen auf dem Remote-System automatisch erstellt.
Replicate Variable Profiles: Synchronisiert die Definitionen der Variablenprofile (Min/Max, Assoziationen etc.) von Lokal nach Remote.
Sektion: Authentication (Local -> Remote)
Local Secrets Module: Auswahl der Instanz, die die API-Credentials hält.
Target Remote Server (Key): Der Name des Schlüssels im SEC-Modul für den Zielserver.
Sektion: Reverse Control (Remote -> Local)
Remote Secrets Instance ID: Die ID der SEC-Instanz auf dem entfernten System.
Local Server Key: Der Name des Schlüssels auf dem entfernten System, der zurück auf das lokale System zeigt.
Sektion: Mirror Anchors & Setup
Local Root Object: Der Ursprung der lokalen Datenquelle.
Remote Data Target ID: Ziel-Ordner auf dem Remote-System.
Remote Script Home ID: Speicherort für die zwei technischen Hilfsscripte auf dem Remote-System.
Sektion: Selection (Batch Tools)
Über die Buttons All / None können die Spalten der Sync-Liste massenweise bearbeitet werden:

Sync: Variable wird überwacht und übertragen.
Action (R-Action): Aktiviert die Rücksteuerung. Die Remote-Variable erhält ein Aktionsscript, das Befehle zurückschickt.
Delete (Del Remote): Markiert die Variable zur Löschung auf dem Remote-Server beim nächsten Sync.
5. Funktionsweise der Synchronisation
Lokale Logik (module.php)
MessageSink: Das Modul registriert sich auf VM_UPDATE Events aller aktivierten Variablen.
Buffering: Bei Änderung wird die Variable in ein internes Array (_BatchBuffer) geschrieben. Der BufferTimer startet neu.
Flush: Nach Ablauf des Timers (200ms Ruhe) wird der gesamte Buffer via JSON-RPC an das Remote-Script RemoteSync_Receiver gesendet.
Remote Logik (Injected Scripts)
Receiver Script:
Empfängt das Batch-Paket.
Prüft, ob Profile existieren, und legt sie ggf. an.
Identifiziert Objekte anhand eines eindeutigen Idents (Rem_[LocalID]) und verknüpft sie zusätzlich über das Feld “Info” (RS_REF:ServerKey:LocalID).
Setzt Werte und verknüpft bei Bedarf das Gateway-Script als Aktionsscript.
Gateway Script:
Wird ausgelöst, wenn ein User am Remote-System eine Variable schaltet.
Extrahiert die Ziel-ID und den Server-Key aus dem Info-Feld.
Ruft via RequestAction (oder Fallback SetValue) die Funktion auf dem lokalen Ursprungssystem auf.
6. PHP-Befehlsreferenz
Obwohl das Modul primär über die Konsole bedient wird, stehen folgende Funktionen zur Verfügung:

Funktion	Beschreibung
RS_ProcessSync(int $InstanzID)	Startet manuell einen vollständigen Abgleich aller aktiven Variablen.
RS_InstallRemoteScripts(int $InstanzID)	Installiert oder aktualisiert die Scripte auf dem Remote-Server.
RS_ToggleAll(int $InstanzID, string $Column, bool $State)	Programmatisches Setzen der Spalten “Active”, “Action” oder “Delete”.
7. Sicherheitshinweise
Keine Passwörter im Code: Das Modul speichert keine Zugangsdaten. Die Sicherheit hängt direkt von der Konfiguration des Secrets Managers (SEC) ab.
SSL/TLS: Die Kommunikation erfolgt verschlüsselt über HTTPS. Das Modul ist so konfiguriert, dass es selbstsignierte Zertifikate akzeptiert (verify_peer: false), was in internen Netzwerken oft nötig ist.
Ident-Schutz: Das Modul nutzt das Präfix Rem_ für Idents auf dem Remote-System. Manuelle Änderungen an diesen Idents können die Synchronisation unterbrechen.
