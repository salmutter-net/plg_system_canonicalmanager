<?php

namespace SalmutterNet\Plugin\System\Canonicalmanager\Service;

final class ArticlePolicy
{
    public function permitsDisplay(?string $task, ?string $template, ?string $layout): bool
    {
        return ($task ?? '') === '' && ($template ?? '') === ''
            && !in_array($layout, ['edit', 'modal'], true);
    }

    public function choose(array $candidates, int $override = 0): ?array
    {
        if ($override > 0) {
            foreach ($candidates as $candidate) {
                if ($candidate['id'] === $override) {
                    $candidate['reason'] = 'override';
                    return $candidate;
                }
            }
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int =>
            [$a['distance'], $a['languageRank'], $a['id']] <=> [$b['distance'], $b['languageRank'], $b['id']]
        );

        return $candidates[0] ?? null;
    }

    public function parameters(array $query, string $allowedParameters = ''): array
    {
        $allowed = array_merge(['start', 'limitstart', 'showall'], preg_split('/[\s,]+/', trim($allowedParameters), -1, PREG_SPLIT_NO_EMPTY));
        $canonical = [];
        $redirect = [];

        foreach ($query as $key => $value) {
            if (in_array($key, ['option', 'view', 'id', 'catid', 'Itemid', 'lang', 'format'], true)) {
                continue;
            }

            $redirect[$key] = $value;

            if (in_array($key, $allowed, true)) {
                $canonical[$key] = $value;
            }
        }

        return ['canonical' => $canonical, 'redirect' => $redirect];
    }

    public function equivalent(string $left, string $right): bool
    {
        $normalize = static function (string $url): array {
            $parts = parse_url($url);
            if ($parts === false) {
                return [$url];
            }
            parse_str($parts['query'] ?? '', $query);
            ksort($query);
            $path = implode('/', array_map(static fn (string $segment): string => rawurlencode(rawurldecode($segment)), explode('/', $parts['path'] ?? '/')));
            return [strtolower($parts['scheme'] ?? ''), strtolower($parts['host'] ?? ''), $parts['port'] ?? null, $path, $query];
        };

        return $normalize($left) === $normalize($right);
    }
}
