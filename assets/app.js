const POLL_MS = 1000;
const INTERACTION_GRACE_MS = 450;
const DRAFT_CLIENT_ID_KEY = 'fidschsterDraftClientId';

const app = document.getElementById('app');
const scoreRows = document.getElementById('scoreRows');
const roleBadge = document.getElementById('roleBadge');
const adminPanel = document.getElementById('adminPanel');
const centerStage = document.getElementById('centerStage');
const timelines = document.getElementById('timelines');
const toastHost = document.getElementById('toastHost');
const actionDock = document.getElementById('actionDock');
const turnFlash = document.getElementById('turnFlash');
const resultFlash = document.getElementById('resultFlash');
const challengeFlash = document.getElementById('challengeFlash');
const lockFlash = document.getElementById('lockFlash');

let state = null;
let draftPlacement = null;
let challengeDraftPlacement = null;
let lastCardId = null;
let lastPhase = null;
let lastRoundSeq = null;
let lastResolutionKey = null;
let lastChallengeKey = null;
let lastPlacementEventKey = null;
let draggingCurrent = false;
let preparingCardDrag = false;
let inputInProgress = false;
let refreshInFlight = false;
let refreshQueued = false;
let uiQuietUntil = 0;
let mutationSeq = 0;
let draftSyncSeq = 0;
let challengeDraftSyncSeq = 0;
const seenEffectSeq = new Map();
const transparentDragImage = new Image();
transparentDragImage.src = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
const draftClientId = getDraftClientId();

function esc(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function getDraftClientId() {
    try {
        let id = sessionStorage.getItem(DRAFT_CLIENT_ID_KEY);
        if (!id) {
            const randomPart = globalThis.crypto?.randomUUID ? globalThis.crypto.randomUUID() : Math.random().toString(36).slice(2);
            id = `${Date.now().toString(36)}-${randomPart}`;
            sessionStorage.setItem(DRAFT_CLIENT_ID_KEY, id);
        }
        return id;
    } catch {
        return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
    }
}

function toast(message, kind = 'normal') {
    const el = document.createElement('div');
    el.className = `toast ${kind}`;
    el.textContent = message;
    toastHost.appendChild(el);
    setTimeout(() => el.remove(), 2800);
}

async function api(action, payload = null) {
    const options = payload === null ? {} : {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload),
    };
    const res = await fetch(`api.php?action=${encodeURIComponent(action)}`, {
        cache: 'no-store',
        ...options,
    });
    const data = await res.json().catch(() => ({ok: false, error: 'Ungültige Serverantwort.'}));
    if (!res.ok || !data.ok) {
        throw new Error(data.error || 'Aktion fehlgeschlagen.');
    }
    return data;
}

function pauseBackgroundRefresh(ms = INTERACTION_GRACE_MS) {
    uiQuietUntil = Math.max(uiQuietUntil, Date.now() + ms);
}

function isAdmin() {
    return state?.me?.role === 'admin';
}

function myPlayerIndex() {
    return Number.isInteger(state?.me?.playerIndex) ? state.me.playerIndex : null;
}

function phaseLabel(phase) {
    const labels = {
        lobby: 'Lobby',
        placing: 'Karte platzieren',
        challenge_window: 'Halt-Fenster',
        challenger_placing: 'Herausforderer platziert',
        resolving: 'Automatische Auswertung',
        round_transition: 'Auflösung',
        finished: 'Spiel beendet',
    };
    return labels[phase] || phase;
}

function activeName() {
    return state?.players?.[state.turn]?.name || `Finalist ${Number(state?.turn ?? 0) + 1}`;
}

function currentActorName() {
    if (state?.phase === 'challenger_placing' && state.challenge?.by !== null && state.challenge?.by !== undefined) {
        return state.players?.[state.challenge.by]?.name || `Finalist ${Number(state.challenge.by) + 1}`;
    }

    return activeName();
}

function playerColorClass(playerIndex) {
    return `player-color-${Number(playerIndex) + 1}`;
}

