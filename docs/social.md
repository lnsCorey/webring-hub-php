# Conversations & Reactions (`social`)

`social` lets verified members of a hub talk to each other and organise together. It adds a **Conversations** page at `/ext/social/` (Feed, Events, Asks & offers, Polls, This week, Activity), a card in the home-page carousel, and moderation and poll panels on `/admin`.

It needs [`webhook-sync`](webhook-sync.md), which collects members' signed `items.json` posts into the hub.

```php
'extensions' => ['webhook-sync', 'social'],
```

## How it works

Content and actions are kept apart:

| What | Where it lives | Who can create it |
| --- | --- | --- |
| Posts, comments, likes, shares, recommendations | On the member's own site, in their signed `items.json`. The hub keeps a verified copy through `webhook-sync`. | The member, publishing from Webring Studio |
| Reactions (👍 ❤️ 🎉 🤔) | On the hub, under the member's DID | Signed-in members who opted in to Conversations |
| Reports | On the hub, under the reporter's DID | Signed-in members who opted in |
| Activity read-marker | On the hub, under the member's DID | Signed-in members who opted in |
| Events, asks & offers | On the member's own site: a signed `post` with an `event` or `listing` object | The member, publishing from Webring Studio |
| RSVPs, poll votes | On the hub, under the member's DID | Signed-in members who opted in |
| Polls | On the hub (they belong to the group) | The admin, and members when `pollCreators` is `members` |

**Threads.** An item replies to another when its `targetUrl` equals the other item's `canonicalUrl`. That's what Studio already writes for a `comment` on a friend's post. A reply only attaches to an item whose `canonicalUrl` is on its author's own site, so nobody can capture someone else's replies by copying their URL. The feed shows top-level items only; replies appear in the thread.

**Replying.** Members reply from Webring Studio by publishing a `comment` whose target is the post's link (the **Copy link** button). It shows up after their site syncs.

**Mentions.** An item mentions a member when its title or text contains their `did:web:` DID, or an `@handle` that exactly one active member uses, or when its `targetAuthorUrl` is their site. Ambiguous handles are ignored.

**Activity** (signed-in members) lists replies to your items, mentions of you and reactions on your items. It's computed when you open it; nothing is delivered or queued, so it isn't an inbox.

## Events and RSVPs

An event is a `post` whose item carries an `event` object. Older Studios and other readers that don't know the field just see a post.

```json
{
  "type": "post",
  "title": "Repair café",
  "event": {
    "startsAt": "2026-12-05T18:00:00-04:00",
    "endsAt": "2026-12-05T20:00:00-04:00",
    "allDay": false,
    "location": "Library, Room 2",
    "url": "https://meet.example/repair"
  }
}
```

