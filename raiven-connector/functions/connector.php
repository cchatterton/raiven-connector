<?php
/** Settings, credentials and bounded requests to the rAIven service. */
if (!defined('ABSPATH')) { exit; }

add_action('admin_init', 'as329_rai_register_settings');
function as329_rai_register_settings() {
    register_setting('as329_rai_settings_group', AS329_RAI_OPTION, array(
        'type' => 'array', 'sanitize_callback' => 'as329_rai_sanitize_settings', 'default' => array(),
    ));
}

function as329_rai_get_settings() {
    $saved = get_option(AS329_RAI_OPTION, array());
    return wp_parse_args(is_array($saved) ? $saved : array(), array(
        'api_base_url' => AS329_RAI_DEFAULT_BASE_URL,
        'model' => '', 'temperature' => 0.7, 'max_tokens' => 2048,
        // Existing installations retain memory; new installations explicitly opt in.
        'memory_enabled' => !empty($saved) ? 1 : 0,
    ));
}

function as329_rai_valid_base_url($url) {
    if (!is_string($url)) { return false; }
    $parts = wp_parse_url($url);
    return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
        && !isset($parts['user']) && !isset($parts['pass'])
        && !isset($parts['query']) && !isset($parts['fragment'])
        && wp_http_validate_url($url);
}

function as329_rai_sanitize_settings($input) {
    $input = is_array($input) ? $input : array();
    $old = as329_rai_get_settings();
    $url = isset($input['api_base_url']) && is_string($input['api_base_url'])
        ? untrailingslashit(esc_url_raw(trim($input['api_base_url']))) : '';
    if (!as329_rai_valid_base_url($url)) {
        add_settings_error(AS329_RAI_OPTION, 'endpoint', __('Enter a public HTTPS API URL without credentials, query parameters or a fragment. The previous URL was kept.', 'raiven-connector'));
        $url = $old['api_base_url'];
    }
    // Console model selection is locked to the service default, including older saved settings.
    $model = '';
    $temperature = $input['temperature'] ?? null;
    if (!is_scalar($temperature) || !is_numeric($temperature) || $temperature < 0 || $temperature > 2) {
        add_settings_error(AS329_RAI_OPTION, 'temperature', __('Temperature must be between 0 and 2. The previous value was kept.', 'raiven-connector'));
        $temperature = $old['temperature'];
    }
    $tokens = $input['max_tokens'] ?? null;
    if (filter_var($tokens, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 32768))) === false) {
        add_settings_error(AS329_RAI_OPTION, 'tokens', __('Output tokens must be a whole number from 1 to 32768. The previous value was kept.', 'raiven-connector'));
        $tokens = $old['max_tokens'];
    }
    return array('api_base_url' => $url, 'model' => $model, 'temperature' => (float) $temperature,
        'max_tokens' => (int) $tokens, 'memory_enabled' => empty($input['memory_enabled']) ? 0 : 1);
}

function as329_rai_get_api_key() {
	$env_key = getenv('RAIVEN_API_KEY');

	if (is_string($env_key) && trim($env_key) !== '') {
		return trim($env_key);
	}

	if (defined('RAIVEN_API_KEY') && is_string(RAIVEN_API_KEY) && trim(RAIVEN_API_KEY) !== '') {
		return trim(RAIVEN_API_KEY);
	}

	$possible_options = array(
		AS329_RAI_NATIVE_KEY_OPTION,
		'raiven_api_key',
		'wp_connector_raiven_api_key',
		'wp_connectors_raiven_api_key',
		'connectors_raiven_api_key',
	);

	foreach ($possible_options as $option_name) {
		$value = get_option($option_name, '');

		if (is_string($value) && trim($value) !== '') {
			return trim($value);
		}
	}

	return '';
}


/** Both native and direct calls must validate the destination before attaching credentials. */
function as329_rai_endpoint($path) {
    $settings = as329_rai_get_settings();
    if (!as329_rai_valid_base_url($settings['api_base_url'])) {
        return new WP_Error('raiven_endpoint', __('Check the public HTTPS API URL in rAIven settings.', 'raiven-connector'));
    }
    return untrailingslashit($settings['api_base_url']) . '/' . ltrim($path, '/');
}

