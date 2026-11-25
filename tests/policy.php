<?php

use SalmutterNet\Plugin\System\Canonicalmanager\Service\ArticlePolicy;

require dirname(__DIR__) . '/src/Service/ArticlePolicy.php';

$policy = new ArticlePolicy();
$count = 0;
$check = static function (bool $condition, string $message) use (&$count): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $count++;
};
$check($policy->permitsDisplay(null, null, null), 'Missing request parameters must permit normal display.');
$check($policy->permitsDisplay('', '', 'heilmeier:article'), 'Custom article layouts must be supported.');
$check(!$policy->permitsDisplay('article.edit', null, null), 'Tasks must be excluded.');
$check(!$policy->permitsDisplay(null, 'component', null), 'Alternate template output must be excluded.');
$check(!$policy->permitsDisplay(null, null, 'edit'), 'Edit layout must be excluded.');
$check(!$policy->permitsDisplay(null, null, 'modal'), 'Modal layout must be excluded.');
$candidates = [
    ['id' => 20, 'distance' => 1, 'languageRank' => 0, 'reason' => 'category'],
    ['id' => 15, 'distance' => 0, 'languageRank' => 1, 'reason' => 'article'],
    ['id' => 10, 'distance' => 0, 'languageRank' => 0, 'reason' => 'article'],
    ['id' => 5, 'distance' => 0, 'languageRank' => 0, 'reason' => 'article'],
];
$check($policy->choose($candidates)['id'] === 5, 'Stable tie-breaking failed.');
$check($policy->choose(array_reverse($candidates))['id'] === 5, 'Selection depends on input ordering.');
$check($policy->choose($candidates, 20)['reason'] === 'override', 'Override precedence failed.');
$check($policy->choose($candidates, 99) === null, 'Invalid override must fail closed.');
$check($policy->choose([]) === null, 'Empty candidate fallback failed.');
$parameters = $policy->parameters(['view' => 'article', 'id' => '25:alias', 'catid' => '12', 'Itemid' => '7',
    'utm_source' => 'newsletter', 'gclid' => 'tracking', 'start' => '2', 'custom' => ['value' => 'yes']]);
$check(!isset($parameters['canonical']['id']), 'Routing parameter leaked.');
$check(!isset($parameters['canonical']['utm_source'], $parameters['canonical']['gclid']), 'Tracking leaked into canonical.');
$check($parameters['redirect']['utm_source'] === 'newsletter', 'Redirect loses campaign attribution.');
$check($parameters['canonical']['start'] === '2', 'Pagination lost.');
$check(!isset($parameters['canonical']['custom']), 'Unknown parameters must not appear in canonicals by default.');
$check($parameters['redirect']['custom'] === ['value' => 'yes'], 'Unknown redirect state lost.');
$check($policy->parameters(['bar' => 'foo'])['canonical'] === [], 'Arbitrary parameters must default to a clean canonical.');
$pagination = $policy->parameters(['start' => '2', 'limitstart' => '3', 'showall' => '1']);
$check($pagination['canonical'] === ['start' => '2', 'limitstart' => '3', 'showall' => '1'], 'Article pagination must be included by default.');
$allowed = $policy->parameters(['variant' => 'a', 'edition' => 'b', 'region' => 'c', 'bar' => 'foo'], "variant, edition\nregion");
$check($allowed['canonical'] === ['variant' => 'a', 'edition' => 'b', 'region' => 'c'], 'Allowlist delimiters or exact matching failed.');
$check($allowed['redirect']['bar'] === 'foo', 'Allowlisting must not remove redirect parameters.');
$check($policy->parameters(['variant_extra' => 'a', 'Variant' => 'b'], 'variant')['canonical'] === [], 'Allowlist must use exact case-sensitive names.');
$check($policy->parameters(['bar' => 'foo'], '*')['canonical'] === [], 'Wildcards must not allow arbitrary parameters.');
$check($policy->parameters(['id' => '25', 'Itemid' => '111', 'catid' => '12'], 'id, Itemid, catid')['canonical'] === [], 'Allowlist must not override routing parameters.');
$check($policy->parameters(['custom' => ['value' => 'yes']], 'custom')['canonical']['custom'] === ['value' => 'yes'], 'Allowlisted array values must be preserved.');
$check($policy->parameters(['start' => '2', 'bar' => 'foo'], 'edition')['canonical'] === ['start' => '2'], 'Custom allowlist must not disable pagination.');
$check($policy->equivalent('https://example.org/a?x=1&y=2', 'https://example.org/a?y=2&x=1'), 'Query ordering causes redirects.');
$check($policy->equivalent('https://example.org/%7ealias', 'https://example.org/~alias'), 'Unreserved encoding mismatch.');
$check(!$policy->equivalent('https://example.org/a%2Fb', 'https://example.org/a/b'), 'Encoded slash was collapsed.');
$check(!$policy->equivalent('https://example.org/a', 'https://example.org/a/'), 'Trailing slash distinction lost.');
$check(!$policy->equivalent('https://example.org/a', 'https://example.org/b'), 'Different paths treated as equal.');
$check($policy->equivalent('https://example.org/über', 'https://example.org/%C3%BCber'), 'UTF-8 aliases cause redirect loops.');
$check(!$policy->equivalent('https://example.org/a%252Fb', 'https://example.org/a%2Fb'), 'Double encoding was collapsed.');
echo $count . " policy checks passed.\n";
