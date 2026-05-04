<?php
session_start();
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_response(['ok' => false, 'error' => 'Ungültiger JSON-Request.'], 400);
    }
    return $data;
}

function require_login(): void
{
    if (!isset($_SESSION['role'])) {
        json_response(['ok' => false, 'error' => 'Nicht eingeloggt.'], 401);
    }
}

function require_admin(): void
{
    require_login();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        json_response(['ok' => false, 'error' => 'Nur der Admin darf diese Aktion ausführen.'], 403);
    }
}

function current_player_index(): ?int
{
    if (($_SESSION['role'] ?? '') === 'player' && isset($_SESSION['playerIndex'])) {
        return (int)$_SESSION['playerIndex'];
    }
    return null;
}

function load_deck(): array
{
    if (!is_file(DECK_FILE)) {
        json_response(['ok' => false, 'error' => 'Deck-Datei fehlt: data/cards.json'], 500);
    }
    $raw = file_get_contents(DECK_FILE);
    $deck = json_decode($raw ?: '[]', true);
    if (!is_array($deck)) {
        json_response(['ok' => false, 'error' => 'Deck-Datei ist kein gültiges JSON.'], 500);
    }

    $byId = [];
    foreach ($deck as $card) {
        if (!isset($card['id'], $card['year'], $card['image'])) {
            continue;
        }
        $id = (string)$card['id'];
        $byId[$id] = [
            'id' => $id,
            'title' => (string)($card['title'] ?? $id),
            'year' => (int)$card['year'],
            'image' => (string)$card['image'],
        ];
    }
    return $byId;
}

function initial_state(): array
{
    $deck = load_deck();
    $drawPile = array_keys($deck);
    shuffle($drawPile);

    $players = [];
    for ($i = 0; $i < PLAYER_COUNT; $i++) {
        $players[] = [
            'name' => PLAYER_DEFAULT_NAMES[$i] ?? ('Finalist ' . ($i + 1)),
            'tokens' => 0,
            'cards' => [],
            'effectSeq' => 0,
            'effectDelta' => 0,
            'effectAt' => 0,
        ];
    }

    return [
        'version' => 2,
        'gameStarted' => false,
        'phase' => 'lobby',
        'players' => $players,
        'turn' => 0,
        'roundSeq' => 0,
        'turnStartedAt' => 0,
        'drawPile' => $drawPile,
        'discardPile' => [],
        'currentCardId' => null,
        'currentPlacement' => null,
        'challenge' => null,
        'drawnAt' => 0,
        'lockedAt' => 0,
        'winner' => null,
        'lastResolution' => null,
        'updatedAt' => time(),
    ];
}

