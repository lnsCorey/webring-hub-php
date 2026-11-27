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
        'welcomeDays' => 14,         // days a newly listed member appears on the welcome wall
        'introTag' => 'intro',       // tag used to link a newcomer's intro post
        'spotlight' => true,         // daily deterministic member spotlight on Feed and Digest
        'roles' => [],               // up to 5 custom role labels in addition to organizer and moderator
        'channels' => [              // up to 30 curated topic channels
            // ['tag' => 'woodworking', 'title' => 'Woodworking', 'description' => 'Shop notes'],
        ],
        'openChannels' => false,     // true: include any tag used by 3+ active members in 30 days
        'badgeThresholds' => [       // optional overrides for nightly earned badges
            'host' => 3,
            'helper' => 3,
            'voice' => 25,
            'welcomer' => 10,
            'voucher' => 5,
            'foundingDays' => 30,
            'participant' => 3,
        ],
    ],
],
```

## Identity & belonging (Tier 3)

- **Vouches:** signed `post` items with a `vouch` object (`subjectDid`, `subjectSiteUrl`, `kind`: `met` | `skill` | `worked-with`, `skill`). Indexed only when the subject is an active entry whose `site_url` matches `subjectSiteUrl` and voucher ≠ subject; newest wins per `(voucher, subject, kind, skill)`. Deleting the post revokes the vouch. If `key-witness` is enabled, vouches whose voucher rotated their key after `published_at` are marked `keyChangedSince: true`. Subjects may toggle "Hide vouches about me" under opt-in (`POST v1/me/vouch-prefs`).
- **Welcome wall & spotlight:** active members listed within `welcomeDays` appear in the "New here" strip with preset-only greetings (`wave`, `welcome`, `coffee`, 30/hour, no self-greetings) and a link to their `#intro` post. Member spotlight picks one active member per UTC day deterministically (`sha256(date + "\n" + did)`) from members who posted in the last 30 days.
- **Roles & badges:** admins assign built-in (`organizer`, `moderator`) or custom role labels; earned badges (`host`, `helper`, `voice`, `welcomer`, `voucher`, `founding`) are computed on `cron.refresh`. Roles and badges are part of the hub's record: they are deleted on removal or block, not on opt-out.
- **Guestbooks (Rule 3 amendment):** the only Tier 3 feature that stores free text about another member on the hub. Allowed only when the **owner** explicitly enables their guestbook (`POST v1/me/guestbook`), plain text ≤ 280 characters, signed by opted-in verified members (1 per author per owner per day, 20 per author per day). The owner or author can delete any entry, entries use the same report reasons and admin Hide/Dismiss/Restore panels as items, and the owner can export entries as CSV (`GET v1/me/guestbook.csv`). Disabling keeps entries hidden; owner or author opt-out/removal/block purges them.

## API (`/api/ext/social/v1/`)

Items are addressed by `did` and `itemId` in the query string or body, never in the path.

| Route | Access | What it does |
| --- | --- | --- |
| `GET config` | public | Reactions, report reasons, `membersOnly`, and whether the caller is signed in and opted in |
| `GET feed?limit&type&tag&from&to&q&before` | public (members with `membersOnly`) | Top-level items, newest first, each with `author`, `replyCount`, `reactions` and `myReactions`. Supports channel `tag` (or `__following`), UTC `from`/`to` time bounds (used by Daily Feed), keyword search `q`, and compound cursor `before`. |
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
| `GET people` | as feed | Active members with roles, badges, vouch counts and guestbook availability |
| `GET members/vouches?did=`, `GET vouches/recent` | as feed | Received (grouped by kind/skill) and given vouches, or recent vouches |
| `GET me/vouch-prefs`, `POST me/vouch-prefs` `{hideReceived}` | signed in (+ opt-in for POST) | Subject toggle to hide vouches about themselves |
| `GET welcome`, `GET spotlight` | as feed | Newcomers with greeting counts & intro posts; daily deterministic member spotlight |
| `POST greetings` `{subjectDid, kind, on}` | signed in + opted in + CSRF | Preset greeting (`wave`, `welcome`, `coffee`), 30/hour, no self-greeting |
| `GET guestbook?did=`, `POST guestbook` `{ownerDid, body}`, `DELETE guestbook/{id}`, `POST guestbook/{id}/report` | as feed / opt-in / owner or author | Read, sign (≤280 chars), delete or report guestbook entries |
| `POST me/guestbook` `{enabled}`, `GET me/guestbook.csv` | signed in (+ opt-in for POST) | Enable/disable own guestbook; export entries as CSV |
| `GET admin/roles`, `POST admin/roles` `{did, role}`, `DELETE admin/roles/{id}`, `POST admin/badges/recompute` | admin token | Role assignment and manual badge recomputation |
| `GET admin/guestbook/reports`, `GET admin/guestbook/hidden`, `POST admin/guestbook/reports/{id}/hide\|dismiss`, `POST admin/guestbook/hidden/{id}/restore` | admin token | Guestbook moderation |
| `GET channels`, `GET me/follows`, `POST follows` `{tag, on}` | as feed / signed in (+ opt-in) | Curated & open topic channels with 7d counts; follow/unfollow (max 50). Feed supports `?tag=<tag>` and `?tag=__following`. |
| `GET library?days&limit`, `POST library/vote` `{url, on}` | as feed / opt-in | De-duplicated shared/recommended links and upvotes (never fetches remote URLs) |
| `GET now` | as feed | Members' latest `Now` status from `entries.now_text` (hidden after 30 days) |
| `GET challenges`, `GET challenges/{id}` | as feed | Active, upcoming and past challenges with tag-matched entries |
| `GET admin/challenges`, `POST admin/challenges`, `POST admin/challenges/{id}/close`, `DELETE admin/challenges/{id}` | admin token | Manage challenges; closing a `voting=poll` challenge creates a Tier 2 poll and emits `ext.social.challenge_closed` |

Signed-in routes use the hub session cookie. POSTs need the `X-Hub-Csrf` header from `GET /api/auth/me`.

## Tables

The tables are `ext_social_reactions`, `_mentions`, `_reports`, `_hidden`, `_seen` and `_limits`, plus (schema 2) `_events`, `_rsvps`, `_listings`, `_polls`, `_poll_options` and `_poll_votes`, plus (schema 3) `_vouches`, `_vouch_prefs`, `_greetings`, `_roles`, `_badges`, `_guestbooks`, `_guestbook_entries` and `_guestbook_reports`, plus (schema 4) `_item_tags`, `_follows`, `_pick_votes` and `_challenges`. Items are keyed by `item_key = sha256(did + "\n" + itemId)`, with `item_did` / `item_id` kept for purges and display. `social` reads `ext_webhook_sync_items` (declared in `reads`) and never writes to it. `webhook-sync` schema 2 adds the indexed `target_url` column used for replies.

## Testing

`php scripts/smoke-social-structure.php` covers Tier 4 channels, follows, shared library, Now wall, challenges, challenge badges, and the `groups` working-groups extension.

`php scripts/smoke-social-belonging.php` covers Tier 3 vouches, key-witness checks, welcome wall, greetings, spotlight, roles, nightly badges, guestbooks, CSV export, guestbook moderation and all purge paths.

`php scripts/smoke-social-groups.php` covers events, RSVPs, the calendar feed, asks & offers matching, polls, the digest and its Atom feed, and their purges.

`php scripts/smoke-social.php` covers the following against a temporary SQLite database, then over HTTP with `php -S`:
- loading and dependencies;
- mentions, the feed and threads;
- reactions, reports and moderation;
- activity and every purge path;
- sign-in, CSRF and opt-in gating.
