<?php
/**
 * Kunena Component
 *
 * @package         Kunena.Site
 * @subpackage      Controller.User
 *
 * @copyright       Copyright (C) 2008 - 2020 Kunena Team. All rights reserved.
 * @license         https://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link            https://www.kunena.org
 **/

namespace Kunena\Forum\Site\Controller\User\Attachments;

defined('_JEXEC') or die();

use Exception;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Kunena\Forum\Libraries\Attachment\KunenaFinder;
use Kunena\Forum\Libraries\Controller\KunenaControllerDisplay;
use Kunena\Forum\Libraries\Exception\KunenaAuthorise;
use Kunena\Forum\Libraries\Factory\KunenaFactory;
use Kunena\Forum\Libraries\Forum\Message\KunenaMessageHelper;
use Kunena\Forum\Libraries\Forum\Topic\KunenaTopicHelper;
use Kunena\Forum\Libraries\Pagination\KunenaPagination;
use Kunena\Forum\Libraries\Route\KunenaRoute;
use Kunena\Forum\Libraries\User\KunenaUser;
use Kunena\Forum\Libraries\User\KunenaUserHelper;
use function defined;

/**
 * Class ComponentUserControllerAttachmentsDisplay
 *
 * @since   Kunena 4.0
 */
class ComponentUserControllerAttachmentsDisplay extends KunenaControllerDisplay
{
	/**
	 * @var     KunenaUser
	 * @since   Kunena 6.0
	 */
	public $me;

	/**
	 * @var     KunenaUser
	 * @since   Kunena 6.0
	 */
	public $profile;

	/**
	 * @var     array
	 * @since   Kunena 6.0
	 */
	public $attachments;

	/**
	 * @var     string
	 * @since   Kunena 6.0
	 */
	public $headerText;

	/**
	 * @var     string
	 * @since   Kunena 6.0
	 */
	protected $name = 'User/Attachments';

	/**
	 * Prepare user attachments list.
	 *
	 * @return  KunenaAuthorise|void
	 *
	 * @since   Kunena 6.0
	 *
	 * @throws  Exception
	 */
	protected function before()
	{
		parent::before();

		$userid = $this->input->getInt('userid');
		$start  = $this->input->getInt('limitstart', 0);
		$limit  = $this->input->getInt('limit', 30);

		$template      = KunenaFactory::getTemplate();
		$this->me      = KunenaUserHelper::getMyself();
		$this->profile = KunenaUserHelper::get($userid);
		$moreUri       = null;

		$embedded = $this->getOptions()->get('embedded', false);

		if ($embedded)
		{
			$moreUri = new Uri('index.php?option=com_kunena&view=user&layout=attachments&userid=' . $userid . '&limit=' . $limit);
			$moreUri->setVar('Itemid', KunenaRoute::getItemID($moreUri));
		}

		$finder = new KunenaFinder;
		$finder->where('userid', '=', $userid);

		$total      = $finder->count();
		$pagination = new KunenaPagination($total, $start, $limit);

		if (!$this->config->show_imgfiles_manage_profile || !$this->me->exists() && !$this->config->pubprofile)
		{
			return new KunenaAuthorise(Text::_('COM_KUNENA_ATTACHMENT_NO_ACCESS'), 403);
		}

		if ($moreUri)
		{
			$pagination->setUri($moreUri);
		}

		$this->attachments = $finder
			->order('id', -1)
			->start($pagination->limitstart)
			->limit($pagination->limit)
			->find();

		// Pre-load messages.
		$messageIds = [];

		foreach ($this->attachments as $attachment)
		{
			$messageIds[] = (int) $attachment->mesid;
		}

		$messages = KunenaMessageHelper::getMessages($messageIds, 'none');

		// Pre-load topics.
		$topicIds = [];

		foreach ($messages as $message)
		{
			$topicIds[] = $message->thread;
		}

		KunenaTopicHelper::getTopics($topicIds, 'none');

		$this->headerText = Text::_('COM_KUNENA_MANAGE_ATTACHMENTS');
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
		$menu_item = $this->app->getMenu()->getActive();

		if ($menu_item)
		{
			$params             = $menu_item->getParams();
			$params_title       = $params->get('page_title');
			$params_description = $params->get('menu-meta_description');

			if (!empty($params_title))
			{
				$title = $params->get('page_title');
				$this->setTitle($title);
			}
			else
			{
				$this->setTitle($this->headerText);
			}

			if (!empty($params_description))
			{
				$description = $params->get('menu-meta_description');
				$this->setDescription($description);
			}
			else
			{
				$this->setDescription($this->headerText);
			}
		}
	}
}
