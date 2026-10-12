<?php
declare(strict_types=1);

const TOKEN_TTL_SECONDS = 2592000;
const MAX_REQUEST_BYTES = 1048576;

final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }
}

function apiError(int $status, string $code, string $message): never
{
    throw new ApiException($status, $code, $message);
}

final class NotesApi
{
    private PDO $db;

    public function __construct(string $databasePath)
    {
        if ($databasePath !== ':memory:') {
            $directory = dirname($databasePath);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException("Could not create database directory: $directory");
            }
        }

        $this->db = new PDO('sqlite:' . $databasePath, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db->exec('PRAGMA foreign_keys = ON');
        if ($databasePath !== ':memory:') {
            $this->db->exec('PRAGMA journal_mode = WAL');
        }
        $this->initialize();
    }

    private function initialize(): void
    {
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY,
                email TEXT NOT NULL UNIQUE COLLATE NOCASE,
                password_hash TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT
                    (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
            );
            CREATE TABLE IF NOT EXISTS sessions (
                token_hash TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                expires_at INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS teams (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                owner_id INTEGER NOT NULL REFERENCES users(id),
                created_at TEXT NOT NULL DEFAULT
                    (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
            );
            CREATE TABLE IF NOT EXISTS memberships (
                team_id INTEGER NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                role TEXT NOT NULL CHECK (role IN ('admin', 'member')),
                created_at TEXT NOT NULL DEFAULT
                    (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
                PRIMARY KEY (team_id, user_id)
            );
            CREATE TABLE IF NOT EXISTS notes (
                id INTEGER PRIMARY KEY,
                team_id INTEGER NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
                author_id INTEGER NOT NULL REFERENCES users(id),
                title TEXT NOT NULL,
                content TEXT NOT NULL,
                version INTEGER NOT NULL DEFAULT 1,
                archived INTEGER NOT NULL DEFAULT 0 CHECK (archived IN (0, 1)),
                pinned INTEGER NOT NULL DEFAULT 0 CHECK (pinned IN (0, 1)),
                created_at TEXT NOT NULL DEFAULT
                    (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
                updated_at TEXT NOT NULL DEFAULT
                    (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
            );
            CREATE TABLE IF NOT EXISTS note_tags (
                note_id INTEGER NOT NULL REFERENCES notes(id) ON DELETE CASCADE,
                tag TEXT NOT NULL,
                PRIMARY KEY (note_id, tag)
            );
            CREATE INDEX IF NOT EXISTS note_tags_tag ON note_tags(tag, note_id);
            CREATE INDEX IF NOT EXISTS notes_team_updated
                ON notes(team_id, updated_at DESC, id DESC);
            CREATE INDEX IF NOT EXISTS sessions_expiry ON sessions(expires_at);
            CREATE VIRTUAL TABLE IF NOT EXISTS notes_fts USING fts5(title, content);
            CREATE TRIGGER IF NOT EXISTS notes_fts_insert AFTER INSERT ON notes BEGIN
                INSERT INTO notes_fts(rowid, title, content)
                VALUES (new.id, new.title, new.content);
            END;
            CREATE TRIGGER IF NOT EXISTS notes_fts_update AFTER UPDATE ON notes BEGIN
                UPDATE notes_fts SET title = new.title, content = new.content
                WHERE rowid = new.id;
            END;
            CREATE TRIGGER IF NOT EXISTS notes_fts_delete AFTER DELETE ON notes BEGIN
                DELETE FROM notes_fts WHERE rowid = old.id;
            END;
            SQL);

        $this->ensureNotesColumns();
        $this->db->exec(
            'CREATE INDEX IF NOT EXISTS notes_team_archived
                ON notes(team_id, archived, updated_at DESC, id DESC)'
        );
        $this->populateSearchIndex();
    }

    private function ensureNotesColumns(): void
    {
        $columns = $this->db->query('PRAGMA table_info(notes)')->fetchAll(PDO::FETCH_COLUMN, 1);
        $additions = [
            'version' => 'ALTER TABLE notes ADD COLUMN version INTEGER NOT NULL DEFAULT 1',
            'archived' => 'ALTER TABLE notes ADD COLUMN archived INTEGER NOT NULL DEFAULT 0',
            'pinned' => 'ALTER TABLE notes ADD COLUMN pinned INTEGER NOT NULL DEFAULT 0',
        ];
        foreach ($additions as $column => $sql) {
            if (!in_array($column, $columns, true)) {
                $this->db->exec($sql);
            }
        }
    }

    private function populateSearchIndex(): void
    {
        $indexed = (int)$this->db->query('SELECT count(*) FROM notes_fts')->fetchColumn();
        $notes = (int)$this->db->query('SELECT count(*) FROM notes')->fetchColumn();
        if ($indexed === 0 && $notes > 0) {
            $this->db->exec(
                'INSERT INTO notes_fts(rowid, title, content) SELECT id, title, content FROM notes'
            );
        }
    }

    private function statement(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }

    private function requireObject(mixed $value): object
    {
        if (!is_object($value) || $value instanceof stdClass === false) {
            apiError(400, 'invalid_json', 'A JSON object is required.');
        }
        return $value;
    }

    private function text(
        mixed $value,
        string $field,
        int $maximum,
        bool $allowEmpty = false,
        bool $strip = true
    ): string {
        if (!is_string($value)) {
            apiError(400, 'invalid_field', "$field must be a string.");
        }
        if (preg_match('//u', $value) !== 1) {
            apiError(400, 'invalid_field', "$field must contain only valid UTF-8 text.");
        }
        if ($strip) {
            $value = trim($value);
        }
        $length = preg_match_all('/./us', $value);
        if ($length > $maximum) {
            apiError(400, 'invalid_field', "$field must be at most $maximum characters.");
        }
        if ($length === 0 && !$allowEmpty) {
            apiError(400, 'invalid_field', "$field must not be empty.");
        }
        return $value;
    }

    private function id(string $value): int
    {
        if (!ctype_digit($value) || (int)$value < 1) {
            apiError(404, 'not_found', 'The requested resource was not found.');
        }
        return (int)$value;
    }

    private function userJson(array $row): array
    {
        return ['id' => (int)$row['id'], 'email' => $row['email']];
    }

    private function noteJson(array $row, array $tags = []): array
    {
        return [
            'id' => (int)$row['id'],
            'team_id' => (int)$row['team_id'],
            'author_id' => (int)$row['author_id'],
            'title' => $row['title'],
            'content' => $row['content'],
            'tags' => $tags,
            'version' => (int)$row['version'],
            'archived' => (bool)$row['archived'],
            'pinned' => (bool)$row['pinned'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function tags(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            apiError(400, 'invalid_tags', 'tags must be an array of strings.');
        }
        if (count($value) > 20) {
            apiError(400, 'invalid_tags', 'A note may have at most 20 tags.');
        }
        $normalized = [];
        foreach ($value as $tag) {
            $normalized[strtolower($this->text($tag, 'tag', 50))] = true;
        }
        return array_keys($normalized);
    }

    private function boolean(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            apiError(400, 'invalid_field', "$field must be a boolean.");
        }
        return $value;
    }

    private function tagsForNote(int $noteId): array
    {
        return $this->statement(
            'SELECT tag FROM note_tags WHERE note_id = ? ORDER BY tag',
            [$noteId]
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    private function tagsForNotes(array $noteIds): array
    {
        if ($noteIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($noteIds), '?'));
        $rows = $this->statement(
            "SELECT note_id, tag FROM note_tags WHERE note_id IN ($placeholders) ORDER BY tag",
            $noteIds
        )->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['note_id']][] = $row['tag'];
        }
        return $map;
    }

    private function replaceTags(int $noteId, array $tags): void
    {
        $this->statement('DELETE FROM note_tags WHERE note_id = ?', [$noteId]);
        foreach ($tags as $tag) {
            $this->statement('INSERT INTO note_tags(note_id, tag) VALUES (?, ?)', [$noteId, $tag]);
        }
    }

    private function matchQuery(string $search): string
    {
        return '"' . str_replace('"', '""', $search) . '"';
    }

    private function encodeCursor(string $sort, string $key, int $id): string
    {
        $payload = json_encode([$sort, $key, $id], JSON_THROW_ON_ERROR);
        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    private function decodeCursor(string $cursor): array
    {
        $padded = strtr($cursor, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            apiError(400, 'invalid_cursor', 'The cursor is invalid.');
        }
        try {
            $data = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            apiError(400, 'invalid_cursor', 'The cursor is invalid.');
        }
        if (!is_array($data) || count($data) !== 3 || !is_string($data[0])
            || !is_string($data[1]) || !is_int($data[2])) {
            apiError(400, 'invalid_cursor', 'The cursor is invalid.');
        }
        return $data;
    }

    private function expectedVersion(string $ifMatch): ?int
    {
        $value = trim($ifMatch);
        if ($value === '*') {
            return null;
        }
        $value = trim($value, '"');
        if ($value === '' || !ctype_digit($value)) {
            apiError(400, 'invalid_if_match', 'If-Match must be a note version or *.');
        }
        return (int)$value;
    }

    private function passwordInput(string $password): string
    {
        return base64_encode(hash('sha384', $password, true));
    }

    private function issueToken(int $userId): array
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = time() + TOKEN_TTL_SECONDS;
        $this->statement(
            'INSERT INTO sessions(token_hash, user_id, expires_at) VALUES (?, ?, ?)',
            [hash('sha256', $token), $userId, $expiresAt]
        );
        return [$token, $expiresAt];
    }

    private function authenticate(?string $authorization): array
    {
        if ($authorization === null || !str_starts_with($authorization, 'Bearer ')) {
            apiError(401, 'unauthorized', 'A valid bearer token is required.');
        }
        $token = substr($authorization, 7);
        if ($token === '') {
            apiError(401, 'unauthorized', 'A valid bearer token is required.');
        }
        $row = $this->statement(
            <<<'SQL'
                SELECT u.id, u.email, s.token_hash
                FROM sessions s JOIN users u ON u.id = s.user_id
                WHERE s.token_hash = ? AND s.expires_at > ?
                SQL,
            [hash('sha256', $token), time()]
        )->fetch();
        if ($row === false) {
            apiError(401, 'unauthorized', 'A valid bearer token is required.');
        }
        $row['id'] = (int)$row['id'];
        return $row;
    }

    private function requireMember(int $teamId, int $userId): string
    {
        $row = $this->statement(
            'SELECT role FROM memberships WHERE team_id = ? AND user_id = ?',
            [$teamId, $userId]
        )->fetch();
        if ($row === false) {
            apiError(404, 'not_found', 'The requested resource was not found.');
        }
        return $row['role'];
    }

    private function requireAdmin(int $teamId, int $userId): void
    {
        if ($this->requireMember($teamId, $userId) !== 'admin') {
            apiError(403, 'forbidden', 'Team admin privileges are required.');
        }
    }

    private function register(mixed $body): array
    {
        $data = $this->requireObject($body);
        $email = strtolower($this->text($data->email ?? null, 'email', 254));
        $password = $data->password ?? null;
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            apiError(400, 'invalid_email', 'email must be a valid email address.');
        }
        $passwordLength = is_string($password) ? preg_match_all('/./us', $password) : false;
        if ($passwordLength === false || $passwordLength < 8 || $passwordLength > 256) {
            apiError(400, 'invalid_password', 'password must be between 8 and 256 characters.');
        }
        $passwordHash = password_hash($this->passwordInput($password), PASSWORD_DEFAULT);
        $this->db->beginTransaction();
        try {
            try {
                $this->statement(
                    'INSERT INTO users(email, password_hash) VALUES (?, ?)',
                    [$email, $passwordHash]
                );
            } catch (PDOException $exception) {
                if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                    apiError(409, 'email_taken', 'An account with that email already exists.');
                }
                throw $exception;
            }
            $userId = (int)$this->db->lastInsertId();
            [$token, $expiresAt] = $this->issueToken($userId);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
        return [201, [
            'user' => ['id' => $userId, 'email' => $email],
            'token' => $token,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expiresAt),
        ]];
    }

    private function login(mixed $body): array
    {
        $data = $this->requireObject($body);
        $email = strtolower($this->text($data->email ?? null, 'email', 254));
        $password = $data->password ?? null;
        if (!is_string($password)) {
            apiError(400, 'invalid_password', 'password must be a string.');
        }
        $row = $this->statement(
            'SELECT id, email, password_hash FROM users WHERE email = ?',
            [$email]
        )->fetch();
        if ($row === false || !password_verify($this->passwordInput($password), $row['password_hash'])) {
            apiError(401, 'invalid_credentials', 'Email or password is incorrect.');
        }
        [$token, $expiresAt] = $this->issueToken((int)$row['id']);
        return [200, [
            'user' => $this->userJson($row),
            'token' => $token,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expiresAt),
        ]];
    }

    private function listNotes(int $teamId, int $userId, array $query): array
    {
        $this->requireMember($teamId, $userId);

        $archived = '0';
        if (array_key_exists('archived', $query)) {
            if (!is_string($query['archived']) || !in_array($query['archived'], ['0', '1'], true)) {
                apiError(400, 'invalid_query', 'archived must be 0 or 1.');
            }
            $archived = $query['archived'];
        }

        $pinned = null;
        if (array_key_exists('pinned', $query)) {
            if (!is_string($query['pinned']) || !in_array($query['pinned'], ['0', '1'], true)) {
                apiError(400, 'invalid_query', 'pinned must be 0 or 1.');
            }
            $pinned = (int)$query['pinned'];
        }

        $tag = null;
        if (array_key_exists('tag', $query)) {
            if (!is_string($query['tag'])) {
                apiError(400, 'invalid_query', 'tag must be a single string.');
            }
            $tag = strtolower($this->text($query['tag'], 'tag', 50));
        }

        $search = null;
        if (array_key_exists('q', $query)) {
            if (!is_string($query['q'])) {
                apiError(400, 'invalid_query', 'q must be a single string.');
            }
            $search = $this->text($query['q'], 'q', 200);
        }

        $sort = 'updated';
        if (array_key_exists('sort', $query)) {
            if (!is_string($query['sort'])
                || !in_array($query['sort'], ['updated', 'created', 'title'], true)) {
                apiError(400, 'invalid_query', 'sort must be updated, created, or title.');
            }
            $sort = $query['sort'];
        }

        $limit = 50;
        if (array_key_exists('limit', $query)) {
            if (!is_string($query['limit'])
                || !ctype_digit($query['limit'])
                || (int)$query['limit'] < 1
                || (int)$query['limit'] > 100) {
                apiError(400, 'invalid_query', 'limit must be an integer between 1 and 100.');
            }
            $limit = (int)$query['limit'];
        }

        $cursor = null;
        if (array_key_exists('cursor', $query)) {
            if (!is_string($query['cursor']) || $query['cursor'] === '') {
                apiError(400, 'invalid_query', 'cursor must be a non-empty string.');
            }
            $cursor = $query['cursor'];
        }

        $joins = [];
        $where = ['n.team_id = ?', 'n.archived = ?'];
        $params = [$teamId, (int)$archived];

        if ($pinned !== null) {
            $where[] = 'n.pinned = ?';
            $params[] = $pinned;
        }
        if ($tag !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM note_tags nt WHERE nt.note_id = n.id AND nt.tag = ?)';
            $params[] = $tag;
        }
        if ($search !== null) {
            $joins[] = 'JOIN notes_fts ON notes_fts.rowid = n.id';
            $where[] = 'notes_fts MATCH ?';
            $params[] = $this->matchQuery($search);
        }

        switch ($sort) {
            case 'created':
                $order = 'n.created_at DESC, n.id DESC';
                $keyField = 'created_at';
                $ascending = false;
                break;
            case 'title':
                $order = 'n.title ASC, n.id ASC';
                $keyField = 'title';
                $ascending = true;
                break;
            default:
                $order = 'n.updated_at DESC, n.id DESC';
                $keyField = 'updated_at';
                $ascending = false;
        }

        if ($cursor !== null) {
            [$cursorSort, $key, $cursorId] = $this->decodeCursor($cursor);
            if ($cursorSort !== $sort) {
                apiError(400, 'invalid_cursor', 'The cursor does not match the requested sort.');
            }
            $operator = $ascending ? '>' : '<';
            $where[] = "(n.$keyField $operator ? OR (n.$keyField = ? AND n.id $operator ?))";
            array_push($params, $key, $key, $cursorId);
        }

        $sql = 'SELECT n.* FROM notes n ' . implode(' ', $joins)
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ' . $order
            . ' LIMIT ' . ($limit + 1);

        $rows = $this->statement($sql, $params)->fetchAll();

        $nextCursor = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $last = $rows[count($rows) - 1];
            $nextCursor = $this->encodeCursor($sort, (string)$last[$keyField], (int)$last['id']);
        }

