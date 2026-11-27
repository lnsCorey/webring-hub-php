# Working Groups (`groups`)

Optional extension for topic sub-groups (`extensions/groups/`). Declares `"reads": ["webhook-sync", "social"]`, so enable it after both in `config.php`:

```php
'extensions' => ['key-witness', 'arcade', 'webhook-sync', 'social', 'groups'],
'extension_settings' => [
    'groups' => [
        'membersOnly' => true,      // default true: requires sign-in to browse groups
        'groupCreators' => 'admin', // or 'members'
    ],
],
```

## How it works

- Each working group has a `slug` (e.g. `hardware`), `title`, `description`, `organizerDid`, and `visibility` (`open` or `request`).
- **Scoped feeds & events:** posts carrying `#wg-<slug>` (indexed by `social`'s `item_tags`) appear in the group's feed and events **only when authored by an active member of that group**.
- **Group polls:** group members can create and vote in group-scoped polls; non-members are rejected (`403`).
- **Organiser handoff & purges:** when an organiser leaves (or opts out / is removed / blocked), organiser status passes to the longest-standing active member, or the group is archived if empty. Opting out of `groups` deletes memberships, join requests, and group poll votes.

## API (`/api/ext/groups/v1/`)

- `GET groups`, `POST groups` (when `groupCreators = 'members'`)
- `GET groups/{slug}` (feed, events, polls, member list for signed-in members, pending requests for organiser)
- `POST groups/{slug}/join`, `POST groups/{slug}/leave`
- `POST groups/{slug}/requests/{did}/approve` (organiser only)
- `POST groups/{slug}/polls`, `POST groups/{slug}/polls/{id}/vote` (members only)
- Admin: `GET admin/groups`, `POST admin/groups`, `POST admin/groups/{id}/archive`, `DELETE admin/groups/{id}`
