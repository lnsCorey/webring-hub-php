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
        'max_sessions_per_did_hour' => 60,
        'max_scores_per_did_hour' => 60,
        'leaderboard_limit' => 25,
    ],
],
```

- **Public arcade:** `/ext/arcade/`
- **Operator admin:** `/ext/arcade/admin.html`

## Installing Games

Drop a self-contained folder into `extensions/arcade/games/<slug>/` with a `game.json` manifest (schemaVersion `1`). Games run inside `<iframe sandbox="allow-scripts allow-pointer-lock">` (opaque origin, no `allow-same-origin`, no inline scripts) and report scores to the parent frame using `/ext/arcade/sdk/arcade.js`:

```js
window.Arcade?.ready();
window.Arcade?.score({ score: 12500, durationMs: 84000, meta: { wave: 3 } });
```
