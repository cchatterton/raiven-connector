<?php
/** Native WordPress AI Client adapter. Loaded only after the bundled client is available. */
if (!defined('ABSPATH')) { exit; }

use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\Traits\WithRequestAuthenticationTrait;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

class AS329_RAI_AI_Provider extends AbstractProvider {
    protected static function createProviderMetadata(): ProviderMetadata {
        return new ProviderMetadata(AS329_RAI_PROVIDER_ID, 'rAIven', ProviderTypeEnum::cloud(),
            'https://raiven.alphasys.com/', RequestAuthenticationMethod::apiKey(),
            __('Text generation with rAIven.', 'raiven-connector'), AS329_RAI_PLUGIN_DIR . 'assets/logo.png');
    }
    protected static function createProviderAvailability(): ProviderAvailabilityInterface { return new AS329_RAI_Availability(); }
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface { return new AS329_RAI_Model_Metadata_Directory(); }
    protected static function createModel(ModelMetadata $modelMetadata, ProviderMetadata $providerMetadata): ModelInterface {
        return new AS329_RAI_Text_Generation_Model($modelMetadata, $providerMetadata);
    }
}

class AS329_RAI_Availability implements ProviderAvailabilityInterface, WithRequestAuthenticationInterface {
    use WithRequestAuthenticationTrait;
    public function isConfigured(): bool {
        try {
            $auth = $this->getRequestAuthentication();
            return $auth instanceof ApiKeyRequestAuthentication && $auth->getApiKey() !== ''
                && !is_wp_error(as329_rai_fetch_models($auth->getApiKey()));
        } catch (\WordPress\AiClient\Common\Exception\RuntimeException $exception) {
            return false; // The native client has not received credentials yet.
        }
    }
}

class AS329_RAI_Model_Metadata_Directory implements ModelMetadataDirectoryInterface, WithRequestAuthenticationInterface {
    use WithRequestAuthenticationTrait;
    public function listModelMetadata(): array {
        $auth = $this->getRequestAuthentication();
        if (!$auth instanceof ApiKeyRequestAuthentication || $auth->getApiKey() === '') {
            throw new \WordPress\AiClient\Common\Exception\RuntimeException('Configure the rAIven API key in Settings → Connectors.');
        }
        $ids = as329_rai_fetch_models($auth->getApiKey());
        if (is_wp_error($ids)) { throw new \WordPress\AiClient\Common\Exception\RuntimeException($ids->get_error_message()); }
        $models = array();
        foreach ($ids as $id) {
            $models[] = new ModelMetadata($id, $id, array(CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory()), array(
                new SupportedOption(OptionEnum::systemInstruction()),
                new SupportedOption(OptionEnum::maxTokens()),
                new SupportedOption(OptionEnum::temperature()),
                new SupportedOption(OptionEnum::inputModalities(), array(array(ModalityEnum::text()))),
                new SupportedOption(OptionEnum::outputModalities(), array(array(ModalityEnum::text()))),
            ));
        }
        return $models;
    }
    public function hasModelMetadata(string $modelId): bool {
        foreach ($this->listModelMetadata() as $model) { if ($model->getId() === $modelId) { return true; } }
        return false;
    }
    public function getModelMetadata(string $modelId): ModelMetadata {
        foreach ($this->listModelMetadata() as $model) { if ($model->getId() === $modelId) { return $model; } }
        throw new \WordPress\AiClient\Common\Exception\InvalidArgumentException('The selected rAIven model is unavailable.');
    }
}

class AS329_RAI_Text_Generation_Model extends AbstractOpenAiCompatibleTextGenerationModel {
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = array(), $data = null): Request {
        $url = as329_rai_endpoint($path);
        if (is_wp_error($url)) { throw new \WordPress\AiClient\Common\Exception\RuntimeException($url->get_error_message()); }
        $options = $this->getRequestOptions();
        $options = $options ? clone $options : new RequestOptions();
        $options->setMaxRedirects(0);
        if ($options->getTimeout() === null) { $options->setTimeout(60); }
        return new Request($method, $url, $headers, $data, $options);
    }
    protected function throwIfNotSuccessful(Response $response): void {
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new \WordPress\AiClient\Common\Exception\RuntimeException('rAIven could not complete the request. Check the connection and selected model.');
        }
    }
}
