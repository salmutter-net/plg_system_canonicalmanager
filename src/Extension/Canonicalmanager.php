<?php

namespace SalmutterNet\Plugin\System\Canonicalmanager\Extension;

use Joomla\CMS\Event\Model\BeforeSaveEvent;
use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\Registry\Registry;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;
use SalmutterNet\Plugin\System\Canonicalmanager\Service\ArticlePolicy;
use SalmutterNet\Plugin\System\Canonicalmanager\Service\ArticleResolver;

final class Canonicalmanager extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;
    private ?string $canonical = null;

    public function __construct(array $config, private DatabaseInterface $db, private \Closure $siteFactory)
    {
        parent::__construct($config);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterDispatch' => 'onAfterDispatch',
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
            || !$app->getIdentity()->guest || $input->getCmd('option') !== 'com_content'
            || $input->getCmd('view') !== 'article' || $input->getInt('id') < 1
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
            $current = Uri::getInstance()->toString(['scheme', 'host', 'port', 'path', 'query']);
            $query = Uri::getInstance()->getQuery(true);
            $parameters = (new ArticlePolicy())->parameters($query, (string) $this->params->get('article_canonical_parameters', ''));
            $target = new Uri($result['url']);
            foreach ($parameters['canonical'] as $key => $value) {
                $target->setVar($key, $value);
            }
            $canonical = $target->toString();
            $target = new Uri($result['url']);
            foreach ($parameters['redirect'] as $key => $value) {
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
                    $this->record('Would redirect article ' . (int) $article->id . ' from ' . json_encode($currentUri->getPath()) . ' to ' . $result['url']);
                }
                return;
            }
            $this->canonical = $canonical;
            if ($different) {
                $app->redirect($redirect, 301);
            }
        } catch (\Throwable $error) {
            $this->record('Skipped article ' . $input->getInt('id') . ': ' . $error->getMessage(), Log::WARNING);
        }
    }

    public function onBeforeCompileHead(): void
    {
        if ($this->canonical === null) {
            return;
        }
        $document = $this->getApplication()->getDocument();
        $head = $document->getHeadData();
        foreach ($head['links'] ?? [] as $url => $link) {
            if (strtolower($link['relation'] ?? '') === 'canonical') {
                unset($head['links'][$url]);
            }
        }
        $document->setHeadData($head);
        $document->addHeadLink(htmlspecialchars($this->canonical, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'canonical');
    }

    public function onContentPrepareForm(PrepareFormEvent $event): void
    {
        $app = $this->getApplication();
        $form = $event->getForm();
        if (!$app->isClient('administrator') || !in_array($form->getName(), ['com_content.article', 'com_menus.item'], true)) {
            return;
        }
        $data = (object) $event->getData();
        $id = (int) ($data->id ?? $form->getValue('id', null, 0));
        $group = $form->getName() === 'com_content.article' ? 'attribs' : 'params';
        $articleId = $id;
        if ($group === 'params') {
            $link = (string) ($data->link ?? $form->getValue('link', null, ''));
            parse_str((string) parse_url($link, PHP_URL_QUERY), $menuQuery);
            if (($menuQuery['option'] ?? '') !== 'com_content' || ($menuQuery['view'] ?? '') !== 'article') {
                return;
            }
            $articleId = (int) ($menuQuery['id'] ?? 0);
        }
        $form->load('<form><fields name="' . $group . '"><fieldset name="canonicalmanager" label="PLG_SYSTEM_CANONICALMANAGER_PANEL">'
            . '<field name="canonicalmanager_preview" type="note" label="PLG_SYSTEM_CANONICALMANAGER_PREVIEW" filter="unset" />'
            . '</fieldset></fields></form>');
        $message = Text::_('PLG_SYSTEM_CANONICALMANAGER_SAVE_FIRST');
        try {
            if ($articleId > 0) {
                $site = ($this->siteFactory)();
                $result = (new ArticleResolver($this->db, $site))->resolve($articleId, $app->getLanguage()->getTag());
                if ($result) {
                    $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $message = '<a href="' . $escape($result['url']) . '" target="_blank" rel="noopener noreferrer">'
                        . $escape($result['url']) . '</a><br>'
                        . Text::_('PLG_SYSTEM_CANONICALMANAGER_REASON_' . strtoupper($result['selection']['reason'] ?? 'fallback'));
                    if ($result['selection']) {
                        $selection = $result['selection'];
                        $message .= ': ' . $escape($selection['title']) . ' (ID ' . $selection['id'] . ')';
                        if ($app->getIdentity()->authorise('core.edit', 'com_menus')) {
                            $message .= ' <a href="index.php?option=com_menus&amp;task=item.edit&amp;id=' . $selection['id'] . '">'
                                . Text::_('JACTION_EDIT') . '</a>';
                        }
                    }
                    if (count($result['candidates']) > 1) {
                        $message .= '<br>' . Text::_('PLG_SYSTEM_CANONICALMANAGER_MULTIPLE');
                    }
                    if ($result['article']->language === '*' && \Joomla\CMS\Language\Multilanguage::isEnabled()) {
                        $message .= '<br>' . Text::_('PLG_SYSTEM_CANONICALMANAGER_ALL_LANGUAGES');
                    }
                    $message .= '<br>' . Text::_('PLG_SYSTEM_CANONICALMANAGER_SAVED_STATE');
                }
            }
        } catch (\Throwable $error) {
            $message = htmlspecialchars($error->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $form->setFieldAttribute('canonicalmanager_preview', 'description', $message, $group);
        if ($group === 'attribs' && $app->getIdentity()->authorise('core.admin', 'com_content')) {
            $form->load('<form><fields name="attribs"><fieldset name="canonicalmanager">'
                . '<field name="canonicalmanager_menu" type="menuitem" label="PLG_SYSTEM_CANONICALMANAGER_OVERRIDE" '
                . 'description="PLG_SYSTEM_CANONICALMANAGER_OVERRIDE_DESC" client_id="0" published="1" filter="integer" default="0">'
                . '<option value="0">PLG_SYSTEM_CANONICALMANAGER_AUTOMATIC</option></field>'
                . '</fieldset></fields></form>');
        }
    }

    public function onContentBeforeSave(BeforeSaveEvent $event): void
    {
        if ($event->getContext() !== 'com_content.article'
            || $this->getApplication()->getIdentity()->authorise('core.admin', 'com_content')) {
            return;
        }
        $item = $event->getItem();
        $id = (int) ($item->id ?? 0);
        $saved = '';
        if ($id > 0) {
            $query = $this->db->getQuery(true)->select($this->db->quoteName('attribs'))
                ->from($this->db->quoteName('#__content'))->where($this->db->quoteName('id') . ' = ' . $id);
            $saved = (string) $this->db->setQuery($query)->loadResult();
        }
        $params = new Registry($item->attribs);
        $params->set('canonicalmanager_menu', (int) (new Registry($saved))->get('canonicalmanager_menu', 0));
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
        $query = $this->db->getQuery(true)->select('ancestor.published, ancestor.access')
            ->from($this->db->quoteName('#__categories', 'ancestor'))
            ->join('INNER', $this->db->quoteName('#__categories', 'child'), 'ancestor.lft <= child.lft AND ancestor.rgt >= child.rgt')
            ->where('child.id = ' . (int) $article->catid)->where('ancestor.id > 1');
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