function render() {
    if (!state) return;

    if (lastCardId !== state.currentCard?.id || lastPhase !== state.phase) {
        draftPlacement = null;
        challengeDraftPlacement = null;
    }
    reconcileTeamDrafts();

    if (state.phase === 'placing' && state.roundSeq && state.roundSeq !== lastRoundSeq) {
        flashTurn(activeName());
    }
    flashChallengeIfNeeded();
    flashResolutionIfNeeded();
    flashPlacementIfNeeded();

    lastCardId = state.currentCard?.id || null;
    lastPhase = state.phase;
    lastRoundSeq = state.roundSeq || lastRoundSeq;

    renderScoreboard();
    renderRole();
    renderAdminPanel();
    renderActionDock();
    renderCenterStage();
    renderTimelines();
}

function reconcileTeamDrafts() {
    if (draggingCurrent || preparingCardDrag) return;

    reconcilePlacementDraft();
    reconcileChallengeDraft();
}

function reconcilePlacementDraft() {
    if (state.phase !== 'placing' || myPlayerIndex() !== state.turn) return;

    const serverDraft = state.currentDraftPlacement;
    if (serverDraft?.playerIndex === state.turn && serverDraft.position !== undefined && serverDraft.position !== null) {
        if (draftPlacement === null || serverDraft.clientId !== draftClientId) {
            draftPlacement = serverDraft.position;
        }
        return;
    }

    draftPlacement = null;
}

function reconcileChallengeDraft() {
    if (state.phase !== 'challenger_placing' || myPlayerIndex() !== state.challenge?.by) return;

    const serverDraft = state.challenge?.draftPlacement;
    if (serverDraft?.playerIndex === state.turn && serverDraft.position !== undefined && serverDraft.position !== null) {
        if (challengeDraftPlacement === null || serverDraft.clientId !== draftClientId) {
            challengeDraftPlacement = serverDraft.position;
        }
        return;
    }

    challengeDraftPlacement = null;
}

function flashTurn(name) {
    turnFlash.innerHTML = `<div><span>Ist am Zug</span><strong>${esc(name)}</strong></div>`;
    turnFlash.classList.remove('hidden', 'run');
    void turnFlash.offsetWidth;
    turnFlash.classList.add('run');
    setTimeout(() => turnFlash.classList.add('hidden'), 900);
}

function flashResolutionIfNeeded() {
    const resolution = state.lastResolution;
    if (!resolution?.at || !resolution.card) return;

    const key = `${resolution.at}:${resolution.cardId || ''}:${resolution.reason || ''}`;
    if (key === lastResolutionKey) return;

    const isRecent = Math.abs((state.serverNow || 0) - resolution.at) <= 4;
    if (lastResolutionKey !== null || isRecent) {
        flashResolution(resolution);
    }
    lastResolutionKey = key;
}

function flashChallengeIfNeeded() {
    if (state.phase !== 'challenger_placing' || state.challenge?.by === null || state.challenge?.by === undefined) return;

    const key = `${state.challenge.by}:${state.challenge.at || ''}:${state.roundSeq || ''}`;
    if (key === lastChallengeKey) return;

    const challenger = state.players?.[state.challenge.by]?.name || `Finalist ${Number(state.challenge.by) + 1}`;
    challengeFlash.innerHTML = `<div>
        <span>Halt!</span>
        <strong>${esc(challenger)}</strong>
        <p>fordert heraus</p>
    </div>`;
    challengeFlash.classList.remove('hidden', 'run');
    void challengeFlash.offsetWidth;
    challengeFlash.classList.add('run');
    setTimeout(() => challengeFlash.classList.add('hidden'), 1800);
    lastChallengeKey = key;
}

function flashResolution(resolution) {
    const rows = (resolution.results || []).map((result) => {
        const verdict = result.correct ? 'richtig' : 'falsch';
        return `<div class="result-line ${result.correct ? 'correct' : 'wrong'}">
            <span>${esc(result.name)}</span>
            <strong>${verdict}</strong>
        </div>`;
    }).join('');

    resultFlash.innerHTML = `<div>
        <p class="result-card-year">${esc(resolution.card.year)}</p>
        <h2>${esc(resolution.card.title)}</h2>
        <div class="result-lines">${rows}</div>
    </div>`;
    resultFlash.classList.remove('hidden', 'run');
    void resultFlash.offsetWidth;
    resultFlash.classList.add('run');
    setTimeout(() => resultFlash.classList.add('hidden'), 4200);
}

