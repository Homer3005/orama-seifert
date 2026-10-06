<?php
/* Kontaktformular (W2)
   Nimmt das Formular der Startseite entgegen und schickt es als Mail an Orama.
   Laeuft auf PHP 7.4 und 8.x: keine Sprachmittel, die erst mit 8.0 kamen.

   Protokolliert NICHTS. Keine Datei, keine Datenbank, kein error_log mit Namen,
   Adressen oder Nachrichten. Einzige Spur ist die PHP-Sitzung der Bremse unten,
   sie haelt nur den Zeitpunkt der letzten Sendung, keinen Inhalt.

   Rueckweg: immer per 303 auf index.html.
   Erfolg   -> index.html#thank-you (die Dankeseite, geoeffnet von routeFromHash)
   Fehler   -> index.html?kontakt=<code>#contact-form (Hinweiszeile im Formular) */

// Wechselt die Adresse, wird hier geaendert.
const KONTAKT_EMPFAENGER = 'orama@orama-coaching.com';
// Absender aus der eigenen Domain, nicht der des Besuchers: sonst faellt die
// Mail bei SPF/DMARC der Empfaenger durch. Antwort-an traegt den Besucher.
const KONTAKT_ABSENDER = 'noreply@orama-coaching.com';
const KONTAKT_ABSENDER_NAME = 'Orama Seifert Website';
const KONTAKT_BETREFF = 'Anfrage über die Website';
const KONTAKT_SPERRE = 60;   // Sekunden zwischen zwei Sendungen derselben Sitzung

const LAENGE_NAME = 100;
const LAENGE_EMAIL = 254;
const LAENGE_BETREFF = 200;
const LAENGE_NACHRICHT = 5000;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
mb_internal_encoding('UTF-8');

function zurueck($ziel)
{
    header('Location: ' . $ziel, true, 303);
    header('Cache-Control: no-store');
    exit;
}

function fehler($code)
{
    zurueck('index.html?kontakt=' . $code . '#contact-form');
}

// Feld aus POST als gueltiger UTF-8-Text. Arrays und fehlende Felder -> ''.
function feld($name)
{
    if (!isset($_POST[$name]) || !is_string($_POST[$name])) {
        return '';
    }
    $wert = $_POST[$name];
    if (!mb_check_encoding($wert, 'UTF-8')) {
        $wert = mb_convert_encoding($wert, 'UTF-8', 'UTF-8');
    }
    return $wert;
}

// Einzeilig: Zeilenumbrueche und alle Steuerzeichen raus, auch die
// Unicode-Zeilentrenner. Danach kann keine Kopfzeile mehr eingeschleust werden.
function einzeilig($wert)
{
    $wert = preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $wert);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

// Mehrzeilig: Umbrueche vereinheitlicht, Tab und Umbruch bleiben, sonst
// keine Steuerzeichen.
function mehrzeilig($wert)
{
    $wert = str_replace(array("\r\n", "\r"), "\n", $wert);
    $wert = preg_replace('/[^\P{Cc}\n\t]/u', '', $wert);
    return trim($wert);
}

function kodiert($text)
{
    return mb_encode_mimeheader($text, 'UTF-8', 'B', "\r\n");
}

// ---- 1. Nur POST ----
if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "405 Method Not Allowed\n";
    exit;
}

// ---- 2. Fallenfelder ----
// Gefuellt heisst Bot. Wie ein Erfolg aussehen lassen, aber nichts senden.
if (feld('contact-website') !== '' || feld('company') !== '') {
    zurueck('index.html#thank-you');
}

// ---- 3. Bremse gegen Mehrfachsendungen ----
// PHP-Sitzung statt Datenbank. Gespeichert wird allein der Zeitpunkt der
// letzten erfolgreichen Sendung. Das Sitzungscookie gilt nur fuer diese Datei
// und endet mit dem Browser.
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('kontakt');
session_set_cookie_params(array(
    'lifetime' => 0,
    'path'     => isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/kontakt.php',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax',
));
session_start();
$zuletzt = isset($_SESSION['zuletzt']) ? (int) $_SESSION['zuletzt'] : 0;
if ($zuletzt > 0 && time() - $zuletzt < KONTAKT_SPERRE) {
    session_write_close();
    fehler('warten');
}

// ---- 4. Felder lesen und pruefen ----
$roh_name      = feld('name');
$roh_email     = feld('email');
$roh_betreff   = feld('offering');
$roh_nachricht = feld('message');

$name      = einzeilig($roh_name);
$email     = einzeilig($roh_email);
$betreff   = einzeilig($roh_betreff);
$nachricht = mehrzeilig($roh_nachricht);
$zustimmung = feld('consent') === 'yes';

if ($name === '' || $email === '' || $nachricht === '' || !$zustimmung) {
    session_write_close();
    fehler('pflicht');
}
if (mb_strlen($name) > LAENGE_NAME || mb_strlen($email) > LAENGE_EMAIL
    || mb_strlen($betreff) > LAENGE_BETREFF || mb_strlen($nachricht) > LAENGE_NACHRICHT) {
    session_write_close();
    fehler('laenge');
}
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    session_write_close();
    fehler('adresse');
}

// ---- 5. Mail bauen ----
$betreffzeile = KONTAKT_BETREFF . ($betreff !== '' ? ' – ' . $betreff : '');

$text = "Neue Anfrage über das Kontaktformular der Website.\n\n"
      . "Name: " . $name . "\n"
      . "E-Mail: " . $email . "\n"
      . "Angebot: " . ($betreff !== '' ? $betreff : '–') . "\n"
      . "Einwilligung Datenschutz: ja\n\n"
      . "Nachricht:\n" . $nachricht . "\n";

$kopf = array(
    'From: ' . kodiert(KONTAKT_ABSENDER_NAME) . ' <' . KONTAKT_ABSENDER . '>',
    'Reply-To: ' . kodiert($name) . ' <' . $email . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: quoted-printable',
);

$gesendet = mail(
    KONTAKT_EMPFAENGER,
    kodiert($betreffzeile),
    quoted_printable_encode(str_replace("\n", "\r\n", $text)),
    implode("\r\n", $kopf),
    '-f' . KONTAKT_ABSENDER
);

if (!$gesendet) {
    session_write_close();
    fehler('versand');
}

$_SESSION['zuletzt'] = time();
session_write_close();
zurueck('index.html#thank-you');
