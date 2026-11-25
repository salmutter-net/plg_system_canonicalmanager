<?php

namespace SalmutterNet\Plugin\System\Canonicalmanager\Service;

final class CanonicalHead
{
    private static function isCanonical(string $relation): bool
    {
        return in_array('canonical', preg_split('/\s+/', strtolower(trim(html_entity_decode($relation, ENT_QUOTES | ENT_HTML5, 'UTF-8')))), true);
    }

    public static function clean(array $head): array
    {
        foreach ($head['links'] ?? [] as $url => $link) {
            if (self::isCanonical((string) ($link['relation'] ?? ''))) {
                unset($head['links'][$url]);
            }
        }
        foreach ($head['custom'] ?? [] as $index => $markup) {
            $head['custom'][$index] = preg_replace_callback(
                '~<!--.*?-->|<script\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>.*?</script\s*>|<style\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|<link\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~is',
                static function (array $match): string {
                    if (!preg_match('~^<link\b~i', $match[0])) {
                        return $match[0];
                    }
                    preg_match_all('~\s+([^\s=/>]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?~', substr($match[0], 5), $attributes, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
                    foreach ($attributes as $attribute) {
                        if (strtolower($attribute[1]) === 'rel'
                            && self::isCanonical($attribute[2] ?? $attribute[3] ?? $attribute[4] ?? '')) {
                            return '';
                        }
                    }
                    return $match[0];
                },
                $markup
            ) ?? $markup;
        }

        return $head;
    }
}
