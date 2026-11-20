<?php
declare(strict_types=1);

use SocialRelay\ConnectionConfig;
use SocialRelay\ProviderCatalog;
use SocialRelay\Publisher;
use SocialRelay\Security;

$snapshot = [
    'text' => "سلام از وردپرس\nhttps://site.example/news/7",
    'title' => 'A new post',
    'url' => 'https://site.example/news/7',
    'featured_image' => 'https://site.example/uploads/story.jpg',
    'featured_image_mime' => 'image/jpeg',
    'featured_image_alt' => 'The city on a clear day',
];
$credentials = 'testing_oauth_token-2026';
$secret = Security::encrypt($credentials);
$key = str_repeat('b', 64);
$test = [
    'facebook' => ['321', '', 'https://graph.facebook.com/v27.0/321/feed', ['id' => '321_44'], '321_44'],
    'linkedin' => ['urn:li:organization:123', '', 'https://api.linkedin.com/rest/posts', [], 'urn:li:share:23'],
    'mastodon' => ['@test', 'https://mastodon.social', 'https://mastodon.social/api/v1/statuses', ['id' => '42'], '42'],
    'pinterest' => ['1234', '', 'https://api.pinterest.com/v5/pins', ['id' => '26'], '26'],
    'x' => ['@example', '', 'https://api.x.com/2/tweets', ['data' => ['id' => '81']], '81'],
    'tumblr' => ['relay.tumblr.com', '', 'https://api.tumblr.com/v2/blog/relay.tumblr.com/posts', ['response' => ['id' => '37']], '37'],
    'reddit' => ['integrationtest', '', 'https://oauth.reddit.com/api/submit', ['json' => ['errors' => [], 'data' => ['name' => 't3_m123']]], 't3_m123'],
];
foreach ($test as $provider => [$surface, $endpoint, $url, $body, $remoteId]) {
    $connection = compact('provider', 'surface', 'endpoint', 'secret');
    $message = $provider === 'reddit' ? ['text' => $snapshot['title']] + $snapshot : $snapshot;
    $GLOBALS['mock_response'] = ['status' => 201, 'body' => wp_json_encode($body), 'headers' => ['x-restli-id' => $remoteId]];
    $result = Publisher::send($connection, $message, $key);
    $request = $GLOBALS['last_request'];
    check($result['ok'] && $result['remote_id'] === $remoteId && $request['url'] === $url, "$provider publishes and records its remote ID");
    check($request['args']['headers']['Authorization'] === 'Bearer ' . $credentials
        && $request['args']['redirection'] === 0 && $request['args']['sslverify'] === true, "$provider restricts credential transport");
    $payload = json_decode($request['args']['body'], true);
    if ($payload === null) {
        parse_str($request['args']['body'], $payload);
    }
    $valid = match ($provider) {
        'facebook' => $payload === ['message' => $snapshot['text'], 'link' => $snapshot['url']],
        'linkedin' => $payload['author'] === $surface && $payload['commentary'] === $snapshot['text']
            && $payload['lifecycleState'] === 'PUBLISHED' && $request['args']['headers']['LinkedIn-Version'] === '202606',
        'mastodon' => $payload['status'] === $snapshot['text'] && $request['args']['headers']['Idempotency-Key'] === $key,
        'pinterest' => $payload['board_id'] === $surface && $payload['media_source'] === ['source_type' => 'image_url', 'url' => $snapshot['featured_image']]
            && $payload['alt_text'] === $snapshot['featured_image_alt'] && $payload['link'] === $snapshot['url'],
        'x' => $payload === ['text' => $snapshot['text']],
        'tumblr' => $payload['content'][1] === ['type' => 'link', 'url' => $snapshot['url']] && $payload['state'] === 'published',
        'reddit' => $payload['sr'] === $surface && $payload['kind'] === 'link' && $payload['url'] === $snapshot['url'],
    };
    check($valid, "$provider translates the snapshot to its API payload");
}

$GLOBALS['mock_response'] = ['status' => 200, 'body' => '{"json":{"errors":[["SUBREDDIT_NOEXIST","not found","sr"]]}}'];
check(Publisher::send($connection, $message, $key)['code'] === 'rejected', 'Reddit application-level errors are surfaced');
$ig = ['provider' => 'instagram', 'surface' => '1111', 'endpoint' => '', 'secret' => $secret];
$GLOBALS['requests'] = [];
$GLOBALS['mock_response'] = ['status' => 200, 'body' => '{"id":"721"}'];
$prepared = Publisher::send($ig, $snapshot, $key);
check(!empty($prepared['prepared']) && $prepared['state'] === '721' && count($GLOBALS['requests']) === 1, 'Instagram returns a container before any publish call');
parse_str($GLOBALS['last_request']['args']['body'], $params);
check($params['image_url'] === $snapshot['featured_image'] && $params['alt_text'] === $snapshot['featured_image_alt'], 'Instagram sends image and alternative text');
$GLOBALS['mock_response'] = ['status' => 200, 'body' => '{"status_code":"IN_PROGRESS"}'];
$igSnapshot = $snapshot + ['_delivery_state' => '721'];
check(Publisher::send($ig, $igSnapshot, $key)['retry_after'] === 60 && $GLOBALS['last_request']['method'] === 'GET', 'Instagram polls a preparing container without publishing');
$GLOBALS['mock_responses'] = [['status' => 200, 'body' => '{"status_code":"FINISHED"}'], ['status' => 200, 'body' => '{"id":"722"}']];
check(Publisher::send($ig, $igSnapshot, $key)['remote_id'] === '722'
    && str_ends_with($GLOBALS['last_request']['url'], '/1111/media_publish'), 'Instagram publishes the finished container');
