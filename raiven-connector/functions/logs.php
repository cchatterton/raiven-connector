<?php
/**
 * rAIven Connector - Session post type, transcript storage, Meta profile JSON and memory lookup.
 */

if (!defined('ABSPATH')) exit;

if (!defined('AS329_RAI_META_PROFILE_KEY')) {
	define('AS329_RAI_META_PROFILE_KEY', '_as329_rai_meta_profile_json');
}

if (!defined('AS329_RAI_META_PROFILE_UPDATED_KEY')) {
	define('AS329_RAI_META_PROFILE_UPDATED_KEY', '_as329_rai_meta_profile_updated');
}

add_action('init', 'as329_rai_register_post_type');

function as329_rai_register_post_type() {
	register_post_type(
		AS329_RAI_POST_TYPE,
		array(
			'labels' => array(
				'name'          => 'rAIven',
				'singular_name' => 'rAIven Session',
				'add_new_item'  => 'Add rAIven Session',
				'edit_item'     => 'Edit rAIven Session',
				'view_item'     => 'View rAIven Session',
				'search_items'  => 'Search rAIven Sessions',
			),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'menu_icon'           => 'dashicons-format-chat',
			'supports'            => array('title'),
            'capabilities' => array_fill_keys(array('edit_post','read_post','delete_post','edit_posts','edit_others_posts','publish_posts','read_private_posts','delete_posts','delete_private_posts','delete_published_posts','delete_others_posts','edit_private_posts','edit_published_posts','create_posts'), 'manage_options'),
			'capability_type'     => 'post',
			'map_meta_cap'        => false,
			'exclude_from_search' => true,
			'show_in_rest'        => false,
		)
	);
}


// ==========================
// META BOX
// ==========================

add_action('add_meta_boxes', 'as329_rai_add_meta_profile_metabox');

function as329_rai_add_meta_profile_metabox() {
	add_meta_box(
		'as329-rai-meta-profile',
		'Meta Profile JSON',
		'as329_rai_render_meta_profile_metabox',
		AS329_RAI_POST_TYPE,
		'normal',
		'default'
	);
}

