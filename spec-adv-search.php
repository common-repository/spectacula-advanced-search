<?php
/*
 Plugin Name: Spectacu.la Advanced Search
 Plugin URI: http://spectacu.la
 Description: Changes the Wordpress search queries to provide more relevant results more efficiently, gives you the option of returning relevant results that would otherwise not be returned and you can allow for the adding operators to your terms.
 Version: 1.0.4
 Author: Interconnect IT, James R Whitehead
 Author URI: http://interconnectit.com
*/
/*
 Release info:
 1.0.3: Moved out of beta and corrected some typos.
 1.0.2: Fixed it so that the ranking score shouldn't now show in anything that
 calls the_excerpt/content once the loop is complete. If you call before the
 loop then it likely still will.
*/

define('SPEC_ADVSEARCH_OPT', 'spec_advanced_search');
define('SPEC_ADVSEARCH_DOM', 'specadvsrch');
define('SPEC_ADVSEARCH_KEY', 'spec_post_content_fulltext');
define('SPEC_ADVSEARCH_WPV', 2.7);

$locale = get_locale( );
if ( file_exists( dirname( __FILE__ ) . '/lang/' . SPEC_ADVSEARCH_DOM . '-' . $locale . '.mo' ) )
	load_textdomain( SPEC_ADVSEARCH_DOM, dirname( __FILE__ ) . '/lang/' . SPEC_ADVSEARCH_DOM . '-' . $locale . '.mo' );


#define('QUERY_TRACE', true); // Used by my debug tools
#$wpdb->show_errors(); // Show db errors
#delete_option(SPEC_ADVSEARCH_OPT); // Wipe out the options for this plug-in

