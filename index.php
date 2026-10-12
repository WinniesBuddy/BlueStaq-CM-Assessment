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
                created_at TEXT NOT NULL DEFAULT
                    (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
                updated_at TEXT NOT NULL DEFAULT
                    (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
            );
            CREATE INDEX IF NOT EXISTS notes_team_updated
                ON notes(team_id, updated_at DESC, id DESC);
            CREATE INDEX IF NOT EXISTS sessions_expiry ON sessions(expires_at);
            SQL);
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
        if ($strip) {
            $value = trim($value);
        }
        $length = preg_match_all('/./us', $value);
        if ((!$allowEmpty && $length === 0) || $length > $maximum) {
            $empty = $allowEmpty ? 'empty or ' : '';
            apiError(400, 'invalid_field', "$field must be $empty"."at most $maximum characters.");
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

    private function noteJson(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'team_id' => (int)$row['team_id'],
            'author_id' => (int)$row['author_id'],
            'title' => $row['title'],
            'content' => $row['content'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
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

    public function handle(
        string $method,
        string $path,
        ?string $authorization,
        mixed $body = null
    ): array {
        $pathOnly = parse_url($path, PHP_URL_PATH);
        $parts = array_values(array_filter(
            explode('/', trim(is_string($pathOnly) ? $pathOnly : '', '/')),
            static fn(string $part): bool => $part !== ''
        ));
        $parts = array_map('rawurldecode', $parts);

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
                    $this->requireMember($teamId, $user['id']);
                    $rows = $this->statement(
                        'SELECT * FROM notes WHERE team_id = ? ORDER BY updated_at DESC, id DESC',
                        [$teamId]
                    )->fetchAll();
                    return [200, ['items' => array_map($this->noteJson(...), $rows)]];
                }
                if (count($parts) === 3 && $method === 'POST') {
                    $data = $this->requireObject($body);
                    $title = $this->text($data->title ?? null, 'title', 200);
                    $content = $this->text(
                        $data->content ?? null,
                        'content',
                        100000,
                        allowEmpty: true,
                        strip: false
                    );
                    $this->requireMember($teamId, $user['id']);
                    $this->statement(
                        'INSERT INTO notes(team_id, author_id, title, content) VALUES (?, ?, ?, ?)',
                        [$teamId, $user['id'], $title, $content]
                    );
                    $note = $this->statement(
                        'SELECT * FROM notes WHERE id = ?',
                        [(int)$this->db->lastInsertId()]
                    )->fetch();
                    return [201, ['note' => $this->noteJson($note)]];
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
                        return [200, ['note' => $this->noteJson($note)]];
                    }
                    if ($method === 'PATCH') {
                        $data = $this->requireObject($body);
                        $fields = get_object_vars($data);
                        if ($fields === [] || array_diff(array_keys($fields), ['title', 'content']) !== []) {
                            apiError(400, 'invalid_fields', 'Provide title and/or content only.');
                        }
                        $updates = [];
                        if (array_key_exists('title', $fields)) {
                            $updates['title'] = $this->text($fields['title'], 'title', 200);
                        }
                        if (array_key_exists('content', $fields)) {
                            $updates['content'] = $this->text(
                                $fields['content'],
                                'content',
                                100000,
                                allowEmpty: true,
                                strip: false
                            );
                        }
                        $this->requireMember($teamId, $user['id']);
                        $exists = $this->statement(
                            'SELECT id FROM notes WHERE id = ? AND team_id = ?',
                            [$noteId, $teamId]
                        )->fetch();
                        if ($exists === false) {
                            apiError(404, 'not_found', 'The requested resource was not found.');
                        }
                        $assignments = implode(', ', array_map(
                            static fn(string $field): string => "$field = ?",
                            array_keys($updates)
                        ));
                        $this->statement(
                            "UPDATE notes SET $assignments, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ? AND team_id = ?",
                            [...array_values($updates), $noteId, $teamId]
                        );
                        $note = $this->statement(
                            'SELECT * FROM notes WHERE id = ?',
                            [$noteId]
                        )->fetch();
                        return [200, ['note' => $this->noteJson($note)]];
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
            $body
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