        $tagMap = $this->tagsForNotes(array_map(
            static fn(array $row): int => (int)$row['id'],
            $rows
        ));
        $items = array_map(
            fn(array $row): array => $this->noteJson($row, $tagMap[(int)$row['id']] ?? []),
            $rows
        );

        return [200, ['items' => $items, 'next_cursor' => $nextCursor]];
    }

    private function createNote(int $teamId, int $userId, mixed $body): array
    {
        $data = $this->requireObject($body);
        $title = $this->text($data->title ?? null, 'title', 200);
        $content = $this->text(
            $data->content ?? null,
            'content',
            100000,
            allowEmpty: true,
            strip: false
        );
        $tags = $this->tags($data->tags ?? null);

        $this->requireMember($teamId, $userId);
        $this->db->beginTransaction();
        try {
            $this->statement(
                'INSERT INTO notes(team_id, author_id, title, content) VALUES (?, ?, ?, ?)',
                [$teamId, $userId, $title, $content]
            );
            $noteId = (int)$this->db->lastInsertId();
            $this->replaceTags($noteId, $tags);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        $note = $this->statement('SELECT * FROM notes WHERE id = ?', [$noteId])->fetch();
        return [201, ['note' => $this->noteJson($note, $this->tagsForNote($noteId))]];
    }

    private function updateNote(
        int $teamId,
        int $noteId,
        int $userId,
        mixed $body,
        ?string $ifMatch
    ): array {
        $data = $this->requireObject($body);
        $fields = get_object_vars($data);
        $allowed = ['title', 'content', 'tags', 'archived', 'pinned'];
        if ($fields === [] || array_diff(array_keys($fields), $allowed) !== []) {
            apiError(400, 'invalid_fields', 'Provide one or more of: title, content, tags, archived, pinned.');
        }

        $title = null;
        $content = null;
        $tags = null;
        $archived = null;
        $pinned = null;
        if (array_key_exists('title', $fields)) {
            $title = $this->text($fields['title'], 'title', 200);
        }
        if (array_key_exists('content', $fields)) {
            $content = $this->text(
                $fields['content'],
                'content',
                100000,
                allowEmpty: true,
                strip: false
            );
        }
        if (array_key_exists('tags', $fields)) {
            $tags = $this->tags($fields['tags']);
        }
        if (array_key_exists('archived', $fields)) {
            $archived = $this->boolean($fields['archived'], 'archived');
        }
        if (array_key_exists('pinned', $fields)) {
            $pinned = $this->boolean($fields['pinned'], 'pinned');
        }

        $this->requireMember($teamId, $userId);
        $current = $this->statement(
            'SELECT * FROM notes WHERE id = ? AND team_id = ?',
            [$noteId, $teamId]
        )->fetch();
        if ($current === false) {
            apiError(404, 'not_found', 'The requested resource was not found.');
        }

        $expected = $ifMatch === null ? null : $this->expectedVersion($ifMatch);

        $updates = [];
        if ($title !== null) {
            $updates['title'] = $title;
        }
        if ($content !== null) {
            $updates['content'] = $content;
        }
        if ($archived !== null) {
            $updates['archived'] = $archived ? 1 : 0;
        }
        if ($pinned !== null) {
            $updates['pinned'] = $pinned ? 1 : 0;
        }

        $this->db->beginTransaction();
        try {
            $assignments = implode(', ', array_map(
                static fn(string $field): string => "$field = ?",
                array_keys($updates)
            ));
            if ($assignments !== '') {
                $assignments .= ', ';
            }
            $guard = $expected === null ? '' : ' AND version = ?';
            $parameters = [...array_values($updates), $noteId, $teamId];
            if ($expected !== null) {
                $parameters[] = $expected;
            }
            $statement = $this->statement(
                "UPDATE notes SET {$assignments}version = version + 1, "
                . "updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') "
                . "WHERE id = ? AND team_id = ?{$guard}",
                $parameters
            );
            if ($expected !== null && $statement->rowCount() === 0) {
                apiError(
                    412,
                    'precondition_failed',
                    'The note changed since version ' . $expected . '.'
                );
            }
            if ($tags !== null) {
                $this->replaceTags($noteId, $tags);
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        $note = $this->statement('SELECT * FROM notes WHERE id = ?', [$noteId])->fetch();
        return [200, ['note' => $this->noteJson($note, $this->tagsForNote($noteId))]];
    }

    public function handle(
        string $method,
        string $path,
        ?string $authorization,
        mixed $body = null,
        ?string $ifMatch = null
    ): array {
        $pathOnly = parse_url($path, PHP_URL_PATH);
        $parts = array_values(array_filter(
            explode('/', trim(is_string($pathOnly) ? $pathOnly : '', '/')),
            static fn(string $part): bool => $part !== ''
        ));
        $parts = array_map('rawurldecode', $parts);

        $query = [];
        $queryString = parse_url($path, PHP_URL_QUERY);
        if (is_string($queryString)) {
            parse_str($queryString, $query);
        }

        if ($parts === ['auth', 'register'] && $method === 'POST') {
            return $this->register($body);
        }
        if ($parts === ['auth', 'login'] && $method === 'POST') {
            return $this->login($body);
        }

        $user = $this->authenticate($authorization);
        if ($parts === ['auth', 'logout'] && $method === 'POST') {
            $this->statement('DELETE FROM sessions WHERE token_hash = ?', [$user['token_hash']]);
            return [204, null];
        }
        if ($parts === ['me'] && $method === 'GET') {
            return [200, ['user' => $this->userJson($user)]];
        }
        if ($parts === ['teams'] && $method === 'GET') {
            $rows = $this->statement(
                <<<'SQL'
                    SELECT t.id, t.name, t.owner_id, t.created_at, m.role
                    FROM teams t JOIN memberships m ON m.team_id = t.id
                    WHERE m.user_id = ? ORDER BY t.id
                    SQL,
                [$user['id']]
            )->fetchAll();
            foreach ($rows as &$row) {
                $row['id'] = (int)$row['id'];
                $row['owner_id'] = (int)$row['owner_id'];
            }
            unset($row);
            return [200, ['items' => $rows]];
        }
        if ($parts === ['teams'] && $method === 'POST') {
            $data = $this->requireObject($body);
            $name = $this->text($data->name ?? null, 'name', 100);
            $this->db->beginTransaction();
            try {
                $this->statement('INSERT INTO teams(name, owner_id) VALUES (?, ?)', [
                    $name,
                    $user['id'],
                ]);
                $teamId = (int)$this->db->lastInsertId();
                $this->statement(
                    "INSERT INTO memberships(team_id, user_id, role) VALUES (?, ?, 'admin')",
                    [$teamId, $user['id']]
                );
                $team = $this->statement(
                    'SELECT id, name, owner_id, created_at FROM teams WHERE id = ?',
                    [$teamId]
                )->fetch();
                $this->db->commit();
            } catch (Throwable $exception) {
                $this->db->rollBack();
                throw $exception;
            }
            $team['id'] = (int)$team['id'];
            $team['owner_id'] = (int)$team['owner_id'];
            return [201, ['team' => $team]];
        }

        if (count($parts) >= 2 && $parts[0] === 'teams') {
            $teamId = $this->id($parts[1]);
            if (count($parts) === 2 && $method === 'GET') {
                $this->requireMember($teamId, $user['id']);
                $team = $this->statement(
                    'SELECT id, name, owner_id, created_at FROM teams WHERE id = ?',
                    [$teamId]
                )->fetch();
                if ($team === false) {
                    apiError(404, 'not_found', 'The requested resource was not found.');
                }
                $team['id'] = (int)$team['id'];
                $team['owner_id'] = (int)$team['owner_id'];
                return [200, ['team' => $team]];
            }

            if (count($parts) >= 3 && $parts[2] === 'members') {
                if (count($parts) === 3 && $method === 'GET') {
                    $this->requireAdmin($teamId, $user['id']);
                    $rows = $this->statement(
                        <<<'SQL'
                            SELECT u.id, u.email, m.role, m.created_at
                            FROM memberships m JOIN users u ON u.id = m.user_id
                            WHERE m.team_id = ? ORDER BY u.email
                            SQL,
                        [$teamId]
                    )->fetchAll();
                    foreach ($rows as &$row) {
                        $row['id'] = (int)$row['id'];
                    }
                    unset($row);
                    return [200, ['items' => $rows]];
                }
                if (count($parts) === 3 && $method === 'POST') {
                    $data = $this->requireObject($body);
                    $email = strtolower($this->text($data->email ?? null, 'email', 254));
                    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                        apiError(400, 'invalid_email', 'email must be a valid email address.');
                    }
                    $this->requireAdmin($teamId, $user['id']);
                    $member = $this->statement(
                        'SELECT id, email FROM users WHERE email = ?',
                        [$email]
                    )->fetch();
                    if ($member === false) {
                        apiError(404, 'user_not_found', 'No registered user has that email.');
                    }
                    try {
                        $this->statement(
                            "INSERT INTO memberships(team_id, user_id, role) VALUES (?, ?, 'member')",
                            [$teamId, $member['id']]
                        );
                    } catch (PDOException $exception) {
                        if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                            apiError(409, 'already_member', 'That user is already a team member.');
                        }
                        throw $exception;
                    }
                    return [201, ['member' => [
                        ...$this->userJson($member),
                        'role' => 'member',
                    ]]];
                }
                if (count($parts) === 4 && $method === 'DELETE') {
                    $memberId = $this->id($parts[3]);
                    $this->db->exec('BEGIN IMMEDIATE');
                    try {
                        $this->requireAdmin($teamId, $user['id']);
                        $membership = $this->statement(
                            'SELECT role FROM memberships WHERE team_id = ? AND user_id = ?',
                            [$teamId, $memberId]
                        )->fetch();
                        if ($membership === false) {
                            apiError(404, 'not_found', 'The requested resource was not found.');
                        }
                        if ($membership['role'] === 'admin') {
                            $admins = (int)$this->statement(
                                "SELECT COUNT(*) FROM memberships WHERE team_id = ? AND role = 'admin'",
                                [$teamId]
                            )->fetchColumn();
                            if ($admins === 1) {
                                apiError(409, 'last_admin', 'A team must retain at least one admin.');
                            }
                        }
                        $this->statement(
                            'DELETE FROM memberships WHERE team_id = ? AND user_id = ?',
                            [$teamId, $memberId]
                        );
                        $this->db->commit();
                    } catch (Throwable $exception) {
                        $this->db->rollBack();
                        throw $exception;
                    }
                    return [204, null];
                }
            }

            if (count($parts) >= 3 && $parts[2] === 'notes') {
                if (count($parts) === 3 && $method === 'GET') {
                    return $this->listNotes($teamId, $user['id'], $query);
                }
                if (count($parts) === 3 && $method === 'POST') {
                    return $this->createNote($teamId, $user['id'], $body);
                }
                if (count($parts) === 4) {
                    $noteId = $this->id($parts[3]);
                    if ($method === 'GET') {
                        $this->requireMember($teamId, $user['id']);
                        $note = $this->statement(
                            'SELECT * FROM notes WHERE id = ? AND team_id = ?',
                            [$noteId, $teamId]
                        )->fetch();
                        if ($note === false) {
                            apiError(404, 'not_found', 'The requested resource was not found.');
                        }
                        return [200, ['note' => $this->noteJson($note, $this->tagsForNote($noteId))]];
                    }
                    if ($method === 'PATCH') {
                        return $this->updateNote($teamId, $noteId, $user['id'], $body, $ifMatch);
                    }
                    if ($method === 'DELETE') {
                        $this->requireMember($teamId, $user['id']);
                        $statement = $this->statement(
                            'DELETE FROM notes WHERE id = ? AND team_id = ?',
                            [$noteId, $teamId]
                        );
                        if ($statement->rowCount() === 0) {
                            apiError(404, 'not_found', 'The requested resource was not found.');
                        }
                        return [204, null];
                    }
                }
            }
        }

        apiError(404, 'not_found', 'The requested endpoint was not found.');
    }
}

