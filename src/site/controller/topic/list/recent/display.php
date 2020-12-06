<?php
/**
 * Kunena Component
 *
 * @package         Kunena.Site
 * @subpackage      Controller.Topic
 *
 * @copyright       Copyright (C) 2008 - 2020 Kunena Team. All rights reserved.
 * @license         https://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link            https://www.kunena.org
 **/

namespace Kunena\Forum\Site\Controller\Topic\KunenaList\Recent;

defined('_JEXEC') or die();

use Exception;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Uri\Uri;
use Kunena\Forum\Libraries\Controller\KunenaControllerDisplay;
use Kunena\Forum\Libraries\Factory\KunenaFactory;
use Kunena\Forum\Libraries\Forum\Category\KunenaCategoryHelper;
use Kunena\Forum\Libraries\Forum\Topic\KunenaTopicFinder;
use Kunena\Forum\Libraries\Pagination\KunenaPagination;
use Kunena\Forum\Libraries\Route\KunenaRoute;
use Kunena\Forum\Libraries\User\KunenaUserHelper;
use Kunena\Forum\Site\Model\TopicsModel;
use function defined;

/**
 * Class ComponentTopicControllerListRecentDisplay
 *
 * @since   Kunena 4.0
 */
class ComponentTopicControllerListRecentDisplay extends KunenaControllerDisplay
{
	private $headerText;
	private $pagination;

