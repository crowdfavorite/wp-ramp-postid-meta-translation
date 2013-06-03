<?php
/*
Plugin Name: RAMP Custom Fields
Plugin URI: http://crowdfavorite.com
Description: Adds the ability to select which custom fields represent a post mapping and adds them to the batch
Version: 1.0
Author: Crowd Favorite
Author URI: http://crowdfavorite.com 
*/

/*
 * Copyright (c) 2012 Crowd Favorite, Ltd. All rights reserved.
 * http://crowdfavorite.com
 *
 * **********************************************************************
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. 
 * **********************************************************************
 */

function ramp_meta_keys() {
	return (array) get_option('ramp_meta_keys');
}

function ramp_meta_init() {
	register_setting('cf-deploy-settings', 'ramp_meta_keys', 'ramp_meta_validate');
	foreach (ramp_meta_keys() as $key) {
		cfr_register_metadata($key);
	}
}
add_action('admin_init', 'ramp_meta_init');

function ramp_meta_validate($settings) {
// TODO
	return $settings;
}

function ramp_meta_excluded_keys() {
	return apply_filters(
		'ramp_meta_excluded_keys',
		array(
			'_cfct_build_data',
			'_edit_last',
			'_edit_lock',
			'_menu_item_classes',
			'_menu_item_menu_item_parent',
			'_menu_item_object',
			'_menu_item_object_id',
			'_menu_item_orphaned',
			'_menu_item_target',
			'_menu_item_type',
			'_menu_item_url',
			'_menu_item_xfn',
			'_thumbnail_id',
			'_wp_attached_file',
			'_wp_attachment_metadata',
			'_wp_page_template',
		)
	);
}

function ramp_meta_admin_form($obj) {
	$options = '';
	$keys = array_diff(ramp_meta_available_keys(), ramp_meta_excluded_keys());
	$count = count($keys);
	$selected = ramp_meta_keys();
	if ($count) {
		$i = 0;
		foreach ($keys as $key) {
			if ($count > 10) {
				if ($i > 0 && $i % ceil($count / 3) == 0) {
					$options .= '</ul><ul>';
				}
			}
			$i++;
			$id = 'ramp_meta_keys-'.$key;
			$checked = (in_array($key, $selected) ? ' checked="checked"' : '');
			$options .= '
		<li>
			<input type="checkbox" name="ramp_meta_keys[]" value="'.esc_attr($key).'" id="'.esc_attr($id).'"'.$checked.' />
			<label for="'.esc_attr($id).'">'.esc_html($key).'</label>
		</li>
			';
		}
?>
<style>
#ramp-meta-keys ul {
	float: left;
	width: 33%;
}
</style>
<?php
	}
	else {
		$options = '<li>'.__('No custom fields found.', 'ramp-custom-fields').'</li>';
	}
?>
<div class="form-section" id="ramp-meta-keys">
	<fieldset>
		<legend><?php _e('Custom Fields', 'ramp-custom-fields'); ?></legend>
		<p class="cf-elm-help"><?php _e('Select any custom fields to be included with your posts and pages. Note that <b>no ID translation is done for these fields</b>, they are transferred over as-is. If you need to manipulate the data (update post ids, etc.) as part of the RAMP process, please refer to the <a href="http://crowdfavorite.com/wordpress/ramp/docs/">RAMP documentation</a> for help creating an appropriate plugin.', 'ramp-custom-fields'); ?></p>
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
add_action('cf_deploy_admin_form', 'ramp_meta_admin_form');

function ramp_meta_available_keys() {
	global $wpdb;
	return $wpdb->get_col("
		SELECT DISTINCT meta_key
		FROM $wpdb->postmeta
		ORDER BY meta_key
	");
}

function ramp_meta_cfd_init() {
	$rpe_deploy_callbacks = new Ramp_Meta;
	$rpe_deploy_callbacks->add_actions();
}
add_action('cfd_admin_init', 'ramp_meta_cfd_init');

class Ramp_Meta {
	var $existing_ids = array(); // Ids already processed or in the batch
	var $added_posts = array(); // New Posts added to the batch
	var $data = array();
	
	function __construct() {
		$this->meta_keys_to_map = ramp_meta_keys();
	}

	function add_actions() {
		add_action('ramp_pre_get_deploy_data', array($this, 'pre_get_deploy_data'));
	}

	/**
	 * Process a post so any of the mapped meta keys also get processed in the batch
	 * 
	 * @param int $post_id ID of a post to map
	 **/
	function process_post($post_id) {
		$meta = get_metadata('post', $post_id);
		if (is_array($meta)) {
			foreach ($meta as $meta_key => $meta_values) {
				// $meta_values should always be an array
				if (is_array($meta_values)) {
					foreach ($meta_values as $meta_value) {
						if (in_array($meta_key, $this->meta_keys_to_map) && (int)$meta_value > 0) {
							// Check existance
							$new_post = get_post($meta_value);
							// Dont process if the post is already in the batch it will be or has been processed
							if ($new_post && !in_array($new_post->ID, $this->existing_ids)) {
								if (!is_array($this->data['post_types'][$new_post->post_type])) {
									$this->data['post_types'][$new_post->post_type] = array();
								}
								$this->data['post_types'][$new_post->post_type][] = $new_post->ID;
								$this->existing_ids[] = $new_post->ID;
								$this->added_posts[] = $new_post;
								$this->process_post($new_post->ID);
							}
						}
					}
				}
			}
		}
	}


	function pre_get_deploy_data($batch) {
		// We get a reference to the object but not arrays
		$this->data = $batch->data;
		$existing_ids = array();
		if (is_array($this->data['post_types'])) {
			foreach ($this->data['post_types'] as $post_type => $post_ids) {
				foreach ($post_ids as $post_id) {
					$this->existing_ids[] = $post_id;
				}
			}

			foreach ($this->data['post_types'] as $post_type => $post_ids) {
				$this->process_post($post_id);
			}
		}

		$batch->data = $this->data;
	}
}