- `startsAt` is required and must include a time zone; `endsAt`, `allDay`, `location` (≤ 200 characters) and `url` (http/https) are optional.
- **Events** lists upcoming and past events. Members RSVP **Going**, **Maybe** or **Can't go**. Counts are public; the names of who's going are shown to signed-in members only.
- RSVPs close when the event ends (6 hours after the start when there's no end time).
- **Subscribe to the calendar:** `GET /api/ext/social/v1/events.ics` is an iCalendar feed of the last 30 days and upcoming events. It's off on members-only hubs.
- If the member republishes the post without the `event` object, the event and its RSVPs are removed.

## Asks & offers

An ask or offer is a `post` with a `listing` object: `{ "kind": "ask" | "offer", "status": "open" | "closed" }`. Its `tags` are compared with members' profile interests.

- **Asks & offers** lists open (or closed) asks and offers, newest first.
- Each one shows how many members list matching interests; signed-in members see their names, and a highlighted **Matches your interests** when it fits theirs.
- Members respond by replying from Studio. To close a listing, the author republishes it with `"status": "closed"`.

## Polls

- The admin creates polls from `/admin` → **New poll**. Members can create them on the Polls tab too, unless `pollCreators` is `admin` (5 per day each).
- 2 to 8 options, single or multiple choice, optionally closing after 1 to 90 days.
- One vote per member (voting again replaces it). Results appear after you vote, or always with `pollResults: 'always'`, and to everyone once the poll closes.
- The creator or the admin can close a poll; the admin can delete one.

## This week (digest)

**This week** summarises the last 7 days: the busiest conversations (replies and reactions), events in the next two weeks, new members, open polls and new asks & offers, with post/comment/reaction counts.

`GET /api/ext/social/v1/digest.atom` is an Atom feed with one entry per completed week (the last four), so members can follow the community from a feed reader without email or tracking. It's off on members-only hubs.

## Privacy and consent

- Reading the feed doesn't need sign-in, unless the operator turns on `membersOnly`.
- Reacting, reporting, RSVPing, voting and creating polls need sign-in **and** opt-in. The first time, the page asks. Members can opt out from **My Data** at any time, which deletes their reactions, reports, RSVPs, votes, the polls they created and their read-marker.
- When a member is removed or blocked, everything about their items and everything they recorded is deleted. Stale members are left out of every view.

## Moderation

- Members report an item with a reason: spam, abuse, off-topic or other, plus an optional note.
- When `autoHideReports` distinct members have reported an item (3 by default), it's hidden automatically until the admin reviews it.
- On `/admin` → **Conversations & Reactions**:
  - **Hide** hides the item and resolves its reports;
  - **Dismiss** rejects the reports and undoes an automatic hide;
  - **Restore** (under **Hidden items**) shows a hidden item again.

**Hiding only affects this hub's conversation pages.** The member's site is untouched, and so is the raw `webhook-sync` feed (`/api/ext/webhook-sync/v1/feed`), which Studio clients verify themselves. In a thread, a hidden item becomes a placeholder and its replies stay visible. To remove a member entirely, block their entry.

## Settings

```php
'extension_settings' => [
    'social' => [
        'membersOnly' => false,      // true: reading needs sign-in
        'autoHideReports' => 3,      // distinct reporters before an automatic hide; 0 turns it off
        'reactions' => ['like', 'heart', 'celebrate', 'think'], // up to 8 keys
        'pollCreators' => 'members', // or 'admin'
        'pollResults' => 'after_vote', // or 'always'
    ],
],
```

## API (`/api/ext/social/v1/`)

Items are addressed by `did` and `itemId` in the query string or body, never in the path.

| Route | Access | What it does |
| --- | --- | --- |
| `GET config` | public | Reactions, report reasons, `membersOnly`, and whether the caller is signed in and opted in |
| `GET feed?limit&type&before` | public (members with `membersOnly`) | Top-level items, newest first, each with `author`, `replyCount`, `reactions` and `myReactions`. `before` takes the `nextCursor` from the previous page. |
| `GET thread?did&id` | as feed | `root` (nested `children`, depth ≤ 6, ≤ 300 nodes), `focus`, `nodeCount`, `truncated`. Opening a reply climbs to its root. |
| `POST reactions` `{did, itemId, reaction, on}` | signed in + opted in + CSRF | Adds or removes a reaction (idempotent). 120 per hour per member. |
| `POST reports` `{did, itemId, reason, note}` | signed in + opted in + CSRF | One report per member per item, 20 per day. You can't report your own item. Returns `autoHidden`. |
| `GET me/activity?limit&before` | signed in | `events` (`reply`, `mention`, `reactions`), `unread`, `lastSeenAt`, `nextBefore` |
| `POST me/activity/seen` | signed in + opted in + CSRF | Updates the read-marker |
| `GET events?when=upcoming\|past` | as feed | Events with `event`, `rsvps`, `myRsvp` and (signed in) `attendees` |
| `GET events.ics` | public (off when `membersOnly`) | iCalendar feed |
| `POST rsvps` `{did, itemId, status}` | signed in + opted in + CSRF | `going`, `maybe`, `declined`, or `null` to clear. 60 changes per hour. |
| `GET listings?kind&status` | as feed | Asks and offers with `listing` and `matches` |
| `GET polls?status=open\|closed` | as feed | Polls with options, results when visible, `myVotes`, `canClose`; plus `canCreate` |
| `POST polls` `{question, description, options[], multi, closesInDays}` | signed in + opted in + CSRF | Creates a poll (when members may) |
| `POST polls/{id}/vote` `{optionIds[]}`, `POST polls/{id}/close` | signed in + opted in + CSRF | Vote (replaces your vote); close your own poll |
| `GET digest?days=7` | as feed | The digest |
| `GET digest.atom` | public (off when `membersOnly`) | Weekly digest feed |
| `GET admin/summary`, `GET admin/reports?status=`, `GET admin/hidden`, `GET admin/polls` | admin token | Panel data |
| `POST admin/reports/{id}/hide`, `…/dismiss`, `POST admin/hidden/{id}/restore` | admin token | Moderation actions |
| `POST admin/polls` `{question, options, closesInDays}`, `POST admin/polls/{id}/close`, `DELETE admin/polls/{id}` | admin token | Poll management (options separated by `\|`) |

Signed-in routes use the hub session cookie. POSTs need the `X-Hub-Csrf` header from `GET /api/auth/me`.

## Tables

The tables are `ext_social_reactions`, `_mentions`, `_reports`, `_hidden`, `_seen` and `_limits`, plus (schema 2) `_events`, `_rsvps`, `_listings`, `_polls`, `_poll_options` and `_poll_votes`. Items are keyed by `item_key = sha256(did + "\n" + itemId)`, with `item_did` / `item_id` kept for purges and display. `social` reads `ext_webhook_sync_items` (declared in `reads`) and never writes to it. `webhook-sync` schema 2 adds the indexed `target_url` column used for replies.

## Testing

`php scripts/smoke-social-groups.php` covers events, RSVPs, the calendar feed, asks & offers matching, polls, the digest and its Atom feed, and their purges.

`php scripts/smoke-social.php` covers the following against a temporary SQLite database, then over HTTP with `php -S`:
- loading and dependencies;
- mentions, the feed and threads;
- reactions, reports and moderation;
- activity and every purge path;
- sign-in, CSRF and opt-in gating.