$GLOBALS['mock_responses'] = [['status' => 200, 'body' => '{"status_code":"FINISHED"}'], new MockError()];
check(!empty(Publisher::send($ig, $igSnapshot, $key)['unknown']), 'A lost Instagram publish response requires review');

$threads = ['provider' => 'threads', 'surface' => '123', 'endpoint' => '', 'secret' => $secret];
$GLOBALS['mock_responses'] = [['status' => 200, 'body' => '{"id":"321"}'], ['status' => 200, 'body' => '{"id":"322"}']];
check(Publisher::send($threads, $snapshot, $key)['remote_id'] === '322'
    && str_ends_with($GLOBALS['last_request']['url'], '/123/threads_publish'), 'Threads creates and publishes a text container');
$bluesky = ['provider' => 'bluesky', 'surface' => 'relay.bsky.social', 'endpoint' => '', 'secret' => Security::encrypt('abcd-ef12-ghi3-4567')];
$GLOBALS['mock_responses'] = [
    ['status' => 200, 'body' => '{"did":"did:plc:example","accessJwt":"example.jwt.access"}'],
    ['status' => 200, 'body' => '{"uri":"at://did:plc:example/app.bsky.feed.post/7"}'],
];
$GLOBALS['requests'] = [];
$result = Publisher::send($bluesky, $snapshot, $key);
check($result['ok'] && str_ends_with($result['remote_id'], '/7') && count($GLOBALS['requests']) === 2, 'Bluesky obtains a session and creates a record');
$record = json_decode($GLOBALS['last_request']['args']['body'], true)['record'];
check($record['facets'][0]['index']['byteStart'] === strpos($snapshot['text'], $snapshot['url'])
    && $record['facets'][0]['features'][0]['uri'] === $snapshot['url'], 'Bluesky computes URL offsets in UTF-8 bytes');
$GLOBALS['mock_response'] = ['status' => 200, 'body' => '{"uri":"at://did:plc:example/app.bsky.feed.post/8"}'];
check(Publisher::send($bluesky, $snapshot, $key)['ok'] && count($GLOBALS['requests']) === 3
    && !str_contains((string) reset($GLOBALS['transients']), 'example.jwt.access'), 'Bluesky reuses an encrypted session');

$whatsapp = ['provider' => 'whatsapp', 'surface' => 'https://whatsapp.com/channel/0029VaRelayTest123', 'secret' => ''];
$requestCount = count($GLOBALS['requests']);
check(Publisher::compatible($whatsapp, $snapshot)['compatible'] && Publisher::capabilities('whatsapp')['manual']
    && Publisher::send($whatsapp, $snapshot, $key)['code'] === 'manual_required'
    && count($GLOBALS['requests']) === $requestCount, 'WhatsApp prepares a manual job without making an HTTP request');
check(!ConnectionConfig::valid_surface('whatsapp', 'https://whatsapp.com.example.net/channel/0029VaRelayTest123')
    && !ConnectionConfig::valid_destination('mastodon', '@test', 'https://127.0.0.1/')
    && !ConnectionConfig::valid_destination('bluesky', 'relay.bsky.social', 'https://example.com/arbitrary/path')
    && !ConnectionConfig::valid_secret('x', "token-value\r\ninjected"), 'Connection validation rejects invalid hosts, origin paths and credentials');
check(Publisher::compatible($ig, ['featured_image' => ''] + $snapshot)['reason'] === 'image_required'
    && Publisher::compatible($ig, ['featured_image_mime' => 'image/png'] + $snapshot)['reason'] === 'image_format', 'Instagram previews image requirements before outbound traffic');
$x = ['provider' => 'x', 'surface' => '@example', 'endpoint' => '', 'secret' => $secret];
check(Publisher::compatible($x, ['text' => str_repeat('a', 256) . ' https://example.com/' . str_repeat('a', 100)])['compatible']
    && !Publisher::compatible($x, ['text' => str_repeat('字', 141)])['compatible'], 'X counts shortened links and weighted Unicode');
$GLOBALS['mock_response'] = ['status' => 429, 'headers' => ['retry-after' => '180']];
check(Publisher::send($x, $snapshot, $key)['retry_after'] === 180, 'Explicit rate-limit delays survive provider classification');
$GLOBALS['mock_response'] = ['status' => 503];
check(!empty(Publisher::send($x, $snapshot, $key)['unknown']), 'An ambiguous social publish stops for review');
check(count(ProviderCatalog::all()) === 13, 'The catalog exposes 11 automatic social networks, WhatsApp and signed webhooks');
foreach (array_keys(ProviderCatalog::all()) as $provider) {
    check(!empty(Publisher::capabilities($provider)['supported']), "$provider has a registered adapter");
}