function as329_rai_request($path, $key, $payload = null, $source = '') {
    if ($key === '') { return new WP_Error('raiven_key', __('Configure your rAIven API key in Settings → Connectors.', 'raiven-connector')); }
    $url = as329_rai_endpoint($path);
    if (is_wp_error($url)) { return $url; }
    $args = array('timeout' => $payload === null ? 15 : 60, 'redirection' => 0,
        'limit_response_size' => 2 * MB_IN_BYTES,
        'headers' => array('Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json'));
    if ($payload !== null) {
        $args['method'] = 'POST';
        $args['headers']['Content-Type'] = 'application/json';
        $args['body'] = wp_json_encode($payload);
    }
    $source = $payload === null ? 'model-discovery' : ($source ?: (wp_doing_cron() ? 'background' : 'connector'));
    $entry = as329_rai_exchange_start($source, $payload === null ? 'GET' : 'POST', $url, $payload, array($key));
    try {
        $response = wp_safe_remote_request($url, $args);
        as329_rai_exchange_finish($entry, is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response), is_wp_error($response) ? '' : wp_remote_retrieve_body($response), is_wp_error($response) ? 'Network request failed.' : '');
    } catch (Throwable $exception) {
        as329_rai_exchange_finish($entry, 0, '', 'Transport exception.');
        throw $exception;
    }
    if (is_wp_error($response)) {
        return new WP_Error('raiven_network', __('rAIven could not be reached. Check the endpoint and try again.', 'raiven-connector'));
    }
    $code = wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        $message = __('rAIven could not complete the request. Check the model and settings, then try again.', 'raiven-connector');
        if ($code === 401 || $code === 403) { $message = __('rAIven rejected the API key. Check Settings → Connectors.', 'raiven-connector'); }
        if ($code === 400) {
            $error_data = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($error_data) && is_string($error_data['message'] ?? null) && strpos($error_data['message'], 'no available endpoint') !== false) {
                $message = __('rAIven has no active endpoint for this request. Please try again later or contact your rAIven administrator.', 'raiven-connector');
            }
        }
        if ($code === 429) { $message = __('rAIven is busy or the request limit was reached. Wait a moment before trying again.', 'raiven-connector'); }
        return new WP_Error('raiven_http', $message, array('status' => $code));
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data)) { return new WP_Error('raiven_json', __('rAIven returned an unreadable response. Please try again.', 'raiven-connector')); }
    return $data;
}

/** Cache by endpoint and credential fingerprint, so a replacement key is actually validated. */
function as329_rai_fetch_models($api_key = '', $refresh = false) {
    $key = $api_key !== '' ? $api_key : as329_rai_get_api_key();
    if ($key === '') { return new WP_Error('raiven_key', __('Configure your rAIven API key in Settings → Connectors.', 'raiven-connector')); }
    $settings = as329_rai_get_settings();
    $cache_key = 'as329_rai_models_' . hash_hmac('sha256', $settings['api_base_url'] . '|' . $key, wp_salt('auth'));
    if (!$refresh) {
        $cached = get_transient($cache_key);
        if (is_array($cached) || is_wp_error($cached)) { return $cached; }
    }
    $data = as329_rai_request('models', $key);
    if (is_wp_error($data)) { set_transient($cache_key, $data, MINUTE_IN_SECONDS); return $data; }
    $rows = $data['data'] ?? $data;
    $ids = array();
    foreach (is_array($rows) ? $rows : array() as $row) {
        $id = is_array($row) ? ($row['id'] ?? null) : $row;
        if (is_string($id) && trim($id) !== '') { $ids[] = sanitize_text_field($id); }
    }
    $ids = array_values(array_unique($ids));
    $result = $ids ?: new WP_Error('raiven_models', __('rAIven returned no available models. Check your account access.', 'raiven-connector'));
    set_transient($cache_key, $result, is_wp_error($result) ? MINUTE_IN_SECONDS : 5 * MINUTE_IN_SECONDS);
    return $result;
}
function as329_rai_get_model_ids() {
    $models = as329_rai_fetch_models();
    return is_wp_error($models) ? array() : $models;
}

function as329_rai_direct_chat_completion($messages, $source = 'console') {
    $settings = as329_rai_get_settings();
    $api_messages = array();
    foreach (is_array($messages) ? $messages : array() as $message) {
        if (in_array($message['role'] ?? '', array('system','user','assistant'), true) && is_string($message['content'] ?? null)) {
            $api_messages[] = array('role' => $message['role'], 'content' => $message['content']);
        }
    }
    if (!$api_messages || strlen(wp_json_encode($api_messages)) > 200000) {
        return new WP_Error('raiven_context', __('The conversation is empty or too long. Start a new session for another request.', 'raiven-connector'));
    }
    $payload = array(
        'messages' => $api_messages, 'stream' => false,
        'temperature' => (float) $settings['temperature'], 'max_tokens' => (int) $settings['max_tokens'],
    );
    // Always use the service default. Never forward a stale saved model such as gpt-4.
    $data = as329_rai_request('chat/completions', as329_rai_get_api_key(), $payload, $source);
    if (is_wp_error($data)) { return $data; }
    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
        return new WP_Error('raiven_response', __('rAIven returned no text. Please try again later.', 'raiven-connector'));
    }
    return trim($content);
}

add_action('init', 'as329_rai_register_ai_provider', 1);
function as329_rai_register_ai_provider() {
    if (!class_exists('WordPress\\AiClient\\AiClient') || !class_exists('WordPress\\AiClient\\Providers\\OpenAiCompatibleImplementation\\AbstractOpenAiCompatibleTextGenerationModel')) { return; }
    require_once AS329_RAI_PLUGIN_DIR . 'functions/provider.php';
    $registry = \WordPress\AiClient\AiClient::defaultRegistry();
    if (!$registry->hasProvider(AS329_RAI_PROVIDER_ID)) { $registry->registerProvider('AS329_RAI_AI_Provider'); }
}
