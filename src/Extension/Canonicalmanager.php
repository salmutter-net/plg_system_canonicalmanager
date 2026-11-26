<?php

namespace SalmutterNet\Plugin\System\Canonicalmanager\Extension;

use Joomla\CMS\Event\Model\BeforeSaveEvent;
use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\Registry\Registry;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;
use SalmutterNet\Plugin\System\Canonicalmanager\Service\ArticlePolicy;
use SalmutterNet\Plugin\System\Canonicalmanager\Service\ArticleResolver;
use SalmutterNet\Plugin\System\Canonicalmanager\Service\CanonicalHead;
use SalmutterNet\Plugin\System\Canonicalmanager\Service\CategoryResolver;
use SalmutterNet\Plugin\System\Canonicalmanager\Service\EditorHint;
use SalmutterNet\Plugin\System\Canonicalmanager\Service\RouteExplanation;

final class Canonicalmanager extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;
    private ?string $canonical = null;
    private ?string $editorHint = null;

    public function __construct(array $config, private DatabaseInterface $db, private \Closure $siteFactory)
    {
        parent::__construct($config);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterDispatch' => 'onAfterDispatch',
            'onAfterRender' => 'onAfterRender',
            'onBeforeCompileHead' => 'onBeforeCompileHead',
            'onContentPrepareForm' => 'onContentPrepareForm',
            'onContentBeforeSave' => 'onContentBeforeSave',
        ];
    }

    public function onAfterDispatch(): void
    {
        $app = $this->getApplication();
        $input = $app->getInput();
        if (!$app->isClient('site') || $app->getDocument()->getType() !== 'html'
            || !$app->getIdentity()->guest
            || !in_array(strtoupper($input->server->getString('REQUEST_METHOD', 'GET')), ['GET', 'HEAD'], true)
            || !(new ArticlePolicy())->permitsDisplay($input->getCmd('task'), $input->getCmd('tmpl'), $input->getCmd('layout'))
            || $input->getBool('print')
            || $input->server->getString('HTTP_X_REQUESTED_WITH') === 'XMLHttpRequest') {
            return;
        }
        foreach ($app->getHeaders() as $header) {
            if (strtolower($header['name']) === 'status' && !str_starts_with((string) $header['value'], '200')) {
                return;
            }
        }
        if (http_response_code() >= 300) {
            return;
        }

        if ($input->getCmd('option') === 'com_content'
            && $input->getCmd('view') === 'article' && $input->getInt('id') >= 1) {
            $this->handleArticle($app, $input);
        } elseif ($input->getCmd('option') === 'com_content'
            && $input->getCmd('view') === 'category' && $input->getInt('id') >= 1
            && $this->params->get('category_enabled', 1)) {
            $this->handleCategory($app, $input);
        } elseif ($this->isFrontpageRequest($app, $input)) {
            $this->handleFrontpage($app, $input);
        }
    }

    private function isFrontpageRequest($app, $input): bool
    {
        if (!$this->params->get('frontpage_enabled', 1)) {
            return false;
        }
        $menu = $app->getMenu();
        $active = $menu->getActive();
        if ($active === null) {
            return false;
        }
        $home = $menu->getDefault($app->getLanguage()->getTag()) ?? $menu->getDefault();
        if ($home === null || (int) $active->id !== (int) $home->id) {
            return false;
        }
        $option = $input->getCmd('option');
        return $option === '' || ($option === 'com_content' && $input->getCmd('view') === 'featured');
    }

    private function handleFrontpage($app, $input): void
    {
        try {
            $menu = $app->getMenu();
            $home = $menu->getDefault($app->getLanguage()->getTag()) ?? $menu->getDefault();
            $canonical = Route::link('site', 'index.php?Itemid=' . (int) $home->id, false, Route::TLS_IGNORE, true);
            $parameters = (new ArticlePolicy())->parameters(Uri::getInstance()->getQuery(true),
                (string) $this->params->get('frontpage_canonical_parameters', ''));
            $this->applyCanonical($app, 'frontpage', $canonical, $parameters,
                (bool) $this->params->get('frontpage_redirect_parameters', 0));
        } catch (\Throwable $error) {
            $this->record('Skipped frontpage: ' . $error->getMessage(), Log::WARNING);
        }
    }

    private function handleCategory($app, $input): void
    {
        try {
            $result = (new CategoryResolver($this->db, $app))->resolve($input->getInt('id'), $app->getLanguage()->getTag());
            if (!$result || !$this->categoryVisible($result['category'])) {
                return;
            }
            $category = $result['category'];
            if ($app->getLanguageFilter() && $category->language !== '*'
                && $category->language !== $app->getLanguage()->getTag()) {
                return;
            }
            $parameters = (new ArticlePolicy())->parameters(Uri::getInstance()->getQuery(true),
                (string) $this->params->get('category_canonical_parameters', ''));
            $this->applyCanonical($app, 'category ' . (int) $category->id, $result['url'], $parameters, true);
        } catch (\Throwable $error) {
            $this->record('Skipped category ' . $input->getInt('id') . ': ' . $error->getMessage(), Log::WARNING);
        }
    }

    private function handleArticle($app, $input): void
    {
        try {
            $result = (new ArticleResolver($this->db, $app))->resolve($input->getInt('id'), $app->getLanguage()->getTag());
            if (!$result || !$this->isPublic($result['article'])) {
                return;
            }
            $article = $result['article'];
            if ($app->getLanguageFilter() && $article->language !== '*'
                && $article->language !== $app->getLanguage()->getTag()) {
                return;
            }
            $parameters = (new ArticlePolicy())->parameters(Uri::getInstance()->getQuery(true),
                (string) $this->params->get('article_canonical_parameters', ''));
            $this->applyCanonical($app, 'article ' . (int) $article->id, $result['url'], $parameters, true);
        } catch (\Throwable $error) {
            $this->record('Skipped article ' . $input->getInt('id') . ': ' . $error->getMessage(), Log::WARNING);
        }
    }

    private function applyCanonical($app, string $label, string $url, array $parameters, bool $redirectParameters): void
    {
        $current = Uri::getInstance()->toString(['scheme', 'host', 'port', 'path', 'query']);
        $target = new Uri($url);
        foreach ($parameters['canonical'] as $key => $value) {
            $target->setVar($key, $value);
        }
        $canonical = $target->toString();
        $target = new Uri($url);
        foreach ($redirectParameters ? $parameters['redirect'] : $parameters['canonical'] as $key => $value) {
            $target->setVar($key, $value);
        }
        $redirect = $target->toString();
        $currentUri = Uri::getInstance();
        if (strtolower((string) $target->getHost()) !== strtolower((string) $currentUri->getHost())
            || !in_array($target->getScheme(), ['http', 'https'], true)) {
            throw new \RuntimeException('Unsafe canonical target rejected.');
        }
        $different = !(new ArticlePolicy())->equivalent($current, $redirect);
        if ($this->params->get('mode', 'audit') === 'audit') {
            if ($different) {
                $this->record('Would redirect ' . $label . ' from ' . json_encode($currentUri->getPath()) . ' to ' . $url);
            }
            return;
        }
        $this->canonical = $canonical;
        if ($different) {
            $app->redirect($redirect, 301);
        }
    }

    public function onAfterRender(): void
    {
        $app = $this->getApplication();
        if ($this->editorHint === null || !$app->isClient('administrator')
            || $app->getDocument()->getType() !== 'html') {
            return;
        }
        $app->setBody(EditorHint::insert($app->getBody(), $this->editorHint));
    }

    public function onBeforeCompileHead(): void
    {
        if ($this->canonical === null) {
            return;
        }
        $document = $this->getApplication()->getDocument();
        $document->setHeadData(CanonicalHead::clean($document->getHeadData()));
        $document->addHeadLink(htmlspecialchars($this->canonical, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'canonical');
    }

    public function onContentPrepareForm(PrepareFormEvent $event): void
    {
        $app = $this->getApplication();
        $form = $event->getForm();
        if (!$app->isClient('administrator')
            || !in_array($form->getName(), ['com_content.article', 'com_menus.item', 'com_categories.categorycom_content'], true)) {
            return;
        }
        $data = (object) $event->getData();
        $id = (int) ($data->id ?? $form->getValue('id', null, 0));
        $formName = $form->getName();
        $group = $formName === 'com_content.article' ? 'attribs' : 'params';
        $articleId = $formName === 'com_content.article' ? $id : 0;
        $categoryId = $formName === 'com_categories.categorycom_content' ? $id : 0;
        if ($formName === 'com_menus.item') {
            $link = (string) ($data->link ?? $form->getValue('link', null, ''));
            parse_str((string) parse_url($link, PHP_URL_QUERY), $menuQuery);
            if (($menuQuery['option'] ?? '') !== 'com_content'
                || !in_array($menuQuery['view'] ?? '', ['article', 'category'], true)) {
                return;
            }
            if (($menuQuery['view'] ?? '') === 'category') {
                $categoryId = (int) ($menuQuery['id'] ?? 0);
            } else {
                $articleId = (int) ($menuQuery['id'] ?? 0);
            }
        }
        if ($categoryId > 0 || ($articleId === 0 && $formName !== 'com_content.article')) {
            $this->renderCategoryPanel($app, $form, $data, $group, $categoryId);
            return;
        }
        $form->load('<form><fields name="' . $group . '"><fieldset name="canonicalmanager" label="PLG_SYSTEM_CANONICALMANAGER_PANEL">'
            . '<field name="canonicalmanager_preview" type="note" label="PLG_SYSTEM_CANONICALMANAGER_PREVIEW" filter="unset" />'
            . '</fieldset></fields></form>');
        $message = Text::_('PLG_SYSTEM_CANONICALMANAGER_SAVE_FIRST');
        $summary = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $summaryClass = 'info';
        try {
            if ($articleId > 0) {
                $site = ($this->siteFactory)();
                $result = (new ArticleResolver($this->db, $site))->resolve($articleId, $app->getLanguage()->getTag());
                if ($result) {
                    $canEdit = false;
                    if ($result['selection'] && $result['selection']['menutype'] !== 'main'
                        && $app->getIdentity()->authorise('core.manage', 'com_menus')) {
                        $query = $this->db->getQuery(true)->select($this->db->quoteName('id'))
                            ->from($this->db->quoteName('#__menu_types'))
                            ->where($this->db->quoteName('menutype') . ' = ' . $this->db->quote($result['selection']['menutype']));
                        $menuTypeId = (int) $this->db->setQuery($query)->loadResult();
                        $canEdit = $menuTypeId > 0 && $app->getIdentity()->authorise('core.edit', 'com_menus.menu.' . $menuTypeId);
                    }
                    $panel = RouteExplanation::render($result, $canEdit,
                        static fn (string $key): string => Text::_('PLG_SYSTEM_CANONICALMANAGER_' . $key));
                    $summary = $panel['summary'];
                    $message = $panel['details'];
                    if ($result['article']->language === '*' && \Joomla\CMS\Language\Multilanguage::isEnabled()) {
                        $message .= '<p>' . htmlspecialchars(Text::_('PLG_SYSTEM_CANONICALMANAGER_ALL_LANGUAGES'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
                    }
                    $app->getDocument()->getWebAssetManager()->registerAndUseScript('plg_system_canonicalmanager.editor',
                        Uri::root(true) . '/media/plg_system_canonicalmanager/js/editor.js', ['version' => 'auto'], ['defer' => true]);
                }
            }
        } catch (\Throwable $error) {
            $message = htmlspecialchars($error->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $summary = $message;
            $summaryClass = 'warning';
        }
        $this->editorHint = '<div id="canonicalmanager-summary" class="alert alert-' . $summaryClass . ' py-2 mb-3" role="note">'
            . '<strong>' . htmlspecialchars(Text::_('PLG_SYSTEM_CANONICALMANAGER_PREVIEW'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . ':</strong> ' . $summary
            . '<small class="d-block mt-1">' . htmlspecialchars(Text::_('PLG_SYSTEM_CANONICALMANAGER_SUMMARY_DETAILS'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</small></div>';
        $form->setFieldAttribute('canonicalmanager_preview', 'description', $message, $group);
    }

    private function renderCategoryPanel($app, $form, object $data, string $group, int $categoryId): void
    {
        $form->load('<form><fields name="' . $group . '"><fieldset name="canonicalmanager" label="PLG_SYSTEM_CANONICALMANAGER_PANEL">'
            . '<field name="canonicalmanager_preview" type="note" label="PLG_SYSTEM_CANONICALMANAGER_PREVIEW_CATEGORY" filter="unset" />'
            . '</fieldset></fields></form>');
        $message = Text::_('PLG_SYSTEM_CANONICALMANAGER_SAVE_FIRST');
        $summary = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $summaryClass = 'info';
        try {
            if ($categoryId > 0) {
                $site = ($this->siteFactory)();
                $result = (new CategoryResolver($this->db, $site))->resolve($categoryId, $app->getLanguage()->getTag());
                if ($result) {
                    $canEdit = false;
                    if ($result['selection'] && $result['selection']['menutype'] !== 'main'
                        && $app->getIdentity()->authorise('core.manage', 'com_menus')) {
                        $query = $this->db->getQuery(true)->select($this->db->quoteName('id'))
                            ->from($this->db->quoteName('#__menu_types'))
                            ->where($this->db->quoteName('menutype') . ' = ' . $this->db->quote($result['selection']['menutype']));
                        $menuTypeId = (int) $this->db->setQuery($query)->loadResult();
                        $canEdit = $menuTypeId > 0 && $app->getIdentity()->authorise('core.edit', 'com_menus.menu.' . $menuTypeId);
                    }
                    $panel = RouteExplanation::renderCategory($result, $canEdit,
                        static fn (string $key): string => Text::_('PLG_SYSTEM_CANONICALMANAGER_' . $key));
                    $summary = $panel['summary'];
                    $message = $panel['details'];
                    $app->getDocument()->getWebAssetManager()->registerAndUseScript('plg_system_canonicalmanager.editor',
                        Uri::root(true) . '/media/plg_system_canonicalmanager/js/editor.js', ['version' => 'auto'], ['defer' => true]);
                }
            }
        } catch (\Throwable $error) {
            $message = htmlspecialchars($error->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $summary = $message;
            $summaryClass = 'warning';
        }
        $this->editorHint = '<div id="canonicalmanager-summary" class="alert alert-' . $summaryClass . ' py-2 mb-3" role="note">'
            . '<strong>' . htmlspecialchars(Text::_('PLG_SYSTEM_CANONICALMANAGER_PREVIEW_CATEGORY'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . ':</strong> ' . $summary
            . '<small class="d-block mt-1">' . htmlspecialchars(Text::_('PLG_SYSTEM_CANONICALMANAGER_SUMMARY_DETAILS_CATEGORY'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</small></div>';
        $form->setFieldAttribute('canonicalmanager_preview', 'description', $message, $group);
    }

    public function onContentBeforeSave(BeforeSaveEvent $event): void
    {
        if (!in_array($event->getContext(), ['com_content.article', 'com_content.form'], true)) {
            return;
        }
        $item = $event->getItem();
        $params = new Registry($item->attribs);
        $params->remove('canonicalmanager_menu');
        $item->attribs = $params->toString();
    }

    private function isPublic(object $article): bool
    {
        $levels = (new \Joomla\CMS\User\User())->getAuthorisedViewLevels();
        if ((int) $article->state !== 1 || !in_array((int) $article->access, $levels, true)) {
            return false;
        }
        $now = Factory::getDate()->toSql();
        if ((!empty($article->publish_up) && $article->publish_up !== $this->db->getNullDate() && $article->publish_up > $now)
            || (!empty($article->publish_down) && $article->publish_down !== $this->db->getNullDate() && $article->publish_down <= $now)) {
            return false;
        }
        return $this->ancestorsAccessible((int) $article->catid, $levels);
    }

    private function categoryVisible(object $category): bool
    {
        $levels = (new \Joomla\CMS\User\User())->getAuthorisedViewLevels();
        if ((int) $category->published !== 1 || !in_array((int) $category->access, $levels, true)) {
            return false;
        }
        return $this->ancestorsAccessible((int) $category->id, $levels);
    }

    private function ancestorsAccessible(int $categoryId, array $levels): bool
    {
        $query = $this->db->getQuery(true)->select('ancestor.published, ancestor.access')
            ->from($this->db->quoteName('#__categories', 'ancestor'))
            ->join('INNER', $this->db->quoteName('#__categories', 'child'), 'ancestor.lft <= child.lft AND ancestor.rgt >= child.rgt')
            ->where('child.id = ' . $categoryId)->where('ancestor.id > 1');
        foreach ($this->db->setQuery($query)->loadObjectList() as $category) {
            if ((int) $category->published !== 1 || !in_array((int) $category->access, $levels, true)) {
                return false;
            }
        }
        return true;
    }

    private function record(string $message, int $priority = Log::INFO): void
    {
        Log::addLogger(['text_file' => 'canonicalmanager.php'], Log::ALL, ['canonicalmanager']);
        Log::add($message, $priority, 'canonicalmanager');
    }
}
