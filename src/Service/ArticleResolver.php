<?php

namespace SalmutterNet\Plugin\System\Canonicalmanager\Service;

use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

final class ArticleResolver implements ResolverInterface
{
    public function __construct(private DatabaseInterface $db, private SiteApplication $site)
    {
    }

    public function resolve(int $id, string $contextLanguage): ?array
    {
        $query = $this->db->getQuery(true)->select('*')->from($this->db->quoteName('#__content'))
            ->where($this->db->quoteName('id') . ' = ' . $id);
        $article = $this->db->setQuery($query)->loadObject();
        if (!$article) {
            return null;
        }

        $language = Multilanguage::isEnabled()
            ? ($article->language === '*' ? $contextLanguage : $article->language) : '*';
        $query = $this->db->getQuery(true)->select('ancestor.id')->from($this->db->quoteName('#__categories', 'ancestor'))
            ->join('INNER', $this->db->quoteName('#__categories', 'child'), 'ancestor.lft <= child.lft AND ancestor.rgt >= child.rgt')
            ->where('child.id = ' . (int) $article->catid)->where('ancestor.extension = ' . $this->db->quote('com_content'))
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
            $view = $item->query['view'] ?? '';
            $targetId = (int) ($item->query['id'] ?? 0);
            $distance = null;
            if ($view === 'article' && $targetId === $id) {
                $distance = 0;
            } elseif ($view === 'category' && ($index = array_search($targetId, $ancestors, true)) !== false) {
                $distance = $index + 1;
            }
            if ($distance !== null) {
                $candidates[] = ['id' => (int) $item->id, 'title' => $item->title,
                    'distance' => $distance, 'languageRank' => $item->language === $language ? 0 : 1,
                    'reason' => $distance === 0 ? 'article' : 'category'];
            }
        }

        $override = (int) (new Registry($article->attribs))->get('canonicalmanager_menu', 0);
        $selection = (new ArticlePolicy())->choose($candidates, $override);
        if ($override && !$selection) {
            throw new \RuntimeException('The preferred menu item is unavailable, inaccessible, or does not route this article.');
        }

        $route = 'index.php?option=com_content&view=article&id=' . $id . ':' . $article->alias
            . '&catid=' . (int) $article->catid . '&Itemid=' . ($selection['id'] ?? -1);
        if ($language !== '*') {
            $route .= '&lang=' . rawurlencode($language);
        }
        $url = Route::link('site', $route, false, Route::TLS_IGNORE, true);

        if (!$selection) {
            $uri = new Uri($url);
            $uri->delVar('Itemid');
            $url = $uri->toString();
        }

        return ['url' => $url, 'selection' => $selection, 'candidates' => $candidates, 'article' => $article];
    }
}