if (!class_exists('spec_search_enhance')) {
	class spec_search_enhance{

		var $defaults = array('enabled' => false,	// Are we doing anything other than a normal query.
							  'mode' => 'dflt',		// Default query mode.
							  'schedule' => 'daily',// How often to rebuild the index.
							  'auto' => false,		// Do we want to optimise the table automatically
							  'start' => '3',		// Time to start schedules. 24hr 3 being 3am and 23 being 11pm.
							  'order' => 'rlvl',	// The sort order.
							  'relevancy' => 1,		// The query expansion relevancy score.
							  'usecron' => true
							  );

		// Tweak the multipliers to	change the rankings.
		var $modes = array();
		var $sort_order = array();
		var $searching = false; // Set to true when a search is performed.
		var $post_where = ''; // Grab the posts_where from the query and dump here.
		var $loop_done = false;

		function spec_search_enhance() {
			/*
			 These two arrays are set here rather than above for translation.
			 The basic format of these is as follows:
				array {unique_name} = array {ver = my sql version} {q = the query} {desc = display name};
			 The q key can have 3 wildcard elements in it.
				**Content** will be replaced with the content field name
				**Title** will be replaced with the title field name
				**Query** will be reaplced with a cleaned ip version of the
				query string.
			*/

			$this->modes = array(
						   'dflt' => array('ver' => '4.0', 'rank' => 0,		'q' => "((MATCH(**Content**) AGAINST ('**Query**') * 1) + (MATCH(**Title**) AGAINST ('**Query**') * 1.25)) AS ranking", 'desc' => __('Default mode, no extensions.', SPEC_ADVSEARCH_DOM)),
						   'bool' => array('ver' => '4.0', 'rank' => 0,		'q' => "((MATCH(**Content**) AGAINST ('**Query**' IN BOOLEAN MODE) * 1) + (MATCH(**Title**) AGAINST ('**Query**' IN BOOLEAN MODE) * 1.25)) AS ranking", 'desc' => __('Boolean mode', SPEC_ADVSEARCH_DOM)),
						   'expn' => array('ver' => '4.0', 'rank' => 'set',	'q' => "((MATCH(**Content**) AGAINST ('**Query**' WITH QUERY EXPANSION) * 1) + (MATCH(**Title**) AGAINST ('**Query**' WITH QUERY EXPANSION) * 1.25)) AS ranking", 'desc' => __('With query expansion', SPEC_ADVSEARCH_DOM)),
						   'inlm' => array('ver' => '5.1', 'rank' => 1,		'q' => "((MATCH(**Content**) AGAINST ('**Query**' IN NATURAL LANGUAGE MODE) * 1) + (MATCH(**Title**) AGAINST ('**Query**' IN NATURAL LANGUAGE MODE) * 1.25)) AS ranking", 'desc' => __('Natural language mode', SPEC_ADVSEARCH_DOM)),
						   'nlqx' => array('ver' => '5.1', 'rank' => 'set',	'q' => "((MATCH(**Content**) AGAINST ('**Query**' IN NATURAL LANGUAGE MODE WITH QUERY EXPANSION) * 1) + (MATCH(**Title**) AGAINST ('**Query**' IN NATURAL LANGUAGE MODE WITH QUERY EXPANSION) * 1.25)) AS ranking", 'desc' => __('Natural language mode with query expansion', SPEC_ADVSEARCH_DOM)),
						   );
			$this->sort_order = array(
							'rlvl' => array('ver' => '4.0', 'q' => 'ranking DESC', 'desc' => __('Sort by relevance (recommended)', SPEC_ADVSEARCH_DOM)),
							'cron' => array('ver' => '4.0', 'q' => 'post_date DESC', 'desc' => __('Sort by date.', SPEC_ADVSEARCH_DOM)),
							);

			$this->db = new spec_search_enhance_db();
			$this->cron = new spec_search_enhance_cron();

			//trace($this->db->mysql_ver);

			$this->settings = array_merge($this->defaults, (array)get_option(SPEC_ADVSEARCH_OPT, $this->defaults));

			// We'll not change the query unless we need to,
			if ($this->settings['enabled'] && !is_admin()) {
				add_filter('posts_where', array(&$this, 'are_we_searching'));
				add_filter('query', array(&$this, 'falsify_searching'), 100); // Turn off the toggle set above with are_we_searching.

				add_filter('posts_fields_request', array(&$this, 'change_columns')); // Add the new ranking column so we know what order to show results in.
				add_filter('posts_orderby', array(&$this, 'change_search_ordering')); // Change the order by if we need to.
				add_filter('posts_where', array(&$this, 'add_having_rank')); // Add the ranking cut off.

				add_filter('the_excerpt', array(&$this, 'add_relevancy'), 100);
				add_filter('the_content', array(&$this, 'add_relevancy'), 100);

			} elseif(!$this->settings['enabled']) {
				add_action('admin_notices', array(&$this, 'admin_notice'));
			}

			register_activation_hook(__FILE__, array(&$this, 'activate'));
			add_action('admin_menu', array(&$this, 'add_options_page'));
			add_action('spec_do_cron_job', array(&$this, 'do_cron_job'), 10, 1); // Attach this function to the cron job run so we can do what we need.
		}


		/*
		 Show a message if the plug-in is active but not enabled.
		*/
		function admin_notice(){
			echo '<div id="update-nag">' . __('<b>Spectacu.la</b> Advanced Search plug-in is activated but has not been enabled. Please go to <b>"Settings / Advanced Search"</b> and follow the procedures there.', SPEC_ADVSEARCH_DOM) . '</div>';
		}


		/*
		 Called on first launch.
		 Sets the options for this plug-in to the defaults.
		*/
		function activate(){
			add_option(SPEC_ADVSEARCH_OPT, $this->defaults);
		}


		/*
		 Add the admin hooks to create the admin page when needed and to save
		 settings as required.
		*/
		function add_options_page() {
			register_setting(SPEC_ADVSEARCH_OPT, SPEC_ADVSEARCH_OPT, array(&$this, 'validate_options'));
			add_options_page(__('Advanced Search', SPEC_ADVSEARCH_DOM), __('Advanced Search', SPEC_ADVSEARCH_DOM), 'manage_options', SPEC_ADVSEARCH_OPT, array(&$this, 'options_page'));
		}


		/*
		 Adds a string to the end of the_content of the_excerpt if we've got a
		 relevancy result in the post array.

		 To avoid this rank score showing up on extra loop objects set up by
		 plug-ins and the like we set a flag in this object that'll stop this
		 from being appened to the content. We can't then check for the start of
		 the loop as that will be true once we've completed the loop and
		 everything is reset, so if you have any posts output before the normal
		 content loop the rank will show up under them. Would be nice if we had
		 the post id available to us in the_content and the_excerpt filters. Not
		 really a problem as the rank only shows for logged in users that can
		 edit pages.

		 @param string $the_content Passed by apply_filters should contain the post content or excertp
		 @return string the same content that was passed in except with the the rank string glued to the end when needed.
		*/
		function add_relevancy($the_content){
			global $post, $posts;

			if ($post->ID == $posts[count($posts) - 1]->ID)
				$this->loop_done = true;

			if (isset($post->ranking) && current_user_can('edit_pages') && $the_content && !$this->loop_done)
				$the_content .= sprintf('<em>' . __('This post has a search ranking of (%s)', SPEC_ADVSEARCH_DOM) . '</em>', round($post->ranking, 2));

			return $the_content;
		}


		/*
		 Called by the cron job the passed in array should have one key called
		 action with a numeric value, that value corresponds to an action taken
		 here.
		*/
		function do_cron_job($args = array()) {
			$has_index = $this->db->have_index();

			switch ((int)$args['action']) {
				default:
				case 0:
					break;
				case 1:
					$this->db->maybe_add_fulltext_index();
					break;
				case 2:
					$this->db->optimise_table();
					break;
				case 3:
					$this->db->drop_index();
					update_option(SPEC_ADVSEARCH_OPT, $this->defaults); // Nuke the options back to the stone age.
					$this->cron->clear_event_hooks(); // Without an index there'll be nothing left to schedule for.
					break;
			}
		}


		/*
		 Simple function to check the query string and qvars to see if we're
		 searching for something or if it's a normal query. The true should be
		 set to false again after the first manipulation.
		*/
		function are_we_searching($query){
			global $wpdb, $wp_query;

			$this->searching = false;

			$q = $wp_query->query_vars;
			$search_string = stripslashes($q['s']); // These get added back before it's added to the query.

			// Lets drop out of this if we're not running a search.
			if(empty($search_string) || !preg_match('/post_(title|content)\s+?LIKE/', $query))
				return $query;

			// Strip operators from query but leave hyphenated words untouched.
			$op_strip = trim(preg_replace('/(?:([^\w]|^)-|\+|~|<|>|\(|\)|")/is', ' ', $search_string));

			// If all your query is shorter than the index length then just let WP do it's normal search.
			$too_short = true;
			foreach(explode(' ', $op_strip) as $term) {
				if (strlen($term) > 3)
					$too_short = false;
			}
			if ($too_short)
				return $query;

			// We're hacking the search string so set to true.
			$this->searching = true;

			// While we're here lets strip the like from the posts_where and add to the obj
			if($query_no_like = preg_replace('/\s*\b(?:AND|OR)\b\s+\(+.*?\bLIKE\b\s+(\'|"|`)[^\1]*?\1\)+/is', '', $query)) {
				$this->posts_where = $query_no_like;
			} else {
				$this->searching = false;
			}

			return $query;
		}


		/*
		 Simply turn off the searching bool after the first query is run so we
		 don't change queries later in the code.
		*/
		function falsify_searching($query) {
			$this->searching = false;
			return $query;
		}


		function sanitise_search($q = '', $bool = false) {
			$q = stripslashes($q); // These get added back before it's added to the query.

			if ($bool) {
				// If we're doing boolean mode we need to not strip certain chars from words
				$cleaned = preg_replace(array('/(?:([^\w\d\+\-\*<>\(\)\"]))/is', '/\s+/'), ' ', $q);
				return esc_sql(trim($cleaned, ' '));
			} else {
				// Remove all but hypens, numerals and letters then escape it. Also remove double+ spaces
				$cleaned = preg_replace(array('/(?:([^\w]|^)-|-([^\w]|$)|[^\w\-0-9])/is', '/\s+/'), ' ', $q);
				return esc_sql(trim($cleaned, ' '));
			}

			// If we get here I don't know what the hell happened.
			return null;
		}


		/*
		 Add the new score column to.
		*/
		function change_columns($columns) {
			global $wpdb, $wp_query;

			if(!$this->searching)
				return $columns;

			// Collect the mode array if set in settings else use the first item
			$mode = is_array($this->modes[$this->settings['mode']]) ? $this->modes[$this->settings['mode']]['q'] : $this->modes[array_pop(array_slice(array_keys($this->modes), 0, 1))]['q'];

			$q = $wp_query->query_vars;
			$search_string = $this->sanitise_search($q['s'], $this->settings['mode'] == 'bool' ? true : false);

			// Assemble the new query. Insert the table names, search string and the conditions extracted from WP's own query.
			$match = ', ' . str_replace(array('**Title**', '**Content**', '**Query**', '**Conditions**'), array("$wpdb->posts.post_title", "$wpdb->posts.post_content", $search_string, ' ' . $this->posts_where), $mode);

			return $columns . $match;
		}


		/*
		 If we're searching we change the sort order to the one asked for at the
		 options page.

		 @param string $orderby Passed into the function via the posts_orderby
		 filter
		 @return string The changed string on search or unchanged otherwise.
		*/
		function change_search_ordering($orderby = '') {
			if($this->searching) {
				$sort_order = is_array($this->sort_order[$this->settings['order']]) ? $this->sort_order[$this->settings['order']]['q'] : $this->sort_order[array_pop(array_slice(array_keys($this->sort_order), 0, 1))]['q'];
				$orderby = ' ' . $sort_order;
			}

			return $orderby;
		}


		/*
		 Simply adds the HAVING ranking string to the end of the post_where
		 string thus 'hopefully' cutting out irrelevant posts.

		 @param string $where Passed into the function via the posts_where
		 filter

		 @return string The changed string on search or unchanged otherwise.
		*/
		function add_having_rank($where){
			if($this->searching && $this->posts_where != '') {
				$rank = $this->modes[$this->settings['mode']]['rank'] !== 'set' ? intval($this->modes[$this->settings['mode']]['rank']) : intval($this->settings['relevancy']);
				$where = trim($this->posts_where, ' ') . ' HAVING ranking > ' . $rank . '';
			}
			return $where;
		}


		/*
		 Find the next occurrence of a time. So you ask for 3:00 and it's
		 currently 8:00 then it will give you a time tomorrow 19hrs ahead. If
		 however you ask for 8:00 and it's 3:00 it will give you a time 5hrs
		 ahead.

		 @param int $hours the hour of the day in 24hr clock
		 @param int $mins Number of mins passed the hour you'd like.
		 @param int $seconds Number of seconds passed the min you'd like.

		 @return int Timestamp as number of seconds since the Unix Epoch.
		*/
		function next_occurrence_of($hours = 0, $mins = 0, $seconds = 0) {
			$current_time = time();
			$next_midnight = ($current_time + (86400 - ($current_time % 86400))); // Next midnight.
			$seconds_passed = 86400 - ($next_midnight - $current_time); // How far passed midnight are we.
			$last_midnight = $current_time - $seconds_passed; // The timestamp for last time we passed midnight.
			$time_required = ($hours * 60 * 60) + ($mins * 60) + $seconds; // Number of seconds we need to our time is passed midnight.

			if ($last_midnight + $time_required > $current_time) {
				// The time we need is ahead of us
				return $last_midnight + $time_required;
			} else {
				// Been there done that, so lets return a time tomorrow.
				return $next_midnight + $time_required;
			}
		}


		/*
		 Validate the form fields.
		*/
		function validate_options($options){
			$output = $this->defaults;
			$options_old = get_option(SPEC_ADVSEARCH_OPT, $this->defaults);

			$output['enabled']	= $options['enabled']	== 1 ? true : false;
			$output['usecron']	= $options['usecron']	== 1 ? true : false;

			$output['auto'] 	= $options['auto']		== 1 ? true : false;
			$output['schedule'] = in_array($options['schedule'], array_keys(wp_get_schedules())) ? $options['schedule'] : false;
			$output['start'] = intval($options['start']);

			$output['mode'] = in_array($options['mode'], array_keys($this->modes)) ? $options['mode'] : array_pop(array_slice(array_keys($this->modes), 0, 1));
			$output['order'] = in_array($options['order'], array_keys($this->sort_order)) ? $options['order'] : array_pop(array_slice(array_keys($this->sort_order), 0, 1));
			$output['relevancy'] = intval($options['relevancy']) > 0 ? intval($options['relevancy']) : $this->defaults['relevancy'];

			$can_cron = !((defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) || $output['usecron'] === false);

			// We need to add things to the cron or at least check the jobs.
			// If we've been given a bad schedule then we delete all schedules.
			if ($can_cron && $output['auto'] && $output['schedule']) {
				$start_time = $this->next_occurrence_of($output['start']);

				// If the schedule has changes since last time we need to delete the old one before adding the new.
				if($options_old['schedule'] != $output['schedule'] || $options_old['start'] != $output['start']) {
					// Rather than use update we'll wipe out the old one and add a new one.
					// That way it'll runn at a new time rather than a continuation of the old schedule.
					$this->cron->clear_event_hook(array('action' => 2, 'schedule' => $options_old['schedule']));
					$this->cron->add_event_hook(array('action' => 2, 'schedule' => $output['schedule']), $start_time);
				} else {
					$this->cron->add_event_hook(array('action' => 2, 'schedule' => $output['schedule']), $start_time);
				}

				$output['auto'] = true;
			} else {
				// Thers not much point being gentle with the removal just kill em all.
				$this->cron->clear_event_hooks();
				$output['auto'] = false;
			}


			/*
			 Set up almost instant cron jobs to do the create optimise and delete
			*/

			if (wp_verify_nonce($options['create'], 'create_index')) {
				$can_cron ? $this->cron->add_event_hook(array('action' => 1, 'schedule' => false), time() - 10) : $this->db->maybe_add_fulltext_index();
			}

			if (wp_verify_nonce($options['optimi'], 'optimi_index')){
				$can_cron ? $this->cron->add_event_hook(array('action' => 2, 'schedule' => false), time() - 10) : $this->db->optimise_table();
			}

			if (wp_verify_nonce($options['delete'], 'delete_index')){
				$this->cron->clear_event_hooks(); // Without an index there'll be nothing left to schedule for.
				$can_cron ? $this->cron->add_event_hook(array('action' => 3, 'schedule' => false), time() - 10) : $this->db->drop_index();
				$output['enabled'] = false;
			}

			return $output;
		}


		/*
		 The HTML page containing the form with all our settings.
		*/
		function options_page(){
			$has_index = $this->db->have_index();?>
			<div class="wrap">
				<h2><?php _e('Advanced search settings', SPEC_ADVSEARCH_DOM)?></h2>
				<form method="post" action="options.php">
					<?php settings_fields(SPEC_ADVSEARCH_OPT); ?>
					<table class="form-table">
					<tbody>
						<tr valign="top">
							<th scope="row">
								<label for="<?php echo SPEC_ADVSEARCH_OPT;?>_enabled"><?php _e('Enable', SPEC_ADVSEARCH_DOM);?></label>
							</th>
							<td>
								<input <?php echo !$has_index ? 'disabled="disabled" ' : ''; ?>type="checkbox"<?php echo $this->settings['enabled'] && $has_index? ' checked="checked"' : '';?> value="1" name="<?php echo SPEC_ADVSEARCH_OPT;?>[enabled]" id="<?php echo SPEC_ADVSEARCH_OPT;?>_enabled"/>
								<em<?php echo $has_index ? ' style="color:green"' : ' style="color:red"';?>><?php echo $has_index ? __('- Index created.', SPEC_ADVSEARCH_DOM)  : __('- No index. Please use the controls below to create an index before trying to enable this.', SPEC_ADVSEARCH_DOM); ?></em>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row"><?php _e('Mode', SPEC_ADVSEARCH_DOM);?></th>
							<td>
								<select <?php echo !$has_index ? 'disabled="disabled" ' : ''; ?>name="<?php echo SPEC_ADVSEARCH_OPT;?>[mode]" id="<?php echo SPEC_ADVSEARCH_OPT;?>_mode">
								<?php
								foreach($this->modes as $key => $mode) {
									if(version_compare($this->db->mysql_ver, $mode['ver'], 'ge'))
										echo '<option value="' . $key . '"' . ($this->settings['mode'] == $key ? ' selected="selected"' : '') . '>' . $mode['desc'] . '</option>';
								}
								?>
								</select>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">
								<label for="<?php echo SPEC_ADVSEARCH_OPT;?>_order"><?php _e('Sort order', SPEC_ADVSEARCH_DOM);?></label>
							</th>
							<td>
								<select <?php echo !$has_index ? 'disabled="disabled" ' : ''; ?>name="<?php echo SPEC_ADVSEARCH_OPT;?>[order]" id="<?php echo SPEC_ADVSEARCH_OPT;?>_order">
								<?php
								foreach($this->sort_order as $key => $order) {
									if(version_compare($this->db->mysql_ver, $order['ver'], 'ge'))
										echo '<option value="' . $key . '"' . ($this->settings['order'] == $key ? ' selected="selected"' : '') . '>' . $order['desc'] . '</option>';
								}
								?>
								</select>

							</td>
						</tr>
						<tr>
							<th><?php _e('Build/Delete index', SPEC_ADVSEARCH_DOM);?></th>
							<td>
								<button <?php echo  $has_index ? 'disabled="disabled" ' : ''; ?>value="<?php echo  $has_index ? '' : wp_create_nonce('create_index'); ?>" name="<?php echo SPEC_ADVSEARCH_OPT;?>[create]" id="<?php echo SPEC_ADVSEARCH_OPT;?>_create"><?php _e('Create Index', SPEC_ADVSEARCH_DOM);?></button>
								<button <?php echo !$has_index ? 'disabled="disabled" ' : ''; ?>value="<?php echo !$has_index ? '' : wp_create_nonce('delete_index'); ?>" name="<?php echo SPEC_ADVSEARCH_OPT;?>[delete]" id="<?php echo SPEC_ADVSEARCH_OPT;?>_delete" onclick="if (confirm('<?php _e('Are you really sure you want to do that?', SPEC_ADVSEARCH_DOM);?>')){return true;}return false;"><?php _e('Delete Index', SPEC_ADVSEARCH_DOM);?></button>
								<br/>
								<?php
								$status = $this->db->index_status();
								echo $status ? '<em>' . $status['message'] . '. ' . sprintf(__('MySQL is returning the following message: "%s"', SPEC_ADVSEARCH_DOM), $status['state']) . '</em><br/>' : '';

								$create_timestamp = $this->cron->get_next_scheduled(array('action' => 1, 'schedule' => false));
								echo $create_timestamp > 0 && !$status ? '<em>' . sprintf(__('The index creation is scheduled to start after %s. Refresh this page to get updates.', SPEC_ADVSEARCH_DOM), strftime('%b %d %Y, %H:%M:%S', $create_timestamp)) . '</em><br/>' : '';

								$delete_timestamp = $this->cron->get_next_scheduled(array('action' => 3, 'schedule' => false));
								echo $delete_timestamp > 0 && !$status? '<em>' . sprintf(__('The index deletion is scheduled to start after %s. Refresh this page to get updates.', SPEC_ADVSEARCH_DOM), strftime('%b %d %Y, %H:%M:%S', $delete_timestamp)) . '</em><br/>' : '';?>
							</td>
						</tr>
						<tr>
							<th><label for="<?php echo SPEC_ADVSEARCH_OPT;?>_optimi"><?php _e('Optimise Table', SPEC_ADVSEARCH_DOM);?></label></th>
							<td>
								<button value="<?php echo wp_create_nonce('optimi_index'); ?>" name="<?php echo SPEC_ADVSEARCH_OPT;?>[optimi]" id="<?php echo SPEC_ADVSEARCH_OPT;?>_optimi"><?php _e('Optimise Table',SPEC_ADVSEARCH_DOM);?></button>
								<?php
								$optimum_timestamp = $this->cron->get_next_scheduled(array('action' => 2, 'schedule' => false));
								echo $optimum_timestamp > 0 ? '<em>' . sprintf(__('We will start optimising the posts table after %s.', SPEC_ADVSEARCH_DOM), strftime('%b %d %Y, %H:%M:%S', $optimum_timestamp)) . '</em><br/>' : '';?>
								<br/>
								<em></em>
							</td>
						</tr>
						<tr class="<?php echo SPEC_ADVSEARCH_OPT;?>_auto">
							<th>
								<label for="<?php echo SPEC_ADVSEARCH_OPT;?>_auto"><?php _e('Scheduled optimisation', SPEC_ADVSEARCH_DOM);?></label>
							</th>
							<td style="vertical-align:middle;">

								<input <?php echo !$has_index ? 'disabled="disabled" ' : ''; ?>type="checkbox"<?php echo $this->settings['auto'] && $has_index? ' checked="checked"' : '';?> value="1" name="<?php echo SPEC_ADVSEARCH_OPT;?>[auto]" id="<?php echo SPEC_ADVSEARCH_OPT;?>_auto"/>
								<label for="<?php echo SPEC_ADVSEARCH_OPT;?>_auto"><?php _e(':Enable scheduled table optimisation.', SPEC_ADVSEARCH_DOM);?></label>
								<br/><br/>

								<div id="<?php echo SPEC_ADVSEARCH_OPT;?>_cron_group">
									<select <?php echo !$has_index ? 'disabled="disabled" ' : ''; ?>name="<?php echo SPEC_ADVSEARCH_OPT;?>[schedule]"><?php
									foreach ((array)wp_get_schedules() as $schedule => $schedule_desc) {
										echo '<option value="'.$schedule.'"'.($this->settings['schedule'] == $schedule ? ' selected="selected"' : '').'>'.$schedule_desc['display'].'</option>';
									};?>
									</select>
									<label for="<?php echo SPEC_ADVSEARCH_OPT;?>_start">
									<?php _e('Starting after', SPEC_ADVSEARCH_DOM); ?></label>
									<select name="<?php echo SPEC_ADVSEARCH_OPT;?>[start]" id="<?php echo SPEC_ADVSEARCH_OPT;?>_start">
										<?php
										for($i = 0; $i <= 23; $i++) {
											printf('<option value="%d"%s>%02d:00</option>', $i, $i == $this->settings['start'] ? ' selected="selected"' : '', $i);
										}?>
									</select>
									<?php
									$timestamp = $this->cron->get_next_scheduled(array('action' => 2, 'schedule' => $this->settings['schedule']));
									echo '<br/>' . ($timestamp > 0 ? sprintf(__('Next scheduled to run after: %s ', SPEC_ADVSEARCH_DOM), strftime('%b %d %Y, %H:%M:%S', $timestamp))  : __('Not scheduled to run.', SPEC_ADVSEARCH_DOM));
									?>
								</div>
							</td>
						</tr>
						<tr valign="top" class="<?php echo SPEC_ADVSEARCH_OPT;?>_relevancy">
							<th scope="row">
								<label for="<?php echo SPEC_ADVSEARCH_OPT;?>_relevancy"><?php _e('Relevancy cut off.', SPEC_ADVSEARCH_DOM);?></label>
							</th>
							<td>
								<input <?php echo !$has_index ? 'disabled="disabled" ' : ''; ?>type="text" name="<?php echo SPEC_ADVSEARCH_OPT;?>[relevancy]" id="<?php echo SPEC_ADVSEARCH_OPT;?>_relevancy" value="<?php echo $this->settings['relevancy']?>" />
								<br/><em><?php _e('This should be a numeric value that represents the lowest relevancy value of a post that should be shown when using Query Expansion.', SPEC_ADVSEARCH_DOM);?></em>
								<br/><em><?php _e('When logged in, using Query expansion and performing a search you will see a numeric value after the post content or excerpt This is how relevant a post is.', SPEC_ADVSEARCH_DOM);?></em>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">
								<label for="<?php echo SPEC_ADVSEARCH_OPT;?>_usecron"><?php _e('Cron is working', SPEC_ADVSEARCH_DOM);?></label>
							</th>
							<td>
								<input type="checkbox"<?php echo $this->settings['usecron'] && $has_index? ' checked="checked"' : '';?> value="1" name="<?php echo SPEC_ADVSEARCH_OPT;?>[usecron]" id="<?php echo SPEC_ADVSEARCH_OPT;?>_usecron"/>
								<em><?php  _e('If you have problems with wp_cron then untick this box. The create, delete and optimise buttons will now work but you should be aware that you may see PHP timeout errors and you will also have to optimise the wp_posts table manually. The timeout errors shouldn\'t be a problem as the db should continue the requested action.', SPEC_ADVSEARCH_DOM); ?></em>
							</td>
						</tr>

					</tbody>
					</table>
					<p class="submit"><input type="submit" class="button-primary" value="<?php _e('Save Changes', SPEC_ADVSEARCH_DOM) ?>" /></p>
					<div class="help-text widefat" style="border:1px #ccc solid;padding:5px;background-color:#FFFFFF;width:500px; height:250px; overflow:auto; margin-left:230px">
						<?php include(dirname(__FILE__) . '/includes/help.php'); ?>
					</div>

					<script type="text/javascript" language="JavaScript">
						//<![CDATA[
						function specFieldToggle(trigger, target) {
							if(typeof jQuery != "undefined"){
								jQuery(trigger).change(function(){
									if(jQuery(this).attr('checked')) {
										jQuery(target).css({color:'#000'}).find('input, select, textarea').attr({disabled:''});
									} else {
										jQuery(target).css({color:'#ccc'}).find('input, select, textarea').attr({disabled:'disabled'});
									}
								});

								 if ( jQuery(trigger).attr('checked')){
									jQuery(target).css({color:'#000'}).find('input, select, textarea').attr({disabled:''});
								} else {
									jQuery(target).css({color:'#ccc'}).find('input, select, textarea').attr({disabled:'disabled'});
								}
							}
						}

						function selectToggle(trigger, target, value) {
							if(typeof jQuery != "undefined"){
								jQuery('select' + trigger).change(function(){
									if(jQuery(this).attr('value').match(value)) {
										jQuery(target).css({color:'#000'}).find('input, select, textarea').attr({disabled:''});
									} else {
										jQuery(target).css({color:'#ccc'}).find('input, select, textarea').attr({disabled:'disabled'});
									}
								});

								if(jQuery('select' + trigger).attr('value').match(value)) {
									jQuery(target).css({color:'#000'}).find('input, select, textarea').attr({disabled:''});
								} else {
									jQuery(target).css({color:'#ccc'}).find('input, select, textarea').attr({disabled:'disabled'});
								}
							}
						}
						<?php
						$triggers = array();
						foreach($this->modes as $key => $mode) {
							if(version_compare($this->db->mysql_ver, $mode['ver'], 'ge') && $mode['rank'] === 'set')
								$triggers[] = $key;
						}
						// The last option of the selectToggle function is regEx. If there is nothing to trigger against it matches to all.
						printf("selectToggle('#%s_mode', '.%s_relevancy', '%s');\n", SPEC_ADVSEARCH_OPT, SPEC_ADVSEARCH_OPT, '^(' . implode('|', (array)$triggers) . ')$');
						printf("specFieldToggle('#%s_usecron', '.%s_auto');\n", SPEC_ADVSEARCH_OPT, SPEC_ADVSEARCH_OPT);
						printf("specFieldToggle('#%s_auto', '#%s_cron_group');\n", SPEC_ADVSEARCH_OPT, SPEC_ADVSEARCH_OPT);
						?>
						//]]>
					</script>
				</form>
			</div>

			<?php
		}
	}

	global $wp_version; // Stops WP throwing something to the output on activation.
	if (version_compare($wp_version, SPEC_ADVSEARCH_WPV, 'ge')){
		add_action('init', create_function('', 'return new spec_search_enhance();'));
	}
}

