<?php
namespace SocialRelay\Providers;

use SocialRelay\Text;

final class LinkedIn extends AuthenticatedProvider
{
    protected const ID = 'linkedin';
    protected const SURFACE = 'member_or_organization';
    protected const TEXT_LIMIT = 3000;
    protected const API = 'LinkedIn Posts API 202606';

    protected function publish(array $connection, array $snapshot, string $token, string $idempotencyKey): array
    {
        // Escape characters reserved by LinkedIn's commentary markup grammar.
        $text = $snapshot['text'];
        $commentary = '';
        $start = 0;
        $special = '\\|{}@[]()<>#*_~';
        foreach (Text::links($text) as [$link, $offset]) {
            $commentary .= addcslashes(substr($text, $start, $offset - $start), $special) . $link;
            $start = $offset + strlen($link);
        }
        $commentary .= addcslashes(substr($text, $start), $special);
        $response = self::post_json('https://api.linkedin.com/rest/posts', [
            'author' => $connection['surface'],
            'commentary' => $commentary,
            'visibility' => 'PUBLIC',
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ], $token, ['LinkedIn-Version' => '202606', 'X-Restli-Protocol-Version' => '2.0.0']);
        return self::result($response, wp_remote_retrieve_header($response, 'x-restli-id'));
    }
}
