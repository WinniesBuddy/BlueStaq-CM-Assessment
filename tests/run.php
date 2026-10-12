<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'index.php';

function expectResponse(
    NotesApi $api,
    string $method,
    string $path,
    ?string $token = null,
    mixed $body = null,
    int $expectedStatus = 200
): mixed {
    try {
        [$status, $payload] = $api->handle(
            $method,
            $path,
            $token === null ? null : 'Bearer ' . $token,
            $body
        );
    } catch (ApiException $exception) {
        $status = $exception->status;
        $payload = ['error' => ['code' => $exception->errorCode]];
    }
    if ($status !== $expectedStatus) {
        throw new RuntimeException(
            "$method $path returned $status; expected $expectedStatus: "
            . json_encode($payload, JSON_THROW_ON_ERROR)
        );
    }
    return $payload;
}

function registerUser(NotesApi $api, string $email): array
{
    return expectResponse(
        $api,
        'POST',
        '/auth/register',
        body: (object)['email' => $email, 'password' => 'a long test password'],
        expectedStatus: 201
    );
}

$databasePath = tempnam(sys_get_temp_dir(), 'notes-api-');
if ($databasePath === false) {
    throw new RuntimeException('Could not create temporary SQLite database.');
}

try {
    $api = new NotesApi($databasePath);
    $owner = registerUser($api, 'owner@example.test');
    $member = registerUser($api, 'member@example.test');
    $outsider = registerUser($api, 'outsider@example.test');
    $ownerToken = $owner['token'];
    $memberToken = $member['token'];

    $team = expectResponse(
        $api,
        'POST',
        '/teams',
        $ownerToken,
        (object)['name' => 'Design'],
        201
    )['team'];
    $teamId = $team['id'];
    $note = expectResponse(
        $api,
        'POST',
        "/teams/$teamId/notes",
        $ownerToken,
        (object)['title' => 'Checklist', 'content' => "Keep this newline\n"],
        201
    )['note'];
    expectResponse($api, 'GET', "/teams/$teamId/notes/{$note['id']}", $memberToken, expectedStatus: 404);
    expectResponse(
        $api,
        'POST',
        "/teams/$teamId/members",
        $ownerToken,
        (object)['email' => $member['user']['email']],
        201
    );
    $shared = expectResponse(
        $api,
        'GET',
        "/teams/$teamId/notes/{$note['id']}",
        $memberToken
    );
    if ($shared['note']['content'] !== "Keep this newline\n") {
        throw new RuntimeException('Note content whitespace was not preserved.');
    }
    expectResponse(
        $api,
        'GET',
        "/teams/$teamId/notes",
        $outsider['token'],
        expectedStatus: 404
    );
    expectResponse(
        $api,
        'GET',
        "/teams/$teamId/members",
        $memberToken,
        expectedStatus: 403
    );
    expectResponse(
        $api,
        'DELETE',
        "/teams/$teamId/members/{$owner['user']['id']}",
        $ownerToken,
        expectedStatus: 409
    );
    expectResponse(
        $api,
        'PATCH',
        "/teams/$teamId/notes/{$note['id']}",
        $memberToken,
        (object)['content' => ''],
        200
    );
    expectResponse(
        $api,
        'POST',
        '/auth/register',
        body: (object)['email' => 'OWNER@example.test', 'password' => 'a long test password'],
        expectedStatus: 409
    );
    $login = expectResponse(
        $api,
        'POST',
        '/auth/login',
        body: (object)['email' => 'owner@example.test', 'password' => 'a long test password'],
        expectedStatus: 200
    );
    expectResponse($api, 'GET', '/me', $login['token']);
    expectResponse($api, 'POST', '/auth/logout', $ownerToken, expectedStatus: 204);
    expectResponse($api, 'GET', '/me', $ownerToken, expectedStatus: 401);

    echo "All PHP API tests passed.\n";
} finally {
    unset($api);
    if (is_file($databasePath)) {
        unlink($databasePath);
    }
    foreach ([$databasePath . '-wal', $databasePath . '-shm'] as $sidecar) {
        if (is_file($sidecar)) {
            unlink($sidecar);
        }
    }
}
