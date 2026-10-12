# Team Notes REST API

A small REST service for notes shared within small teams. The implementation
uses PHP 8.1+ and SQLite through PDO; it has no Composer dependencies. PHP must
be built with the `pdo_sqlite` extension.

## Run it

From the repository directory:

```powershell
php -S 127.0.0.1:8000 index.php
```

The service creates `notes.db` in the repository directory. Configure the
listener and database with environment variables:

| Variable | Default | Purpose |
| --- | --- | --- |
| `HOST` | `127.0.0.1` | Listen address |
| `PORT` | `8000` | Listen port |
| `NOTES_DB` | `notes.db` | SQLite database file |

PowerShell example:

```powershell
$env:HOST = "127.0.0.1"
$env:PORT = "8080"
$env:NOTES_DB = "data\team-notes.sqlite3"
php -S "$($env:HOST):$($env:PORT)" index.php
```

The built-in PHP server is intended for local development. Put the service
behind a properly configured production web server and TLS-terminating proxy
before exposing it to a network.

Run the dependency-free PHP API tests with:

```powershell
php tests/run.php
```

The single suite exercises the routing, authentication, authorization, notes,
tags, search, pagination, archive/pin, and optimistic-concurrency behaviour, the
transport-layer body decoding (`415`/`413`/invalid JSON), and the legacy-schema
migration and search-index backfill.

## API

All request and response bodies are JSON (except empty `204` responses). Send
`Content-Type: application/json` when providing a body. Errors have a consistent
shape:

```json
{"error":{"code":"invalid_field","message":"title must be at most 200 characters."}}
```

Protected endpoints use `Authorization: Bearer <token>`. Registration and login
return an opaque bearer token valid for 30 days. Store it securely; logout
revokes that token. Passwords must be 8-256 characters. Emails are normalized
to lowercase.

| Method | Path | Description |
| --- | --- | --- |
| `POST` | `/auth/register` | Create an account; body: `{"email":"...","password":"..."}` |
| `POST` | `/auth/login` | Authenticate; same body as registration |
| `POST` | `/auth/logout` | Revoke the current token |
| `GET` | `/me` | Return the authenticated user |
| `GET` | `/teams` | List teams the user belongs to, including their role |
| `POST` | `/teams` | Create a team; body: `{"name":"..."}`; creator is its first admin |
| `GET` | `/teams/{teamId}` | Get a team (members only) |
| `GET` | `/teams/{teamId}/members` | List members (admins only) |
| `POST` | `/teams/{teamId}/members` | Add an existing account; body: `{"email":"..."}` (admins only) |
| `DELETE` | `/teams/{teamId}/members/{userId}` | Remove a member (admins only) |
| `GET` | `/teams/{teamId}/notes` | List notes; filters below (members only) |
| `POST` | `/teams/{teamId}/notes` | Create a note; body: `{"title":"...","content":"...","tags":["..."]}` |
| `GET` | `/teams/{teamId}/notes/{noteId}` | Get a note |
| `PATCH` | `/teams/{teamId}/notes/{noteId}` | Update `title`, `content`, `tags`, `archived`, `pinned`; honors `If-Match` |
| `DELETE` | `/teams/{teamId}/notes/{noteId}` | Delete a note |

A note is returned as:

```json
{
  "id": 1, "team_id": 1, "author_id": 1,
  "title": "Next steps", "content": "Agree on the milestones",
  "tags": ["planning", "urgent"],
  "version": 3, "archived": false, "pinned": true,
  "created_at": "2026-01-01T00:00:00.000Z",
  "updated_at": "2026-01-02T00:00:00.000Z"
}
```

`tags` are free-form labels; they are trimmed, lowercased, deduplicated, and
returned sorted, with at most 20 tags of 50 characters each per note. Sending
`tags` on `PATCH` replaces the whole set. `archived` notes are hidden from the
default listing. Every change increments `version`; to avoid silently
overwriting a teammate, send `If-Match: <version>` on `PATCH` (or `If-Match: *`
to opt out) and expect `412` when the version is stale.

`GET /teams/{teamId}/notes` accepts these query parameters and returns
`{"items":[...],"next_cursor":"..."}` (`next_cursor` is `null` on the last page):

| Parameter | Values | Meaning |
| --- | --- | --- |
| `tag` | any tag | Only notes carrying that tag (case-insensitive) |
| `q` | text | Full-text phrase search over title and content (SQLite FTS5) |
| `archived` | `0` (default) or `1` | Active notes, or only archived notes |
| `pinned` | `0` or `1` | Optionally filter by pinned flag |
| `sort` | `updated` (default), `created`, `title` | Newest first, or title A-Z |
| `limit` | 1-100 (default 50) | Page size |
| `cursor` | previous `next_cursor` | Continue the listing |

