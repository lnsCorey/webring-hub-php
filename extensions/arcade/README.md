# Arcade Extension (`arcade`)

Self-hosted HTML/JavaScript arcade for Webring Hub with DID leaderboards and play history.

## Enabling

Add `'arcade'` to `'extensions'` in `config.php` (or `HUB_EXTENSIONS=key-witness,arcade`):

```php
'extensions' => ['key-witness', 'arcade'],
'extension_settings' => [
    'arcade' => [
        'history_retention_days' => 365,
        'max_runs_per_player_game' => 100,
        'session_ttl_seconds' => 7200,
        // Per visitor (IP) and player DID: one visitor can't use up another visitor's quota.
        'max_sessions_per_did_hour' => 60,
        'max_scores_per_did_hour' => 60,
        // Per visitor (IP) across all players.
        'max_sessions_per_ip_hour' => 120,
        'max_scores_per_ip_hour' => 300,
        'leaderboard_limit' => 25,
    ],
],
```

- **Public arcade:** `/ext/arcade/`
- **Operator admin:** `/ext/arcade/admin.html` (uses the same admin token as `/admin`; signing in to either signs in to both for that tab)

## Trust model: scores are unverified

Scores are reported by the player's browser and are **not checked**. Choosing a player DID needs no proof that you own that site, so anyone can play, and post scores, as any listed DID. The leaderboard therefore marks every score **Unverified**, and the arcade page says so at the top. Treat leaderboards as a game, never as proof of who played or of their skill.

What the hub does to limit abuse:

- Sessions and scores are rate-limited per visitor (by a salted hash of their IP address, never the IP itself) and per visitor-and-player pair, so a visitor can't flood the boards or use up another visitor's quota for a DID.
- Each session can submit one score, scores must be within the game's declared `score.min`/`score.max`, and sessions expire.
- Removing a site from the hub deletes its scores, play history and rate-limit rows.

A **Verified only** filter is already on the leaderboard. It stays empty until signed score claims (proof of DID ownership) are added.

## Games are sandboxed

Every game page is sent with a `Content-Security-Policy` that starts with `sandbox allow-scripts allow-pointer-lock`, the same restrictions as the arcade's iframe. A game therefore runs on an opaque origin **even when someone opens its URL directly**, so it can never read the hub's storage (including the admin token) or call hub APIs as the visitor. Only install games you trust anyway: they run in your visitors' browsers.

## Installing Games

New or changed game folders are picked up by the cron refresh (`scripts/refresh.php`) or when an operator opens the arcade admin page. Public requests only read the catalog, so they never write to the database.

Drop a self-contained folder into `extensions/arcade/games/<slug>/` with a `game.json` manifest (schemaVersion `1`). Games run inside `<iframe sandbox="allow-scripts allow-pointer-lock">` (opaque origin, no `allow-same-origin`, no inline scripts) and report scores to the parent frame using `/ext/arcade/sdk/arcade.js`:

```js
window.Arcade?.ready();
window.Arcade?.score({ score: 12500, durationMs: 84000, meta: { wave: 3 } });
```