function as329_rai_render_meta_profile_metabox($post) {
	$json = get_post_meta($post->ID, AS329_RAI_META_PROFILE_KEY, true);
	$updated = get_post_meta($post->ID, AS329_RAI_META_PROFILE_UPDATED_KEY, true);

	if (!$json) {
		$json = wp_json_encode(
			as329_rai_empty_meta_profile(),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	?>
	<p>This JSON is generated in the background from the saved chat transcript when memory is enabled.</p>
    <?php $profile_error = get_post_meta($post->ID, '_as329_rai_profile_error', true); ?>
    <?php if ($profile_error) : ?><p role="status"><?php echo esc_html($profile_error); ?> The previous index was kept.</p><?php endif; ?>

	<?php if ($updated): ?>
		<p><strong>Last generated:</strong> <?php echo esc_html($updated); ?></p>
	<?php endif; ?>

	<textarea
		readonly
		aria-label="Meta profile JSON" class="large-text code" rows="16"
	><?php echo esc_textarea($json); ?></textarea>
	<?php
}


// ==========================
// SESSION STORAGE
// ==========================

function as329_rai_create_session() {
	$session_id = wp_insert_post(
		array(
			'post_type'    => AS329_RAI_POST_TYPE,
			'post_status'  => 'private',
			'post_title'   => 'rAIven Session - ' . current_time('Y-m-d H:i:s'),
			'post_content' => '',
			'post_author'  => get_current_user_id(),
		),
		true
	);

	if (is_wp_error($session_id)) {
		return $session_id;
	}

	update_post_meta($session_id, '_as329_rai_messages', array());
	update_post_meta(
		$session_id,
		AS329_RAI_META_PROFILE_KEY,
		wp_json_encode(
			as329_rai_empty_meta_profile(),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		)
	);

	return $session_id;
}

function as329_rai_get_session_messages($session_id) {
	$messages = get_post_meta((int) $session_id, '_as329_rai_messages', true);

	return is_array($messages) ? $messages : array();
}

function as329_rai_save_session_messages($session_id, $messages) {


	$transcript = '';

	foreach ($messages as $message) {
		$role = isset($message['role']) ? strtoupper((string) $message['role']) : 'MESSAGE';
		$timestamp = isset($message['timestamp']) ? (string) $message['timestamp'] : '';
		$content = isset($message['content']) ? (string) $message['content'] : '';

		$transcript .= '## ' . $role;

		if ($timestamp !== '') {
			$transcript .= ' - ' . $timestamp;
		}

		$transcript .= "\n\n" . $content . "\n\n";
	}

	$saved = wp_update_post(
		array(
			'ID'           => (int) $session_id,
			'post_content' => wp_slash($transcript),
		), true
	);
    if (is_wp_error($saved)) { return new WP_Error('raiven_storage', __('The transcript could not be saved. Please try again.', 'raiven-connector')); }
    update_post_meta((int) $session_id, '_as329_rai_messages', wp_slash($messages));
    if (as329_rai_get_session_messages($session_id) !== $messages) { return new WP_Error('raiven_storage', __('The message history could not be saved. Please try again.', 'raiven-connector')); }

	if (!empty(as329_rai_get_settings()['memory_enabled'])) {
        if (!wp_next_scheduled('as329_rai_index_session', array((int) $session_id))) {
            wp_schedule_single_event(time() + 5, 'as329_rai_index_session', array((int) $session_id));
        }
    }
}


// ==========================
// META PROFILE GENERATION
// ==========================

function as329_rai_empty_meta_profile() {
	return array(
		'people'   => array(),
		'business' => array(),
		'projects' => array(),
		'systems'  => array(),
	);
}

function as329_rai_update_meta_profile_json($session_id, $transcript) {
	$transcript = trim((string) $transcript);

	if ($transcript === '') {
		return;
	}

	if (!function_exists('as329_rai_direct_chat_completion')) {
		return;
	}

	$response = as329_rai_generate_meta_profile_json($transcript);

	if (is_wp_error($response)) {
        update_post_meta($session_id, '_as329_rai_profile_error', $response->get_error_message());
        return;
    }
    delete_post_meta($session_id, '_as329_rai_profile_error');

	update_post_meta($session_id, AS329_RAI_META_PROFILE_KEY, wp_slash($response));
	update_post_meta($session_id, AS329_RAI_META_PROFILE_UPDATED_KEY, current_time('mysql'));
}

function as329_rai_generate_meta_profile_json($transcript) {
	$prompt = 'Create a Meta profile JSON index for this rAIven chat transcript.

Extract only information clearly present in the transcript.

Return ONLY valid JSON.
Do not include markdown.
Do not wrap the JSON in code fences.
Do not invent details.

Use this exact structure:

{
  "people": [],
  "business": [],
  "projects": [],
  "systems": []
}

Rules:
- return arrays of strings only
- people are named individuals mentioned in the chat
- business means organisations, clients, internal businesses, business units, or named operating groups
- projects are named initiatives, programs, workstreams, builds, products, plugins, or engagements
- systems are platforms, applications, tools, websites, plugins, integrations, or technical systems
- use canonical names where obvious
- do not add descriptions, aliases, relationships, explanations, confidence scores, or context
- use empty arrays where nothing is found

Chat transcript:

' . $transcript;

	$messages = array(
		array(
			'role'    => 'system',
			'content' => 'You extract simple structured Meta profile JSON from chat transcripts.'
		),
		array(
			'role'    => 'user',
			'content' => $prompt
		),
	);

	$response = as329_rai_direct_chat_completion($messages, 'memory');

	if (is_wp_error($response)) {
		return $response;
	}

	$json_text = as329_rai_clean_meta_profile_json((string) $response);
	$decoded = json_decode($json_text, true);

	if (!is_array($decoded) || array_diff(array('people','business','projects','systems'), array_keys($decoded))) {
        return new WP_Error('raiven_profile', __('The memory index could not be updated. The previous index was kept.', 'raiven-connector'));
    }

	$decoded = as329_rai_normalise_meta_profile_json($decoded);

	return wp_json_encode(
		$decoded,
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	);
}

function as329_rai_clean_meta_profile_json($text) {
	$text = trim((string) $text);

	if (strpos($text, '```') === 0) {
		$text = preg_replace('/^```json\s*/', '', $text);
		$text = preg_replace('/^```\s*/', '', $text);
		$text = preg_replace('/\s*```$/', '', $text);
		$text = trim($text);
	}

	return $text;
}

function as329_rai_normalise_meta_profile_json($data) {
	$normalised = as329_rai_empty_meta_profile();

	foreach ($normalised as $key => $default) {
		if (empty($data[$key]) || !is_array($data[$key])) {
			continue;
		}

		foreach ($data[$key] as $item) {
			$value = '';

			if (is_string($item)) {
				$value = trim($item);
			} elseif (is_array($item)) {
				foreach (array('name', 'title', 'label') as $name_key) {
					if (!empty($item[$name_key]) && is_string($item[$name_key])) {
						$value = trim($item[$name_key]);
						break;
					}
				}
			}

			if ($value !== '') {
				$normalised[$key][] = $value;
			}
		}

		$normalised[$key] = array_values(array_unique($normalised[$key]));
	}

	if (isset($data['_error'])) {
		$normalised['_error'] = $data['_error'];
	}

	if (isset($data['_raw'])) {
		$normalised['_raw'] = $data['_raw'];
	}

	return $normalised;
}


// ==========================
// MEMORY CONTEXT LOOKUP
// ==========================

function as329_rai_build_prompt_with_memory_context($prompt, $session_id) {
	$prompt = trim((string) $prompt);

	if ($prompt === '') {
		return $prompt;
	}

	if (!function_exists('as329_rai_generate_meta_profile_json')) {
		return $prompt;
	}

	if (empty(as329_rai_get_settings()['memory_enabled'])) { return $prompt; }

	$profile_json = as329_rai_generate_meta_profile_json("## USER INPUT\n\n" . $prompt);

	if (is_wp_error($profile_json)) {
		return $prompt;
	}

	$profile = json_decode((string) $profile_json, true);

	if (!is_array($profile)) {
		return $prompt;
	}

	$matches = as329_rai_find_matching_memory_sessions($profile, (int) $session_id, 5);

	if (empty($matches)) {
		return $prompt;
	}

	$memory = "Possible relevant prior rAIven chat transcripts are included below.\n";
	$memory .= "Use them only as background context. The current user message remains the priority.\n\n";

	foreach ($matches as $match) {
		$memory .= "==============================\n";
		$memory .= "Prior rAIven chat: " . get_the_title($match->ID) . "\n";
		$memory .= "Date: " . get_the_date('Y-m-d H:i:s', $match->ID) . "\n";
		$memory .= "==============================\n\n";
		$memory .= mb_substr(trim((string) $match->post_content), 0, 6000) . "\n\n";
	}

	$memory .= "==============================\n";
	$memory .= "Current user message\n";
	$memory .= "==============================\n\n";
	$memory .= $prompt;

	return $memory;
}

function as329_rai_find_matching_memory_sessions($profile, $exclude_session_id = 0, $limit = 5) {
	$profile_values = as329_rai_flatten_meta_profile_values($profile);

	if (empty($profile_values)) {
		return array();
	}

	$query = new WP_Query(
		array(
			'post_type'      => AS329_RAI_POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => 50,
            'author' => get_current_user_id(),
			'post__not_in'   => $exclude_session_id ? array((int) $exclude_session_id) : array(),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		)
	);

	if (empty($query->posts)) {
		return array();
	}

	$matches = array();

	foreach ($query->posts as $post) {
		$saved_json = get_post_meta($post->ID, AS329_RAI_META_PROFILE_KEY, true);

		if (!$saved_json) {
			continue;
		}

		$saved_profile = json_decode((string) $saved_json, true);

		if (!is_array($saved_profile)) {
			continue;
		}

		$saved_values = as329_rai_flatten_meta_profile_values($saved_profile);

		if (empty($saved_values)) {
			continue;
		}

		if (array_intersect($profile_values, $saved_values)) {
			$matches[] = $post;
		}

		if (count($matches) >= absint($limit)) {
			break;
		}
	}

	// Queried newest first, injected oldest-to-newest for chronological context.
	return array_reverse($matches);
}

function as329_rai_flatten_meta_profile_values($profile) {
	$values = array();

	foreach (array('people', 'business', 'projects', 'systems') as $key) {
		if (empty($profile[$key]) || !is_array($profile[$key])) {
			continue;
		}

		foreach ($profile[$key] as $item) {
			if (!is_string($item)) {
				continue;
			}

			$value = strtolower(trim($item));

			if ($value !== '') {
				$values[] = $value;
			}
		}
	}

	return array_values(array_unique($values));
}
add_action('add_meta_boxes', 'as329_rai_add_transcript_box');
function as329_rai_add_transcript_box() {
    add_meta_box('as329-rai-transcript', __('Chat transcript', 'raiven-connector'), 'as329_rai_render_transcript_box', AS329_RAI_POST_TYPE, 'normal', 'high');
}
function as329_rai_render_transcript_box($post) {
    echo '<textarea readonly rows="20" class="large-text code" aria-label="Chat transcript">' . esc_textarea($post->post_content) . '</textarea>';
    $url = add_query_arg(array('post_type' => AS329_RAI_POST_TYPE, 'page' => 'as329-rai', 'session_id' => $post->ID), admin_url('edit.php'));
    echo '<p><a class="button" href="' . esc_url($url) . '">' . esc_html__('Continue chat', 'raiven-connector') . '</a></p>';
}

add_action('as329_rai_index_session', 'as329_rai_index_session');
function as329_rai_index_session($session_id) {
    $post = get_post($session_id);
    if ($post && $post->post_type === AS329_RAI_POST_TYPE && $post->post_status === 'private' && !empty(as329_rai_get_settings()['memory_enabled'])) {
        as329_rai_update_meta_profile_json($session_id, $post->post_content);
    }
}
