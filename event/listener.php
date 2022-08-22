<?php
/**
*
* @package Topic description
* @copyright (c) 2016 RMcGirr83
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*
*/

namespace rmcgirr83\topicdescription\event;

/**
* @ignore
*/
use phpbb\auth\auth;
use phpbb\request\request;
use phpbb\template\template;
use phpbb\language\language;
use phpbb\user;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
* Event listener
*/
class listener implements EventSubscriberInterface
{
	/** @var auth */
	protected $auth;

	/** @var request */
	protected $request;

	/** @var template */
	protected $template;

	/** @var language */
	protected $language;

	/** @var user */
	protected $user;

	/**
	* Constructor
	*
	* @param auth		$auth				Auth object
	* @param request	$request			Request object
	* @param template	$template       	Template object
	* @param language	$language          	Language object
	* @param user		$user				User object
	* @access public
	*/
	public function __construct(
		auth $auth,
		request $request,
		template $template,
		language $language,
		user $user)
	{
		$this->auth = $auth;
		$this->request = $request;
		$this->template = $template;
		$this->language = $language;
		$this->user = $user;
	}

	/**
	* Assign functions defined in this class to event listeners in the core
	*
	* @return array
	* @static
	* @access public
	*/
	static public function getSubscribedEvents()
	{
		return array(
			'core.acp_extensions_run_action_after'	=>	'acp_extensions_run_action_after',
			'core.permissions'						=> 'add_permission',
			'core.user_setup_after'					=> 'user_setup_after',
			'core.posting_modify_message_text'		=> 'modify_message_text',
			'core.posting_modify_submission_errors'		=> 'topic_desc_add_to_post_data',
			'core.posting_modify_submit_post_before'	=> 'topic_desc_add',
			'core.submit_post_modify_sql_data'		=> 'submit_post_modify_sql_data',
			'core.posting_modify_template_vars'		=> 'topic_data_topic_desc',
			'core.viewtopic_modify_page_title'		=> 'topic_desc_add_viewtopic',
			'core.viewforum_modify_topicrow'		=> 'modify_topicrow',
			'core.search_modify_tpl_ary'			=> 'search_modify_tpl_ary',
			'core.mcp_view_forum_modify_topicrow'	=> 'modify_topicrow',
		);
	}

	/* Display additional metdata in extension details
	*
	* @param $event			event object
	* @param return null
	* @access public
	*/
	public function acp_extensions_run_action_after($event)
	{
		if ($event['ext_name'] == 'rmcgirr83/topicdescription' && $event['action'] == 'details')
		{
			$this->template->assign_var('S_BUY_ME_A_BEER_TOPICDESCRIPTION',true);
		}
	}

	/**
	* Add administrative permissions to manage forums
	*
	* @param object $event The event object
	* @return null
	* @access public
	*/
	public function add_permission($event)
	{
		$permissions = $event['permissions'];
		$permissions['f_topic_desc'] = ['lang' => 'ACL_F_TOPIC_DESC', 'cat' => 'post'];
		$event['permissions'] = $permissions;
	}

	/**
	 * Add language
	 *
	 * @param object $event The event object
	 * @return null
	 * @access public
	 */
	public function user_setup_after($event)
	{
		$this->language->add_lang('common', 'rmcgirr83/topicdescription');
	}

	public function topic_data_topic_desc($event)
	{
		$mode = $event['mode'];
		$post_data = $event['post_data'];
		$page_data = $event['page_data'];
		$topic_desc = (!empty($post_data['topic_desc'])) ? $post_data['topic_desc'] : '';

		if ($this->auth->acl_get('f_topic_desc', $event['forum_id']) && ($mode == 'post' || ($mode == 'edit' && $post_data['topic_first_post_id'] == $post_data['post_id'])))
		{
			$page_data['TOPIC_DESC'] = $this->request->variable('topic_desc', $topic_desc, true);
			$page_data['S_DESC_TOPIC'] = true;
		}

		$event['page_data']	= $page_data;
	}

	public function topic_desc_add_to_post_data($event)
	{
		$topic_desc = $this->request->variable('topic_desc', '', true);
		$error = [];
		/**
		 * Replace Emojis and other 4bit UTF-8 chars not allowed by MySQL to UCR/NCR.
		 * Using their Numeric Character Reference's Hexadecimal notation.
		 * Check the permissions for posting Emojis first.
		 */
		if ($this->auth->acl_get('u_emoji'))
		{
			$topic_desc = utf8_encode_ucr($topic_desc);
		}
		else
		{
			/**
			 * Check for out-of-bounds characters that are currently
			 * not supported by utf8_bin in MySQL
			 */
			if (preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $topic_desc, $matches))
			{
				$character_list = implode('<br>', $matches[0]);

				$error[] = $this->user->lang('UNSUPPORTED_CHARACTERS_TD', $character_list);
				$event['error'] = array_merge($event['error'], $error);
			}
		}

		if (!count($error))
		{
			$event['post_data'] = array_merge($event['post_data'], [
				'topic_desc'	=> $topic_desc,
			]);
		}
	}

	public function topic_desc_add($event)
	{
		$event['data'] = array_merge($event['data'], [
			'topic_desc'	=> $event['post_data']['topic_desc'],
		]);
	}

	public function modify_message_text($event)
	{
		$event['post_data'] = array_merge($event['post_data'], [
			'topic_desc'	=> $this->request->variable('topic_desc', '', true),
		]);
	}

	public function submit_post_modify_sql_data($event)
	{
		$mode = $event['post_mode'];
		$topic_desc = !empty($event['data']['topic_desc']) ? $event['data']['topic_desc'] : '';
		$data_sql = $event['sql_data'];
		$topic_desc = truncate_string($topic_desc, 120);

		if (in_array($mode, ['post', 'edit_topic', 'edit_first_post']))
		{
			$data_sql[TOPICS_TABLE]['sql']['topic_desc'] = $topic_desc;
		}

		$event['sql_data'] = $data_sql;
	}

	public function topic_desc_add_viewtopic($event)
	{
		$topic_data = $event['topic_data'];
		$this->template->assign_var('TOPIC_DESC',censor_text($topic_data['topic_desc']));
	}

	public function modify_topicrow($event)
	{
		$row = $event['row'];

		if (!empty($row['topic_desc']))
		{
			$topic_row = $event['topic_row'];
			$topic_row['TOPIC_DESC'] = censor_text($row['topic_desc']);
			$event['topic_row'] = $topic_row;
		}
	}

	public function search_modify_tpl_ary($event)
	{
		$row = $event['row'];

		if ($event['show_results'] == 'topics' && !empty($row['topic_desc']))
		{
			$tpl_array = $event['tpl_ary'];
			$tpl_array['TOPIC_DESC'] = censor_text($row['topic_desc']);
			$event['tpl_ary'] = $tpl_array;
		}
	}
}
