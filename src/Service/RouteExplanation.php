<?php

namespace SalmutterNet\Plugin\System\Canonicalmanager\Service;

final class RouteExplanation
{
    public static function render(array $result, bool $canEdit, callable $text): array
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $selection = $result['selection'];
        $reason = strtoupper($selection['reason'] ?? 'fallback');
        $source = $escape($text('REASON_' . $reason));
        $url = $escape($result['url']);
        $link = '<a class="text-break" href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>';
        $details = '<div class="mb-3 fs-5">' . $link . '</div><p><strong>' . $source . '</strong>';
        if ($selection) {
            $details .= ': ' . $escape($selection['title']) . ' (ID ' . (int) $selection['id'] . ')';
        }
        $details .= '</p><p>' . $escape($text('EXPLAIN_' . $reason)) . '</p>';
        $details .= '<dl class="row mb-3"><dt class="col-sm-4">' . $escape($text('ARTICLE_ALIAS'))
            . '</dt><dd class="col-sm-8"><code>' . $escape((string) $result['article']->alias) . '</code></dd>';
        if ($selection) {
            $details .= '<dt class="col-sm-4">' . $escape($text('MENU_PATH')) . '</dt><dd class="col-sm-8"><code>'
                . $escape($selection['route'] === '' ? '/' : $selection['route']) . '</code></dd>';
        }
        $details .= '</dl><div class="d-flex flex-wrap gap-2 align-items-center mb-3">'
            . '<button type="button" class="btn btn-outline-secondary" data-canonical-copy="' . $url . '" '
            . 'data-copy-success="' . $escape($text('COPIED')) . '" data-copy-error="' . $escape($text('COPY_FAILED')) . '" hidden>'
            . '<span class="icon-copy" aria-hidden="true"></span> ' . $escape($text('COPY_URL')) . '</button>';
        if ($selection && $canEdit) {
            $details .= '<a class="btn btn-outline-primary" href="index.php?option=com_menus&amp;task=item.edit&amp;id='
                . (int) $selection['id'] . '" target="_blank" rel="noopener noreferrer">'
                . '<span class="icon-edit" aria-hidden="true"></span> ' . $escape($text('EDIT_MENU')) . '</a>';
        }
        $details .= '<span data-canonical-copy-status role="status" aria-live="polite"></span></div>';
        if ($selection && $canEdit) {
            $details .= '<p class="small text-muted">' . $escape($text('EDIT_MENU_NOTE')) . '</p>';
        }
        if (count($result['candidates']) > 1) {
            $details .= '<p>' . $escape($text('MULTIPLE')) . '</p>';
        }
        $details .= '<p class="small text-muted mb-0">' . $escape($text('SAVED_STATE')) . '</p>';

        return ['details' => $details, 'summary' => $link . '<span class="d-block small mt-1">' . $source
            . ($selection ? ': ' . $escape($selection['title']) : '') . '</span>'];
    }
}
