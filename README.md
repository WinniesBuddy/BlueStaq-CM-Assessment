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
| `GET` | `/teams/{teamId}/notes` | List shared notes (members only) |
| `POST` | `/teams/{teamId}/notes` | Create a note; body: `{"title":"...","content":"..."}` |
| `GET` | `/teams/{teamId}/notes/{noteId}` | Get a note |
| `PATCH` | `/teams/{teamId}/notes/{noteId}` | Update `title` and/or `content` |
| `DELETE` | `/teams/{teamId}/notes/{noteId}` | Delete a note |

Team names are limited to 100 characters, note titles to 200, note content to
100,000, and request bodies to 1 MiB. An empty note body is allowed and note
content whitespace is preserved. All team members can create, edit, and delete
all notes in their team. Team membership is required to read notes; team admins
additionally manage membership. A team must always retain at least one admin.
Unknown teams and notes outside the caller's teams return `404` to avoid
disclosing their existence. Common status codes are `201` for creation, `204`
for successful deletion/logout, `400` for invalid input, `401` for
missing/invalid credentials, `403` for an insufficient team role, `404` for an
inaccessible or absent resource, and `409` for a conflict.

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
  -Body '{"title":"Next steps","content":"Agree on the milestones"}'
```

## Design choices

1. **Team-scoped data and explicit membership.** Notes belong to a team rather
   than an individual, so sharing and isolation are enforced at the same
   boundary. Membership is checked on each team and note request, and team
   admins alone can add or remove members. To keep the initial service easy to
   operate, an admin can add an account after it has registered; there is no
   invitation-email workflow.
2. **SQLite and a dependency-free PHP HTTP entry point.** A small team service
   benefits from a single-file database and straightforward local setup. SQLite
   foreign keys, transactions, and indexes keep the data model consistent,
   while PDO and PHP's built-in development server avoid a Composer dependency
   chain. The built-in server is for local development, not high availability
   or heavy concurrent workloads.
3. **Opaque, revocable sessions and bounded inputs.** Passwords are stored with
   PHP's `password_hash` and verified with `password_verify` (the password is
   SHA-384 pre-hashed to safely support the documented length with bcrypt-based
   PHP defaults); clients receive random tokens whose SHA-256 digests are stored
   server-side, so logout can revoke credentials without storing raw tokens.
   Request and field limits provide a basic resource boundary, and
   authorization failures do not reveal whether a different team's data exists.

## If I had more time

- Replace the built-in server with a maintained production PHP framework and
  web-server configuration, and add TLS, rate limiting, and health checks.
- Add verified-email invitations, password reset/change, session listing and
  revocation, and account lifecycle controls.
- Add note revision history or optimistic concurrency (ETags) to make
  simultaneous editing safer, plus pagination for large teams.
- Add migration/version management, backup/restore procedures, and broader
  concurrency, expiry, and malformed-request tests.
- Revisit SQLite if usage requires multiple service instances or sustained
  concurrent writes; move to a managed relational database with appropriate
  migrations and deployment monitoring.