function flashPlacementIfNeeded() {
    const event = state.placementEvent;
    if (!event?.at || event.playerIndex === null || event.playerIndex === undefined) return;

    const key = `${event.roundSeq || ''}:${event.cardId || ''}:${event.playerIndex}:${event.position}:${event.at}`;
    if (key === lastPlacementEventKey) return;

    const isRecent = Math.abs((state.serverNow || 0) - event.at) <= 4;
    if (lastPlacementEventKey !== null || isRecent) {
        flashPlacement(event);
    }
    lastPlacementEventKey = key;
}

function flashPlacement(event) {
    const player = state.players?.[event.playerIndex];
    const name = player?.name || `Finalist ${Number(event.playerIndex) + 1}`;
    lockFlash.innerHTML = `<div class="${playerColorClass(event.playerIndex)}">
        <span>Eingeloggt</span>
        <strong>${esc(name)}</strong>
    </div>`;
    lockFlash.classList.remove('hidden', 'run');
    void lockFlash.offsetWidth;
    lockFlash.classList.add('run');
    setTimeout(() => lockFlash.classList.add('hidden'), 1200);
}

function renderRole() {
    const me = state.me || {};
    const activePlayer = currentActorName();
    let loginHtml = '<strong>Zuschauer</strong>';

    if (me.role === 'admin') {
        loginHtml = '<span>Eingeloggt:</span> <strong>Admin</strong>';
    }
    if (me.role === 'player') {
        loginHtml = `<span>Eingeloggt:</span> <strong>${esc(state.players?.[me.playerIndex]?.name || '')}</strong>`;
    }

    roleBadge.innerHTML = `<div>${loginHtml}</div>
        <hr>
        <div><span>Am Zug:</span> <strong>${esc(activePlayer)}</strong></div>`;
}

function renderScoreboard() {
    scoreRows.innerHTML = state.players.map((player, idx) => {
        const active = idx === state.turn && state.gameStarted && state.phase !== 'finished';
        const tokens = Array.from({length: Math.max(0, player.tokens)}, () => '<span class="token-dot" aria-hidden="true"></span>').join('');
        return `<tr class="${active ? 'active' : ''} ${playerColorClass(idx)}" data-score-player="${idx}">
            <td class="color-cell"><span class="player-color-dot" aria-label="Spielerfarbe"></span></td>
            <td><span class="player-name">${esc(player.name)}</span></td>
            <td><span class="token-stack" aria-label="${player.tokens} Marken">${tokens}</span></td>
            <td><span class="pill">${player.cards.length}</span></td>
        </tr>`;
    }).join('');

    state.players.forEach((player, idx) => {
        const previous = seenEffectSeq.get(idx);
        if (previous === undefined) {
            seenEffectSeq.set(idx, player.effectSeq);
            return;
        }
        if (player.effectSeq !== previous) {
            seenEffectSeq.set(idx, player.effectSeq);
            const row = scoreRows.querySelector(`[data-score-player="${idx}"] td:nth-child(3)`);
            if (row && player.effectDelta !== 0) {
                const pop = document.createElement('span');
                pop.className = `effect-pop ${player.effectDelta < 0 ? 'minus' : ''}`;
                pop.textContent = player.effectDelta > 0 ? '+1' : '-1';
                row.appendChild(pop);
                setTimeout(() => pop.remove(), 1200);
            }
        }
    });
}

