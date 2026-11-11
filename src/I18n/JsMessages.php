<?php

namespace App\I18n;

use Symfony\Component\Translation\TranslatableMessage;

use function Symfony\Component\Translation\t;

/**
 * Texts the browser script (public/js/game.js) shows, translated into the page's
 * language and handed over as window.I18N (German text => translation).
 */
final class JsMessages
{
    /** @return list<TranslatableMessage> */
    public static function all(): array
    {
        return [
            t('Alles gelesen'),
            t('Kopiert ✓'),
            t('Link kopieren:'),
            t('Ungespeicherte Änderungen'),
            t('endet am {date}'),
            t('1 bis 30 Tage'),
            t('PayPal konnte nicht geladen werden.'),
            t('Die Bestätigung ist fehlgeschlagen. Lade die Seite neu – das Abo wird über PayPal nachgetragen.'),
            t('PayPal meldet einen Fehler. Bitte versuche es später noch einmal.'),
            t('Diese Flotte hat keinen Frachtraum – nur Frachter und Großfrachter transportieren Ladung.'),
            t('Die ausgewählten Flotten haben keinen Frachtraum.'),
            t('📦 Frachtraum der Auswahl {capacity} · bereits geladen {loaded} · neue Ladung {typed} · frei {free} – beim Transport im Konvoi wird die Ladung auf die Frachter verteilt.'),
            t('📦 Frachtraum {capacity} · bereits geladen {loaded} · neue Ladung {typed} · frei {free}'),
            t('{free} (zu viel!)'),
            t('Keine Flotte ausgewählt'),
            t('1 Flotte ausgewählt'),
            t('{count} Flotten ausgewählt'),
            t('← Hake oben in der Tabelle die Flotte(n) an, die losfliegen sollen.'),
            t('← Wähle oben eine Aktion aus.'),
            t('(ausgeschaltet)'),
            t('– {shown} von {total} Flotten'),
            t('Wähle eine Flotte aus.'),
            t('Geschwindigkeit: 0,1 bis 100 %'),
            t('Flugdauer {duration} · Ankunft heute {time} Uhr · Rückkehr {back} nach Start'),
            t('Flugdauer {duration} · Ankunft {time} Uhr · Rückkehr {back} nach Start'),
            t('Bestätigen'),
            t('Verbrauch hin und zurück: {hydrogen} Wasserstoff · {food} Nahrung · {water} Wasser'),
            t('Verbrauch für den Hinflug: {hydrogen} Wasserstoff · {food} Nahrung · {water} Wasser'),
            t('Flugdauer {duration} · Ankunft heute {time} Uhr · die Flotte bleibt am Ziel'),
            t('Flugdauer {duration} · Ankunft {time} Uhr · die Flotte bleibt am Ziel'),
        ];
    }
}
