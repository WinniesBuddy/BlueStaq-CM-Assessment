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

function expectApiError(callable $callback, int $expectedStatus): void
{
    try {
        $callback();
    } catch (ApiException $exception) {
        if ($exception->status === $expectedStatus) {
            return;
        }
        throw new RuntimeException("Expected API error $expectedStatus, received {$exception->status}.");
    }
    throw new RuntimeException("Expected API error $expectedStatus, but none was thrown.");
}

function expectAuthStatus(
    NotesApi $api,
    string $method,
    string $path,
    ?string $authorization,
    int $expectedStatus
): void {
    try {
        [$status] = $api->handle($method, $path, $authorization);
    } catch (ApiException $exception) {
        $status = $exception->status;
    }
    if ($status !== $expectedStatus) {
        throw new RuntimeException(
            "$method $path with Authorization " . var_export($authorization, true)
            . " returned $status; expected $expectedStatus."
        );
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

    $betaVersionOne = expectResponse(
        $api,
        'PATCH',
        "/teams/$featureTeamId/notes/{$beta['id']}",
        $ownerToken,
        (object)['title' => 'Beta v2'],
        200,
        (string)$beta['version']
    )['note'];
    assertTrue($betaVersionOne['version'] === 2, 'A successful If-Match write should bump the version.');

    expectResponse(
        $api,
        'PATCH',
        "/teams/$featureTeamId/notes/{$beta['id']}",
        $ownerToken,
        (object)['title' => 'stale write'],
        412,
        (string)$beta['version']
    );
    $betaAfterStale = expectResponse(
        $api,
        'GET',
        "/teams/$featureTeamId/notes/{$beta['id']}",
        $ownerToken
    )['note'];
    assertTrue(
        $betaAfterStale['title'] === 'Beta v2' && $betaAfterStale['version'] === 2,
        'A rejected stale write must not mutate the note.'
    );

    $betaVersionThree = expectResponse(
        $api,
        'PATCH',
        "/teams/$featureTeamId/notes/{$beta['id']}",
        $ownerToken,
        (object)['title' => 'wildcard write'],
        200,
        '*'
    )['note'];
    assertTrue($betaVersionThree['version'] === 3, 'A wildcard If-Match should always succeed.');

    $betaVersionFour = expectResponse(
        $api,
        'PATCH',
        "/teams/$featureTeamId/notes/{$beta['id']}",
        $ownerToken,
        (object)['title' => 'quoted write'],
        200,
        '"' . $betaVersionThree['version'] . '"'
    )['note'];
    assertTrue($betaVersionFour['version'] === 4, 'A quoted ETag If-Match value should be accepted.');

    expectResponse(
        $api,
        'PATCH',
        "/teams/$featureTeamId/notes/{$beta['id']}",
        $ownerToken,
        (object)['title' => 'bad precondition'],
        expectedStatus: 400,
        ifMatch: 'not-a-version'
    );
    expectResponse(
        $api,
        'PATCH',
        "/teams/$featureTeamId/notes/999999",
        $ownerToken,
        (object)['title' => 'missing'],
        expectedStatus: 404,
        ifMatch: '1'
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

    $edge = registerUser($api, 'edge@example.test');
    $edgeToken = $edge['token'];
    $edgeTeam = expectResponse(
        $api,
        'POST',
        '/teams',
        $edgeToken,
        (object)['name' => 'Edges'],
        201
    )['team'];
    $edgeTeamId = $edgeTeam['id'];

    $zebra = expectResponse(
        $api,
        'POST',
        "/teams/$edgeTeamId/notes",
        $edgeToken,
        (object)['title' => 'zebra crossing', 'content' => 'the fox', 'tags' => ['a', 'b']],
        201
    )['note'];
    $apple = expectResponse(
        $api,
        'POST',
        "/teams/$edgeTeamId/notes",
        $edgeToken,
        (object)['title' => 'apple', 'content' => 'nothing'],
        201
    )['note'];
    $mango = expectResponse(
        $api,
        'POST',
        "/teams/$edgeTeamId/notes",
        $edgeToken,
        (object)['title' => 'mango', 'content' => 'another'],
        201
    )['note'];

    $edgeAll = expectResponse($api, 'GET', "/teams/$edgeTeamId/notes", $edgeToken);
    assertTrue(count($edgeAll['items']) === 3, 'The edge team should list three notes.');

    $titleMatch = expectResponse($api, 'GET', "/teams/$edgeTeamId/notes?q=zebra", $edgeToken);
    assertTrue(count($titleMatch['items']) === 1, 'Search should match note titles.');
    $caseMatch = expectResponse($api, 'GET', "/teams/$edgeTeamId/notes?q=ZEBRA", $edgeToken);
    assertTrue(count($caseMatch['items']) === 1, 'Search should be case-insensitive.');
    $noMatch = expectResponse($api, 'GET', "/teams/$edgeTeamId/notes?q=zzznope", $edgeToken);
    assertTrue(
        count($noMatch['items']) === 0 && $noMatch['next_cursor'] === null,
        'A search with no hits should return an empty page.'
    );
    $quoteSearch = expectResponse(
        $api,
        'GET',
        "/teams/$edgeTeamId/notes?q=" . rawurlencode('zeb"ra'),
        $edgeToken
    );
    assertTrue($quoteSearch['items'] === [], 'Search should tolerate FTS special characters.');

    expectResponse(
        $api,
        'PATCH',
        "/teams/$edgeTeamId/notes/{$zebra['id']}",
        $edgeToken,
        (object)['archived' => true],
        200
    );
    $archivedSearch = expectResponse($api, 'GET', "/teams/$edgeTeamId/notes?q=zebra", $edgeToken);
    assertTrue(count($archivedSearch['items']) === 0, 'Archived notes should be excluded from the default search.');
    $archivedSearchIncluded = expectResponse(
        $api,
        'GET',
        "/teams/$edgeTeamId/notes?q=zebra&archived=1",
        $edgeToken
    );
    assertTrue(count($archivedSearchIncluded['items']) === 1, 'archived=1 should include archived notes in search.');
    expectResponse(
        $api,
        'PATCH',
        "/teams/$edgeTeamId/notes/{$zebra['id']}",
        $edgeToken,
        (object)['archived' => false],
        200
    );
    $unarchivedSearch = expectResponse($api, 'GET', "/teams/$edgeTeamId/notes?q=zebra", $edgeToken);
    assertTrue(count($unarchivedSearch['items']) === 1, 'Unarchiving should restore default search results.');

    $emptyTags = expectResponse(
        $api,
        'POST',
        "/teams/$edgeTeamId/notes",
        $edgeToken,
        (object)['title' => 'no tags', 'content' => '', 'tags' => []],
        201
    )['note'];
    assertTrue($emptyTags['tags'] === [], 'An empty tag array should be accepted.');

    $cleared = expectResponse(
        $api,
        'PATCH',
        "/teams/$edgeTeamId/notes/{$zebra['id']}",
        $edgeToken,
        (object)['tags' => []],
        200
    )['note'];
    assertTrue($cleared['tags'] === [], 'PATCH with an empty tag array should clear tags.');

    expectResponse($api, 'PATCH', "/teams/$edgeTeamId/notes/{$zebra['id']}", $edgeToken, (object)['tags' => ['x']], 200);
    $keptTags = expectResponse(
        $api,
        'PATCH',
        "/teams/$edgeTeamId/notes/{$zebra['id']}",
        $edgeToken,
        (object)['title' => 'zebra II'],
        200
    )['note'];
    assertTrue($keptTags['tags'] === ['x'], 'PATCH without tags should leave the tag set unchanged.');

    expectResponse(
        $api,
        'POST',
        "/teams/$edgeTeamId/notes",
        $edgeToken,
        (object)['title' => 'x', 'content' => '', 'tags' => [5]],
        expectedStatus: 400
    );
    expectResponse(
        $api,
        'POST',
        "/teams/$edgeTeamId/notes",
        $edgeToken,
        (object)['title' => 'x', 'content' => '', 'tags' => ['']],
        expectedStatus: 400
    );
    expectResponse(
        $api,
        'POST',
        "/teams/$edgeTeamId/notes",
        $edgeToken,
        (object)['title' => 'x', 'content' => '', 'tags' => [str_repeat('t', 51)]],
        expectedStatus: 400
    );
    expectResponse(
        $api,
        'POST',
        "/teams/$edgeTeamId/notes",
        $edgeToken,
        (object)['title' => 'x', 'content' => '', 'tags' => ["bad\xFF"]],
        expectedStatus: 400
    );
    $noTag = expectResponse($api, 'GET', "/teams/$edgeTeamId/notes?tag=nomatch", $edgeToken);
    assertTrue(
        count($noTag['items']) === 0 && $noTag['next_cursor'] === null,
        'An unmatched tag filter should return an empty page.'
    );

    $pinnedNone = expectResponse($api, 'GET', "/teams/$edgeTeamId/notes?pinned=0", $edgeToken);
    assertTrue(count($pinnedNone['items']) === 4, 'pinned=0 should list all unpinned notes.');
    expectResponse(
        $api,
        'PATCH',
        "/teams/$edgeTeamId/notes/{$apple['id']}",
        $edgeToken,
        (object)['pinned' => true],
        200
    );
    $pinnedNoneAfter = expectResponse($api, 'GET', "/teams/$edgeTeamId/notes?pinned=0", $edgeToken);
    assertTrue(count($pinnedNoneAfter['items']) === 3, 'pinned=0 should exclude pinned notes.');
    $unpinned = expectResponse(
        $api,
        'PATCH',
        "/teams/$edgeTeamId/notes/{$apple['id']}",
        $edgeToken,
        (object)['pinned' => false],
        200
    )['note'];
    assertTrue($unpinned['pinned'] === false, 'PATCH should be able to unpin a note.');

    $createdSort = expectResponse($api, 'GET', "/teams/$edgeTeamId/notes?sort=created", $edgeToken);
    assertTrue(
        array_column($createdSort['items'], 'id') === [$emptyTags['id'], $mango['id'], $apple['id'], $zebra['id']],
        'sort=created should order notes newest first.'
    );

    $titlePage1 = expectResponse($api, 'GET', "/teams/$edgeTeamId/notes?sort=title&limit=2", $edgeToken);
    assertTrue(is_string($titlePage1['next_cursor']), 'A limited title-sorted page should carry a cursor.');
    $titlePage2 = expectResponse(
        $api,
        'GET',
        "/teams/$edgeTeamId/notes?sort=title&limit=2&cursor=" . rawurlencode($titlePage1['next_cursor']),
        $edgeToken
    );
    $allTitles = array_merge(
        array_column($titlePage1['items'], 'title'),
        array_column($titlePage2['items'], 'title')
    );
    $sortedTitles = $allTitles;
    sort($sortedTitles);
    assertTrue($allTitles === $sortedTitles, 'sort=title pagination should stay ascending across pages.');
    $pageIds = array_merge(
        array_column($titlePage1['items'], 'id'),
        array_column($titlePage2['items'], 'id')
    );
    assertTrue(count($pageIds) === count(array_unique($pageIds)), 'Paginated pages should not overlap.');
    expectResponse(
        $api,
        'GET',
        "/teams/$edgeTeamId/notes?sort=updated&cursor=" . rawurlencode($titlePage1['next_cursor']),
        $edgeToken,
        expectedStatus: 400
    );

    expectResponse($api, 'GET', "/teams/$edgeTeamId/notes?limit=1", $edgeToken);
    expectResponse($api, 'GET', "/teams/$edgeTeamId/notes?limit=100", $edgeToken);
    expectResponse($api, 'GET', "/teams/$edgeTeamId/notes?limit=101", $edgeToken, expectedStatus: 400);
    expectResponse($api, 'GET', "/teams/$edgeTeamId/notes?limit=abc", $edgeToken, expectedStatus: 400);

    expectResponse(
        $api,
        'PATCH',
        "/teams/$edgeTeamId/notes/{$apple['id']}",
        $edgeToken,
        (object)['tags' => ['multi']],
        200
    );
    expectResponse(
        $api,
        'PATCH',
        "/teams/$edgeTeamId/notes/{$mango['id']}",
        $edgeToken,
        (object)['tags' => ['multi']],
        200
    );
    $tagPage1 = expectResponse($api, 'GET', "/teams/$edgeTeamId/notes?tag=multi&limit=1", $edgeToken);
    assertTrue(
        count($tagPage1['items']) === 1 && is_string($tagPage1['next_cursor']),
        'A filtered first page should carry a cursor.'
    );
    $tagPage2 = expectResponse(
        $api,
        'GET',
        "/teams/$edgeTeamId/notes?tag=multi&limit=1&cursor=" . rawurlencode($tagPage1['next_cursor']),
        $edgeToken
    );
    assertTrue(
        count($tagPage2['items']) === 1 && $tagPage2['next_cursor'] === null,
        'A filtered second page should finish the listing.'
    );

    expectResponse($api, 'POST', '/teams', $edgeToken, (object)['name' => str_repeat('n', 100)], 201);
    expectResponse(
        $api,
        'POST',
        "/teams/$edgeTeamId/notes",
        $edgeToken,
        (object)['title' => str_repeat('t', 200), 'content' => ''],
        201
    );
    expectResponse(
        $api,
        'POST',
        "/teams/$edgeTeamId/notes",
        $edgeToken,
        (object)['title' => 'big', 'content' => str_repeat('c', 100000)],
        201
    );
    expectResponse(
        $api,
        'POST',
        '/auth/register',
        body: (object)['email' => 'pw8@example.test', 'password' => str_repeat('p', 8)],
        expectedStatus: 201
    );
    expectResponse(
        $api,
        'POST',
        '/auth/register',
        body: (object)['email' => 'pw256@example.test', 'password' => str_repeat('p', 256)],
        expectedStatus: 201
    );
    expectResponse(
        $api,
        'POST',
        '/auth/register',
        body: (object)['email' => 'pw257@example.test', 'password' => str_repeat('p', 257)],
        expectedStatus: 400
    );
    expectResponse($api, 'POST', '/auth/register', body: 'not-an-object', expectedStatus: 400);
    expectResponse(
        $api,
        'POST',
        '/auth/register',
        body: (object)['email' => 'nopw@example.test'],
        expectedStatus: 400
    );

    expectAuthStatus($api, 'GET', '/me', null, 401);
    expectAuthStatus($api, 'GET', '/me', 'Bearer ', 401);
    expectAuthStatus($api, 'GET', '/me', 'Bearer', 401);
    expectAuthStatus($api, 'GET', '/me', 'Token abc', 401);

    $edgeMember = registerUser($api, 'edge-member@example.test');
    expectResponse(
        $api,
        'POST',
        "/teams/$edgeTeamId/members",
        $edgeToken,
        (object)['email' => $edgeMember['user']['email']],
        201
    );
    expectResponse(
        $api,
        'POST',
        "/teams/$edgeTeamId/members",
        $edgeMember['token'],
        (object)['email' => $edge['user']['email']],
        expectedStatus: 403
    );
    expectResponse(
        $api,
        'DELETE',
        "/teams/$edgeTeamId/members/{$edge['user']['id']}",
        $edgeMember['token'],
        expectedStatus: 403
    );
    expectResponse(
        $api,
        'POST',
        "/teams/$edgeTeamId/members",
        $outsider['token'],
        (object)['email' => $edge['user']['email']],
        expectedStatus: 404
    );
    expectResponse(
        $api,
        'DELETE',
        "/teams/$edgeTeamId/members/{$edge['user']['id']}",
        $outsider['token'],
        expectedStatus: 404
    );
    expectResponse($api, 'GET', "/teams/$edgeTeamId/notes/{$apple['id']}", $outsider['token'], expectedStatus: 404);
    expectResponse($api, 'DELETE', "/teams/$edgeTeamId/notes/{$apple['id']}", $outsider['token'], expectedStatus: 404);
    expectResponse($api, 'GET', "/teams/$edgeTeamId/members", $outsider['token'], expectedStatus: 404);
    expectResponse($api, 'PUT', '/teams', $edgeToken, expectedStatus: 404);
    expectResponse($api, 'DELETE', "/teams/$edgeTeamId/notes/{$apple['id']}", expectedStatus: 401);

    $transportReads = 0;
    $transportBody = function () use (&$transportReads): string {
        $transportReads++;
        return '{"ok":true}';
    };
    $decodedObject = decodeRequestBody('application/json', '11', $transportBody);
    assertTrue(
        $decodedObject instanceof stdClass && $decodedObject->ok === true,
        'A JSON object body should decode.'
    );
    assertTrue($transportReads === 1, 'The request body should be read exactly once.');
    assertTrue(decodeRequestBody(null, null, $transportBody) === null, 'A missing body should decode to null.');
    assertTrue(
        decodeRequestBody('application/json', '0', $transportBody) === null,
        'A zero-length body should decode to null.'
    );
    assertTrue($transportReads === 1, 'The body should not be read for an empty request.');
    assertTrue(
        decodeRequestBody('application/json; charset=utf-8', '11', $transportBody) instanceof stdClass,
        'A Content-Type with parameters should be accepted.'
    );
    expectApiError(fn() => decodeRequestBody('text/plain', '11', $transportBody), 415);
    expectApiError(fn() => decodeRequestBody(null, '11', $transportBody), 415);
    expectApiError(fn() => decodeRequestBody('application/json', 'abc', $transportBody), 400);
    expectApiError(
        fn() => decodeRequestBody('application/json', (string)(MAX_REQUEST_BYTES + 1), $transportBody),
        413
    );
    expectApiError(fn() => decodeRequestBody('application/json', '11', fn(): string => '{'), 400);
    $oversizedReads = 0;
    expectApiError(
        function () use (&$oversizedReads): void {
            decodeRequestBody(
                'application/json',
                (string)(MAX_REQUEST_BYTES + 1),
                function () use (&$oversizedReads): string {
                    $oversizedReads++;
                    return '';
                }
            );
        },
        413
    );
    assertTrue($oversizedReads === 0, 'An oversized request should be rejected before reading the body.');

    $legacyPath = tempnam(sys_get_temp_dir(), 'notes-legacy-');
    if ($legacyPath === false) {
        throw new RuntimeException('Could not create a legacy database.');
    }
    try {
        $legacy = new PDO('sqlite:' . $legacyPath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $legacy->exec(<<<'SQL'
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                email TEXT NOT NULL UNIQUE COLLATE NOCASE,
                password_hash TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
            );
            CREATE TABLE sessions (
                token_hash TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL,
                expires_at INTEGER NOT NULL
            );
            CREATE TABLE teams (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                owner_id INTEGER NOT NULL,
                created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
            );
            CREATE TABLE memberships (
                team_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                role TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
                PRIMARY KEY (team_id, user_id)
            );
            CREATE TABLE notes (
                id INTEGER PRIMARY KEY,
                team_id INTEGER NOT NULL,
                author_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                content TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
                updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
            );
            INSERT INTO users(id, email, password_hash) VALUES (1, 'legacy@example.test', 'x');
            INSERT INTO teams(id, name, owner_id) VALUES (1, 'Legacy', 1);
            INSERT INTO memberships(team_id, user_id, role) VALUES (1, 1, 'admin');
            INSERT INTO notes(id, team_id, author_id, title, content)
                VALUES (1, 1, 1, 'Legacy note', 'hidden backfill token');
            SQL);
        $legacy->prepare('INSERT INTO sessions(token_hash, user_id, expires_at) VALUES (?, ?, ?)')->execute(
            [hash('sha256', 'legacytoken'), 1, time() + 3600]
        );
        unset($legacy);

        $migrated = new NotesApi($legacyPath);
        try {
            $legacyNote = expectResponse($migrated, 'GET', '/teams/1/notes/1', 'legacytoken')['note'];
            assertTrue($legacyNote['version'] === 1, 'Migration should add a default version of 1.');
            assertTrue(
                $legacyNote['archived'] === false && $legacyNote['pinned'] === false,
                'Migration should default the new flags to false.'
            );
            $backfilled = expectResponse($migrated, 'GET', '/teams/1/notes?q=backfill', 'legacytoken');
            assertTrue(count($backfilled['items']) === 1, 'Migration should backfill the search index.');
            $retagged = expectResponse(
                $migrated,
                'PATCH',
                '/teams/1/notes/1',
                'legacytoken',
                (object)['tags' => ['migrated']],
                200
            )['note'];
            assertTrue($retagged['tags'] === ['migrated'], 'Migration should enable tags on existing notes.');
        } finally {
            unset($migrated);
        }
    } finally {
        foreach ([$legacyPath, $legacyPath . '-wal', $legacyPath . '-shm'] as $sidecar) {
            if (is_file($sidecar)) {
                unlink($sidecar);
            }
        }
    }

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