function renderAdminPanel() {
    if (!isAdmin()) {
        adminPanel.classList.add('hidden');
        adminPanel.innerHTML = '';
        return;
    }

    adminPanel.classList.remove('hidden');
    const focusedInside = document.activeElement && adminPanel.contains(document.activeElement);
    if (focusedInside && state.phase === 'lobby') return;

    if (state.phase === 'lobby' || !state.gameStarted) {
        adminPanel.innerHTML = `<h2>Admin</h2>
            <div class="admin-box">
                <p class="muted">Drei feste Finalisten. Nach dem Start zieht das Spiel automatisch die erste Karte.</p>
                ${state.players.map((p, idx) => `<div class="admin-name-row">
                    <input id="adminName${idx}" value="${esc(p.name)}" maxlength="40" aria-label="Name Finalist ${idx + 1}">
                    <span class="pill">${idx + 1}</span>
                </div>`).join('')}
                <button class="primary" data-action="start-game" type="button">Spiel starten</button>
            </div>`;
        return;
    }

    adminPanel.innerHTML = `<h2>Admin</h2>
        <div class="admin-box">
            <div><strong>Deck:</strong> ${state.deckRemaining} Karten übrig</div>
            ${state.players.map((p, idx) => `<div class="token-row">
                <span>${esc(p.name)}</span>
                <button class="ghost small" data-action="remove-token" data-player="${idx}" type="button">− Marke</button>
                <button class="primary small" data-action="add-token" data-player="${idx}" type="button">+ Marke</button>
            </div>`).join('')}
        </div>
        <div class="admin-box">
            <button class="danger" data-action="reset-game" type="button">Spiel zurücksetzen</button>
        </div>`;
}

function renderActionDock() {
    const me = myPlayerIndex();
    if (me === null) {
        actionDock.innerHTML = '';
        return;
    }

    const phase = state.phase;
    const active = state.turn;
    const isActivePlayer = me !== null && me === active;
    const isChallenger = me !== null && me === state.challenge?.by;
    const seconds = Math.max(0, (state.challengeDeadline || 0) - (state.serverNow || 0));

    const activeDraftPlacement = currentDraftPosition();
    const canLockPlacement = phase === 'placing' && isActivePlayer && activeDraftPlacement !== null;
    const placementCanBeChallenged = (state.players[active]?.cards?.length ?? 0) > 0;
    const canHalt = phase === 'challenge_window' &&
        placementCanBeChallenged &&
        me !== null &&
        me !== active &&
        (state.players[me]?.tokens ?? 0) > 0;
    const activeChallengeDraftPlacement = currentChallengeDraftPosition();
    const canLockChallenge = phase === 'challenger_placing' &&
        isChallenger &&
        activeChallengeDraftPlacement !== null &&
        activeChallengeDraftPlacement !== state.currentPlacement?.position;
    const canLockAnyPlacement = canLockPlacement || canLockChallenge;
    const lockAction = canLockChallenge ? 'lock-challenge' : 'lock-placement';

    const haltText = phase === 'challenge_window' ? `Halt! ${seconds}s` : 'Halt!';

    actionDock.innerHTML = `<div class="dock-inner">
        <button class="dock-button" data-action="${lockAction}" type="button" ${canLockAnyPlacement ? '' : 'disabled'}>Platzierung einloggen</button>
        <button class="dock-button" data-action="halt" type="button" ${canHalt ? '' : 'disabled'}>${haltText}</button>
    </div>`;
}

function currentDraftPosition() {
    if (myPlayerIndex() !== state?.turn) return null;
    if (draftPlacement !== null) return draftPlacement;
    if (state.currentDraftPlacement?.playerIndex === state.turn) {
        return state.currentDraftPlacement.position;
    }
    return null;
}

function currentChallengeDraftPosition() {
    if (myPlayerIndex() !== state?.challenge?.by) return null;
    if (challengeDraftPlacement !== null) return challengeDraftPlacement;
    if (state.challenge?.draftPlacement?.playerIndex === state.turn) {
        return state.challenge.draftPlacement.position;
    }
    return null;
}

