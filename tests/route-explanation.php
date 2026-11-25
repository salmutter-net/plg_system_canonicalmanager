<?php

use SalmutterNet\Plugin\System\Canonicalmanager\Service\RouteExplanation;

require dirname(__DIR__) . '/src/Service/RouteExplanation.php';

$count = 0;
$check = static function (bool $condition, string $message) use (&$count): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $count++;
};
$text = static fn (string $key): string => $key;
$result = ['url' => 'https://example.org/services.html?x=1&y=2',
    'article' => (object) ['alias' => 'our-services'],
    'selection' => ['id' => 111, 'title' => 'Services', 'route' => 'services', 'reason' => 'article'],
    'candidates' => [[], []]];
$panel = RouteExplanation::render($result, true, $text);
$check(str_contains($panel['summary'], 'REASON_ARTICLE') && str_contains($panel['summary'], 'Services'), 'Summary must identify the URL source.');
$check(str_contains($panel['details'], 'EXPLAIN_ARTICLE'), 'Direct article route explanation missing.');
$check(str_contains($panel['details'], '<code>our-services</code>') && str_contains($panel['details'], '<code>services</code>'), 'Article and menu aliases must be distinguishable.');
$check(str_contains($panel['details'], 'task=item.edit&amp;id=111') && str_contains($panel['details'], 'btn btn-outline-primary'), 'Menu edit action must be an identifiable button.');
$check(str_contains($panel['details'], 'data-canonical-copy="https://example.org/services.html?x=1&amp;y=2"'), 'Copy action must contain the escaped preferred URL.');
$check(str_contains($panel['details'], 'MULTIPLE'), 'Multiple candidate explanation missing.');
$check(!str_contains(RouteExplanation::render($result, false, $text)['details'], 'task=item.edit'), 'Menu edit action must respect permissions.');
$result['selection']['reason'] = 'category';
$check(str_contains(RouteExplanation::render($result, true, $text)['details'], 'EXPLAIN_CATEGORY'), 'Category route explanation missing.');
$result['selection']['title'] = '<script>alert(1)</script>';
$result['article']->alias = '<img src=x onerror=alert(1)>';
$panel = RouteExplanation::render($result, true, $text);
$check(!str_contains($panel['details'], '<script>') && !str_contains($panel['summary'], '<script>'), 'Menu titles must be HTML escaped.');
$check(!str_contains($panel['details'], '<img src='), 'Article aliases must be HTML escaped.');
$result['selection'] = null;
$result['candidates'] = [];
$panel = RouteExplanation::render($result, true, $text);
$check(str_contains($panel['details'], 'EXPLAIN_FALLBACK'), 'Fallback must be explained as a valid route.');
$check(!str_contains($panel['details'], 'task=item.edit') && !str_contains($panel['details'], 'MENU_PATH'), 'Fallback must not offer a nonexistent menu route.');
echo $count . " route explanation checks passed.\n";
