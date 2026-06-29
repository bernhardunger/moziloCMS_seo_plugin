# Release-Workflow seo_urls

## Voraussetzungen
- Branch vollständig umgesetzt, alle Tests grün
- Code Review abgeschlossen, alle Blocker behoben
- Staging-Test bestanden

## 1. Release-Dateien finalisieren (auf Feature-Branch)
- [ ] `index.php`: `const VERSION = 'vX.Y.Z'`
- [ ] `CHANGELOG.md`: `tbd` → Datum, Format `## [vX.Y.Z] – YYYY-MM-DD`
- [ ] `README.md`: veraltete Dateinamen/Versionen prüfen
- [ ] `memory/project_status.md`: Versionsstand aktualisieren

Commit: "Release: vX.Y.Z – VERSION, README und CHANGELOG finalisiert"

## 2. PR → Merge
- PR auf GitHub erstellen: Feature-Branch → main
- Review, dann merge

## 3. ZIP bauen (lokal, nach Merge auf main)

    git checkout main
    git pull
    git archive --format=zip --prefix=_seo_urls/ HEAD:_seo_urls/ -o _seo_urls-vX.Y.Z.zip

Vollständiger Befehl mit aktueller Version: siehe doc/release-commands.md

## 4. GitHub Release
- Tag setzen: `git tag vX.Y.Z && git push origin vX.Y.Z`
- GitHub Release anlegen: Tag auswählen, ZIP als Asset hochladen
- GitHub-Auto-ZIP ignorieren (kann nicht gelöscht werden)

## 5. Deploy
- ZIP auf Staging deployen → Smoke-Test
- ZIP auf Produktion deployen (steuerkanzlei-hader.de)

## 6. Notes-Repo aktualisieren

    Arbeitsverzeichnis: C:\dev\moziloCMS-seo_urls_notes
    Finding X in ToDos_Offen.txt → ToDos_Erledigt.txt verschieben
    Stand-Datum aktualisieren
    git add ToDos_Offen.txt ToDos_Erledigt.txt
    git commit -m "Dokumentation: Finding X erledigt – vX.Y.Z"

## Hinweise
- seo_urls_projekt_kontext.md ist gitignored – lokal aktualisieren, nie committen
- memory/project_status.md ist gitignored – lokal aktualisieren, nie committen
- ZIP-Inhalt prüfen: `unzip -l _seo_urls-vX.Y.Z.zip` (keine tests/, vendor/, .claude/)