/*
 Cron elements are here. This is my stuff for making the wp_cron easier to deal
 with.
*/
if(!class_exists('spec_search_enhance_cron')){
	class spec_search_enhance_cron {

		var $schedules = array();
		var $default_schedule = 'daily';
		var $hook = 'spec_do_cron_job';

		function spec_search_enhance_cron() {
			$this->schedules = array(
				'days2' => array( 'interval' =>  172800, 'display' => __('Every 2 Days', SPEC_ADVSEARCH_DOM)),
				'weekly' => array( 'interval' => 604800, 'display' => __('Every week', SPEC_ADVSEARCH_DOM)),
				'week2' => array( 'interval' => 1209600, 'display' => __('Every fortnight', SPEC_ADVSEARCH_DOM)),
				'week4' => array( 'interval' => 2419200, 'display' => __('Every 28 days', SPEC_ADVSEARCH_DOM))
			);


			// First and last run functions here. Remove cron jobs on destruct.
			register_deactivation_hook(__FILE__, array(&$this, 'deactive'));
			add_filter('cron_schedules', array(&$this, 'add_intervals'));
			// Add the collector to be run at each scheduled time.
			add_action('spec_do_cron_job', array(&$this, 'do_cron_job'), 10, 1); // This can be added from any other class this one does nothing.
		}


		/*
		 Called when plug-in deactivated.
		 Remove cron jobs.

		 @ignore
		 @return null
		*/
		function deactive() {
			$this->clear_event_hooks();
		}


		/*
		 Called from wp_cron only.

		 @param $args array keys:
			schedule = wp_get_schedules key
		*/
		function do_cron_job($args = array()){
			// Do your stuff here.
		}


		/*
		 Simply add some new frequencies.
		 Called from filter cron_schedules.

		 @param array Wordpress passes in it's array of existing intervals.
		 @return array The array Wordpress passed in wit a few extra intervals.
		*/
		function add_intervals($schedules = array()){
			$schedules = array_merge((array)$schedules, $this->schedules);
			return $schedules;
		}


		/*
		 Add a new event to wordpress cron.

		 @param $array args Parameters to be passed to the called function.
			one key in the array should be called schedule and contain the key
			of the interval to be used.
		 @param int $time Timestamp.
		 @return null.
		*/
		function add_event_hook($args = array(), $time = 0){
			if (!$time)
				$time = (time() + (60 - (time() % 60))); // The next full min.


			if (isset($args['schedule'])) {
				$schedule = $args['schedule'];
			} elseif($args['schedule'] === false) {
				$schedule = false;
			} else {
				$schedule = $this->default_schedule;
			}

			if($schedule == false) {
				wp_schedule_single_event($time, $this->hook, array('args' => $args));
			} elseif (!wp_next_scheduled($this->hook, array('args' => $args)))
				wp_schedule_event($time, $schedule, $this->hook, array('args' => $args));

			return;

		}


		/*
		 Check the current status of the schedule and update if needed after
		 removing the old one.

		 @param array $old_args The arguments the cron job is currently running with.
		 @param array $new_args The arguments needed to replace the chron job.
		*/
		function update_event_hook($old_args = array(), $new_args = array()) {
			if (isset($new_args['schedule']))
				$schedule = $new_args['schedule'];
			else
				$schedule = $this->default_schedule;

			while ($old_args != $new_args && $timestamp = wp_next_scheduled( $this->hook, array('args' => $old_args )) ){
				wp_unschedule_event($timestamp, $this->hook, array('args' => $old_args));
				wp_schedule_event($timestamp, $schedule, $this->hook, array('args' => $new_args));
			}
		}


		/*
		 Replacement for the broken version of this function from
		 wp-includes/chron.php

		 @param array $args Array containing parameters that were to be passed.
		*/
		function clear_event_hook($args = array()){
			while ($timestamp = wp_next_scheduled( $this->hook, array('args' => $args )) ){
				wp_unschedule_event( $timestamp, $this->hook, array('args' => $args) );
			}
		}


		/*
		 Quick function to kill all cron events with our hook.

		 @return null
		*/
		function clear_event_hooks() {
			$crons = _get_cron_array();
			foreach($crons as $timestamp => $data) {

				if (is_array($data[$this->hook]))
					unset($crons[$timestamp][$this->hook]);

				// Wipe out any empty timestamps created above.
				if (empty($crons[$timestamp]))
					unset($crons[$timestamp]);
			}
			_set_cron_array( $crons );
		}


		/*
		 Get the timestamp of the next event with the args passed.

		 @param array $args Array containing parameters that were to be passed.
		 @return int Timestamp as number of seconds since the Unix Epoch.
		*/
		function get_next_scheduled($args = array()){
			$timestamp = wp_next_scheduled( $this->hook, array('args' => $args ));
			return $timestamp ? $timestamp : false;
		}


		/*
		 Get all event hooks, used for debug only at the moment.

		 @return array containing the arguments the timestamp and hook.
		*/
		function get_event_args(){
			$crons = _get_cron_array();
			$args = array();

			foreach($crons as $timestamp => $funcs){
				foreach((array)$funcs as $key => $func) {
					if ($key == $this->hook) {
						foreach($func as $test) {
							$test['args']['timestamp'] = $timestamp;
							$test['args']['hook'] = $key;
							$args[] = $test['args'];
						}

					}
				}
			}
			return $args;
		}
	}


	/*
	 Can't add a function to the schedule from inside a class so adding a simple
	 wrapper here.
	*/
	if (!function_exists('spec_do_cron_job')) {
		function spec_do_cron_job($args = array()){
			do_action('spec_do_cron_job', $args);
		}
	}
}