function renderCenterStage() {
    if (state.phase === 'finished') {
        centerStage.innerHTML = `<div class="winner-splash"><h2>${esc(state.winner?.name || 'Gewonnen')}</h2><p>gewinnen das Finale.</p></div>`;
        return;
    }

    if (!state.currentCard) {
        centerStage.innerHTML = `<div class="empty-stage">Warten auf Spielstart.</div>`;
        return;
    }

    const canDrag = (state.phase === 'placing' && myPlayerIndex() === state.turn) ||
        (state.phase === 'challenger_placing' && myPlayerIndex() === state.challenge?.by);

    centerStage.innerHTML = `<div class="card-focus">
        <div class="current-card" ${canDrag ? 'draggable="true" data-drag-current="1"' : ''}>
            <img src="${esc(state.currentCard.image)}" alt="Aktive Karte" draggable="false">
        </div>
    </div>`;
}

function pendingForPlayer(playerIndex) {
    const pending = [];
    if (state.currentPlacement?.playerIndex === playerIndex && ['challenge_window', 'challenger_placing', 'resolving'].includes(state.phase)) {
        pending.push({position: state.currentPlacement.position, className: `pending locked ${playerColorClass(playerIndex)}`, label: 'Eingeloggt', draggable: false});
    }
    if (state.challenge?.placement?.playerIndex === playerIndex && ['resolving'].includes(state.phase)) {
        pending.push({position: state.challenge.placement.position, className: `pending challenge-pending ${playerColorClass(state.challenge.by)}`, label: 'Herausforderung', draggable: false});
    }
    if (playerIndex === state.turn && state.phase === 'placing') {
        const serverDraft = state.currentDraftPlacement?.playerIndex === playerIndex ? state.currentDraftPlacement.position : null;
        const localDraft = myPlayerIndex() === state.turn && (draggingCurrent || preparingCardDrag || draftPlacement !== null) ? draftPlacement : null;
        const position = localDraft ?? serverDraft;
        if (position !== null && position !== undefined) {
            pending.push({position, className: `pending draft ${playerColorClass(playerIndex)}`, label: 'Probe', draggable: myPlayerIndex() === state.turn});
        }
    }
    if (playerIndex === state.turn && state.phase === 'challenger_placing') {
        const serverDraft = state.challenge?.draftPlacement?.playerIndex === playerIndex ? state.challenge.draftPlacement.position : null;
        const localDraft = myPlayerIndex() === state.challenge?.by && (draggingCurrent || preparingCardDrag || challengeDraftPlacement !== null) ? challengeDraftPlacement : null;
        const position = localDraft ?? serverDraft;
        if (position !== null && position !== undefined) {
            pending.push({position, className: `pending challenge-pending draft ${playerColorClass(state.challenge.by)}`, label: 'Probe', draggable: myPlayerIndex() === state.challenge?.by});
        }
    }
    return pending;
}

function renderTimelines() {
    const phase = state.phase;
    let visible = [0, 1, 2];

    if (phase === 'placing' || phase === 'challenge_window' || phase === 'round_transition') {
        visible = [state.turn];
    }
    if (phase === 'challenger_placing' || phase === 'resolving') {
        visible = [state.turn];
    }
    if (phase === 'lobby' || phase === 'finished') {
        visible = [0, 1, 2];
    }

    timelines.innerHTML = visible.map((idx) => {
        const player = state.players[idx];
        const interactiveOwner = phase === 'placing' && myPlayerIndex() === idx && idx === state.turn;
        const interactiveChallenger = phase === 'challenger_placing' && idx === state.turn && myPlayerIndex() === state.challenge?.by;
        const focus = idx === state.turn ? 'focus' : '';
        return renderTimelinePanel(player, idx, interactiveOwner || interactiveChallenger, `${focus} ${playerColorClass(idx)}`.trim());
    }).join('');
}

function renderTimelinePanel(player, playerIndex, interactive, extraClass) {
    const pending = pendingForPlayer(playerIndex);
    const cardsHtml = renderTimelineCards(player, playerIndex, interactive, pending);

    return `<section class="timeline-panel ${extraClass}">
        <div class="timeline-head">
            <h2>${esc(player.name)}</h2>
        </div>
        <div class="timeline-row" data-timeline-player="${playerIndex}" ${interactive ? 'data-timeline-drop="1"' : ''}>${cardsHtml}</div>
    </section>`;
}

