<?php
/**
 * rAIven Connector - Admin console.
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', 'as329_rai_admin_menu');

function as329_rai_admin_menu() {
	add_submenu_page(
		'edit.php?post_type=' . AS329_RAI_POST_TYPE,
		'rAIven Connector',
		'Live Chat',
		'manage_options',
		'as329-rai',
		'as329_rai_render_admin_page'
	);
}

add_action('admin_enqueue_scripts', 'as329_rai_enqueue_admin_assets');

function as329_rai_enqueue_admin_assets($hook) {
	if ($hook !== AS329_RAI_POST_TYPE . '_page_as329-rai') {
		return;
	}

	wp_enqueue_style(
		'as329-rai-console',
		AS329_RAI_PLUGIN_URL . 'assets/style.css',
		array(),
		AS329_RAI_VERSION
	);

	wp_enqueue_script(
		'as329-rai-console',
		AS329_RAI_PLUGIN_URL . 'assets/script.js',
		array(),
		AS329_RAI_VERSION,
		true
	);

	wp_localize_script(
		'as329-rai-console',
		'as329Rai',
		array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce'   => wp_create_nonce('as329_rai_chat_nonce'),
		)
	);
}

add_action('wp_ajax_as329_rai_send_prompt', 'as329_rai_ajax_send_prompt');

function as329_rai_ajax_send_prompt() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Only site administrators can use this console.', 'raiven-connector')), 403);
    }
    if (!check_ajax_referer('as329_rai_chat_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => __('Your session expired. Copy your message and reload this page.', 'raiven-connector')), 403);
    }
    $prompt = isset($_POST['prompt']) && is_string($_POST['prompt']) ? sanitize_textarea_field(wp_unslash($_POST['prompt'])) : '';
    $session_id = isset($_POST['session_id']) && is_scalar($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    if ($prompt === '' || strlen($prompt) > 16000) {
        wp_send_json_error(array('message' => __('Enter a message of no more than 16000 bytes.', 'raiven-connector')), 400);
    }
    if ($session_id && !as329_rai_can_use_session($session_id)) {
        wp_send_json_error(array('message' => __('This chat is unavailable. Start a new session.', 'raiven-connector')), 403);
    }
    // An atomic per-user lock prevents concurrent requests overwriting conversation history.
    $lock = 'as329_rai_busy_' . get_current_user_id();
    $started = (int) get_option($lock, 0);
    if ($started && $started < time() - 300) { delete_option($lock); }
    if (!add_option($lock, time(), '', false)) {
        wp_send_json_error(array('message' => __('A request is already running. Wait for it to finish.', 'raiven-connector')), 409);
    }
    try {
        $result = as329_rai_process_prompt($prompt, $session_id);
    } catch (Throwable $exception) {
        $result = new WP_Error('raiven_failed', __('The request could not be completed. Your message is still available to retry.', 'raiven-connector'));
    } finally {
        delete_option($lock);
    }
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message(), 'session_id' => $session_id), 502);
    }
    wp_send_json_success(array('chat_message' => $result, 'session_id' => $session_id));
}

function as329_rai_can_use_session($session_id) {
    $post = get_post($session_id);
    return current_user_can('manage_options') && $post && $post->post_type === AS329_RAI_POST_TYPE
        && $post->post_status === 'private' && (int) $post->post_author === get_current_user_id();
}

function as329_rai_process_prompt($prompt, &$session_id) {
    if (!$session_id) {
        $created = as329_rai_create_session();
        if (is_wp_error($created)) { return new WP_Error('raiven_storage', __('The chat could not be saved. Please try again.', 'raiven-connector')); }
        $session_id = $created;
    }
    $messages = as329_rai_get_session_messages($session_id);
    $messages[] = array('role' => 'user', 'content' => $prompt, 'timestamp' => current_time('mysql'));
    $ai_messages = $messages;
    $ai_messages[count($ai_messages) - 1]['content'] = as329_rai_build_prompt_with_memory_context($prompt, $session_id);
    $result = as329_rai_direct_chat_completion($ai_messages);
    if (is_wp_error($result)) { return $result; }
    $assistant = array('role' => 'assistant', 'content' => $result, 'timestamp' => current_time('mysql'));
    $messages[] = $assistant;
    $saved = as329_rai_save_session_messages($session_id, $messages);
    if (is_wp_error($saved)) { return $saved; }
    return $assistant;
}

function as329_rai_render_admin_page() {
	if (!current_user_can('manage_options')) {
		return;
	}

	$settings = as329_rai_get_settings();
	$api_key = as329_rai_get_api_key();
	$session_id = isset($_GET['session_id']) ? absint($_GET['session_id']) : 0;
	if ($session_id && !as329_rai_can_use_session($session_id)) {
        wp_die(esc_html__('This chat is unavailable. Open Live Chat to start a new session.', 'raiven-connector'), '', array('response' => 403));
    }
    $messages = $session_id ? as329_rai_get_session_messages($session_id) : array();

	$models = array();
	$model_err = null;

	if ($api_key !== '') {
		$model_result = as329_rai_fetch_models($api_key);

		if (is_wp_error($model_result)) {
			$model_err = $model_result->get_error_message();
		} else {
			$models = $model_result;
            if ($settings['model'] !== '' && !in_array($settings['model'], $models, true)) { array_unshift($models, $settings['model']); }
		}
	}

    ?>
    <div class="wrap as329-rai-wrap">
        <h1 class="screen-reader-text">rAIven Connector</h1>
        <?php settings_errors(AS329_RAI_OPTION); ?>
        <?php if (!class_exists('AS329_RAI_AI_Provider')) : ?>
            <div class="notice notice-error"><p><?php esc_html_e('The native AI provider is unavailable. This plugin requires WordPress 7.0 or later.', 'raiven-connector'); ?></p></div>
        <?php endif; ?>
        <header class="as329-rai-hero">
            <span class="as329-rai-version" aria-label="<?php echo esc_attr('Version ' . AS329_RAI_VERSION); ?>">v<?php echo esc_html(AS329_RAI_VERSION); ?></span>
            <p class="as329-rai-eyebrow">ALPHASYS</p>
            <h2>rAIven Connector</h2>
            <p><?php esc_html_e('Connect WordPress to rAIven and test conversations in one place.', 'raiven-connector'); ?></p>
        </header>
        <section class="as329-rai-status" aria-label="Connection status">
            <div><strong><?php echo esc_html($api_key === '' ? __('Setup required', 'raiven-connector') : ($model_err ? __('Connection needs attention', 'raiven-connector') : __('API key accepted', 'raiven-connector'))); ?></strong>
            <p><?php echo esc_html($api_key === '' ? __('Add your API key to start using rAIven.', 'raiven-connector') : ($model_err ?: __('Model list accessible. Model availability is confirmed when you send a message.', 'raiven-connector'))); ?></p></div>
            <a class="button" href="<?php echo esc_url(admin_url('options-connectors.php')); ?>"><?php esc_html_e('Manage connection', 'raiven-connector'); ?></a>
        </section>

		<div class="as329-rai-layout">
			<div class="as329-rai-settings-panel">
				<h2>Chat settings</h2><p class="description">Choose the model and response limits for this console.</p>

				<form method="post" action="options.php">
					<?php settings_fields('as329_rai_settings_group'); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="as329-rai-api-base-url">API Base URL</label>
							</th>
							<td>
								<input
									type="url" required
									id="as329-rai-api-base-url"
									name="<?php echo esc_attr(AS329_RAI_OPTION); ?>[api_base_url]"
									value="<?php echo esc_attr($settings['api_base_url']); ?>"
									class="regular-text"
								>
								<p class="description">
									Requests and your API key are sent to this endpoint.<br>Default: <code><?php echo esc_html(AS329_RAI_DEFAULT_BASE_URL); ?></code>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="as329-rai-model">Model</label>
							</th>
							<td>
								<?php if (!empty($models)) : ?>
									<select
										id="as329-rai-model"
										name="<?php echo esc_attr(AS329_RAI_OPTION); ?>[model]"
										class="regular-text"
									>
										<option value="" <?php selected($settings['model'], ''); ?>>rAIven default (recommended)</option>
                                        <?php foreach ($models as $model) : ?>
											<option value="<?php echo esc_attr($model); ?>" <?php selected($settings['model'], $model); ?>>
												<?php echo esc_html($model); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description">The default lets rAIven choose its model. Listed models may not have an active endpoint. Save settings before sending.</p>
								<?php else : ?>
									<input
										type="text"
										id="as329-rai-model"
										name="<?php echo esc_attr(AS329_RAI_OPTION); ?>[model]"
										value="<?php echo esc_attr($settings['model']); ?>"
										class="regular-text"
										placeholder="Leave blank for rAIven default"
									>
									<?php if ($model_err) : ?>
										<p class="description as329-rai-error-text">
											Could not load models: <?php echo esc_html($model_err); ?>
										</p>
									<?php endif; ?>
								<?php endif; ?>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="as329-rai-temperature">Temperature</label>
							</th>
							<td>
								<input
									type="number"
									id="as329-rai-temperature"
									name="<?php echo esc_attr(AS329_RAI_OPTION); ?>[temperature]"
									value="<?php echo esc_attr($settings['temperature']); ?>"
									class="small-text"
									step="0.1"
									min="0"
									max="2"
								>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="as329-rai-max-tokens">Max Output Tokens</label>
							</th>
							<td>
								<input
									type="number"
									id="as329-rai-max-tokens"
									name="<?php echo esc_attr(AS329_RAI_OPTION); ?>[max_tokens]"
									value="<?php echo esc_attr((string) $settings['max_tokens']); ?>"
									class="small-text"
									min="1" max="32768"
								>
							</td>
						</tr>
                        <tr><th scope="row">Memory</th><td>
                            <label><input type="checkbox" name="<?php echo esc_attr(AS329_RAI_OPTION); ?>[memory_enabled]" value="1" <?php checked(!empty($settings['memory_enabled'])); ?>> Use my previous chats as context</label>
                            <p class="description">When enabled, rAIven indexes saved chats and receives matching excerpts from your own previous sessions. This makes additional AI requests. Other administrators can manage saved transcripts.</p>
                        </td></tr>
                    </table>

					<?php submit_button(__('Save settings', 'raiven-connector')); ?>
				</form>
			</div>

			<div class="as329-rai-chat-panel">
				<div class="as329-rai-chat-header">
					<div>
						<h2>Live Chat</h2>
						<p id="as329-rai-session-label">
							Session:
							<?php if ($session_id) : ?>
								<a href="<?php echo esc_url(get_edit_post_link($session_id)); ?>" >
									#<?php echo esc_html((string) $session_id); ?>
								</a>
							<?php else : ?>
								None
							<?php endif; ?>
						</p>
					</div>

					<a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=' . AS329_RAI_POST_TYPE . '&page=as329-rai')); ?>">
						New session
					</a>
				</div>

				<div
					id="as329-rai-chat-window"
					class="as329-rai-chat-window"
					role="log" aria-label="Conversation" aria-live="polite" tabindex="0"
                    data-session-id="<?php echo esc_attr((string) $session_id); ?>"
				>
					<?php if (empty($messages)) : ?>
						<div class="as329-rai-empty-state">Ask rAIven something to start the chat.</div>
					<?php else : ?>
						<?php foreach ($messages as $message) : ?>
							<?php as329_rai_render_message_bubble($message); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>

				<p class="description">Messages are sent to your configured rAIven service and saved on this site. Start a new session to keep topics separate.</p>
                <label for="as329-rai-chat-prompt">Your message</label>
                <form id="as329-rai-chat-form" class="as329-rai-chat-form">
					<textarea
						id="as329-rai-chat-prompt"
						rows="3" maxlength="16000" aria-describedby="as329-rai-chat-help"
						placeholder="Message rAIven..."
						required
					></textarea>

					<button type="submit" class="button button-primary" id="as329-rai-send-button" <?php disabled($api_key === ''); ?>>Send</button>
				</form>

				<p class="description">Using the saved Chat settings. Save any changes before sending.</p>
                <p class="description" id="as329-rai-chat-help">Enter sends. Shift + Enter adds a new line.</p>
			</div>
        </div>
        <details class="as329-rai-reference"><summary>Developer reference</summary><p>Use the native WordPress AI Client with provider ID <code>raiven</code>. Console settings apply to console calls; native clients choose their own model and options.</p><pre><code>wp_ai_client_prompt( 'Write a short greeting.' )
    -&gt;using_model_preference( array( 'raiven', 'your-model-id' ) )
    -&gt;generate_text();</code></pre></details>
    </div>
    <?php
}

function as329_rai_render_message_bubble($message) {
	$role = isset($message['role']) ? sanitize_key((string) $message['role']) : 'message';
	$content = isset($message['content']) ? (string) $message['content'] : '';
	$time = isset($message['timestamp']) ? (string) $message['timestamp'] : '';

	if (!in_array($role, array('user', 'assistant', 'error'), true)) {
		$role = 'assistant';
	}

	$label = 'Message';

	if ($role === 'user') {
		$label = 'You';
	} elseif ($role === 'assistant') {
		$label = 'rAIven';
	} elseif ($role === 'error') {
		$label = 'Error';
	}

	?>
	<div class="as329-rai-message as329-rai-message-<?php echo esc_attr($role); ?>">
		<div class="as329-rai-bubble">
			<div class="as329-rai-meta">
				<?php echo esc_html($label); ?>
				<?php if ($time !== '') : ?>
					— <?php echo esc_html($time); ?>
				<?php endif; ?>
			</div>
			<div class="as329-rai-content"><?php echo esc_html($content); ?></div>
		</div>
	</div>
	<?php
}