/*
 DB manuipulation wlements of this plug-in are contained within this class.
 Adds, deletes and optimises the index.

 @todo Get the status of the index creation.
 @todo Report on the size of the table to be indexed.
 @todo Report the index size.
*/

if(!class_exists('spec_search_enhance_db')) {
	class spec_search_enhance_db {
		var $mysql_ver;

		function spec_search_enhance_db() {
			global $wpdb;
			$this->mysql_ver = $wpdb->db_version(); // just to make the version number easier to access.
		}


		/* If we don't have an index create one. */
		function maybe_add_fulltext_index() {
			global $wpdb;
			if (!$this->have_index()) {
				$result = $wpdb->query('CREATE FULLTEXT INDEX ' . SPEC_ADVSEARCH_KEY . "_title ON {$wpdb->posts} (post_title);");
				$result = $wpdb->query('CREATE FULLTEXT INDEX ' . SPEC_ADVSEARCH_KEY . " ON {$wpdb->posts} (post_content);");
			}

			return $result;
		}


		/*
		 Do we have an index? Yeah or Neigh.
		*/
		function have_index() {
			global $wpdb;
			if (isset($this->has_index))
				return $this->has_index;

			$index = $wpdb->query("SHOW INDEX FROM $wpdb->posts WHERE key_name = '" . SPEC_ADVSEARCH_KEY . "';") && $wpdb->query("SHOW INDEX FROM $wpdb->posts WHERE key_name = '" . SPEC_ADVSEARCH_KEY . "_title';");

			$this->has_index = $index ? true : false;
			return $this->has_index;
		}


		/*
		 Return the status of the index if we have one.
		 @todo Not sure or if I can do this. Need to look into this.
		*/
		function index_status(){
			global $wpdb;
			$results = $wpdb->get_results("SHOW PROCESSLIST;");

			foreach($results as $result) {

				if(preg_match('/^CREATE\b.*' . preg_quote(SPEC_ADVSEARCH_KEY) . '\b.*\bpost_content\b/', $result->Info))
					return array('code' => 1, 'command' => $result->Info, 'state' => $result->State, 'message' => __('Creating index on post_content', SPEC_ADVSEARCH_DOM));

				if(preg_match('/^DROP\b.*' . preg_quote(SPEC_ADVSEARCH_KEY) . '\b.*/', $result->Info))
					return array('code' => 3, 'command' => $result->Info, 'state' => $result->State, 'message' => __('Deleting index on post_content', SPEC_ADVSEARCH_DOM));

				if(preg_match('/^CREATE\b.*' . preg_quote(SPEC_ADVSEARCH_KEY) . '\b.*\bpost_title\b/', $result->Info))
					return array('code' => 2, 'command' => $result->Info, 'state' => $result->State, 'message' => __('Creating index on post_title', SPEC_ADVSEARCH_DOM));

				if(preg_match('/^DROP\b.*' . preg_quote(SPEC_ADVSEARCH_KEY) . '_title\b.*/', $result->Info))
					return array('code' => 4, 'command' => $result->Info, 'state' => $result->State, 'message' => __('Deleting index on post_title', SPEC_ADVSEARCH_DOM));
			}

			return false;
		}


		/*
		 This will update the the index to include any new posts. Not really
		 very much to this. Just run the query then return.
		*/
		function optimise_table(){
			global $wpdb;
			$result = $wpdb->get_row("OPTIMIZE TABLE {$wpdb->posts};");

			return $result;
		}


		/*
		 Nothing much to say about this other that it drops the index.
		*/
		function drop_index(){
			global $wpdb;
			if ($this->have_index()) {
				$result = $wpdb->query('DROP INDEX ' . SPEC_ADVSEARCH_KEY . " ON $wpdb->posts;");
				$result = $wpdb->query('DROP INDEX ' . SPEC_ADVSEARCH_KEY . "_title ON $wpdb->posts;");
			}

			return $result;
		}

	}
}

if (!function_exists('esc_sql')) {
	/*
	 A blatant copy of the WP function for use with wp27.
	*/
	function esc_sql( $sql ) {
		global $wpdb;
		return $wpdb->escape( $sql );
	}
}?>