function renderTimelineCards(player, playerIndex, interactive, pendingList) {
    const cards = player.cards || [];
    const count = cards.length;
    const hasPending = pendingList.length > 0;

    if (count === 0 && !hasPending && !interactive) {
        return `<div class="empty-timeline">Noch keine Karten im Zeitstrahl.</div>`;
    }

    let html = '';
    for (let pos = 0; pos <= count; pos++) {
        pendingList.filter(p => p.position === pos).forEach((p) => {
            html += `<article class="timeline-card ${p.className}" ${p.draggable ? 'draggable="true" data-drag-current="1"' : ''}>
                <img src="${esc(state.currentCard?.image || '')}" alt="Probeplatzierung" draggable="false">
                <div class="label"><span class="year">?</span><br>${esc(p.label)}</div>
            </article>`;
        });

        if (pos < count) {
            const card = cards[pos];
            html += `<article class="timeline-card">
                <img src="${esc(card.image)}" alt="${esc(card.title)}" draggable="false">
                <div class="label"><span class="year">${esc(card.year)}</span><br>${esc(card.title)}</div>
            </article>`;
        }
    }

    return html || `<div class="empty-timeline">Leerer Zeitstrahl. Karte hier ablegen.</div>`;
}

function selectSlot(playerIndex, position) {
    if (state.phase === 'placing' && myPlayerIndex() === state.turn && playerIndex === state.turn) {
        if (draftPlacement === position) return;
        draftPlacement = position;
        renderActionDock();
        renderTimelines();
        syncDraftPlacement(position);
        return;
    }
    if (state.phase === 'challenger_placing' && myPlayerIndex() === state.challenge?.by && playerIndex === state.turn) {
        if (challengeDraftPlacement === position) return;
        challengeDraftPlacement = position;
        renderActionDock();
        renderTimelines();
        syncChallengeDraftPlacement(position);
    }
}

async function syncDraftPlacement(position) {
    const syncId = ++draftSyncSeq;
    try {
        const clientSeq = Date.now() * 1000 + syncId;
        const nextState = await api('set_draft_placement', {position, clientSeq, clientId: draftClientId});
        if (syncId === draftSyncSeq && !draggingCurrent && !preparingCardDrag) {
            state = nextState;
            render();
        }
    } catch (e) {
        if (syncId === draftSyncSeq) {
            toast(e.message, 'error');
        }
    }
}

async function syncChallengeDraftPlacement(position) {
    const syncId = ++challengeDraftSyncSeq;
    try {
        const clientSeq = Date.now() * 1000 + syncId;
        const nextState = await api('set_challenge_draft_placement', {position, clientSeq, clientId: draftClientId});
        if (syncId === challengeDraftSyncSeq && !draggingCurrent && !preparingCardDrag) {
            state = nextState;
            render();
        }
    } catch (e) {
        if (syncId === challengeDraftSyncSeq) {
            toast(e.message, 'error');
        }
    }
}

function timelineDropPosition(row, clientX) {
    const cards = [...row.querySelectorAll('.timeline-card:not(.pending)')];
    if (cards.length === 0) return 0;

    for (let i = 0; i < cards.length; i++) {
        const rect = cards[i].getBoundingClientRect();
        if (clientX < rect.left + rect.width / 2) return i;
    }

    return cards.length;
}

async function refresh({force = false, showErrors = false} = {}) {
    if (!force && (draggingCurrent || preparingCardDrag || inputInProgress || Date.now() < uiQuietUntil)) {
        refreshQueued = true;
        return;
    }

    if (refreshInFlight) {
        refreshQueued = true;
        return;
    }

    refreshInFlight = true;
    const startedAtMutation = mutationSeq;

    try {
        const nextState = await api('state');
        if (force || startedAtMutation === mutationSeq) {
            state = nextState;
            render();
        }
    } catch (e) {
        if (showErrors) {
            toast(e.message, 'error');
        }
    } finally {
        refreshInFlight = false;
        if (refreshQueued) {
            refreshQueued = false;
            setTimeout(() => refresh(), Math.max(0, uiQuietUntil - Date.now()));
        }
    }
}

