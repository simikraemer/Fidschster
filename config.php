<?php
/**
 * Hitster-Finale: lokale Konfiguration.
 * Diese Datei wird nur serverseitig geladen. Die Codes werden nicht an den Browser ausgeliefert.
 */

declare(strict_types=1);

function env_value(string $key, string $default = ''): string
{
    static $values = null;

    if ($values === null) {
        $envFile = __DIR__ . '/.env';
        $values = is_file($envFile) ? (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: []) : [];
    }

    $value = getenv($key);
    if ($value !== false) {
        return (string)$value;
    }

    return (string)($values[$key] ?? $default);
}

define('ADMIN_CODE', env_value('FINALE_ADMIN_CODE', env_value('ADMIN_CODE')));

define('PLAYER_CODES', [
    0 => env_value('FINALE_PLAYER_1_CODE', env_value('PLAYER_CODE_1')),
    1 => env_value('FINALE_PLAYER_2_CODE', env_value('PLAYER_CODE_2')),
    2 => env_value('FINALE_PLAYER_3_CODE', env_value('PLAYER_CODE_3')),
]);

const PLAYER_DEFAULT_NAMES = [
    0 => 'Spieler 1',
    1 => 'Spieler 2',
    2 => 'Spieler 3',
];

const STATE_FILE = __DIR__ . '/data/state.json';
const DECK_FILE = __DIR__ . '/data/cards.json';

const PLAYER_COUNT = 3;
const WIN_CARD_COUNT = 7;
const CHALLENGE_SECONDS = 5;
const TRANSITION_SECONDS = 5;

/**
 * Aktiviere das nur in einem abgeschlossenen LAN/VPN.
 * Für Internetbetrieb sollte zusätzlich HTTPS, Basic Auth oder ein Reverse-Proxy-Login davor.
 */
const ALLOW_SPECTATORS_WITHOUT_CODE = true;
