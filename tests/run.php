<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'index.php';

function expectResponse(
    NotesApi $api,
    string $method,
    string $path,
    ?string $token = null,
    mixed $body = null,
    int $expectedStatus = 200,
    ?string $ifMatch = null
): mixed {
    try {
        [$status, $payload] = $api->handle(
            $method,
            $path,
            $token === null ? null : 'Bearer ' . $token,
            $body,
            $ifMatch
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

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
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
    $ownerId = $owner['user']['id'];
    $memberId = $member['user']['id'];

    expectResponse($api, 'GET', '/me', expectedStatus: 401);
    $me = expectResponse($api, 'GET', '/me', $ownerToken);
    assertTrue($me['user']['email'] === 'owner@example.test', '/me did not return the owner.');

    expectResponse(
        $api,
        'POST',
        '/auth/register',
        body: (object)['email' => 'not-an-email', 'password' => 'a long test password'],
        expectedStatus: 400
    );
    expectResponse(
        $api,
        'POST',
        '/auth/register',
        body: (object)['email' => 'short@example.test', 'password' => 'short'],
        expectedStatus: 400
    );
    expectResponse(
        $api,
        'POST',
        '/auth/login',
        body: (object)['email' => 'owner@example.test', 'password' => 'wrong password'],
        expectedStatus: 401
    );
    expectResponse(
        $api,
        'POST',
        '/auth/login',
        body: (object)['email' => 'nobody@example.test', 'password' => 'a long test password'],
        expectedStatus: 401
    );
    expectResponse($api, 'GET', '/no/such/route', $ownerToken, expectedStatus: 404);

    expectResponse($api, 'POST', '/teams', $ownerToken, 'not-an-object', 400);
    expectResponse($api, 'POST', '/teams', $ownerToken, ['bad'], 400);
    expectResponse(
        $api,
        'POST',
        '/teams',
        $ownerToken,
        (object)['name' => str_repeat('x', 101)],
        expectedStatus: 400
    );
    expectResponse(
        $api,
        'POST',
        '/teams',
        $ownerToken,
        (object)['name' => '   '],
        expectedStatus: 400
    );

    $team = expectResponse(
        $api,
        'POST',
        '/teams',
        $ownerToken,
        (object)['name' => 'Design'],
        201
    )['team'];
    $teamId = $team['id'];

    $ownerTeam = null;
    foreach (expectResponse($api, 'GET', '/teams', $ownerToken)['items'] as $row) {
        if ($row['id'] === $teamId) {
            $ownerTeam = $row;
        }
    }
    assertTrue($ownerTeam !== null, 'Owner was not listed as a team member.');
    assertTrue($ownerTeam['role'] === 'admin', 'Team creator was not an admin.');

    expectResponse($api, 'GET', "/teams/$teamId", $outsider['token'], expectedStatus: 404);
    $teamRead = expectResponse($api, 'GET', "/teams/$teamId", $ownerToken);
    assertTrue($teamRead['team']['name'] === 'Design', 'Team lookup returned the wrong team.');

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
    expectResponse(
        $api,
        'POST',
        "/teams/$teamId/members",
        $ownerToken,
        (object)['email' => $owner['user']['email']],
        expectedStatus: 409
    );
    expectResponse(
        $api,
        'POST',
        "/teams/$teamId/members",
        $ownerToken,
        (object)['email' => 'nobody@example.test'],
        expectedStatus: 404
    );

    $members = expectResponse($api, 'GET', "/teams/$teamId/members", $ownerToken)['items'];
    $memberRoles = array_column($members, 'role', 'id');
    assertTrue(($memberRoles[$memberId] ?? null) === 'member', 'Added member did not appear as a member.');
    expectResponse($api, 'GET', "/teams/$teamId/members", $memberToken, expectedStatus: 403);

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
        'POST',
        '/auth/register',
        body: (object)['email' => 'OWNER@example.test', 'password' => 'a long test password'],
        expectedStatus: 409
    );

    $second = expectResponse(
        $api,
        'POST',
        "/teams/$teamId/notes",
        $ownerToken,
        (object)['title' => 'Second', 'content' => ''],
        201
    )['note'];
    assertTrue($second['content'] === '', 'Empty note content was not preserved.');

    $list = expectResponse($api, 'GET', "/teams/$teamId/notes", $ownerToken)['items'];
    assertTrue($list[0]['id'] === $second['id'], 'Notes were not ordered newest first.');

    expectResponse(
        $api,
        'POST',
        "/teams/$teamId/notes",
        $ownerToken,
        (object)['title' => '', 'content' => 'body'],
        expectedStatus: 400
    );
    expectResponse(
        $api,
        'POST',
        "/teams/$teamId/notes",
        $ownerToken,
        (object)['title' => str_repeat('x', 201), 'content' => 'body'],
        expectedStatus: 400
    );
    expectResponse(
        $api,
        'POST',
        "/teams/$teamId/notes",
        $ownerToken,
        (object)['title' => 'Too long', 'content' => str_repeat('x', 100001)],
        expectedStatus: 400
    );
    expectResponse(
        $api,
        'POST',
        "/teams/$teamId/notes",
        $ownerToken,
        (object)['title' => "bad\xFF", 'content' => 'body'],
        expectedStatus: 400
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
        'PATCH',
        "/teams/$teamId/notes/{$note['id']}",
        $ownerToken,
        (object)['unexpected' => 'field'],
        expectedStatus: 400
    );
    expectResponse(
        $api,
        'PATCH',
        "/teams/$teamId/notes/{$note['id']}",
        $ownerToken,
        (object)[],
        expectedStatus: 400
    );
    $patched = expectResponse(
        $api,
        'PATCH',
        "/teams/$teamId/notes/{$note['id']}",
        $ownerToken,
        (object)['title' => 'Checklist v2'],
        200
    )['note'];
    assertTrue($patched['title'] === 'Checklist v2', 'PATCH did not update the title.');
    expectResponse(
        $api,
        'PATCH',
        "/teams/$teamId/notes/999999",
        $ownerToken,
        (object)['title' => 'nope'],
        expectedStatus: 404
    );

    expectResponse(
        $api,
        'DELETE',
        "/teams/$teamId/members/{$ownerId}",
        $ownerToken,
        expectedStatus: 409
    );
    expectResponse(
        $api,
        'DELETE',
        "/teams/$teamId/members/999999",
        $ownerToken,
        expectedStatus: 404
    );

    expectResponse(
        $api,
        'DELETE',
        "/teams/$teamId/notes/{$second['id']}",
        $memberToken,
        expectedStatus: 204
    );
    expectResponse(
        $api,
        'DELETE',
        "/teams/$teamId/notes/{$second['id']}",
        $ownerToken,
        expectedStatus: 404
    );

    expectResponse($api, 'GET', '/teams/%31', $ownerToken);
    expectResponse($api, 'GET', '/teams/0', $ownerToken, expectedStatus: 404);
    expectResponse($api, 'GET', '/teams/abc', $ownerToken, expectedStatus: 404);

    expectResponse(
        $api,
        'DELETE',
        "/teams/$teamId/members/{$memberId}",
        $ownerToken,
        expectedStatus: 204
    );
    expectResponse($api, 'GET', "/teams/$teamId/notes", $memberToken, expectedStatus: 404);
    expectResponse($api, 'GET', "/teams/$teamId", $memberToken, expectedStatus: 404);
    expectResponse($api, 'GET', '/me', $memberToken);
    $memberTeams = expectResponse($api, 'GET', '/teams', $memberToken)['items'];
    foreach ($memberTeams as $row) {
        assertTrue($row['id'] !== $teamId, 'Removed member was still listed for the team.');
    }

    $featureTeam = expectResponse(
        $api,
        'POST',
        '/teams',
        $ownerToken,
        (object)['name' => 'Features'],
        201
    )['team'];
    $featureTeamId = $featureTeam['id'];

    $tagged = expectResponse(
        $api,
        'POST',
        "/teams/$featureTeamId/notes",
        $ownerToken,
        (object)[
            'title' => 'Alpha',
            'content' => 'the quick brown fox',
            'tags' => ['Work', 'work', 'Urgent'],
        ],
        201
    )['note'];
    assertTrue($tagged['tags'] === ['urgent', 'work'], 'Tags were not normalized, deduplicated, and sorted.');
    assertTrue($tagged['version'] === 1, 'New notes should start at version 1.');
    assertTrue(
        $tagged['archived'] === false && $tagged['pinned'] === false,
        'Note flags should default to false.'
    );

    expectResponse(
        $api,
        'POST',
        "/teams/$featureTeamId/notes",
        $ownerToken,
        (object)['title' => 'Beta', 'content' => 'lazy dog', 'tags' => ['home']],
        201
    );
    $beta = expectResponse(
        $api,
        'POST',
        "/teams/$featureTeamId/notes",
        $ownerToken,
        (object)['title' => 'Gamma', 'content' => 'fox and hound', 'tags' => ['work', 'home']],
        201
    )['note'];

    $featureAll = expectResponse($api, 'GET', "/teams/$featureTeamId/notes", $ownerToken);
    assertTrue(count($featureAll['items']) === 3, 'Expected the three feature notes to be listed.');
    assertTrue($featureAll['next_cursor'] === null, 'An unpaginated listing should not return a cursor.');

    $byTag = expectResponse($api, 'GET', "/teams/$featureTeamId/notes?tag=work", $ownerToken);
    assertTrue(count($byTag['items']) === 2, 'tag=work should match two notes.');
    $byTagUpper = expectResponse($api, 'GET', "/teams/$featureTeamId/notes?tag=HOME", $ownerToken);
    assertTrue(count($byTagUpper['items']) === 2, 'Tag filtering should be case-insensitive.');

    $search = expectResponse($api, 'GET', "/teams/$featureTeamId/notes?q=fox", $ownerToken);
    assertTrue(count($search['items']) === 2, 'Full-text search should match title and content.');

    $titleSorted = expectResponse($api, 'GET', "/teams/$featureTeamId/notes?sort=title", $ownerToken);
    assertTrue(
        array_column($titleSorted['items'], 'title') === ['Alpha', 'Beta', 'Gamma'],
        'sort=title should order titles ascending.'
    );

    $page1 = expectResponse($api, 'GET', "/teams/$featureTeamId/notes?limit=2", $ownerToken);
    assertTrue(
        count($page1['items']) === 2 && is_string($page1['next_cursor']),
        'The first page should carry a cursor.'
    );
    $page2 = expectResponse(
        $api,
        'GET',
        "/teams/$featureTeamId/notes?limit=2&cursor=" . rawurlencode($page1['next_cursor']),
        $ownerToken
    );
    assertTrue(
        count($page2['items']) === 1 && $page2['next_cursor'] === null,
        'The second page should finish the listing without another cursor.'
    );

    $updated = expectResponse(
        $api,
        'PATCH',
        "/teams/$featureTeamId/notes/{$tagged['id']}",
        $ownerToken,
        (object)['tags' => ['solo'], 'pinned' => true],
        200
    )['note'];
    assertTrue($updated['tags'] === ['solo'], 'PATCH should replace the whole tag set.');
    assertTrue($updated['pinned'] === true, 'PATCH should set pinned.');
    assertTrue($updated['version'] === 2, 'PATCH should increment the version.');

    $pinned = expectResponse($api, 'GET', "/teams/$featureTeamId/notes?pinned=1", $ownerToken);
    assertTrue(
        count($pinned['items']) === 1 && $pinned['items'][0]['id'] === $tagged['id'],
        'pinned=1 should return only pinned notes.'
    );

    expectResponse(
        $api,
        'PATCH',
        "/teams/$featureTeamId/notes/{$tagged['id']}",
        $ownerToken,
        (object)['archived' => true],
        200
    );
    $activeNotes = expectResponse($api, 'GET', "/teams/$featureTeamId/notes", $ownerToken);
    assertTrue(count($activeNotes['items']) === 2, 'Archived notes should be hidden from the default listing.');
    $archivedNotes = expectResponse($api, 'GET', "/teams/$featureTeamId/notes?archived=1", $ownerToken);
    assertTrue(count($archivedNotes['items']) === 1, 'archived=1 should list archived notes.');

    expectResponse(
        $api,
        'PATCH',
        "/teams/$featureTeamId/notes/{$beta['id']}",
        $ownerToken,
        (object)['title' => 'Beta v2'],
        200,
        (string)$beta['version']
    );
    expectResponse(
        $api,
        'PATCH',
        "/teams/$featureTeamId/notes/{$beta['id']}",
        $ownerToken,
        (object)['title' => 'stale write'],
        412,
        (string)$beta['version']
    );
    expectResponse(
        $api,
        'PATCH',
        "/teams/$featureTeamId/notes/{$beta['id']}",
        $ownerToken,
        (object)['title' => 'wildcard write'],
        200,
        '*'
    );

    expectResponse($api, 'GET', "/teams/$featureTeamId/notes?limit=0", $ownerToken, expectedStatus: 400);
    expectResponse($api, 'GET', "/teams/$featureTeamId/notes?sort=bogus", $ownerToken, expectedStatus: 400);
    expectResponse(
        $api,
        'GET',
        "/teams/$featureTeamId/notes?cursor=not-a-cursor",
        $ownerToken,
        expectedStatus: 400
    );
    expectResponse($api, 'GET', "/teams/$featureTeamId/notes?archived=maybe", $ownerToken, expectedStatus: 400);
    expectResponse(
        $api,
        'GET',
        "/teams/$featureTeamId/notes?tag=" . str_repeat('x', 51),
        $ownerToken,
        expectedStatus: 400
    );
    expectResponse(
        $api,
        'PATCH',
        "/teams/$featureTeamId/notes/{$beta['id']}",
        $ownerToken,
        (object)['archived' => 'yes'],
        expectedStatus: 400
    );
    expectResponse(
        $api,
        'POST',
        "/teams/$featureTeamId/notes",
        $ownerToken,
        (object)['title' => 'x', 'content' => '', 'tags' => 'nope'],
        expectedStatus: 400
    );
    expectResponse(
        $api,
        'POST',
        "/teams/$featureTeamId/notes",
        $ownerToken,
        (object)['title' => 'x', 'content' => '', 'tags' => array_fill(0, 21, 'tag')],
        expectedStatus: 400
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