async function runAction(action, payload = null) {
    const actionMutation = ++mutationSeq;
    try {
        const nextState = await api(action, payload);
        if (actionMutation === mutationSeq) {
            state = nextState;
            render();
        }
    } catch (e) {
        toast(e.message, 'error');
    }
}

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-action], #logoutBtn');
    if (!button || button.disabled) return;

    pauseBackgroundRefresh();
    inputInProgress = true;

    if (button.id === 'logoutBtn') {
        try {
            await api('logout');
            location.reload();
        } catch (e) {
            toast(e.message, 'error');
            inputInProgress = false;
            refresh({force: true, showErrors: true});
        }
        return;
    }

    const action = button.dataset.action;
    try {
        if (action === 'start-game') {
            const names = [0, 1, 2].map(idx => document.getElementById(`adminName${idx}`)?.value?.trim() || '');
            await runAction('start_game', {names});
        } else if (action === 'reset-game') {
            if (confirm('Spiel wirklich zurücksetzen?')) await runAction('reset_game');
        } else if (action === 'lock-placement') {
            const position = currentDraftPosition();
            if (position !== null) await runAction('lock_placement', {position});
        } else if (action === 'halt') {
            await runAction('halt');
        } else if (action === 'lock-challenge') {
            const position = currentChallengeDraftPosition();
            if (position !== null) await runAction('lock_challenge', {position});
        } else if (action === 'add-token' || action === 'remove-token') {
            await runAction(action === 'add-token' ? 'add_token' : 'remove_token', {playerIndex: Number(button.dataset.player)});
        } else if (action === 'resolve') {
            await runAction('resolve_round', {mode: button.dataset.mode || 'auto'});
        }
    } finally {
        inputInProgress = false;
        refresh({force: true, showErrors: true});
    }
});

document.addEventListener('dragstart', (event) => {
    const current = event.target.closest('[data-drag-current]');
    if (!current) return;
    preparingCardDrag = false;
    draggingCurrent = true;
    document.body.classList.add('is-card-dragging');
    event.dataTransfer.setData('text/plain', 'current-card');
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setDragImage(transparentDragImage, 0, 0);
});

document.addEventListener('dragover', (event) => {
    if (!draggingCurrent) return;

    const row = event.target.closest('[data-timeline-drop]');
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    if (!row) return;
    selectSlot(Number(row.dataset.timelinePlayer), timelineDropPosition(row, event.clientX));
});

document.addEventListener('drop', (event) => {
    if (!draggingCurrent) return;

    const row = event.target.closest('[data-timeline-drop]');
    event.preventDefault();
    if (row) {
        selectSlot(Number(row.dataset.timelinePlayer), timelineDropPosition(row, event.clientX));
    }
    draggingCurrent = false;
    preparingCardDrag = false;
    document.body.classList.remove('is-card-dragging');
    refresh({force: true, showErrors: true});
});

document.addEventListener('dragend', () => {
    if (!draggingCurrent && !preparingCardDrag) return;

    draggingCurrent = false;
    preparingCardDrag = false;
    document.body.classList.remove('is-card-dragging');
    refresh({force: true, showErrors: true});
});

document.addEventListener('pointerdown', (event) => {
    if (event.target.closest('[data-action], #logoutBtn')) {
        pauseBackgroundRefresh();
    }

    if (!event.target.closest('[data-drag-current]')) return;
    preparingCardDrag = true;
    document.body.classList.add('is-card-dragging');
});

document.addEventListener('pointerup', () => {
    if (!preparingCardDrag || draggingCurrent) return;

    preparingCardDrag = false;
    document.body.classList.remove('is-card-dragging');
    refresh({force: true, showErrors: true});
});

document.addEventListener('pointercancel', () => {
    if (!preparingCardDrag || draggingCurrent) return;

    preparingCardDrag = false;
    document.body.classList.remove('is-card-dragging');
    refresh({force: true, showErrors: true});
});

refresh({force: true, showErrors: true});
setInterval(refresh, POLL_MS);