function with_state(callable $fn): array
{
    $dir = dirname(STATE_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $handle = fopen(STATE_FILE, 'c+');
    if (!$handle) {
        json_response(['ok' => false, 'error' => 'State-Datei kann nicht geöffnet werden.'], 500);
    }

    flock($handle, LOCK_EX);
    rewind($handle);
    $raw = stream_get_contents($handle);
    $state = $raw ? json_decode($raw, true) : null;
    if (!is_array($state)) {
        $state = initial_state();
    }
    migrate_state($state);

    $changed = auto_advance($state);
    $result = $fn($state, $changed);
    if (!is_array($result)) {
        $result = ['state' => $state, 'changed' => true];
    }
    $state = $result['state'] ?? $state;
    $changed = (bool)($result['changed'] ?? $changed);

    if ($changed) {
        $state['updatedAt'] = time();
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $state;
}

function migrate_state(array &$state): void
{
    if (!isset($state['roundSeq'])) {
        $state['roundSeq'] = 0;
    }
    if (!isset($state['turnStartedAt'])) {
        $state['turnStartedAt'] = 0;
    }
    if (($state['phase'] ?? '') === 'overview' && ($state['gameStarted'] ?? false)) {
        draw_next_card($state);
    }
    if (($state['phase'] ?? '') === 'round_transition' && ($state['gameStarted'] ?? false)) {
        if (!isset($state['transitionUntil'])) {
            $state['transitionUntil'] = time();
        }
        if (!isset($state['nextTurn'])) {
            $state['nextTurn'] = ((int)($state['turn'] ?? 0) + 1) % PLAYER_COUNT;
        }
    }
}

function transition_to_next_round(array &$state): void
{
    $state['nextTurn'] = ((int)($state['turn'] ?? 0) + 1) % PLAYER_COUNT;
    $state['phase'] = 'round_transition';
    $state['transitionUntil'] = time() + TRANSITION_SECONDS;
    $state['currentPlacement'] = null;
    $state['challenge'] = null;
    $state['lockedAt'] = 0;
}

function auto_advance(array &$state): bool
{
    $changed = false;

    if (!($state['gameStarted'] ?? false) || ($state['phase'] ?? '') === 'finished') {
        return false;
    }

    if (($state['phase'] ?? '') === 'round_transition') {
        if ((int)($state['transitionUntil'] ?? 0) <= time()) {
            $state['turn'] = (int)($state['nextTurn'] ?? (((int)($state['turn'] ?? 0) + 1) % PLAYER_COUNT));
            unset($state['nextTurn']);
            $state['currentCardId'] = null;
            $state['currentPlacement'] = null;
            $state['challenge'] = null;
            draw_next_card($state);
            return true;
        }
        return false;
    }

    if (($state['phase'] ?? '') === 'challenge_window') {
        $deadline = (int)($state['lockedAt'] ?? 0) + CHALLENGE_SECONDS;
        $hasChallenge = isset($state['challenge']['by']) && $state['challenge']['by'] !== null;
        if (!$hasChallenge && $deadline <= time()) {
            resolve_current_round($state);
            $changed = true;
        }
    }

    if (($state['phase'] ?? '') === 'resolving') {
        resolve_current_round($state);
        $changed = true;
    }

    if (($state['phase'] ?? '') === 'overview') {
        draw_next_card($state);
        $changed = true;
    }

    return $changed;
}

function public_card(array $deck, string $cardId, bool $reveal = true): ?array
{
    if (!isset($deck[$cardId])) {
        return null;
    }

    $card = $deck[$cardId];
    if (!$reveal) {
        return [
            'id' => $card['id'],
            'image' => $card['image'],
        ];
    }
    return $card;
}

function export_state(array $state): array
{
    $deck = load_deck();
    $me = [
        'role' => $_SESSION['role'] ?? null,
        'playerIndex' => current_player_index(),
    ];

    $players = [];
    foreach (($state['players'] ?? []) as $idx => $player) {
        $cards = [];
        foreach (($player['cards'] ?? []) as $cardId) {
            $card = public_card($deck, (string)$cardId, true);
            if ($card) {
                $cards[] = $card;
            }
        }
        $players[] = [
            'index' => $idx,
            'name' => (string)($player['name'] ?? ('Finalist ' . ($idx + 1))),
            'tokens' => (int)($player['tokens'] ?? 0),
            'cards' => $cards,
            'effectSeq' => (int)($player['effectSeq'] ?? 0),
            'effectDelta' => (int)($player['effectDelta'] ?? 0),
            'effectAt' => (int)($player['effectAt'] ?? 0),
        ];
    }

    $currentCard = null;
    if (!empty($state['currentCardId'])) {
        // Absichtlich für alle Rollen verdeckt. Der Admin teilt seinen Bildschirm im Call.
        $currentCard = public_card($deck, (string)$state['currentCardId'], false);
    }

    return [
        'ok' => true,
        'serverNow' => time(),
        'me' => $me,
        'config' => [
            'playerCount' => PLAYER_COUNT,
            'winCardCount' => WIN_CARD_COUNT,
            'challengeSeconds' => CHALLENGE_SECONDS,
        ],
        'gameStarted' => (bool)($state['gameStarted'] ?? false),
        'phase' => (string)($state['phase'] ?? 'lobby'),
        'players' => $players,
        'turn' => (int)($state['turn'] ?? 0),
        'roundSeq' => (int)($state['roundSeq'] ?? 0),
        'turnStartedAt' => (int)($state['turnStartedAt'] ?? 0),
        'deckRemaining' => count($state['drawPile'] ?? []),
        'currentCard' => $currentCard,
        'currentPlacement' => $state['currentPlacement'] ?? null,
        'challenge' => $state['challenge'] ?? null,
        'drawnAt' => (int)($state['drawnAt'] ?? 0),
        'lockedAt' => (int)($state['lockedAt'] ?? 0),
        'challengeDeadline' => (($state['phase'] ?? '') === 'challenge_window') ? ((int)($state['lockedAt'] ?? 0) + CHALLENGE_SECONDS) : null,
        'winner' => $state['winner'] ?? null,
        'lastResolution' => $state['lastResolution'] ?? null,
        'updatedAt' => (int)($state['updatedAt'] ?? 0),
    ];
}

function ensure_position(array $state, int $playerIndex, int $position): void
{
    if ($playerIndex < 0 || $playerIndex >= PLAYER_COUNT) {
        json_response(['ok' => false, 'error' => 'Ungültiger Spieler.'], 400);
    }
    $count = count($state['players'][$playerIndex]['cards'] ?? []);
    if ($position < 0 || $position > $count) {
        json_response(['ok' => false, 'error' => 'Ungültige Position im Zeitstrahl.'], 400);
    }
}

function placement_is_correct(array $cardIds, int $position, string $newCardId, array $deck): bool
{
    if (!isset($deck[$newCardId])) {
        return false;
    }

    $year = (int)$deck[$newCardId]['year'];
    $leftOk = true;
    $rightOk = true;

    if ($position > 0) {
        $leftId = (string)$cardIds[$position - 1];
        $leftOk = isset($deck[$leftId]) && ((int)$deck[$leftId]['year'] <= $year);
    }
    if ($position < count($cardIds)) {
        $rightId = (string)$cardIds[$position];
        $rightOk = isset($deck[$rightId]) && ($year <= (int)$deck[$rightId]['year']);
    }

    return $leftOk && $rightOk;
}

function sorted_position_for_card(array $cardIds, string $newCardId, array $deck): int
{
    if (!isset($deck[$newCardId])) {
        return count($cardIds);
    }

    $year = (int)$deck[$newCardId]['year'];
    foreach ($cardIds as $idx => $cardId) {
        if (isset($deck[$cardId]) && $year < (int)$deck[$cardId]['year']) {
            return (int)$idx;
        }
    }

    return count($cardIds);
}

function insert_card(array &$state, int $playerIndex, int $position, string $cardId): void
{
    $cards = $state['players'][$playerIndex]['cards'] ?? [];
    array_splice($cards, $position, 0, [$cardId]);
    $state['players'][$playerIndex]['cards'] = array_values($cards);
}

function add_token_effect(array &$state, int $playerIndex, int $delta): void
{
    $tokens = (int)($state['players'][$playerIndex]['tokens'] ?? 0);
    $state['players'][$playerIndex]['tokens'] = max(0, $tokens + $delta);
    $state['players'][$playerIndex]['effectSeq'] = (int)($state['players'][$playerIndex]['effectSeq'] ?? 0) + 1;
    $state['players'][$playerIndex]['effectDelta'] = $delta;
    $state['players'][$playerIndex]['effectAt'] = time();
}

function winner_by_most_cards(array $state): array
{
    $maxCards = -1;
    $winners = [];

    foreach (($state['players'] ?? []) as $idx => $player) {
        $cardCount = count($player['cards'] ?? []);
        if ($cardCount > $maxCards) {
            $maxCards = $cardCount;
            $winners = [$idx];
            continue;
        }
        if ($cardCount === $maxCards) {
            $winners[] = $idx;
        }
    }

    $names = array_map(function (int $idx) use ($state): string {
        return (string)($state['players'][$idx]['name'] ?? ('Finalist ' . ($idx + 1)));
    }, $winners);

    return [
        'index' => count($winners) === 1 ? $winners[0] : null,
        'name' => implode(' & ', $names),
        'isTie' => count($winners) > 1,
    ];
}

function draw_next_card(array &$state): void
{
    if (empty($state['drawPile'])) {
        $winner = winner_by_most_cards($state);
        $state['phase'] = 'finished';
        $state['winner'] = array_merge($winner, [
            'at' => time(),
            'reason' => 'Deck leer.',
        ]);
        $state['currentCardId'] = null;
        $state['currentPlacement'] = null;
        $state['challenge'] = null;
        return;
    }

    $state['currentCardId'] = array_shift($state['drawPile']);
    $state['phase'] = 'placing';
    $state['drawnAt'] = time();
    $state['turnStartedAt'] = time();
    $state['roundSeq'] = (int)($state['roundSeq'] ?? 0) + 1;
    $state['currentPlacement'] = null;
    $state['challenge'] = null;
    unset($state['nextTurn']);
    $state['lockedAt'] = 0;
}

function finish_round(array &$state, ?int $winnerIndex, ?int $winnerPosition, string $reason, array $details = []): void
{
    $cardId = (string)($state['currentCardId'] ?? '');
    if ($cardId === '') {
        return;
    }

    if ($winnerIndex !== null && $winnerPosition !== null) {
        insert_card($state, $winnerIndex, $winnerPosition, $cardId);
    } else {
        $state['discardPile'][] = $cardId;
    }

    $state['lastResolution'] = array_merge([
        'winnerIndex' => $winnerIndex,
        'reason' => $reason,
        'cardId' => $cardId,
        'at' => time(),
    ], $details);

    if ($winnerIndex !== null && count($state['players'][$winnerIndex]['cards'] ?? []) >= WIN_CARD_COUNT) {
        $state['phase'] = 'finished';
        $state['winner'] = [
            'index' => $winnerIndex,
            'name' => $state['players'][$winnerIndex]['name'] ?? ('Finalist ' . ($winnerIndex + 1)),
            'at' => time(),
        ];
        $state['currentCardId'] = null;
        $state['currentPlacement'] = null;
        $state['challenge'] = null;
        return;
    }

    transition_to_next_round($state);
}

function resolve_current_round(array &$state): void
{
    if (empty($state['currentCardId']) || empty($state['currentPlacement'])) {
        return;
    }

    $deck = load_deck();
    $cardId = (string)$state['currentCardId'];
    $owner = (int)$state['currentPlacement']['playerIndex'];
    $ownerPos = (int)$state['currentPlacement']['position'];
    $challenger = $state['challenge']['by'] ?? null;
    $challengerPos = null;

    if ($challenger !== null && isset($state['challenge']['placement']['position'])) {
        $challenger = (int)$challenger;
        $challengerPos = (int)$state['challenge']['placement']['position'];
    }

    $ownerCards = $state['players'][$owner]['cards'] ?? [];
    $ownerCorrect = placement_is_correct($ownerCards, $ownerPos, $cardId, $deck);
    $challengerCorrect = false;
    if ($challenger !== null && $challengerPos !== null) {
        $challengerCorrect = placement_is_correct($ownerCards, $challengerPos, $cardId, $deck);
    }

    $details = [
        'card' => [
            'title' => (string)($deck[$cardId]['title'] ?? $cardId),
            'year' => (int)($deck[$cardId]['year'] ?? 0),
        ],
        'results' => [[
            'name' => (string)($state['players'][$owner]['name'] ?? ('Finalist ' . ($owner + 1))),
            'correct' => $ownerCorrect,
        ]],
    ];
    if ($challenger !== null) {
        $details['results'][] = [
            'name' => (string)($state['players'][$challenger]['name'] ?? ('Finalist ' . ($challenger + 1))),
            'correct' => $challengerCorrect,
        ];
    }

    if ($ownerCorrect) {
        if ($challenger !== null) {
            add_token_effect($state, $challenger, 1);
        }
        finish_round($state, $owner, $ownerPos, 'Original-Platzierung korrekt.', $details);
        return;
    }

    if ($challengerCorrect) {
        $winnerPos = sorted_position_for_card($state['players'][$challenger]['cards'] ?? [], $cardId, $deck);
        finish_round($state, $challenger, $winnerPos, 'Herausforderer-Platzierung korrekt.', $details);
        return;
    }

    finish_round($state, null, null, 'Alle Platzierungen falsch. Karte entfernt.', $details);
}

$action = $_GET['action'] ?? 'state';

if ($action === 'login') {
    $body = request_json();
    $mode = (string)($body['mode'] ?? '');

    if ($mode === 'spectator') {
        if (!ALLOW_SPECTATORS_WITHOUT_CODE) {
            json_response(['ok' => false, 'error' => 'Zuschauer-Login ist deaktiviert.'], 403);
        }
        $_SESSION['role'] = 'spectator';
        unset($_SESSION['playerIndex']);
        json_response(['ok' => true, 'role' => 'spectator']);
    }

    if ($mode === 'code') {
        $code = trim((string)($body['code'] ?? ''));
        if ($code !== '' && hash_equals(ADMIN_CODE, $code)) {
            $_SESSION['role'] = 'admin';
            unset($_SESSION['playerIndex']);
            json_response(['ok' => true, 'role' => 'admin']);
        }
        foreach (PLAYER_CODES as $idx => $playerCode) {
            if ($code !== '' && hash_equals((string)$playerCode, $code)) {
                $_SESSION['role'] = 'player';
                $_SESSION['playerIndex'] = (int)$idx;
                json_response(['ok' => true, 'role' => 'player', 'playerIndex' => (int)$idx]);
            }
        }
        json_response(['ok' => false, 'error' => 'Code nicht erkannt.'], 403);
    }

    json_response(['ok' => false, 'error' => 'Unbekannter Login-Modus.'], 400);
}

if ($action === 'logout') {
    session_destroy();
    json_response(['ok' => true]);
}

require_login();
// Danach lesen die Aktionen die Session nur noch. Den PHP-Session-Lock früh freigeben,
// damit ein laufender State-Poll keine Button-Aktion derselben Browser-Session ausbremst.
session_write_close();

if ($action === 'state') {
    $state = with_state(fn(array $state, bool $changed) => ['state' => $state, 'changed' => $changed]);
    json_response(export_state($state));
}

if ($action === 'start_game') {
    require_admin();
    $body = request_json();
    $names = $body['names'] ?? [];

    $state = with_state(function (array $state) use ($names) {
        $new = initial_state();
        for ($i = 0; $i < PLAYER_COUNT; $i++) {
            $name = trim((string)($names[$i] ?? ''));
            $new['players'][$i]['name'] = $name !== '' ? mb_substr($name, 0, 40) : (PLAYER_DEFAULT_NAMES[$i] ?? ('Finalist ' . ($i + 1)));
        }
        $new['gameStarted'] = true;
        $new['turn'] = 0;
        draw_next_card($new);
        return ['state' => $new, 'changed' => true];
    });
    json_response(export_state($state));
}

if ($action === 'reset_game') {
    require_admin();
    $state = with_state(fn(array $state) => ['state' => initial_state(), 'changed' => true]);
    json_response(export_state($state));
}

if ($action === 'draw_card') {
    // Nur als Recovery-Hook behalten. Der normale Spielablauf zieht automatisch.
    require_admin();
    $state = with_state(function (array $state) {
        if (!($state['gameStarted'] ?? false)) {
            json_response(['ok' => false, 'error' => 'Das Spiel wurde noch nicht gestartet.'], 400);
        }
        if (in_array(($state['phase'] ?? ''), ['placing', 'challenge_window', 'challenger_placing', 'finished'], true)) {
            json_response(['ok' => false, 'error' => 'Aktuell liegt bereits eine Karte oder das Spiel ist beendet.'], 400);
        }
        draw_next_card($state);
        return ['state' => $state, 'changed' => true];
    });
    json_response(export_state($state));
}

if ($action === 'lock_placement') {
    $playerIndex = current_player_index();
    if ($playerIndex === null) {
        json_response(['ok' => false, 'error' => 'Nur Finalisten dürfen Karten einloggen.'], 403);
    }
    $body = request_json();
    $position = (int)($body['position'] ?? -1);

    $state = with_state(function (array $state) use ($playerIndex, $position) {
        if (($state['phase'] ?? '') !== 'placing') {
            json_response(['ok' => false, 'error' => 'Aktuell kann keine Platzierung eingeloggt werden.'], 400);
        }
        if ($playerIndex !== (int)($state['turn'] ?? 0)) {
            json_response(['ok' => false, 'error' => 'Nur der aktive Finalist darf einloggen.'], 403);
        }
        ensure_position($state, $playerIndex, $position);
        $state['currentPlacement'] = [
            'playerIndex' => $playerIndex,
            'position' => $position,
            'at' => time(),
        ];
        $state['lockedAt'] = time();
        $state['challenge'] = null;
        $state['phase'] = 'challenge_window';
        return ['state' => $state, 'changed' => true];
    });
    json_response(export_state($state));
}

if ($action === 'halt') {
    $playerIndex = current_player_index();
    if ($playerIndex === null) {
        json_response(['ok' => false, 'error' => 'Nur Finalisten können Halt drücken.'], 403);
    }

    $state = with_state(function (array $state) use ($playerIndex) {
        if (($state['phase'] ?? '') !== 'challenge_window') {
            json_response(['ok' => false, 'error' => 'Das Halt-Fenster ist nicht offen.'], 400);
        }
        if ($playerIndex === (int)($state['turn'] ?? 0)) {
            json_response(['ok' => false, 'error' => 'Der aktive Finalist kann seine eigene Platzierung nicht herausfordern.'], 403);
        }
        if (time() > (int)($state['lockedAt'] ?? 0) + CHALLENGE_SECONDS) {
            resolve_current_round($state);
            return ['state' => $state, 'changed' => true];
        }
        if (($state['players'][$playerIndex]['tokens'] ?? 0) <= 0) {
            json_response(['ok' => false, 'error' => 'Keine Marke verfügbar.'], 403);
        }
        if (isset($state['challenge']['by']) && $state['challenge']['by'] !== null) {
            json_response(['ok' => false, 'error' => 'Ein anderer Finalist war schneller.'], 409);
        }

        add_token_effect($state, $playerIndex, -1);
        $state['challenge'] = [
            'by' => $playerIndex,
            'at' => time(),
            'placement' => null,
        ];
        $state['phase'] = 'challenger_placing';
        return ['state' => $state, 'changed' => true];
    });
    json_response(export_state($state));
}

if ($action === 'lock_challenge') {
    $playerIndex = current_player_index();
    if ($playerIndex === null) {
        json_response(['ok' => false, 'error' => 'Nur Finalisten dürfen platzieren.'], 403);
    }
    $body = request_json();
    $position = (int)($body['position'] ?? -1);

    $state = with_state(function (array $state) use ($playerIndex, $position) {
        if (($state['phase'] ?? '') !== 'challenger_placing') {
            json_response(['ok' => false, 'error' => 'Aktuell gibt es keine Herausforderer-Platzierung.'], 400);
        }
        $challenger = $state['challenge']['by'] ?? null;
        if ($challenger === null || $playerIndex !== (int)$challenger) {
            json_response(['ok' => false, 'error' => 'Nur der Herausforderer darf diese Platzierung einloggen.'], 403);
        }
        $owner = (int)($state['currentPlacement']['playerIndex'] ?? ($state['turn'] ?? 0));
        ensure_position($state, $owner, $position);
        if ($position === (int)($state['currentPlacement']['position'] ?? -1)) {
            json_response(['ok' => false, 'error' => 'Der Herausforderer muss eine andere Position wählen.'], 400);
        }
        $state['challenge']['placement'] = [
            'playerIndex' => $owner,
            'position' => $position,
            'at' => time(),
        ];
        resolve_current_round($state);
        return ['state' => $state, 'changed' => true];
    });
    json_response(export_state($state));
}

if ($action === 'add_token' || $action === 'remove_token') {
    require_admin();
    $body = request_json();
    $playerIndex = (int)($body['playerIndex'] ?? -1);
    if ($playerIndex < 0 || $playerIndex >= PLAYER_COUNT) {
        json_response(['ok' => false, 'error' => 'Ungültiger Spieler.'], 400);
    }
    $delta = $action === 'add_token' ? 1 : -1;

    $state = with_state(function (array $state) use ($playerIndex, $delta) {
        add_token_effect($state, $playerIndex, $delta);
        return ['state' => $state, 'changed' => true];
    });
    json_response(export_state($state));
}

if ($action === 'resolve_round') {
    // Backward compatible, falls ein alter Client den Button noch im Cache hat.
    require_admin();
    $state = with_state(function (array $state) {
        resolve_current_round($state);
        return ['state' => $state, 'changed' => true];
    });
    json_response(export_state($state));
}

json_response(['ok' => false, 'error' => 'Unbekannte Aktion.'], 404);
