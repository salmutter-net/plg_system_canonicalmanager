<?php

use SalmutterNet\Plugin\System\Canonicalmanager\Service\EditorHint;

require dirname(__DIR__) . '/src/Service/EditorHint.php';

$count = 0;
$check = static function (bool $condition, string $message) use (&$count): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $count++;
};
$hint = '<div id="canonicalmanager-summary" role="note">Preferred URL</div>';
$form = '<form id="item-form"><div class="title-alias">Title</div><div class="main-card">Tabs</div></form>';
$expected = '<form id="item-form"><div class="title-alias">Title</div>' . $hint . '<div class="main-card">Tabs</div></form>';
$result = EditorHint::insert($form, $hint);
$check($result === $expected, 'Hint must appear between title/alias and the card containing tabs.');
$check(EditorHint::insert($result, $hint) === $result, 'Hint must not be duplicated.');
$check(EditorHint::insert($form, '') === $form, 'Empty hints must not alter output.');
$other = '<form id="other"><div class="main-card">Other</div></form>';
$check(EditorHint::insert($other, $hint) === $other, 'Unrelated forms must not be modified.');
$check(EditorHint::insert($other . $form, $hint) === $other . $expected, 'Other cards must not receive the hint.');
$missingCard = '<form id="item-form">Fields</form><div class="main-card">Outside</div>';
$check(EditorHint::insert($missingCard, $hint) === $missingCard, 'Insertion must stay within the item form.');
$singleQuotes = "<form method='post' id='item-form'><div class='title-alias'>Title</div><div class='main-card mb-3'>Tabs</div></form>";
$check(str_contains(EditorHint::insert($singleQuotes, $hint), $hint . "<div class='main-card mb-3'>"), 'Single-quoted attributes and additional classes must work.');
$invalid = '<form id="item-form"><div class="main-card">Tabs</div>';
$check(EditorHint::insert($invalid, $hint) === $invalid, 'Incomplete forms must not be modified.');
echo $count . " editor hint checks passed.\n";
