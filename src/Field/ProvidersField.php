<?php
/**
 * Custom form field — renders the supported gateway providers section.
 */

namespace Joomla\Plugin\PhocacartPayment\Infynipay\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri;
use Joomla\Plugin\PhocacartPayment\Infynipay\Extension\Infynipay;
use Joomla\Plugin\PhocacartPayment\Infynipay\Helper\ProviderIconHelper;

use function dirname;
use function file_exists;
use function filemtime;
use function is_array;
use function json_decode;
use function substr;
use function trim;

class ProvidersField extends FormField
{
    protected $type = 'Providers';

    private const SHARED_STYLESHEET_PATH = 'plugins/pcp/infynipay/media/css/setupbutton.css';

    private const SHARED_SCRIPT_PATH = 'plugins/pcp/infynipay/media/js/setupbutton.js';

    private const STYLESHEET_PATH = 'plugins/pcp/infynipay/media/css/providers.css';

    public function getInput(): string
    {
        $app = Factory::getApplication();
        $app->getLanguage()->load(
            'plg_pcp_infynipay',
            $this->getPluginRootPath(),
            null,
            false,
            true
        );

        $providers = $this->fetchAllProviders();

        $this->loadAssets();

        return $this->renderLayout(
                [
                    'providers' => $providers,
                    'emptyText' => Text::_('PLG_PCP_INFYNIPAY_SUPPORTED_GATEWAYS_EMPTY'),
                ]
            );
    }

    private function loadAssets(): void
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
        $wa->registerAndUseStyle('plg.pcp.infynipay.setupbutton', $this->getAssetUrl(self::SHARED_STYLESHEET_PATH));
        $wa->registerAndUseStyle('plg.pcp.infynipay.providers', $this->getAssetUrl(self::STYLESHEET_PATH));
        $wa->registerAndUseScript('plg.pcp.infynipay.js', $this->getAssetUrl(self::SHARED_SCRIPT_PATH));
        $loaded = true;
    }

    private function renderLayout(array $displayData): string
    {
        $layout = new FileLayout('field.providers', $this->getPluginRootPath() . '/layouts');

        return $layout->render($displayData);
    }

    private function getPluginRootPath(): string
    {
        return dirname(__DIR__, 2);
    }

    private function getAssetUrl(string $relativePath): string
    {
        $absolutePath = $this->getPluginRootPath() . '/' . str_replace('/', DIRECTORY_SEPARATOR, substr($relativePath, strlen('plugins/pcp/infynipay/')));
        $version = file_exists($absolutePath) ? (string) filemtime($absolutePath) : '1';

        return Uri::root(true) . '/' . $relativePath . '?v=' . $version;
    }

    private function fetchAllProviders(): array
    {
        // Cache the result (including inlined icon data URIs) for 24 hours so
        // icon HTTP requests only happen on the first load after a cache miss.
        try {
            $cache = Factory::getCache('plg_pcp_infynipay', 'callback');
            $cache->setCaching(true);
            $cache->setLifeTime(24 * 60);

            $result = $cache->get(function () {
                return $this->doFetchAllProviders();
            }, [], 'providers_v1', false);

            if (is_array($result) && $result !== []) {
                return $result;
            }
        } catch (\Exception $e) {
        }

        // Never let a transient timeout pin the admin UI blank for the cache lifetime.
        return $this->doFetchAllProviders();
    }

    private function doFetchAllProviders(): array
    {
        $apiUrl = rtrim(Infynipay::PAYMENT_APP_API_URL, '/') . '/api/apps/providers';

        try {
            $http = HttpFactory::getHttp();
            $response = $http->get(
                $apiUrl,
                ['Accept' => 'application/json'],
                15
            );
        } catch (\RuntimeException $e) {
            $this->log('ProvidersField: providers API unreachable — ' . $e->getMessage(), Log::WARNING);
            return [];
        }

        if ($response->code !== 200) {
            $this->log('ProvidersField: unexpected HTTP ' . $response->code . ' from ' . $apiUrl, Log::WARNING);
            return [];
        }

        $data = json_decode((string) $response->body, true);

        if (!is_array($data)) {
            $this->log('ProvidersField: invalid JSON from providers API', Log::WARNING);
            return [];
        }

        $providers = [];

        foreach ($data as $provider) {
            if (!is_array($provider)) {
                continue;
            }

            $key = (string) ($provider['provider'] ?? '');

            if ($key !== '') {
                $providers[$key] = $provider;
            }
        }

        return $this->inlineProviderIcons(array_values($providers));
    }

    private function inlineProviderIcons(array $providers): array
    {
        foreach ($providers as &$provider) {
            if (!is_array($provider)) {
                continue;
            }

            foreach (['iconUri1', 'iconUri2'] as $iconKey) {
                $provider[$iconKey] = ProviderIconHelper::inline(
                    (string) ($provider[$iconKey] ?? ''),
                    'plg_pcp_infynipay'
                );
            }
        }

        unset($provider);

        return $providers;
    }

    private function log(string $message, int $priority = Log::INFO): void
    {
        static $registered = false;

        if (!$registered) {
            Log::addLogger(
                ['text_file' => 'plg_pcp_infynipay.php'],
                Log::ALL,
                ['plg_pcp_infynipay']
            );
            $registered = true;
        }

        Log::add($message, $priority, 'plg_pcp_infynipay');
    }
}