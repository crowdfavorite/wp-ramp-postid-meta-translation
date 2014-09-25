<?php
/*
Plugin Name: RAMP Post ID Meta Translation
Plugin URI: http://crowdfavorite.com
Description: Adds the ability to select which post meta fields represent a post mapping and adds them to the batch
Version: 1.1.0
Author: Crowd Favorite
Author URI: http://crowdfavorite.com
*/

/*
 * Copyright (c) 2012-2013 Crowd Favorite, Ltd. All rights reserved.
 * http://crowdfavorite.com
 *
 * **********************************************************************
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * **********************************************************************
 */

load_plugin_textdomain('ramp-mm');

function ramp_mm_keys( $parse = true ) {
	$keys = array();
	$patterns = get_option( 'ramp_mm_keys', array() );
	if ( ! $parse ) {
		return $patterns;
	}
	if ( ! is_array( $patterns ) ) {
		$patterns = array();
	}

	$distinct_keys = ramp_mm_distinct_keys(); // Get keys which are in the database

	foreach ( $patterns as $pattern ) {
		if ( in_array( $pattern, $distinct_keys ) ) {
			$keys[] = $pattern;
			continue;
		}

		// Need a way to distinguish regex pattern, assume anything starting with / is regex
		if ( '/' != substr( $pattern, 0, 1 ) ) {
			// Escape pattern for regexifying, no one should have escapable characters
			// in their meta_key, but just in case
			$pattern = preg_quote( $pattern );
			// Wildcards are now escaped hence the \{ and \}
			$pattern = str_replace( array( '\{%d\}', '\{%s\}' ), array( '[0-9]+', '[^0-9_-]+' ), $pattern );
			$pattern = '/' . $pattern . '/';
		}

		foreach ( $distinct_keys as $keyval ) {
			preg_match_all( $pattern, $keyval, $matches );
			if ( is_array( $matches ) && !empty( $matches ) ) {
				$keys = array_merge( $keys, $matches[0] );
			}
		}

	}

	return array_unique( $keys );
}

function ramp_mm_init() {
	register_setting('cf-deploy-settings', 'ramp_mm_keys', 'ramp_mm_validate');
	foreach (ramp_mm_keys() as $key) {
		cfr_register_metadata($key);
	}
}
add_action('admin_init', 'ramp_mm_init');

function ramp_mm_validate($settings) {
	$excluded_keys = ramp_mm_excluded_keys();
	foreach ($settings as $key => $setting) {
		if (in_array($setting, $excluded_keys)) {
			unset($settings[$key]);
		}
	}
	return $settings;
}

function ramp_mm_excluded_keys() {
	return apply_filters(
		'ramp_mm_excluded_keys',
		array(
			'_cfct_build_data',
			'_edit_last',
			'_edit_lock',
			'_format_audio_embed',
			'_format_gallery',
			'_format_image',
			'_format_link_url',
			'_format_quote_source_name',
			'_format_quote_source_url',
			'_format_url',
			'_format_video_embed',
			'_menu_item_classes',
			'_menu_item_menu_item_parent',
			'_menu_item_object',
			'_menu_item_object_id',
			'_menu_item_orphaned',
			'_menu_item_target',
			'_menu_item_type',
			'_menu_item_url',
			'_menu_item_xfn',
			'_post_restored_from',
			'_thumbnail_id',
			'_wp_attached_file',
			'_wp_attachment_metadata',
			'_wp_page_template',
			'_batch_deploy_messages',
			'_batch_destination',
			'_batch_export_complete',
			'_batch_export_failed',
			'_batch_import_complete',
			'_batch_id',
			'_batch_import_messages',
			'_batch_send_user',
			'_batch_session_token',
			'_batch_source',
			'_preflight_data',
			'_ramp_mm_comp_data',
		)
	);
}

function ramp_mm_admin_form($obj) {
	$options = '';
	$keys = array_diff(ramp_mm_available_keys(), ramp_mm_excluded_keys());
	$count = count($keys);
	$selected = ramp_mm_keys( false );
	if ($count) {
		$i = 0;
		foreach ($keys as $key) {
			if ($count > 10) {
				if ($i > 0 && $i % ceil($count / 3) == 0) {
					$options .= '</ul><ul>';
				}
			}
			$i++;
			$id = 'ramp_mm_keys-'.$key;
			$checked = (in_array($key, $selected) ? ' checked="checked"' : '');
			$options .= '
		<li>
			<input type="checkbox" name="ramp_mm_keys[]" value="'.esc_attr($key).'" id="'.esc_attr($id).'"'.$checked.' />
			<label for="'.esc_attr($id).'">'.esc_html($key).'</label>
		</li>
			';
		}
?>
<style>
#ramp-mm-keys ul {
	float: left;
	width: 33%;
}
</style>
<?php
	}
	else {
		$options = '<li>'.__('No custom fields found.', 'ramp-mm').'</li>';
	}