	/**
	 * Prepare recent topics list.
	 *
	 * @return  void
	 *
	 * @since   Kunena 6.0
	 *
	 * @throws  Exception
	 * @throws  null
	 */
	protected function before()
	{
		parent::before();

		$model = new TopicsModel([], $this->input);
		$model->initialize($this->getOptions(), $this->getOptions()->get('embedded', false));
		$state    = $model->getState();
		$me       = KunenaUserHelper::getMyself();
		$moreUri  = null;
		$holding  = $this->getOptions()->get('topics_deletedtopics');
		$embedded = $this->getOptions()->get('embedded', true);

		$start = $state->get('list.start');
		$limit = $state->get('list.limit');

		$Itemid = $this->input->getInt('Itemid');
		$format = $this->input->getCmd('format');

		if (!$Itemid && $format != 'feed' && $this->config->sef_redirect)
		{
			if ($this->config->topiclist_id)
			{
				$itemidfix = $this->config->topiclist_id;
			}
			else
			{
				$menu      = $this->app->getMenu();
				$getid     = $menu->getItem(KunenaRoute::getItemID("index.php?option=com_kunena&view=topics&mode={$state->get('list.mode')}"));
				$itemidfix = $getid->id;
			}

			if (!$itemidfix)
			{
				$itemidfix = KunenaRoute::fixMissingItemID();
			}

			$controller = BaseController::getInstance("kunena");
			$controller->setRedirect(KunenaRoute::_("index.php?option=com_kunena&view=topics&mode={$state->get('list.mode')}&Itemid={$itemidfix}", false));
			$controller->redirect();
		}

		// Handle &sel=x parameter.
		$time = $state->get('list.time');

		if ($time < 0)
		{
			$time = null;
		}
		elseif ($time == 0)
		{
			$time = new Date(KunenaFactory::getSession()->lasttime);
		}
		else
		{
			$time = new Date(Factory::getDate()->toUnix() - ($time * 3600));
		}

		if ($holding)
		{
			$hold = '0,2,3';
		}
		else
		{
			$hold = '0';
		}

		// Get categories for the filter.
		$categoryIds = $state->get('list.categories');
		$reverse     = !$state->get('list.categories.in');
		$authorise   = 'read';
		$order       = 'last_post_time';

		$finder = new KunenaTopicFinder;
		$finder->filterByMoved(false);

		switch ($state->get('list.mode'))
		{
			case 'topics' :
				$order = 'first_post_time';
				$finder
					->filterByHold([$hold])
					->filterByTime($time, null, false);
				break;
			case 'sticky' :
				$finder
					->filterByHold([$hold])
					->where('a.ordering', '>', 0)
					->filterByTime($time);
				break;
			case 'locked' :
				$finder
					->filterByHold([$hold])
					->where('a.locked', '>', 0)
					->filterByTime($time);
				break;
			case 'noreplies' :
				$finder
					->filterByHold([$hold])
					->where('a.posts', '=', 1)
					->filterByTime($time);
				break;
			case 'unapproved' :
				$authorise = 'topic.approve';
				$finder
					->filterByHold([1])
					->filterByTime($time);
				break;
			case 'deleted' :
				$authorise = 'topic.undelete';
				$finder
					->filterByHold([2, 3])
					->filterByTime($time);
				break;
			case 'replies' :
			default :
				$finder
					->filterByHold([$hold])
					->filterByTime($time);
				break;
		}

		$categories = KunenaCategoryHelper::getCategories($categoryIds, $reverse, $authorise);
		$finder->filterByCategories($categories);

		$this->pagination = new KunenaPagination($finder->count(), $start, $limit);

		$doc = Factory::getApplication()->getDocument();

		$page = $this->pagination->pagesCurrent;

		$pagdata = $this->pagination->getData();

		if ($pagdata->previous->link)
		{
			$pagdata->previous->link = str_replace('?limitstart=0', '', $pagdata->previous->link);
			$doc->addHeadLink($pagdata->previous->link, 'prev');
		}

		if ($pagdata->next->link)
		{
			$doc->addHeadLink($pagdata->next->link, 'next');
		}

		if ($page > 1)
		{
			foreach ($doc->_links as $key => $value)
			{
				if (is_array($value))
				{
					if (array_key_exists('relation', $value))
					{
						if ($value['relation'] == 'canonical')
						{
							$canonicalUrl               = KunenaRoute::_();
							$doc->_links[$canonicalUrl] = $value;
							unset($doc->_links[$key]);
							break;
						}
					}
				}
			}
		}

		if ($moreUri)
		{
			$this->pagination->setUri($moreUri);
		}

		$topics = $finder
			->order($order, -1)
			->start($this->pagination->limitstart)
			->limit($this->pagination->limit)
			->find();

		if ($topics)
		{
			$this->prepareTopics();
		}

		$actions = ['delete', 'approve', 'undelete', 'move', 'permdelete'];

		$params      = Factory::getApplication()->getMenu()->getActive()->getParams();
		$title       = $params->get('page_title');
		$pageheading = $params->get('show_page_heading');

		switch ($state->get('list.mode'))
		{
			case 'topics' :
				if (!empty($title) && $pageheading)
				{
					$this->headerText = $title;
				}
				else
				{
					$this->headerText = Text::_('COM_KUNENA_VIEW_TOPICS_DEFAULT_MODE_TOPICS');
				}

				$canonicalUrl = KunenaRoute::_('index.php?option=com_kunena&view=topics&mode=topics');
				break;
			case 'sticky' :
				if (!empty($title) && $pageheading)
				{
					$this->headerText = $title;
				}
				else
				{
					$this->headerText = Text::_('COM_KUNENA_VIEW_TOPICS_DEFAULT_MODE_STICKY');
				}

				$canonicalUrl = KunenaRoute::_('index.php?option=com_kunena&view=topics&mode=sticky');
				break;
			case 'locked' :
				if (!empty($title) && $pageheading)
				{
					$this->headerText = $title;
				}
				else
				{
					$this->headerText = Text::_('COM_KUNENA_VIEW_TOPICS_DEFAULT_MODE_LOCKED');
				}

				$canonicalUrl = KunenaRoute::_('index.php?option=com_kunena&view=topics&mode=locked');
				break;
			case 'noreplies' :
				if (!empty($title) && $pageheading)
				{
					$this->headerText = $title;
				}
				else
				{
					$this->headerText = Text::_('COM_KUNENA_VIEW_TOPICS_DEFAULT_MODE_NOREPLIES');
				}

				$canonicalUrl = KunenaRoute::_('index.php?option=com_kunena&view=topics&mode=noreplies');
				break;
			case 'unapproved' :
				if (!empty($title) && $pageheading)
				{
					$this->headerText = $title;
				}
				else
				{
					$this->headerText = Text::_('COM_KUNENA_VIEW_TOPICS_DEFAULT_MODE_UNAPPROVED');
				}

				$canonicalUrl = KunenaRoute::_('index.php?option=com_kunena&view=topics&mode=unapproved');
				break;
			case 'deleted' :
				if (!empty($title) && $pageheading)
				{
					$this->headerText = $title;
				}
				else
				{
					$this->headerText = Text::_('COM_KUNENA_VIEW_TOPICS_DEFAULT_MODE_DELETED');
				}

				$canonicalUrl = KunenaRoute::_('index.php?option=com_kunena&view=topics&mode=deleted');
				break;
			case 'replies' :
			default :
				if (!empty($title) && $pageheading)
				{
					$this->headerText = $title;
				}
				else
				{
					$this->headerText = Text::_('COM_KUNENA_VIEW_TOPICS_DEFAULT_MODE_TOPICS');
				}

				$canonicalUrl = KunenaRoute::_('index.php?option=com_kunena&view=topics&mode=replies');
				break;
		}

		$doc = Factory::getApplication()->getDocument();

		foreach ($doc->_links as $key => $value)
		{
			if (is_array($value))
			{
				if (array_key_exists('relation', $value))
				{
					if ($value['relation'] == 'canonical')
					{
						$doc->_links[$canonicalUrl] = $value;
						unset($doc->_links[$key]);
						break;
					}
				}
			}
		}

		$actions1 = $this->getTopicActions($topics, $actions);
	}