function requestHeader(string $name): ?string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if (!is_array($headers)) {
        $headers = [];
    }
    foreach ($headers as $key => $value) {
        if (strcasecmp($key, $name) === 0) {
            return $value;
        }
    }
    if (strcasecmp($name, 'Authorization') === 0 && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }
    return null;
}

function emitResponse(int $status, mixed $payload): never
{
    http_response_code($status);
    if ($status === 204) {
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    try {
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    } catch (JsonException $exception) {
        error_log('Could not encode API response: ' . $exception->getMessage());
        http_response_code(500);
        echo '{"error":{"code":"internal_error","message":"An internal error occurred."}}';
    }
    exit;
}

if (PHP_SAPI === 'cli-server') {
    try {
        $lengthHeader = $_SERVER['CONTENT_LENGTH'] ?? '0';
        if (!ctype_digit((string)$lengthHeader)) {
            apiError(400, 'invalid_content_length', 'Content-Length must be an integer.');
        }
        $length = (int)$lengthHeader;
        if ($length > MAX_REQUEST_BYTES) {
            apiError(413, 'request_too_large', 'Request body exceeds 1 MiB.');
        }
        $body = null;
        if ($length > 0) {
            if (strtolower(trim(explode(';', requestHeader('Content-Type') ?? '')[0])) !== 'application/json') {
                apiError(415, 'unsupported_media_type', 'Content-Type must be application/json.');
            }
            try {
                $body = json_decode(file_get_contents('php://input'), flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                apiError(400, 'invalid_json', 'Request body must contain valid JSON.');
            }
        }
        $databasePath = getenv('NOTES_DB') ?: __DIR__ . DIRECTORY_SEPARATOR . 'notes.db';
        $api = new NotesApi($databasePath);
        [$status, $payload] = $api->handle(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $_SERVER['REQUEST_URI'] ?? '/',
            requestHeader('Authorization'),
            $body,
            requestHeader('If-Match')
        );
        emitResponse($status, $payload);
    } catch (ApiException $exception) {
        emitResponse($exception->status, [
            'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()],
        ]);
    } catch (Throwable $exception) {
        error_log(sprintf(
            'Unhandled error while processing %s %s: %s',
            $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            $_SERVER['REQUEST_URI'] ?? '/',
            $exception
        ));
        emitResponse(500, [
            'error' => ['code' => 'internal_error', 'message' => 'An internal error occurred.'],
        ]);
    }
}
