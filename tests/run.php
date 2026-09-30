<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

function wp_salt(string $scheme): string { return str_repeat($scheme, 8); }
function __(string $text, string $domain = ''): string { return $text; }
function get_option(string $name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function home_url(string $path = ''): string { return 'https://site.example' . $path; }
function wp_get_environment_type(): string { return $GLOBALS['environment'] ?? 'production'; }
function wp_parse_url(string $url) { return parse_url($url); }
function wp_http_validate_url(string $url) {
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host || in_array($host, ['localhost', '127.0.0.1', '169.254.169.254'], true)) { return false; }
    return $url;
}
function wp_json_encode($value, int $options = 0): string { return json_encode($value, $options | JSON_THROW_ON_ERROR); }
function wp_safe_remote_post(string $url, array $args) {
    $GLOBALS['last_request'] = ['url' => $url, 'args' => $args];
    return $GLOBALS['mock_response'];
}
function is_wp_error($value): bool { return $value instanceof MockError; }
function wp_remote_retrieve_response_code(array $response): int { return $response['status']; }
function wp_remote_retrieve_body(array $response): string { return $response['body'] ?? ''; }
function wp_remote_retrieve_header(array $response, string $header): string { return $response['headers'][$header] ?? ''; }
class MockError {}

require_once __DIR__ . '/../src/Rules.php';
require_once __DIR__ . '/../src/Template.php';
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/ProviderInterface.php';
require_once __DIR__ . '/../src/HttpClient.php';
require_once __DIR__ . '/../src/Providers/Webhook.php';
require_once __DIR__ . '/../src/Providers/Telegram.php';
require_once __DIR__ . '/../src/Publisher.php';
require_once __DIR__ . '/../src/PublicationKey.php';

function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
    echo "PASS $message\n";
}

