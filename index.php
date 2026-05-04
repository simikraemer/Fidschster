<?php
session_start();
$isLoggedIn = isset($_SESSION['role']);
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Finale Frühstücksmeeting</title>
    <link rel="stylesheet" href="assets/style.css?v=14">
</head>
<body>
<div id="toastHost" aria-live="polite"></div>

<?php if (!$isLoggedIn): ?>
<main class="login-shell">
    <section class="login-card">
        <h1>Finale Frühstücksmeeting</h1>

        <button id="spectatorLogin" class="secondary" type="button">Als Zuschauer beitreten</button>
        <div class="login-divider" aria-hidden="true"></div>

        <form id="loginForm" class="login-form">
            <input id="codeInput" name="code" autocomplete="one-time-code" placeholder="Code eingeben">
            <button type="submit" class="primary">Mit Code beitreten</button>
        </form>

        <p id="loginError" class="error-line" role="alert"></p>
    </section>
</main>
<script>
async function postLogin(payload) {
    const res = await fetch('api.php?action=login', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (!res.ok || !data.ok) throw new Error(data.error || 'Login fehlgeschlagen.');
    location.reload();
}
document.getElementById('loginForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const err = document.getElementById('loginError');
    err.textContent = '';
    try {
        await postLogin({mode: 'code', code: document.getElementById('codeInput').value.trim()});
    } catch (e) {
        err.textContent = e.message;
    }
});
document.getElementById('spectatorLogin').addEventListener('click', async () => {
    const err = document.getElementById('loginError');
    err.textContent = '';
    try {
        await postLogin({mode: 'spectator'});
    } catch (e) {
        err.textContent = e.message;
    }
});
</script>
<?php else: ?>
<div id="app" class="app-shell" data-session-role="<?= htmlspecialchars((string)$_SESSION['role'], ENT_QUOTES) ?>">
    <aside class="sidebar">
        <div class="sidebar-head">
            <div>
                <p class="eyebrow">Finale</p>
                <h1>Spielstand</h1>
            </div>
            <button id="logoutBtn" class="ghost small" type="button">Logout</button>
        </div>
        <div id="roleBadge" class="role-badge"></div>
        <table class="score-table" aria-label="Spielstand und Reihenfolge">
            <thead>
            <tr>
                <th aria-label="Spielerfarbe"></th>
                <th>Name</th>
                <th>Marken</th>
                <th>Karten</th>
            </tr>
            </thead>
            <tbody id="scoreRows"></tbody>
        </table>
        <div id="adminPanel" class="admin-panel hidden"></div>
    </aside>

    <main class="stage">
        <section id="centerStage" class="center-stage"></section>
        <section id="timelines" class="timelines"></section>
    </main>
</div>
<div id="actionDock" class="action-dock" aria-live="polite"></div>
<div id="turnFlash" class="turn-flash hidden" aria-hidden="true"></div>
<div id="resultFlash" class="result-flash hidden" aria-live="polite"></div>
<div id="challengeFlash" class="challenge-flash hidden" aria-live="polite"></div>
<script src="assets/app.js?v=11"></script>
<?php endif; ?>
</body>
</html>
