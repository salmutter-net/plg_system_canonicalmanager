<?php

namespace SalmutterNet\Plugin\System\Canonicalmanager\Service;

use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

final class CategoryResolver implements ResolverInterface
{
    public function __construct(private DatabaseInterface $db, private SiteApplication $site)
    {
    }

    public function resolve(int $id, string $contextLanguage): ?array
    {
        $query = $this->db->getQuery(true)->select('*')->from($this->db->quoteName('#__categories'))
            ->where($this->db->quoteName('id') . ' = ' . $id)
            ->where($this->db->quoteName('extension') . ' = ' . $this->db->quote('com_content'));
        $category = $this->db->setQuery($query)->loadObject();
        if (!$category) {
            return null;
        }

        $language = Multilanguage::isEnabled()
            ? ($category->language === '*' ? $contextLanguage : $category->language) : '*';
        $query = $this->db->getQuery(true)->select('ancestor.id')->from($this->db->quoteName('#__categories', 'ancestor'))
            ->join('INNER', $this->db->quoteName('#__categories', 'child'), 'ancestor.lft <= child.lft AND ancestor.rgt >= child.rgt')
            ->where('child.id = ' . $id)->where('ancestor.extension = ' . $this->db->quote('com_content'))
            ->order('ancestor.lft DESC');
        $ancestors = array_map('intval', $this->db->setQuery($query)->loadColumn());
        $menu = $this->site->getMenu();
        $candidates = [];
        $publicLevels = (new \Joomla\CMS\User\User())->getAuthorisedViewLevels();

        foreach ($menu->getMenu() as $item) {
            if ($item->type !== 'component' || $item->component !== 'com_content'
                || !in_array((int) $item->access, $publicLevels, true)
                || ($language !== '*' && !in_array($item->language, ['*', $language], true))) {
                continue;
            }
            $accessible = true;
            foreach ($item->tree as $parentId) {
                $parent = $menu->getItem($parentId);
                if (!$parent || !in_array((int) $parent->access, $publicLevels, true)) {
                    $accessible = false;
                    break;
                }
            }
            if (!$accessible) {
                continue;
            }
            $targetId = (int) ($item->query['id'] ?? 0);
            if (($item->query['view'] ?? '') === 'category'
                && ($index = array_search($targetId, $ancestors, true)) !== false) {
                $candidates[] = ['id' => (int) $item->id, 'title' => $item->title, 'route' => $item->route,
                    'menutype' => $item->menutype, 'layout' => $item->query['layout'] ?? 'blog',
                    'distance' => $index, 'languageRank' => $item->language === $language ? 0 : 1,
                    'reason' => $index === 0 ? 'category' : 'ancestor'];
            }
        }

        $selection = (new ArticlePolicy())->choose($candidates);

        $route = 'index.php?option=com_content&view=category&id=' . $id . ':' . $category->alias
            . '&Itemid=' . ($selection['id'] ?? -1);
        if ($language !== '*') {
            $route .= '&lang=' . rawurlencode($language);
        }
        $url = Route::link('site', $route, false, Route::TLS_IGNORE, true);

        if (!$selection) {
            $uri = new Uri($url);
            $uri->delVar('Itemid');
            $url = $uri->toString();
        }

        return ['url' => $url, 'selection' => $selection, 'candidates' => $candidates, 'category' => $category];
    }
}