$rule = ['all' => [
    ['field' => 'post_type', 'operator' => 'eq', 'value' => 'product'],
    ['field' => 'taxonomy:product_cat', 'operator' => 'contains', 'value' => 'laptop'],
    ['field' => 'field:stock_status', 'operator' => 'eq', 'value' => 'instock'],
    ['field' => 'field:sale_price', 'operator' => 'exists', 'value' => ''],
    ['not' => ['field' => 'taxonomy:post_tag', 'operator' => 'contains', 'value' => 'internal']],
]];
$count = 0;
check(SocialRelay\Rules::validate($rule, 0, $count), 'nested sale rule validates');
$content = ['post_type' => 'product', 'taxonomies' => ['product_cat' => ['laptop'], 'post_tag' => []], 'fields' => ['stock_status' => 'instock', 'sale_price' => '1299']];
$trace = SocialRelay\Rules::evaluate($rule, $content);
check($trace['pass'] && count($trace['children']) === 5, 'eligible product has a five-condition execution trace');
$out = $content; $out['fields']['stock_status'] = 'outofstock';
check(!SocialRelay\Rules::evaluate($rule, $out)['pass'], 'out-of-stock product is excluded');
$out = $content; $out['fields']['sale_price'] = '';
check(!SocialRelay\Rules::evaluate($rule, $out)['pass'], 'non-sale product is excluded');
$out = $content; $out['taxonomies']['post_tag'] = ['internal'];
check(!SocialRelay\Rules::evaluate($rule, $out)['pass'], 'internal-tag product is excluded');
$bad = ['field' => 'meta:secret', 'operator' => 'regex', 'value' => '.*'];
$count = 0;
check(!SocialRelay\Rules::validate($bad, 0, $count), 'unknown field and regex are rejected');
check(!SocialRelay\Rules::evaluate(['field' => 'field:missing', 'operator' => 'neq', 'value' => 'x'], $content)['pass'], 'missing fields do not pass negative comparisons');
check(SocialRelay\Template::render('{title} {price} {secret}', ['title' => 'Laptop', 'fields' => ['price' => '1299']]) === 'Laptop 1299', 'template exposes only normalized values');
$key = SocialRelay\PublicationKey::first(1, 7, 3, 'Same message');
check($key === SocialRelay\PublicationKey::first(1, 7, 3, 'Same message'), 'duplicate first-publication events have a stable key');
check($key !== SocialRelay\PublicationKey::first(1, 7, 3, 'Different message'), 'distinct content to one destination remains publishable');
check(!SocialRelay\Security::validate_endpoint('http://example.com/hook'), 'HTTP endpoint rejected');
check(!SocialRelay\Security::validate_endpoint('https://127.0.0.1/hook'), 'loopback endpoint rejected');
check(!SocialRelay\Security::validate_endpoint('https://169.254.169.254/latest/meta-data'), 'metadata endpoint rejected');
check(!SocialRelay\Security::validate_endpoint('https://[::1]/hook'), 'IPv6 loopback endpoint rejected');
check(!SocialRelay\Security::validate_endpoint('https://service.internal/hook'), 'internal domain endpoint rejected');
check(!SocialRelay\Security::validate_endpoint('https://localhost./hook'), 'trailing-dot localhost endpoint rejected');
check(SocialRelay\Security::validate_endpoint('https://example.com/hook'), 'public HTTPS endpoint accepted');
$GLOBALS['options'] = ['social_relay_enabled' => '0', 'social_relay_origin_url' => 'https://site.example/'];
check(!SocialRelay\Security::can_send(), 'outbound publishing defaults paused');
$GLOBALS['options']['social_relay_enabled'] = '1';
$GLOBALS['options']['social_relay_origin_url'] = 'https://old.example/';
check(!SocialRelay\Security::can_send(), 'site URL change blocks sends');
$GLOBALS['options']['social_relay_origin_url'] = 'https://site.example/';
$GLOBALS['environment'] = 'staging';
check(!SocialRelay\Security::can_send(), 'staging blocks sends without opt-in');
$GLOBALS['options']['social_relay_allow_staging'] = '1';
check(SocialRelay\Security::can_send(), 'explicit staging opt-in allows sends');
$encrypted = SocialRelay\Security::encrypt('this-is-a-long-random-test-secret');
check($encrypted !== 'this-is-a-long-random-test-secret' && SocialRelay\Security::decrypt($encrypted) === 'this-is-a-long-random-test-secret', 'secret encryption round trip');
$snapshot = ['source_id' => 7, 'source_type' => 'product', 'url' => 'https://site.example/p/7', 'title' => 'Laptop', 'text' => 'Laptop: 1299', 'profile' => 'product', 'objective' => 'promotion'];
$connection = ['provider' => 'webhook', 'endpoint' => 'https://example.com/hook', 'secret' => $encrypted, 'surface' => 'feed'];
$GLOBALS['mock_response'] = ['status' => 503];
$result = SocialRelay\Publisher::send($connection, $snapshot, str_repeat('a', 64));
check(!$result['ok'] && $result['retry'] && $result['code'] === 'temporary', '503 is retryable');
$GLOBALS['mock_response'] = ['status' => 200, 'headers' => ['x-social-relay-remote-id' => 'remote-7']];
$result = SocialRelay\Publisher::send($connection, $snapshot, str_repeat('a', 64));
$request = $GLOBALS['last_request'];
check($result['ok'] && $result['remote_id'] === 'remote-7', 'webhook acceptance records remote ID');
check($request['args']['headers']['X-Social-Relay-Signature'] === 'sha256=' . hash_hmac('sha256', $request['args']['body'], 'this-is-a-long-random-test-secret'), 'webhook JSON is HMAC signed');
$GLOBALS['mock_response'] = ['status' => 429];
check(SocialRelay\Publisher::send($connection, $snapshot, 'key')['code'] === 'rate_limit', '429 is classified as rate limit');
$telegram = ['provider' => 'telegram', 'surface' => '@examplechannel', 'secret' => SocialRelay\Security::encrypt('123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZabcd')];
$GLOBALS['mock_response'] = ['status' => 200, 'body' => '{"ok":true,"result":{"message_id":42}}'];
check(SocialRelay\Publisher::send($telegram, $snapshot, 'key')['remote_id'] === '42', 'Telegram sendMessage response is parsed');
check(SocialRelay\Publisher::capabilities('telegram')['max_characters'] === 4096, 'Telegram capability is exposed to validation and UI');
$long = $snapshot; $long['text'] = str_repeat('a', 4097);
check(!SocialRelay\Publisher::compatible($telegram, $long)['compatible'], 'Telegram over-limit text is rejected at preview');
echo "All focused tests passed.\n";
