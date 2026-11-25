<?php

namespace SalmutterNet\Plugin\System\Canonicalmanager\Service;

final class EditorHint
{
    public static function insert(string $body, string $hint): string
    {
        if ($hint === '' || str_contains($body, 'id="canonicalmanager-summary"')
            || !preg_match('~<form\b[^>]*\bid\s*=\s*(["\'])item-form\1[^>]*>~i', $body, $form, PREG_OFFSET_CAPTURE)) {
            return $body;
        }

        $start = $form[0][1] + strlen($form[0][0]);
        $end = stripos($body, '</form>', $start);
        if ($end === false) {
            return $body;
        }

        $content = substr($body, $start, $end - $start);
        if (!preg_match('~<div\b[^>]*\bclass\s*=\s*(["\'])[^"\']*\bmain-card\b[^"\']*\1[^>]*>~i', $content, $card, PREG_OFFSET_CAPTURE)) {
            return $body;
        }

        return substr_replace($body, $hint, $start + $card[0][1], 0);
    }
}
