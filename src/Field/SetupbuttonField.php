<?php
/**
 * Custom form field � renders the Infynipay setup card in the plugin's
 * admin settings panel, and handles the OAuth callback directly.
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

use function bin2hex;
use function dirname;
use function is_array;
use function array_key_exists;
use function file_exists;
use function filemtime;
use function json_decode;
use function json_encode;
use function random_bytes;
use function rtrim;
use function strlen;
use function substr;
use function urlencode;

class SetupbuttonField extends FormField
{
    protected $type = 'Setupbutton';

    private const STYLESHEET_PATH = 'plugins/pcp/infynipay/media/css/setupbutton.css';

    private const PROVIDERS_STYLESHEET_PATH = 'plugins/pcp/infynipay/media/css/providers.css';

    private const SCRIPT_PATH = 'plugins/pcp/infynipay/media/js/setupbutton.js';

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

        $input       = $app->getInput();
        $extensionId = $input->getInt('extension_id', 0);

        // Handle activation callback
        $licenseId         = $input->getString('licenseId', '');
        $authorizationCode = $input->get('authorizationCode', '', 'RAW');
        $activationError   = '';

        // Honour a manual cache-refresh request (e.g. from the "Refresh status" link)
        if ($input->getInt('ph_clear', 0)) {
            $this->clearAppStatusCache();
        }

        if ($licenseId !== '' && $authorizationCode !== '' && $extensionId > 0) {
            if (!$this->isAlreadyActivated($extensionId, $licenseId)) {
                [$isError, $message] = $this->processActivation($extensionId, $licenseId, $authorizationCode);
                if ($isError) {
                    $activationError = $message;
                } else {
                    // Invalidate any cached app status so fresh data is fetched on this page load
                    $this->clearAppStatusCache();
                }
            } else {
                $this->log('Activation: skipped — license already activated.');
            }
        }

        // Load current saved params (re-read after possible activation save)
        $db    = Factory::getDbo();
        $query = $db->getQuery(true)
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('extension_id') . ' = ' . (int) $extensionId);
        $db->setQuery($query);

        $params    = json_decode($db->loadResult() ?? '{}', true) ?: [];
        $params    = $this->sanitizeStoredParams($extensionId, $params);

        $sandboxAppId = (string) ($params['sandbox_app_id'] ?? '');
        $sandboxToken = (string) ($params['sandbox_token'] ?? '');
        $liveAppId    = (string) ($params['live_app_id'] ?? '');
        $liveToken    = (string) ($params['live_token'] ?? '');

        $sandboxConnected = $sandboxAppId !== '' && $sandboxToken !== '';
        $liveConnected    = $liveAppId !== '' && $liveToken !== '';
        $isConnected      = $sandboxConnected || $liveConnected;

        $this->log(sprintf('SetupbuttonField::getInput() called. extensionId=%d sandboxConnected=%s liveConnected=%s',
            $extensionId,
            $sandboxConnected ? 'true' : 'false',
            $liveConnected ? 'true' : 'false'
        ));

        $returnUrl  = Uri::base()
            . 'index.php?option=com_plugins&view=plugin&layout=edit&extension_id=' . $extensionId;
        $refreshUrl = $returnUrl . '&ph_clear=1';

        $setupUrl = Infynipay::PANEL_URL
            . '/auth/signin?returnUrl=' . urlencode($returnUrl)
            . '&grantAuthorization=true';

        $baseUrl   = Uri::root(true) . '/plugins/pcp/infynipay/images/logos/';
        $logoLight = $baseUrl . 'infynipay-logo-light.png';
        $logoDark  = $baseUrl . 'infynipay-logo-dark.png';

        $this->loadAssets();

        // Detect localhost / private-network to warn about webhook unreachability
        $siteUrl     = Uri::root();
        $serverHost  = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
        $isLocalhost = $this->isLocalhostUrl($siteUrl)
            || ($serverHost !== '' && $this->isLocalhostUrl('http://' . $serverHost . '/'));

        // Fetch app statuses once (uses static in-memory + Joomla disk cache)
        $noStatus      = ['isSetupCompleted' => false, 'isActive' => true, 'unreachable' => false];
        $sandboxStatus = $sandboxConnected ? $this->getAppStatus($sandboxAppId, $sandboxToken) : $noStatus;
        $liveStatus    = $liveConnected    ? $this->getAppStatus($liveAppId,    $liveToken)    : $noStatus;

        // License expiration: only check the live app (skip when API unreachable)
        $isLicenseExpired = $liveConnected
            && empty($liveStatus['unreachable'])
            && empty($liveStatus['isActive']);
        $renewUrl = $liveConnected ? rtrim(Infynipay::PANEL_URL, '/') . '/' . urlencode($liveAppId) : '';

        return $this->renderLayout(
            [
                'activationError'  => $activationError,
                'refreshUrl'       => $refreshUrl,
                'isConnected'      => $isConnected,
                'isLocalhost'      => $isLocalhost,
                'isLicenseExpired' => $isLicenseExpired,
                'renewUrl'         => $renewUrl,
                'setupUrl'         => $setupUrl,
                'logoLight'        => $logoLight,
                'logoDark'         => $logoDark,
                'apps'             => [
                    [
                        'mode'             => 'sandbox',
                        'isConnected'      => $sandboxConnected,
                        'isSetupCompleted' => $sandboxConnected && !empty($sandboxStatus['isSetupCompleted']),
                        'isUnreachable'    => $sandboxConnected && !empty($sandboxStatus['unreachable']),
                        'url'              => $sandboxAppId !== '' ? rtrim(Infynipay::PANEL_URL, '/') . '/' . urlencode($sandboxAppId): '',
                    ],
                    [
                        'mode'             => 'live',
                        'isConnected'      => $liveConnected,
                        'isSetupCompleted' => $liveConnected && !empty($liveStatus['isSetupCompleted']),
                        'isUnreachable'    => $liveConnected && !empty($liveStatus['unreachable']),
                        'url'              => $liveAppId !== '' ? rtrim(Infynipay::PANEL_URL, '/') . '/' . urlencode($liveAppId): '',
                    ],
                ],
                'text'            => [
                    'connect'       => Text::_('PLG_PCP_INFYNIPAY_CONNECT'),
                    'manage'        => Text::_('PLG_PCP_INFYNIPAY_GATEWAYS_MANAGE'),
                    'setup'         => Text::_('PLG_PCP_INFYNIPAY_GATEWAYS_SETUP'),
                    'connected'     => Text::_('PLG_PCP_INFYNIPAY_STATUS_CONNECTED'),
                    'notConnected'  => Text::_('PLG_PCP_INFYNIPAY_GATEWAYS_NOT_CONNECTED'),
                    'setupRequired' => Text::_('PLG_PCP_INFYNIPAY_SETUP_REQUIRED_TITLE'),
                    'sandbox'       => Text::_('PLG_PCP_INFYNIPAY_MODE_SANDBOX'),
                    'live'          => Text::_('PLG_PCP_INFYNIPAY_MODE_LIVE'),
                ],
            ]
        );
    }

    private function isAppSetupCompleted(string $appId, string $token): bool
    {
        return $this->getAppStatus($appId, $token)['isSetupCompleted'];
    }

    /**
     * Returns the app's active status from GET /api/apps/{appId}.
     */
    private function isAppActive(string $appId, string $token): bool
    {
        return $this->getAppStatus($appId, $token)['isActive'];
    }

    /**
     * Fetches and caches the app status from GET /api/apps/{appId}.
     * Returns ['isSetupCompleted' => bool, 'isActive' => bool].
     */
    private function clearAppStatusCache(): void
    {
        try {
            $jCache = Factory::getCache('plg_pcp_infynipay_appstatus', '');
            $jCache->clean();
        } catch (\Exception $e) {
            // Ignore cache errors
        }
    }

    private function getAppStatus(string $appId, string $token): array
    {
        static $cache = [];

        if (array_key_exists($appId, $cache)) {
            return $cache[$appId];
        }

        $default = ['isSetupCompleted' => false, 'isActive' => true];

        // Only cache the "complete" state (5 min TTL = fast repeated loads once set up).
        // If the cached value says incomplete, we re-fetch immediately so the user
        // sees status changes without waiting for the cache to expire.
        try {
            $jCache = Factory::getCache('plg_pcp_infynipay_appstatus', 'callback');
            $jCache->setCaching(true);
            $jCache->setLifeTime(5);   // minutes

            $cacheKey = 'appstatus_' . md5($appId);

            $result = $jCache->get(
                function (string $aId, string $tk) {
                    return $this->doFetchAppStatus($aId, $tk);
                },
                [$appId, $token],
                $cacheKey,
                false
            );

            if (!is_array($result)) {
                $result = $default;
            }

            // API was unreachable — don't retry (save time), clear any stale cache entry,
            // and return immediately so the layout can show an error notice.
            if (!empty($result['unreachable'])) {
                $this->clearAppStatusCache();
                return $cache[$appId] = $result;
            }

            // Cached "complete" — trust it.
            if (!empty($result['isSetupCompleted'])) {
                return $cache[$appId] = $result;
            }

            // Cached "incomplete" (or just fetched as incomplete):
            // ignore the cached value, re-fetch directly so we return current data.
            $fresh = $this->doFetchAppStatus($appId, $token);
            if (is_array($fresh)) {
                $result = $fresh;
            }

            // If the re-fetch also failed, bail without caching.
            if (!empty($result['unreachable'])) {
                $this->clearAppStatusCache();
                return $cache[$appId] = $result;
            }

            if (!empty($result['isSetupCompleted'])) {
                // Just transitioned to complete — store in cache for future fast loads.
                $jCache->store($result, $cacheKey, false);
            } else {
                // Still incomplete — wipe cached entry so next load re-fetches too.
                $this->clearAppStatusCache();
            }

            return $cache[$appId] = $result;
        } catch (\Exception $e) {
            // Cache unavailable — fall through to direct HTTP call
        }

        return $cache[$appId] = $this->doFetchAppStatus($appId, $token);
    }

    private function doFetchAppStatus(string $appId, string $token): array
    {
        $default     = ['isSetupCompleted' => false, 'isActive' => true, 'unreachable' => false];
        $unreachable = ['isSetupCompleted' => false, 'isActive' => true, 'unreachable' => true];

        $apiUrl = rtrim(Infynipay::PAYMENT_APP_API_URL, '/') . '/api/apps/' . urlencode($appId);

        try {
            $http = HttpFactory::getHttp();
            $response = $http->get(
                $apiUrl,
                [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
                3
            );
        } catch (\RuntimeException $e) {
            $this->log('SetupbuttonField: app API unreachable — ' . $e->getMessage(), Log::WARNING);
            return $unreachable;
        }

        if ($response->code !== 200) {
            return $default;
        }

        $data = json_decode((string) $response->body, true);

        if (!is_array($data)) {
            return $default;
        }

        $this->log(sprintf('doFetchAppStatus: appId=%s isConnectFirstGateway=%s isActive=%s',
            $appId,
            isset($data['isConnectFirstGateway']) ? ($data['isConnectFirstGateway'] ? 'true' : 'false') : '(not set)',
            isset($data['isActive']) ? ($data['isActive'] ? 'true' : 'false') : '(not set)'
        ));

        return [
            'isSetupCompleted' => !empty($data['isConnectFirstGateway']),
            'isActive'         => !isset($data['isActive']) || !empty($data['isActive']),
            'unreachable'      => false,
        ];
    }

    private function loadAssets(): void
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
        $wa->registerAndUseStyle('plg.pcp.infynipay.setupbutton', $this->getAssetUrl(self::STYLESHEET_PATH));
        $wa->registerAndUseStyle('plg.pcp.infynipay.providers', $this->getAssetUrl(self::PROVIDERS_STYLESHEET_PATH));
        $wa->registerAndUseScript('plg.pcp.infynipay.js', $this->getAssetUrl(self::SCRIPT_PATH));
        $loaded = true;
    }

    private function renderLayout(array $displayData): string
    {
        $layout = new FileLayout('field.setupbutton', $this->getPluginRootPath() . '/layouts');

        return $layout->render($displayData);
    }

    private function getAssetUrl(string $relativePath): string
    {
        $absolutePath = $this->getPluginRootPath() . '/' . str_replace('/', DIRECTORY_SEPARATOR, substr($relativePath, strlen('plugins/pcp/infynipay/')));
        $version = file_exists($absolutePath) ? (string) filemtime($absolutePath) : '1';

        return Uri::root(true) . '/' . $relativePath . '?v=' . $version;
    }

    /**
     * Returns true when the given URL resolves to loopback or a private-network address.
     */
    private function isLocalhostUrl(string $url): bool
    {
        $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));

        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($host);
            return ($long & 0xFF000000) === 0x0A000000   // 10.0.0.0/8
                || ($long & 0xFFF00000) === 0xAC100000   // 172.16.0.0/12
                || ($long & 0xFFFF0000) === 0xC0A80000   // 192.168.0.0/16
                || ($long & 0xFF000000) === 0x7F000000;  // 127.0.0.0/8
        }

        return false;
    }

    private function getPluginRootPath(): string
    {
        return dirname(__DIR__, 2);
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

    /**
     * Returns true if this licenseId already has app credentials saved in the
     * database, meaning the OAuth activation API does not need to be called again.
     */
    private function isAlreadyActivated(int $extensionId, string $licenseId): bool
    {
        $db    = Factory::getDbo();
        $query = $db->getQuery(true)
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('extension_id') . ' = ' . (int) $extensionId);
        $db->setQuery($query);

        $stored = json_decode($db->loadResult() ?? '{}', true);

        if (!is_array($stored)) {
            return false;
        }

        if ((string) ($stored['license_id'] ?? '') !== $licenseId) {
            return false;
        }

        return !empty($stored['sandbox_app_id']) || !empty($stored['live_app_id']);
    }

    /**
     * Call GET /api/licenses/{licenseId}, parse the response, and persist
     * credentials to #__extensions params.
     *
     * @return array{0: bool, 1: string}  [isError, message]
     */
    private function processActivation(int $extensionId, string $licenseId, string $authorizationCode): array
    {
        $apiUrl = rtrim(Infynipay::PAYMENT_APP_API_URL, '/')
                . '/api/licenses/' . urlencode($licenseId);

        $this->log('Activation: fetching license credentials from API.');

        try {
            $http     = HttpFactory::getHttp();
            $response = $http->get(
                $apiUrl,
                [
                    'Accept'        => 'text/plain',
                    'Authorization' => 'Bearer ' . $authorizationCode,
                ],
                30
            );
        } catch (\RuntimeException $e) {
            $this->log('API unreachable: ' . $e->getMessage(), Log::ERROR);
            return [true, 'Infynipay API unreachable: ' . $e->getMessage()];
        }

        $this->log('Activation: license API HTTP=' . $response->code);

        if ($response->code !== 200) {
            $this->log('Activation: license API failed. HTTP=' . $response->code, Log::ERROR);
            return [true, 'Infynipay API returned HTTP ' . $response->code];
        }

        $licenses = json_decode((string) $response->body, true);

        if (!is_array($licenses) || empty($licenses)) {
            $this->log('Activation: license API returned unexpected response. HTTP=' . $response->code, Log::ERROR);
            return [true, 'Infynipay API returned unexpected response.'];
        }

        $this->log('Activation: license API returned ' . count($licenses) . ' license(s).');

        $sandboxAppId      = '';
        $sandboxToken      = '';
        $liveAppId         = '';
        $liveToken         = '';
        $selectedIsSandbox = null;

        foreach ($licenses as $license) {
            if (!is_array($license)) {
                continue;
            }

            $currentLicenseId = (string) ($license['licenseId'] ?? $license['id'] ?? '');

            if ($currentLicenseId !== '' && $currentLicenseId === $licenseId) {
                $selectedIsSandbox = !empty($license['isSandbox']);
            }

            if (!empty($license['isSandbox'])) {
                $sandboxAppId = (string) ($license['appId']             ?? '');
                $sandboxToken = (string) ($license['authorizationCode'] ?? '');
            } else {
                $liveAppId = (string) ($license['appId']             ?? '');
                $liveToken = (string) ($license['authorizationCode'] ?? '');
            }
        }

        $db    = Factory::getDbo();
        $query = $db->getQuery(true)
            ->select([$db->quoteName('extension_id'), $db->quoteName('params')])
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('extension_id') . ' = ' . $extensionId);
        $db->setQuery($query);
        $row = $db->loadObject();

        if (!$row) {
            $this->log('Could not find plugin record. extension_id=' . $extensionId, Log::ERROR);
            return [true, 'Could not find plugin record in database (extension_id=' . $extensionId . ').'];
        }

        $params                   = $this->sanitizeStoredParams($extensionId, json_decode($row->params ?? '{}', true) ?: []);
        $params['license_id']     = $licenseId;
        $params['sandbox_app_id'] = $sandboxAppId;
        $params['sandbox_token']  = $sandboxToken;
        $params['live_app_id']    = $liveAppId;
        $params['live_token']     = $liveToken;

        if ($selectedIsSandbox === null) {
            if ($sandboxAppId !== '' && $liveAppId === '') {
                $selectedIsSandbox = true;
            } elseif ($liveAppId !== '' && $sandboxAppId === '') {
                $selectedIsSandbox = false;
            } else {
                $selectedIsSandbox = (int) ($params['sandbox'] ?? 1) === 1;
            }
        }

        $params['sandbox'] = $selectedIsSandbox ? '1' : '0';

        // Generate a webhook secret for each environment — never overwrite an existing
        // one to avoid invalidating a webhook Infynipay is already calling successfully.
        if ($sandboxAppId !== '' && empty($params['sandbox_webhook_secret'])) {
            $params['sandbox_webhook_secret'] = bin2hex(random_bytes(32));
        }
        if ($liveAppId !== '' && empty($params['live_webhook_secret'])) {
            $params['live_webhook_secret'] = bin2hex(random_bytes(32));
        }

        $db->setQuery(
            $db->getQuery(true)
               ->update($db->quoteName('#__extensions'))
               ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($params)))
               ->where($db->quoteName('extension_id') . ' = ' . $extensionId)
        );
        $db->execute();

        $this->log('Credentials saved. selectedMode=' . ($selectedIsSandbox ? 'sandbox' : 'live'));

        // Register the webhook auth token in Infynipay so it includes
        // `Authorization: Bearer {secret}` when calling the Joomla webhook URL.
        if ($sandboxAppId !== '' && $sandboxToken !== '' && !empty($params['sandbox_webhook_secret'])) {
            $this->registerWebhookAuth($sandboxAppId, $sandboxToken, $params['sandbox_webhook_secret']);
        }
        if ($liveAppId !== '' && $liveToken !== '' && !empty($params['live_webhook_secret'])) {
            $this->registerWebhookAuth($liveAppId, $liveToken, $params['live_webhook_secret']);
        }

        return [false, ''];
    }

    /**
     * PATCH /api/apps/{appId} — tells Infynipay which Authorization header
     * to send when it calls the Joomla webhook URL.
     * Infynipay will then include: Authorization: Bearer {webhookSecret}
     */
    private function registerWebhookAuth(string $appId, string $token, string $webhookSecret): void
    {
        $apiUrl = rtrim(Infynipay::PAYMENT_APP_API_URL, '/') . '/api/apps/' . urlencode($appId);

        $payload = json_encode([
            'webhookAuthorizationHeaderScheme'    => ['value' => 'Bearer'],
            'webhookAuthorizationHeaderParameter' => ['value' => $webhookSecret],
        ], JSON_UNESCAPED_SLASHES);

        try {
            $http     = HttpFactory::getHttp();
            $response = $http->patch(
                $apiUrl,
                $payload,
                [
                    'accept'        => 'text/plain',
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
                15
            );
            $this->log('registerWebhookAuth: appId=' . $appId . ' webhookSecret=' . $webhookSecret . ' HTTP=' . $response->code . ($response->code === 200 ? ' OK' : ''));
        } catch (\RuntimeException $e) {
            $this->log('registerWebhookAuth: failed — ' . $e->getMessage(), Log::WARNING);
        }
    }

    private function sanitizeStoredParams(int $extensionId, array $params): array
    {
        $legacyKeys = [
            'merchant_id',
            'secret_key',
            'gateway_url',
            'sandbox_gateway_url',
            'payment_info',
        ];

        $changed = false;

        foreach ($legacyKeys as $key) {
            if (!array_key_exists($key, $params)) {
                continue;
            }

            unset($params[$key]);
            $changed = true;
        }

        if (!$changed || $extensionId <= 0) {
            return $params;
        }

        $db = Factory::getDbo();
        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($params)))
                ->where($db->quoteName('extension_id') . ' = ' . $extensionId)
        );
        $db->execute();

        $this->log('Removed legacy params from plugin configuration. extension_id=' . $extensionId);

        return $params;
    }
}