Team names are limited to 100 characters, note titles to 200, note content to
100,000, and request bodies to 1 MiB. An empty note body is allowed and note
content whitespace is preserved. All team members can create, edit, and delete
all notes in their team. Team membership is required to read notes; team admins
additionally manage membership. A team must always retain at least one admin.
Unknown teams and notes outside the caller's teams return `404` to avoid
disclosing their existence. Common status codes are `201` for creation, `204`
for successful deletion/logout, `400` for invalid input, `401` for
missing/invalid credentials, `403` for an insufficient team role, `404` for an
inaccessible or absent resource, `409` for a conflict, `412` for a failed
`If-Match` preconditions, `413` for an oversized body, and `415` for an
unsupported content type.

Example:

```powershell
$account = Invoke-RestMethod -Method Post -Uri http://127.0.0.1:8000/auth/register `
  -ContentType "application/json" `
  -Body '{"email":"alex@example.com","password":"a-long-enough-password"}'
$headers = @{ Authorization = "Bearer $($account.token)" }
$team = Invoke-RestMethod -Method Post -Uri http://127.0.0.1:8000/teams `
  -Headers $headers -ContentType "application/json" -Body '{"name":"Planning"}'
Invoke-RestMethod -Method Post `
  -Uri "http://127.0.0.1:8000/teams/$($team.team.id)/notes" `
  -Headers $headers -ContentType "application/json" `
  -Body '{"title":"Next steps","content":"Agree on the milestones","tags":["planning"]}'
```

## Design choices

Four decisions took the most time and shaped the rest of the service:

1. **Teams own the data; membership is the access boundary.** Notes live under a
   team rather than a user, so sharing and isolation are enforced in the same
   place: every team or note request checks the caller's membership, and absent
   or inaccessible resources both return `404` so requests never reveal whether
   another team's data exists. Admins alone add or remove members, and a team
   must always keep at least one admin. Most of the time went into the
   authorization ordering — validate input, then decide who may act, without
   leaking other teams' existence — and into accepting the tradeoff that all
   members of a team share the same read/write privileges on its notes.

   *How "shared" is scoped.* The brief calls this a service "shared amongst
   several small teams"; that is interpreted as the service being multi-tenant,
   not notes crossing team boundaries. Keeping every team's notes private to
   that team avoids one team's conventions, edits, or naming leaking into
   another's and the confusion that would follow. Cross-team sharing is
   therefore intentionally out of scope; if it were added, it would be an
   explicit, opt-in grant (for example a per-note visibility field or a
   team-to-team share) rather than an implicit capability.
2. **SQLite with WAL and a zero-dependency HTTP entry point.** A small shared
   service wants single-file, predictable setup; SQLite foreign keys,
   transactions, an index on `(team_id, updated_at)`, a `(tag, note_id)` index,
   and FTS5 for search keep the model consistent and queryable without a
   database server to run. The tradeoff is a single primary: the service targets
   one instance, and concurrent writers or horizontal scale are a deliberate
   follow-up rather than a silent assumption. PHP's built-in server is only the
   local-development runtime and is explicitly documented as needing a real web
   server and TLS in front of it.
3. **Opaque, revocable bearer sessions and bounded inputs.** Clients receive
   random tokens whose SHA-256 digests are stored server-side, so logout is a
   real revocation and raw credentials are never at rest. Passwords use
   `password_hash`/`password_verify` over a pre-hash that safely covers the
   documented 8-256-character range under bcrypt's byte limits. Requests are
   capped at 1 MiB before parsing, field lengths are counted as Unicode
   characters, and stored values are validated as UTF-8 so every note can always
   be re-encoded without loss. Simple, all-or-nothing limits are easier to
   explain, test, and defend than elaborate streaming rules.
4. **Discovery and safe concurrent editing.** Teams asked for notes they can
   find and organize, so the design centers on filtering (`tag`), full-text
   search (`q` via FTS5), and keyset pagination (`limit` + `cursor`), with tags
   normalized and returned sorted. Because several people share one note, every
   mutation bumps a `version` and `PATCH` honors `If-Match`, returning `412` on a
   stale write instead of silently clobbering a teammate. The tradeoffs were
   keeping tags free-form (flexible, but the server only normalizes rather than
   curating a vocabulary) and using a simple monotonic version instead of full
   revision history, which would store every prior content snapshot.

## If I had more time

**Add.** Verified-email invitations, password reset/change, session listing and
revocation, and account lifecycle controls (deletion, ownership transfer). Note
revision history (or surfacing `version` as a real HTTP `ETag`) so editors can
diff and restore, plus a controlled per-team tag vocabulary and cursor support
for the teams/members lists. Brute-force protection on login, rate limiting,
health checks, structured logging, and explicit migration/backup/restore
procedures.

**Change.** Replace PHP's built-in development server and the hand-rolled router
with a maintained framework and a real web-server/TLS configuration, letting the
framework own request parsing, routing, and validation instead of the custom
glue here (content-length and content-type checks, body decoding). Move to a
managed relational database if the service needs multiple instances or
sustained concurrent writes, and revisit the fixed request and field limits as
usage grows.

**Stop doing.** Hand-maintaining web-server glue that a framework or managed
runtime provides; storing large note bodies inline in the notes table once long
or attached content appears; and treating SQLite's single writer as an
acceptable default — it is only acceptable because the intended scope is one
small team service, and it would be the first constraint re-examined if the
scope grew.
