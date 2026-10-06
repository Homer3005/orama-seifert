#!/usr/bin/env bash
# Ausspielen der Seite per FTP auf den Webspace von All-Inkl (W3).
#
# Ablauf: erst ein Probelauf, der zeigt, was uebertragen und was geloescht
# wuerde. Danach wird nur auf ausdrueckliche Bestaetigung ausgespielt.
#
# Kennwort: steht NICHT in diesem Skript. Liegt im Repo eine Datei
# .ftp-kennwort (steht in .gitignore, wird nicht hochgeladen), wird es daraus
# gelesen, sonst beim Aufruf verdeckt abgefragt. An lftp geht es ueber die
# Umgebungsvariable LFTP_PASSWORD, nicht ueber die Befehlszeile, damit es
# nicht in der Prozessliste steht.
#
# --delete: was im Repo nicht (mehr) existiert, verschwindet auch auf dem
# Server. Ausgeschlossene Pfade fasst mirror auf keiner Seite an.

set -euo pipefail

# Zielverzeichnis auf dem Server. Wechselt die Domain, wird hier geaendert.
ZIEL="/oramaseifert.com"

FTP_HOST="w0170ebe.kasserver.com"
FTP_USER="w0170ebe"
KENNWORT_DATEI=".ftp-kennwort"

cd "$(dirname "$0")"

if ! command -v lftp >/dev/null 2>&1; then
  echo "lftp fehlt. Installieren mit: brew install lftp" >&2
  exit 1
fi

if [ -f "$KENNWORT_DATEI" ]; then
  if [ -n "$(find "$KENNWORT_DATEI" \( -perm -004 -o -perm -040 \) 2>/dev/null)" ]; then
    echo "Hinweis: $KENNWORT_DATEI ist fuer andere lesbar. Empfohlen: chmod 600 $KENNWORT_DATEI" >&2
  fi
  LFTP_PASSWORD="$(head -n 1 "$KENNWORT_DATEI")"
else
  read -r -s -p "FTP-Kennwort fuer $FTP_USER@$FTP_HOST: " LFTP_PASSWORD
  echo
fi
if [ -z "$LFTP_PASSWORD" ]; then
  echo "Kein Kennwort, Abbruch." >&2
  exit 1
fi
export LFTP_PASSWORD

# Regulaere Ausdruecke auf den Pfad relativ zum Repo, in lftp-Anfuehrungszeichen
# wegen | und \. Lokal gegen file:// geprueft. .DS_Store auch in Unterordnern.
MIRROR="mirror --reverse --delete --verbose --parallel=4 \
  --exclude '^\.git/' \
  --exclude '^_auftraege/' \
  --exclude '(^|/)\.DS_Store\$' \
  --exclude '^\.gitignore\$' \
  --exclude '^TODO\.md\$' \
  --exclude '^deploy\.sh\$' \
  --exclude '^\.ftp-kennwort\$'"

lftp_lauf() {
  lftp --env-password -u "$FTP_USER" "$FTP_HOST" <<EOF
set ftp:ssl-force true
set cmd:fail-exit true
$1
bye
EOF
}

echo "Probelauf: so wuerde $ZIEL auf $FTP_HOST angeglichen (nichts wird geaendert)."
echo
lftp_lauf "$MIRROR --dry-run . $ZIEL"
echo
read -r -p "Jetzt wirklich ausspielen? Zum Bestaetigen ja eingeben: " ANTWORT
if [ "$ANTWORT" != "ja" ]; then
  echo "Abgebrochen, nichts uebertragen."
  exit 0
fi

lftp_lauf "$MIRROR . $ZIEL"
echo "Fertig."
