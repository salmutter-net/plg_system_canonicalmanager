<?php

use SalmutterNet\Plugin\System\Canonicalmanager\Service\CanonicalHead;

require dirname(__DIR__) . '/src/Service/CanonicalHead.php';

$count = 0;
$check = static function (bool $condition, string $message) use (&$count): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $count++;
};
foreach ([
    '<link rel="canonical" href="https://example.org/old"/>',
    "<LINK href='https://example.org/old' REL='Canonical'>",
    '<link rel=canonical href=https://example.org/old>',
    "<link\nrel = \"alternate canonical\"\nhref=\"https://example.org/old\">",
    '<link rel="&#99;anonical" href="/old">',
] as $tag) {
    $clean = CanonicalHead::clean(['custom' => [$tag]]);
    $check($clean['custom'][0] === '', 'Custom canonical was not removed: ' . $tag);
}
$preserved = [
    '<link rel="stylesheet" href="/style.css">',
    '<link rel="alternate" hreflang="de" href="/de">',
    '<meta property="og:url" content="https://example.org/old">',
    '<script>const example = \'<link rel="canonical" href="/example">\';</script>',
    '<!-- <link rel="canonical" href="/comment"> -->',
    '<link data-example=\'rel="canonical"\' rel="stylesheet" href="/style.css">',
    '<link rel href="/unknown">',
];
foreach ($preserved as $tag) {
    $check(CanonicalHead::clean(['custom' => [$tag]])['custom'][0] === $tag, 'Unrelated head markup was altered.');
}
$head = ['links' => ['/old' => ['relation' => 'canonical'], '/style' => ['relation' => 'stylesheet']],
    'custom' => ['<link rel="canonical" href="/old"><meta name="test" content="keep">'], 'title' => 'Keep title'];
$clean = CanonicalHead::clean($head);
$check(!isset($clean['links']['/old']) && isset($clean['links']['/style']), 'Document API canonical removal failed.');
$check($clean['custom'][0] === '<meta name="test" content="keep">', 'Bundled custom markup must be preserved.');
$check($clean['title'] === $head['title'], 'Other document data must remain unchanged.');
$check(CanonicalHead::clean($clean) === $clean, 'Cleanup must be idempotent.');
$check(CanonicalHead::clean([]) === [], 'Empty head data must be supported.');
echo $count . " canonical head checks passed.\n";