	/**
	 * Prepare document.
	 *
	 * @return  void|boolean
	 *
	 * @since   Kunena 6.0
	 *
	 * @throws  Exception
	 */
	protected function prepareDocument(): bool
	{
		$page       = $this->pagination->pagesCurrent;
		$total      = $this->pagination->pagesTotal;
		$headerText = $this->headerText . ($total > 1 && $page > 1 ? " - " . Text::_('COM_KUNENA_PAGES') . " {$page}" : '');

		$image     = null;
		$config    = Factory::getApplication();
		$robots    = $config->get('robots');
		$menu_item = $this->app->getMenu()->getActive();

		$this->setMetaData('og:url', Uri::current(), 'property');

		if (File::exists(JPATH_SITE . '/' . $this->config->emailheader))
		{
			$image = Uri::base() . $this->config->emailheader;
			$this->setMetaData('og:image', $image, 'property');
		}

		if ($robots == 'noindex, follow')
		{
			$this->setMetaData('robots', 'noindex, follow');
		}
		elseif ($robots == 'index, nofollow')
		{
			$this->setMetaData('robots', 'index, nofollow');
		}
		elseif ($robots == 'noindex, nofollow')
		{
			$this->setMetaData('robots', 'noindex, nofollow');
		}
		else
		{
			$this->setMetaData('robots', 'index, follow');
		}

		if ($menu_item)
		{
			$params             = $menu_item->getParams();
			$params_title       = $params->get('page_title');
			$params_description = $params->get('menu-meta_description');
			$params_robots      = $params->get('robots');

			if (!empty($params_title))
			{
				$title = $params->get('page_title') . ($total > 1 && $page > 1 ? " - " . Text::_('COM_KUNENA_PAGES') . " {$page}" : '');
				$this->setTitle($title);
			}
			else
			{
				$this->setTitle($headerText);
			}

			if (!empty($params_description))
			{
				$description = $params->get('menu-meta_description') . ($total > 1 && $page > 1 ? " - " . Text::_('COM_KUNENA_PAGES') . " {$page}" : '');
				$this->setDescription($description);
			}
			else
			{
				$description = Text::_('COM_KUNENA_ALL_DISCUSSIONS') . ': ' . $this->config->board_title . ($total > 1 && $page > 1 ? " - " . Text::_('COM_KUNENA_PAGES') . " {$page}" : '');
				$this->setDescription($description);
			}

			if (!empty($params_robots))
			{
				$robots = $params->get('robots');
				$this->setMetaData('robots', $robots);
			}

			$this->setMetaData('og:type', 'article', 'property');
			$this->setMetaData('og:description', $description, 'property');
			$this->setMetaData('og:title', $title, 'property');
			$this->setMetaData('twitter:card', 'summary', 'name');
			$this->setMetaData('twitter:title', $title, 'name');
			$this->setMetaData('twitter:image', $image, 'property');
			$this->setMetaData('twitter:description', $description);
		}
	}
}