?>
<div class="form-section" id="ramp-mm-keys">
	<fieldset>
		<legend><?php _e('Custom Fields', 'ramp-mm'); ?></legend>
		<p class="cf-elm-help"><?php _e('Select any custom fields that represent a post id to be translated when a batch is sent.', 'ramp-mm'); ?></p>
		<div class="cf-elm-block cf-elm-width-full">
<?php
	echo '<ul>'.$options.'</ul>';
?>
			<div class="cf-clearfix"></div>
		</div>
	</fieldset>
</div>
<?php
}
add_action('cf_deploy_admin_form', 'ramp_mm_admin_form');

function ramp_mm_available_keys() {
	$keys = ramp_mm_distinct_keys();
	if ( ! is_array( $keys ) ) {
		$keys = array();
	}
	return apply_filters( 'ramp_mm_available_keys', $keys );
}

function ramp_mm_distinct_keys() {
	global $wpdb;
	return $wpdb->get_col( "
		SELECT DISTINCT meta_key
		FROM $wpdb->postmeta
		ORDER BY meta_key
	" );
}

function ramp_mm_cfd_init() {
	$ramp_meta = RAMP_Meta_Mappings::factory();
	$ramp_meta->add_actions();
}
add_action('cfd_admin_init', 'ramp_mm_cfd_init');

class RAMP_Meta_Mappings {
	var $existing_ids = array(); // Ids already processed or in the batch
	var $added_posts = array(); // New Posts added to the batch
	var $batch_posts = array(); // Posts that are a part of the batch
	var $data = array();
	var $client_server_post_mappings = array(); // Store the client id => server id mapping
	var $comparison_data = array();
	var $comparison_key = '_ramp_mm_comp_data';
	var $history_key = '_ramp_mm_history_data';
	var $name = 'RAMP Meta Mappings';

	static $instance;

	// Singleton
	public function factory() {
		if (!isset(self::$instance)) {
			self::$instance = new RAMP_Meta_Mappings;
		}
		return self::$instance;
	}

	function __construct() {
		$this->meta_keys_to_map = ramp_mm_keys();
		$this->extras_id = cfd_make_callback_id('ramp_mm_keys');
	}

	function add_actions() {
	// Client Actions
		// Need comparison data on preflight page
		add_action('ramp_preflight_batch', array($this, 'fetch_comparison_data'));

		// This runs after the two actions above, comparison data is stored in $this->post_types_compare
		// Adds additional posts to the batch based on meta data
		add_action('ramp_pre_get_deploy_data', array($this, 'pre_get_deploy_data'));

		// Modifies the object that the client gets locally to compare with the server post
		add_filter('ramp_get_comparison_data_post', array($this, 'get_comparison_data_post'));

		// Modified the meta just before sending it to the server
		add_filter('ramp_get_deploy_object', array($this, 'get_deploy_object'), 10, 2);

		// Extras handling
		add_action('ramp_extras_preflight_name', array($this, 'extras_preflight_name'), 10, 2);
		add_filter('ramp_get_comparison_extras', array($this, 'get_extras_filter'));
		add_filter('ramp_get_preflight_extras', array($this, 'get_extras_filter'));
		add_filter('ramp_get_deploy_extras', array($this, 'get_deploy_extras'), 10, 2);
		add_filter('ramp_add_extras_comparison_data', array($this, 'get_extras_filter'));
		add_action('ramp_do_batch_extra_send', array($this, 'do_batch_extra_send'), 10, 2);

		// Cleanup
		add_filter('ramp_close_batch_send', array($this, 'close_batch_send'));

		add_filter('ramp_history_data', array($this, 'history_data'), 10, 2);

	// Server actions
		// Preps the data with the meta keys to map
		add_filter('ramp_compare_extras', array($this, 'compare_extras'), 10, 2);

		// Modifies server return data. Updates post meta to be guids of posts on the server
		add_filter('ramp_compare', array($this, 'compare'));

		// Adds additional messages to the preflight display
		add_filter('ramp_preflight_post', array($this, 'preflight_post'), 10, 3);

		// Handles the remapping
		add_action('ramp_do_batch_extra_receive', array($this, 'do_batch_extra_receive'), 10, 3);

	}

// Client functions
	/**
	 * Runs on Client
	 * Fetch comparison data on preflight and save in the meta
	 * Allows a consistent set of comparison data to be accessed on the send page
	 **/
	function fetch_comparison_data($admin_deploy) {
		$admin_deploy->batch->init_comparison_data();
		$admin_deploy->batch->add_extras_comparison_data($admin_deploy->get_comparison_extras());
		$admin_deploy->do_server_comparison($admin_deploy->batch);

		// Now the batch has populated c_data
		$data = $admin_deploy->batch->get_comparison_data('post_types');

		$this->save_comparison_data($admin_deploy->batch->ID, $data);
	}

	/**
	 * Save comparison data in a compact way on the batch post
	 **/
	function save_comparison_data($batch_id, $data) {
		$post_guids = array();
		foreach ($data as $post_type => $posts) {
			$post_guids = array_merge($post_guids, array_keys($posts));
		}

		update_post_meta($batch_id, $this->comparison_key, $post_guids);
	}

	/**
	 * Loads comparison data that happened on preflight
	 **/
	function load_comparison_data($batch_id) {
		$this->comparison_data = (array) get_post_meta($batch_id, $this->comparison_key, true);
	}

	/**
	 * Deletes preflight comparison data
	 **/
	function delete_comparison_data($batch_id) {
		delete_post_meta($batch_id, $this->comparison_key);
		$this->comparison_data = array();
	}

	/**
	 * Runs on client
	 * Used for displaying extra row name on preflight
	 **/
	function extras_preflight_name($name, $extra_id) {
		if ($extra_id == $this->extras_id) {
			return $this->name;
		}
		return $name;
	}

	/**
	 * Runs on client
	 *
	 * Modifies the post the client grabs to compare with the server post
	 * Replaces the post's meta mapped keys with the guids
	 **/
	function get_comparison_data_post($post) {
		$post_meta_keys = $post->profile['meta'];
		if (is_array($post_meta_keys)) {
			foreach ($post_meta_keys as $meta_key => $meta_value) {
				if (in_array($meta_key, $this->meta_keys_to_map)) {
					$guid = cfd_get_post_guid($meta_value);
					if ($guid) {
						$post->profile['meta'][$meta_key] = $guid;
					}
				}
			}
		}
		return $post;
	}

	/**
	 * Runs on client
	 * Modifies a post object's meta with the guid where appropriate
	 * This occurs just before sending data to the server
	 **/
	function get_deploy_object($object, $object_type) {
		if ($object_type == 'post_types') {
			if (isset($object['meta']) && is_array($object['meta'])) {
				foreach ($object['meta'] as $meta_key => $meta_value) {
					if (in_array($meta_key, $this->meta_keys_to_map) && is_numeric($meta_value)) {
						$guid = cfd_get_post_guid($meta_value);
						if ($guid) {
							$object['meta'][$meta_key] = $guid;
						}
					}
				}
			}
		}
		return $object;
	}

	/**
	 * Runs on client
	 * Process a post so any of the mapped meta keys also get processed in the batch
	 *
	 * @param int $post_id ID of a post to map
	 * @param bool $add_guid whether or not to add this guid to the set of batch posts. Prevents loading the same posts into memory
	 **/
	function process_post($post_id, $add_guid = true) {
		if ($add_guid) {
			$this->batch_posts[] = cfd_get_post_guid($post_id);
		}
		$meta = get_metadata('post', $post_id);
		if (is_array($meta)) {
			foreach ($meta as $meta_key => $meta_values) {
				// $meta_values should always be an array
				if (is_array($meta_values)) {
					foreach ($meta_values as $meta_value) {
						if (in_array($meta_key, $this->meta_keys_to_map) && (int)$meta_value > 0) {
							// Check existance
							$new_post = get_post($meta_value);
							if (
								$new_post // Post exists check
								&& !in_array($new_post->ID, $this->existing_ids) // Post isnt already in the batch
								&& in_array($new_post->guid, $this->comparison_data)// Post is modified
							) {
								if (!is_array($this->data['post_types'][$new_post->post_type])) {
									$this->data['post_types'][$new_post->post_type] = array();
								}
								$this->data['post_types'][$new_post->post_type][] = $new_post->ID;
								$this->existing_ids[] = $new_post->ID;
								// Use for processes and notices
								$this->added_posts[$new_post->ID] = array(
														'post_title' => $new_post->post_title,
														'post_type' => $new_post->post_type,
														'guid' => $new_post->guid
													);
								$this->batch_posts[] = $new_post->guid;
								$this->process_post($new_post->ID, false);
							}
						}
					}
				}
			}
		}
	}

	// Helper for displaying the meta keys
	function meta_to_markup($meta_keys) {
		return '<code>'.implode('</code>, <code>', $meta_keys).'</code>';
	}

	/**
	 * Runs on the client
	 * Add extra meta data to pass from client to server
	 **/
	function get_extras($extras, $type = 'default') {
		if ($type == 'history') {
			$batch_id = $_GET['batch'];
			$meta = get_post_meta($batch_id, $this->history_key, true);
			$meta_keys = isset($meta['meta_keys']) ? $meta['meta_keys'] : array();
		}
		else {
			$meta_keys = $this->meta_keys_to_map;
		}
		$extras[$this->extras_id] = array(
			'meta_keys' => $meta_keys, // The keys we're mapping (or were mapped)
			'mapped_posts' => $this->added_posts, // All posts which have been added to the batch by this plugin
			'batch_posts' => $this->batch_posts, // All posts being sent in the batch
			'name' => __('Meta Mappings', 'ramp-mm'),
			'description' => sprintf(__('Key mappings: %s', 'ramp-mm'), $this->meta_to_markup($meta_keys)),
			'__message__' => sprintf(__('Keys to be remapped: %s', 'ramp-mm'), $this->meta_to_markup($meta_keys)),
		);
		return $extras;
	}

	function get_deploy_extras($extras, $type) {
		return $this->get_extras($extras, $type);
	}

	function get_extras_filter($extras) {
		return $this->get_extras($extras, 'default');
	}

	/**
	 * Runs on the client
	 * Add extra data to send data via filter instead of callback
	 **/
	function do_batch_extra_send($extra, $id) {
		if ($id == $this->extras_id) {
			$extras = $this->get_extras(array(), 'default');
			$extra = $extras[$this->extras_id];
		}
		return $extra;
	}

	/**
	 * Runs on the client
	 * Loads additional posts into the deploy data based on meta values
	 *
	 **/
	function pre_get_deploy_data($batch) {
		$this->load_comparison_data($batch->ID);
		// We get a reference to the object but not arrays
		$this->data = $batch->data;
		$existing_ids = array();
		if (isset($this->data['post_types']) && is_array($this->data['post_types'])) {
			foreach ($this->data['post_types'] as $post_type => $post_ids) {
				foreach ($post_ids as $post_id) {
					$this->existing_ids[] = $post_id;
				}
			}

			foreach ($this->data['post_types'] as $post_type => $post_ids) {
				foreach ($post_ids as $post_id) {
					$this->process_post($post_id);
				}
			}
		}
		// So the server knows which ones are added, also for display
		if (isset($this->data['extras'])) {
			$this->data['extras'] = $this->get_extras($this->data['extras']);
		}
		else {
			$this->data['extras'] = array();
		}

		$batch->data = $this->data;
	}


	/**
	 * Runs on Client after sending a batch
	 * Store history data
	 * Cleanup meta that was saved to the batch post
	 **/
	function close_batch_send($args) {
		$batch_id = $args['batch_id'];
		$batch = new cfd_batch(array('ID' => intval($batch_id)));

		// Save this data for the history view without modifying RAMP data
		$this->pre_get_deploy_data($batch);
		$history_data = array(
			'meta_keys' => $this->meta_keys_to_map,
			'posts' => $this->added_posts,
		);
		update_post_meta($batch_id, $this->history_key, $history_data);
		// Cleanup
		$this->delete_comparison_data($batch_id);
	}

	/**
	 * Runs on client
	 *
	 * Displays history data when viewing a batch history
	 * Includes posts added to the batch by the plugin
	 **/
	function history_data($data, $batch_id) {
		$rm_data = get_post_meta($batch_id, $this->history_key, true);
		if (is_array($rm_data)) {
			foreach ($rm_data['posts'] as $post_id => $post_data) {
				$post_type = $post_data['post_type'];
				if (!in_array($post_data['guid'], (array)$data['post_types'][$post_type])) {
					$data['post_types'][$post_type][$post_data['guid']] = array(
						'post' => array(
							'ID' => $post_id,
							'post_title' => $post_data['post_title'],
							'post_type' => $post_data['post_type'],
						),
					);
				}
			}
		}

		return $data;
	}

// Server functions

	/**
	 * Runs on Server
	 *
	 * Updates any post meta ids with a guid of the post its mapped to
	 * Occurs before sending post difference back to the client
	 **/
	function compare($c_data) {
		$meta_keys_to_map = $c_data['extras'][ $this->extras_id ]['meta_keys']['status'];
		foreach ( $c_data['post_types'] as $post_type => $posts ) {
			foreach ( $posts as $post_guid => $post_data ) {
				// Make sure that the return data is what we want and not something else like an error
				if ( isset( $post_data['profile']['meta'] ) ) {
					$post_meta = $post_data['profile']['meta'];
					if ( is_array( $post_meta ) ) {
						foreach ( $post_meta as $meta_key => $meta_value ) {
							if ( in_array( $meta_key, $meta_keys_to_map ) && is_numeric( $meta_value ) ) {
								// Get guid and set it as that!
								$guid = cfd_get_post_guid( $meta_value );
								if ( $guid ) {
									$c_data['post_types'][ $post_type ][ $post_guid ]['profile']['meta'][ $meta_key ] = $guid;
								}
							}
						}
					}
				}
			}
		}
		// This gets returned to the client
		return $c_data;
	}

	/**
	 * Runs on server
	 * So compare($c_data) knows the meta_keys to lookup
	 * Adds in extra data via a filter instead of callback
	 **/
	function compare_extras($ret, $extras) {
		if (!isset($ret[$this->extras_id])) {
			$ret[$this->extras_id] = $extras[$this->extras_id];
		}
		return $ret;
	}

	/**
	 * Runs on server
	 * Processes data throws notices for RAMP Meta added items
	 **/
	function preflight_post($ret, $post, $batch_items) {
		if (!empty($batch_items['extras'][$this->extras_id]['mapped_posts'])) {
			$meta_added = $batch_items['extras'][$this->extras_id]['mapped_posts'];
			$mapped_keys = $batch_items['extras'][$this->extras_id]['meta_keys'];

			// Show notice that this post was not originally in the batch, but added by RAMP Meta
			if (in_array($post['post']['ID'], array_keys($meta_added))) {
				$ret['__notice__'][] =  __('This post was added by the RAMP Meta Plugin.', 'ramp-mm');
			}

			// Show notice on post of what items the meta maps to
			if (isset($post['meta']) && is_array($post['meta'])) {
				foreach ($post['meta'] as $meta_key => $meta_value) {
					if (in_array($meta_value, array_keys($meta_added)) && in_array($meta_key, $mapped_keys)) {
						$guid = $meta_added[$meta_value]['guid'];
						$post_type = $meta_added[$meta_value]['post_type'];

						// Need to ensure that the post is still there, throw an error if its not
						if (isset($batch_items['post_types'][$post_type][$guid])) {
							$ret['__notice__'][] =  sprintf(__('%s "%s" was found mapped in the post meta and has been added to the batch.', 'ramp-mm'), $meta_added[$meta_value]['post_type'], $meta_added[$meta_value]['post_title']);
						}
						else {
							$ret['__error__'][] =  sprintf(__('%s "%s" was mapped by the RAMP Meta plugin but not found in the batch.', 'ramp-mm'), $meta_added[$meta_value]['post_type'], $meta_added[$meta_value]['post_title']);
						}
					}
				}
			}
		}

		return $ret;
	}

	/**
	 * Runs on server
	 * Recieves a list of guids that have been mapped locally
	 * Recieves a list of meta_keys that need to be remapped (they are currently set as guids)
	 *
	 * This is always run last in the batch send process
	 **/
	function do_batch_extra_receive($extra_data, $extra_id, $batch_args) {
		if ($extra_id == $this->extras_id) {

			$batch_guids = (array) array_unique($batch_args['batch_posts']);
			$mapped_keys = $batch_args['meta_keys'];

			// Loop through list of guids sent in the batch
			foreach ($batch_guids as $guid) {
				$server_post = cfd_get_post_by_guid($guid);
				if ($server_post) {
					$meta = get_metadata('post', $server_post->ID);
					if (is_array($meta)) {
						// Loop through server post meta checking meta keys that should be mapped
						foreach ($meta as $meta_key => $meta_values) {
							foreach ($meta_values as $meta_value) {
								if (in_array($meta_key, $mapped_keys) && !is_numeric($meta_value)) {
									$mapped_server_post = cfd_get_post_by_guid($meta_value);
									if ($mapped_server_post) {
										update_post_meta($server_post->ID, $meta_key, $mapped_server_post->ID, $meta_value);
									}
								}
							}
						}
					}
				}
			}
		}
	}
}
