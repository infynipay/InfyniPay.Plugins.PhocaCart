<?php
/**
 * Infynipay Payment Plugin for Phoca Cart
 *
 * Joomla 4 / 5 — namespace: Joomla\Plugin\PhocacartPayment\Infynipay
 *
 * Plugin group : pcp  (Phoca Cart Payment)
 *
 * PCP events handled
 * ------------------
 * onPCPbeforeSaveOrder          – called just before the order is saved
 * onPCPbeforeSetPaymentForm     – renders the "pay now" button on checkout confirm page
 * onPCPbeforeProceedToPayment   – redirects the buyer to the payment gateway
 * onPCPbeforeCheckPayment       – processes the return URL from the gateway
 * onPCPonPaymentWebhook         – handles server-to-server webhook / IPN
 * onPCPafterCancelPayment       – buyer clicked "cancel" on the gateway
 * onPCPonDisplayPaymentPos      – POS (point-of-sale) display helper
 */

namespace Joomla\Plugin\PhocacartPayment\Infynipay\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Log\Log;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

use Joomla\Plugin\PhocacartPayment\Infynipay\Helper\ProviderIconHelper;

use function array_diff_key;
use function array_values;
use function file_get_contents;
use function file_put_contents;
use function hash_equals;
use function hash_hmac;
use function http_response_code;
use function implode;
use function is_array;
use function is_file;
use function json_decode;
use function json_encode;
use function ksort;
use function number_format;
use function round;
use function rtrim;
use function strtolower;
use function strtoupper;
use function substr;
use function urlencode;

class Infynipay extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    // Infynipay service URLs (single source of truth — also used by field classes)
    public const PANEL_URL           = 'https://console.infynipay.com';
    public const PAYMENT_APP_API_URL = 'https://appapi.infynipay.com';
    public const PAYMENT_API_URL     = 'https://api.infynipay.com';

    protected string $infynipay_panel_url           = self::PANEL_URL;
    protected string $infynipay_payment_app_api_url = self::PAYMENT_APP_API_URL;
    protected string $infynipay_payment_api_url     = self::PAYMENT_API_URL;

    // -----------------------------------------------------------------------
    // SubscriberInterface — map Joomla events to methods
    // -----------------------------------------------------------------------

    public static function getSubscribedEvents(): array
    {
        return [
            'onPCPbeforeSaveOrder'         => 'onPCPbeforeSaveOrder',
            'onPCPbeforeSetPaymentForm'    => 'onPCPbeforeSetPaymentForm',
            'onPCPbeforeProceedToPayment'  => 'onPCPbeforeProceedToPayment',
            'onPCPbeforeCheckPayment'      => 'onPCPbeforeCheckPayment',
            'onPCPonPaymentWebhook'        => 'onPCPonPaymentWebhook',
            'onPCPafterCancelPayment'      => 'onPCPafterCancelPayment',
            'onPCPafterRecievePayment'     => 'onPCPafterRecievePayment',
            'onPCPonDisplayPaymentPos'     => 'onPCPonDisplayPaymentPos',
            'onBeforeRender'               => 'onBeforeRender',
            'onAjaxInfynipay'            => 'onAjaxInfynipay',
        ];
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    /** Return the active gateway URL. */
    private function getGatewayUrl(): string
    {
        return rtrim($this->infynipay_payment_api_url, '/');
    }

    /** Return the active app_id and token based on sandbox/live mode. */
    private function getActiveCredentials(): array
    {
        $isSandbox = (int) $this->params->get('sandbox', 1) === 1;

        return [
            'app_id' => (string) $this->params->get($isSandbox ? 'sandbox_app_id' : 'live_app_id', ''),
            'token'  => (string) $this->params->get($isSandbox ? 'sandbox_token'  : 'live_token',  ''),
        ];
    }

    /** True when the required credentials for the current mode are missing. */
    private function needsSetup(): bool
    {
        $creds = $this->getActiveCredentials();

        return $creds['app_id'] === '' || $creds['token'] === '';
    }

    /** Build HMAC-SHA256 signature for a payload array (sorted by key). */
    private function buildSignature(array $data, string $secretKey): string
    {
        ksort($data);
        $plain = implode('|', array_values($data));

        return hash_hmac('sha256', $plain, $secretKey);
    }

    /** Verify the signature received from the gateway. */
    private function verifySignature(array $data, string $receivedSig, string $secretKey): bool
    {
        $expected = $this->buildSignature($data, $secretKey);

        return hash_equals($expected, $receivedSig);
    }

    /** Return the plugin name identifier. */
    private function getPluginName(): string
    {
        return 'infynipay';
    }

    /**
     * Output a self-redirecting HTML page and exit.
     * Used instead of $app->redirect() inside com_ajax (format=raw) callbacks
     * where response headers may already be managed by Joomla's output buffer.
     */

    /**
     * Builds the Infynipay admin panel URL for a given payment.
     */
    private function buildPanelDetailUrl(int $phPaymentId): string
    {
        if ($phPaymentId <= 0) {
            return '';
        }

        $appId = $this->getActiveCredentials()['app_id'];

        return rtrim($this->infynipay_panel_url, '/')
            . '/' . urlencode($appId)
            . '/payments/detail?' . urlencode($appId)
            . '&paymentId=' . $phPaymentId;
    }

    /**
     * Merges key-value pairs into the JSON stored in params_payment for an order.
     */
    private function mergeParamsPayment(int $orderId, array $merge): void
    {
        try {
            $db    = Factory::getDbo();
            $query = $db->getQuery(true)
                ->select($db->quoteName('params_payment'))
                ->from($db->quoteName('#__phocacart_orders'))
                ->where($db->quoteName('id') . ' = ' . $orderId);
            $db->setQuery($query);
            $current = (string) ($db->loadResult() ?? '');
            $params  = $current !== '' ? (json_decode($current, true) ?? []) : [];
            $params  = array_merge($params, $merge);
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__phocacart_orders'))
                    ->set($db->quoteName('params_payment') . ' = ' . $db->quote(json_encode($params)))
                    ->where($db->quoteName('id') . ' = ' . $orderId)
            );
            $db->execute();
        } catch (\Exception $e) {
            $this->log('mergeParamsPayment failed for orderId=' . $orderId . ': ' . $e->getMessage(), Log::WARNING);
        }
    }

    /**
     * Rebuild the minimal PhocaCart info-session payload for a finished order.
     *
     * PhocaCart's info view reads order context only from the phocaCart session.
     * When the buyer returns from an external payment page after the original
     * checkout session has expired, we need to restore that payload from order_id.
     */
    private function restoreInfoSessionForOrder(int $orderId, array $message = []): bool
    {
        if ($orderId < 1) {
            return false;
        }

        $orderView   = new \PhocacartOrderView();
        $orderCommon = $orderView->getItemCommon($orderId);

        if (!$orderCommon || !isset($orderCommon->id)) {
            $this->log('restoreInfoSessionForOrder: order not found. orderId=' . $orderId, Log::WARNING);
            return false;
        }

        $shippingMethod = json_decode((string) ($orderCommon->params_shipping ?? ''), true) ?: [];
        $paymentMethod  = json_decode((string) ($orderCommon->params_payment ?? ''), true) ?: [];

        $session = Factory::getSession();
        $session->set('infoaction', (int) $session->get('infoaction', 3, 'phocaCart') ?: 3, 'phocaCart');
        $session->set('infodata', [
            'order_id'        => (int) $orderCommon->id,
            'order_token'     => (string) ($orderCommon->order_token ?? ''),
            'user_id'         => (int) ($orderCommon->user_id ?? 0),
            'shipping_id'     => (int) ($orderCommon->shipping_id ?? 0),
            'payment_id'      => (int) ($orderCommon->payment_id ?? 0),
            'shipping_method' => (string) ($shippingMethod['method'] ?? ''),
            'payment_method'  => (string) ($paymentMethod['method'] ?? $this->getPluginName()),
        ], 'phocaCart');

        if (!empty($message)) {
            $session->set('infomessage', $message, 'phocaCart');
        }

        return true;
    }

    /**
     * Applies a Infynipay payment status to a PhocaCart order.
     *
     * Shared by both the server-to-server webhook and the return-page fallback so
     * that the order is always updated regardless of which path fires first.
     *
     * Rules:
     * - For the return-page fallback, only terminal states (captured / failed / disputed)
     *   are processed; "pending" is never applied to avoid downgrading an order that the
     *   webhook already promoted.
     * - Idempotent: silently skips if the order is already at the target status.
     *
     * @param  string $trigger       Label used in log messages, e.g. "Webhook" or "Return fallback"
     * @param  bool   $terminalOnly  When true, skips processing if $paymentState is not terminal
     * @return string                'changed' | 'skipped' | 'not_found'
     */
    private function syncPaymentStatus(
        int    $orderId,
        string $paymentState,
        int    $phPaymentId,
        string $transactionId,
        string $trigger,
        bool   $terminalOnly = false
    ): string {
        if ($terminalOnly && !in_array($paymentState, ['captured', 'failed', 'disputed', 'refunded'], true)) {
            $this->log($trigger . ' skipped — non-terminal state. orderId=' . $orderId . ' paymentState=' . $paymentState);
            return 'skipped';
        }

        $newStatus = match ($paymentState) {
            'captured'  => (int) $this->params->get('order_status_success',   2),
            'failed'    => (int) $this->params->get('order_status_failed',    3),
            'disputed'  => (int) $this->params->get('order_status_cancelled', 3),
            'refunded'  => (int) $this->params->get('order_status_cancelled', 3),
            default     => (int) $this->params->get('order_status_pending',   1),
        };

        $orderView   = new \PhocacartOrderView();
        $orderCommon = $orderView->getItemCommon($orderId);

        if (!$orderCommon || !isset($orderCommon->id)) {
            $this->log($trigger . ' rejected — order not found. orderId=' . $orderId . ' phPaymentId=' . $phPaymentId, Log::WARNING);
            return 'not_found';
        }

        // Idempotency: if the order is already at the target status, nothing to do.
        if ((int) $orderCommon->status_id === $newStatus) {
            $this->log($trigger . ' skipped — already processed. orderId=' . $orderId . ' phPaymentId=' . $phPaymentId . ' status=' . $newStatus);
            return 'skipped';
        }

        // Store Infynipay payment link for admin panel
        if ($phPaymentId > 0) {
            $this->mergeParamsPayment($orderId, [
                'method'        => 'infynipay',
                'ph_payment_id' => $phPaymentId,
                'ph_detail_url' => $this->buildPanelDetailUrl($phPaymentId),
            ]);
        }

        // 1) Change status in order table
        \PhocacartOrderStatus::changeStatusInOrderTable((int) $orderId, (int) $newStatus);

        // 2) Change status (sends emails / notifications)
        $notify = false;
        try {
            $notify = \PhocacartOrderStatus::changeStatus((int) $orderId, (int) $newStatus, $orderCommon->order_token);
        } catch (\RuntimeException $e) {
            $this->log($trigger . ' changeStatus exception: ' . $e->getMessage() . '. orderId=' . $orderId . ' phPaymentId=' . $phPaymentId, Log::ERROR);
        }

        // 3) Set history
        $comment = 'Order status changed by Infynipay ' . $trigger . '.'
            . "\nPayment State: " . $paymentState
            . "\nTransaction ID: " . $transactionId;
        \PhocacartOrderStatus::setHistory((int) $orderId, (int) $newStatus, (int) $notify, $comment);

        $this->log($trigger . ' completed. orderId=' . $orderId . ' phPaymentId=' . $phPaymentId . ' paymentState=' . $paymentState . ' newStatus=' . $newStatus);

        return 'changed';
    }

    private function jsRedirect(string $url, string $message = '', string $type = 'message'): never
    {
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $jsUrl   = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $msgHtml = '';
        if ($message !== '') {
            $colour  = $type === 'error' ? '#f8d7da' : '#d1e7dd';
            $msgHtml = '<p style="font-family:sans-serif;padding:8px 12px;background:' . $colour . ';border-radius:4px">'
                     . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
           . '<meta http-equiv="refresh" content="0;url=' . $safeUrl . '">'
           . '</head><body>' . $msgHtml
           . '<script>window.location.replace(' . $jsUrl . ');</script>'
           . '</body></html>';

        exit;
    }

    private function jsDelayedRedirect(string $url, string $message, string $type = 'error', int $seconds = 1): never
    {
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $jsUrl   = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $colour  = $type === 'error' ? '#f8d7da' : '#d1e7dd';
        $msgHtml = '<p style="font-family:sans-serif;padding:8px 12px;background:' . $colour . ';border-radius:4px">'
                 . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';

        echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
           . '<meta http-equiv="refresh" content="' . $seconds . ';url=' . $safeUrl . '">'
           . '</head><body>' . $msgHtml
           . '<script>setTimeout(function(){window.location.replace(' . $jsUrl . ');},' . ($seconds * 1000) . ');</script>'
           . '</body></html>';

        exit;
    }

    /** Write a log entry (stored in Joomla logs folder). */
    private function log(string $message, int $priority = Log::INFO): void
    {
        static $loggerRegistered = false;

        if (!$loggerRegistered) {
            Log::addLogger(
                ['text_file' => 'plg_pcp_infynipay.php'],
                Log::ALL,
                ['plg_pcp_infynipay']
            );
            $loggerRegistered = true;
        }

        Log::add($message, $priority, 'plg_pcp_infynipay');
    }

    // -----------------------------------------------------------------------
    // onBeforeRender  (inject provider icons on PhocaCart checkout page)
    // -----------------------------------------------------------------------

    /**
     * Injects a small JS snippet on any PhocaCart frontend page.
     * The JS detects the Infynipay radio input in the checkout payment list,
     * then fetches available provider icons via com_ajax and renders them inline.
     *
     * This works because BeforeShowPossiblePaymentMethod (which fires during
     * the checkout view) calls PluginHelper::importPlugin('pcp'), which loads
     * our plugin and registers all its subscribed events — including this one —
     * before onBeforeRender fires.
     */
    public function onBeforeRender(Event $event): void
    {
        $app = Factory::getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $input = $app->getInput();

        if ($input->getString('option') !== 'com_phocacart') {
            return;
        }

        if ($this->needsSetup()) {
            return;
        }

        // Look up the Infynipay payment method ID from the DB.
        $db    = Factory::getDbo();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__phocacart_payment_methods'))
            ->where($db->quoteName('alias')     . ' = ' . $db->quote('infynipay'))
            ->where($db->quoteName('published') . ' = 1');
        $db->setQuery($query);
        $paymentMethodId = (int) $db->loadResult();

        if ($paymentMethodId === 0) {
            return;
        }

        $ajaxUrl     = Uri::base() . 'index.php?option=com_ajax&plugin=infynipay&group=pcp&action=checkoutmethods&format=raw&shop=phocacart';
        $jsAjaxUrl   = json_encode($ajaxUrl,         JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $jsPaymentId = json_encode($paymentMethodId, JSON_UNESCAPED_SLASHES);

        $script = <<<JS
(function () {
    var ajaxUrl   = {$jsAjaxUrl};
    var paymentId = {$jsPaymentId};

    function init() {
        var radio = document.querySelector('input[name="phpaymentopt"][value="' + paymentId + '"]');
        if (!radio) return;

        // Find the label wrapping this radio
        var label = radio.closest('label');
        if (!label) return;

        // The parent is .controls div
        var controls = label.parentElement;
        if (!controls) return;

        // Inject minimal CSS for the icon strip
        if (!document.getElementById('phh-ico-css')) {
            var s = document.createElement('style');
            s.id  = 'phh-ico-css';
            s.textContent =
                '.phh-icons{display:inline-flex;flex-wrap:wrap;align-items:center;gap:6px;margin-left:10px;vertical-align:middle}'
                + '.phh-icons img{height:20px;width:auto;max-width:48px;object-fit:contain;vertical-align:middle}'
                + '.phh-icons .phh-cc{display:inline-flex;align-items:center}'
                + '.phh-icons .phh-cc svg{height:18px;width:auto;color:#374151;vertical-align:middle}';
            document.head.appendChild(s);
        }

        // Create the icons container - insert it right after the label (inside controls)
        var strip = document.createElement('span');
        strip.className = 'phh-icons';
        strip.id = 'phh-icons-strip';
        // Insert after label
        if (label.nextSibling) {
            controls.insertBefore(strip, label.nextSibling);
        } else {
            controls.appendChild(strip);
        }

        // Fetch checkout methods and render icons
        fetch(ajaxUrl, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (methods) {
                if (!Array.isArray(methods) || !methods.length) return;
                var html = '';
                for (var i = 0; i < methods.length; i++) {
                    var group  = methods[i];
                    var method = group.checkoutMethod || '';
                    var items  = group.paymentCheckoutMethodItems || [];

                    if (method === 'CreditCard') {
                        html += '<span class="phh-cc"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/><line x1="5" y1="15" x2="9" y2="15"/></svg></span>';
                    } else if (method === 'ProviderHostedPage') {
                        for (var j = 0; j < items.length; j++) {
                            var profile = items[j] && items[j].paymentProfile;
                            if (!profile) continue;
                            var prov = profile.paymentProvider;
                            if (!prov) continue;
                            var iconUrl = prov.iconUri1 || prov.iconUri2 || '';
                            var name = (prov.provider || '').replace(/</g,'&lt;').replace(/"/g,'&quot;');
                            if (iconUrl && (iconUrl.indexOf('https://') === 0 || iconUrl.indexOf('data:image/') === 0)) {
                                html += '<img src="' + iconUrl + '" alt="' + name + '" title="' + name + '">';
                            }
                        }
                    }
                }
                strip.innerHTML = html;
            })
            .catch(function () {});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
JS;

        Factory::getDocument()->addScriptDeclaration($script);
    }

    // -----------------------------------------------------------------------
    // onPCPbeforeSaveOrder
    // -----------------------------------------------------------------------

    /**
     * Called before the order is persisted.
     * Use this to validate / mutate order data specific to this gateway.
     */
    public function onPCPbeforeSaveOrder(Event $event): void
    {
        $eventData = $event->getArgument('eventData');

        if (!isset($eventData['pluginname']) || $eventData['pluginname'] !== $this->getPluginName()) {
            return;
        }

        // Nothing to mutate for Infynipay at this stage.
        // The order will be saved with the pending status set in the manifest params.
    }

    // -----------------------------------------------------------------------
    // onPCPbeforeSetPaymentForm
    // -----------------------------------------------------------------------

    /**
     * Builds the payment redirect form shown in the payment view.
     * The HTML written to $form is rendered by PhocaCart's payment view template.
     */
    public function onPCPbeforeSetPaymentForm(Event $event): void
    {
        $eventData = $event->getArgument('eventData');

        $this->log(sprintf('[DEBUG] onPCPbeforeSetPaymentForm fired. pluginname=%s', $eventData['pluginname'] ?? '(not set)'));

        if (!isset($eventData['pluginname']) || $eventData['pluginname'] !== $this->getPluginName()) {
            $this->log(sprintf('[DEBUG] onPCPbeforeSetPaymentForm skipped — pluginname mismatch (got=%s expected=%s)', $eventData['pluginname'] ?? '(not set)', $this->getPluginName()));
            return;
        }

        if ($this->needsSetup()) {
            $this->log('Payment form requested but plugin is not connected to Infynipay.', Log::ERROR);

            return;
        }

        // Explicitly load plugin language from its own folder (autoloadLanguage
        // defaults to JPATH_ADMINISTRATOR which is wrong for frontend rendering).
        Factory::getLanguage()->load(
            'plg_pcp_infynipay',
            JPATH_PLUGINS . '/pcp/infynipay'
        );

        $orderData  = $event->getArgument('orderData');

        // Guard: if PhocaCart passes empty/null orderData the form cannot be built
        if (empty($orderData) || !isset($orderData['common']) || !isset($orderData['common']->id) || (int) $orderData['common']->id < 1) {
            $this->log('Payment form: orderData is empty or has no valid order — form cannot render. Keys=' . (is_array($orderData) ? implode(',', array_keys($orderData)) : 'null'), Log::WARNING);

            return;
        }

        $creds      = $this->getActiveCredentials();
        $merchantId = $creds['app_id'];
        $secretKey  = $creds['token'];

        // ------------------------------------------------------------------
        // Calculate amounts
        // ------------------------------------------------------------------
        $cartBrutto    = 0.0;
        $paymentBrutto = 0.0;
        $rounding      = PHP_ROUND_HALF_UP;

        $r = isset($orderData['common']->currency_exchange_rate) && (float) $orderData['common']->currency_exchange_rate > 0
            ? (float) $orderData['common']->currency_exchange_rate
            : 1.0;

        if (!empty($orderData['products'])) {
            foreach ($orderData['products'] as $product) {
                $paymentBrutto += round((float) $product->netto * $r, 2, $rounding) * (int) $product->quantity;
            }
        }

        if (!empty($orderData['total'])) {
            foreach ($orderData['total'] as $total) {
                if ($total->amount == 0 && $total->amount_currency == 0) {
                    continue;
                }

                if ($total->type === 'brutto') {
                    $cartBrutto = $total->amount_currency != 0
                        ? round((float) $total->amount_currency, 2, $rounding)
                        : round((float) $total->amount * $r, 2, $rounding);
                } else {
                    $paymentBrutto += $total->amount_currency != 0
                        ? round((float) $total->amount_currency, 2, $rounding)
                        : round((float) $total->amount * $r, 2, $rounding);
                }
            }
        }

        $finalAmount = $cartBrutto > 0 ? $cartBrutto : round($paymentBrutto, 2, $rounding);

        // ------------------------------------------------------------------
        // Build return / cancel / webhook URLs
        // ------------------------------------------------------------------
        $paymentId  = isset($orderData['common']->payment_id) ? (int) $orderData['common']->payment_id : 0;
        $orderId    = isset($orderData['common']->id) ? (int) $orderData['common']->id : 0;
        $currency   = strtoupper((string) ($orderData['common']->currency_code ?? 'USD'));

        $baseUrl = Uri::base();

        // When using a reverse proxy / tunnel (e.g. Cloudflare tunnel), the tunnel URL
        // rotates on each restart but Joomla's configured $live_site keeps the old URL.
        // Override $baseUrl with the scheme + host from the actual incoming request so
        // the "Continue to Pay" button URL is always reachable by the user's browser.
        $requestHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($requestHost !== '') {
            $parsedBase   = parse_url($baseUrl);
            $configHost   = $parsedBase['host'] ?? '';
            if ($configHost !== $requestHost) {
                $scheme  = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : ($parsedBase['scheme'] ?? 'http');
                $path    = $parsedBase['path'] ?? '/';
                $baseUrl = $scheme . '://' . $requestHost . $path;
            }
        }

        $this->log(sprintf('[Order #%d] Payment page rendered. amount=%.2f %s (hosted payment deferred to button click)', $orderId, $finalAmount, $currency));

        // Fetch available checkout methods for the provider icon display
        $checkoutMethods = $this->fetchCheckoutMethods($merchantId, $creds['token']);

        // Build the pay URL — hosted payment will be created when user clicks "Continue to Pay"
        // orderId is embedded in the URL so the AJAX handler can identify the order
        // even if the session is lost (e.g. page reload after a long delay).
        $payActionUrl = $baseUrl . 'index.php?option=com_ajax&plugin=infynipay&group=pcp&format=raw&action=pay&shop=phocacart&order_id=' . $orderId;

        $html = $this->buildPaymentMethodSelectionForm($checkoutMethods, $payActionUrl, $finalAmount, $currency, $orderId);

        $form  = $event->getArgument('form');
        $form .= $html;
        $event->setArgument('form', $form);
    }

    // -----------------------------------------------------------------------
    // onPCPbeforeProceedToPayment
    // -----------------------------------------------------------------------

    /**
     * Sets proceed=1 to signal PhocaCart to redirect the buyer to the payment view.
     * The actual payment form is built in onPCPbeforeSetPaymentForm.
     */
    public function onPCPbeforeProceedToPayment(Event $event): void
    {
        $eventData = $event->getArgument('eventData');

        $receivedPlugin = $eventData['pluginname'] ?? '(not set)';
        $this->log(sprintf('[DEBUG] onPCPbeforeProceedToPayment fired. pluginname=%s', $receivedPlugin));

        if (!isset($eventData['pluginname']) || $eventData['pluginname'] !== $this->getPluginName()) {
            return;
        }

        if ($this->needsSetup()) {
            $this->log('Plugin not connected to Infynipay. Please complete setup in plugin settings.', Log::ERROR);

            return;
        }

        // Signal PhocaCart to redirect the buyer to the payment view
        $event->setArgument('proceed', 1);
        $this->log('[DEBUG] proceed=1 set — redirecting to payment view');
    }

    // -----------------------------------------------------------------------
    // onPCPbeforeCheckPayment  (return URL callback)
    // -----------------------------------------------------------------------

    /**
     * Processes the buyer's return after completing (or failing) payment.
     * Phoca Cart calls this when the return URL is hit.
     */
    public function onPCPbeforeCheckPayment(Event $event): void
    {
        $eventData = $event->getArgument('eventData');

        if (!isset($eventData['pluginname']) || $eventData['pluginname'] !== $this->getPluginName()) {
            return;
        }

        $paymentId = $event->getArgument('paymentId');

        // Log the customer's return from the Infynipay hosted payment page.
        $input      = Factory::getApplication()->getInput();
        $payKey     = $input->getString('paymentKey', '');
        $returnedId = $input->getString('paymentId', (string) $paymentId);

        $this->log(sprintf('Payment return: customer returned from Infynipay. paymentId=%s paymentKey=%s',
            $returnedId, $payKey !== '' ? substr($payKey, 0, 8) . '...' : '(none)'));
    }

    // -----------------------------------------------------------------------
    // onPCPafterRecievePayment  (display confirmation card)
    // -----------------------------------------------------------------------

    /**
     * Renders a styled payment confirmation card on the info/thank-you page.
     */
    public function onPCPafterRecievePayment(Event $event): void
    {
        $eventData = $event->getArgument('eventData');

        if (!isset($eventData['pluginname']) || $eventData['pluginname'] !== $this->getPluginName()) {
            return;
        }

        // Handle APP_INACTIVE error — show "service unavailable" card
        $phError = Factory::getApplication()->getInput()->getString('ph_error', '');
        if ($phError === 'inactive') {
            $message = $event->getArgument('message');
            $message['payment_nodownload'] = $this->buildServiceUnavailableCard();
            $event->setArgument('message', $message);
            return;
        }

        // Prefer order_id from the return URL (embedded by handleAjaxPay) so the result
        // page works even when the PHP session has expired during a long payment flow.
        $orderId = Factory::getApplication()->getInput()->getInt('order_id', 0);
        if ($orderId < 1) {
            $infoData = Factory::getSession()->get('infodata', [], 'phocaCart');
            $orderId  = (int) ($infoData['order_id'] ?? 0);
        }

        if ($orderId < 1) {
            $this->log('Result page: could not determine orderId from URL or session.', Log::WARNING);
            return;
        }

        $orderView   = new \PhocacartOrderView();
        $orderCommon = $orderView->getItemCommon($orderId);

        if (!$orderCommon || !isset($orderCommon->id)) {
            return;
        }

        $orderNumber  = $orderCommon->order_number ?: '#' . $orderId;
        $currencyCode = strtoupper($orderCommon->currency_code ?: 'USD');
        $date         = $orderCommon->date ? date('M j, Y', strtotime($orderCommon->date)) : '';

        // Get total from order_total table
        $orderTotal = $orderView->getItemTotal($orderId);
        $totalAmount = '0.00';
        if (!empty($orderTotal)) {
            foreach ($orderTotal as $row) {
                if (isset($row->type) && $row->type === 'brutto') {
                    $totalAmount = number_format((float) $row->amount, 2);
                    break;
                }
            }
        }

        // Call Infynipay API to get payment state and redirectUrl
        $creds  = $this->getActiveCredentials();

        $verifyUrl = rtrim($this->infynipay_payment_api_url, '/')
                   . '/api/apps/' . urlencode($creds['app_id']) . '/payments/referenceId:' . $orderId;

        $paymentState  = 'pending';
        $redirectUrl   = '';
        $phPaymentId   = 0;
        $transactionId = '';

        try {
            $http     = HttpFactory::getHttp();
            $response = $http->get($verifyUrl, [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $creds['token'],
            ], 15);

            if ($response->code === 200) {
                $apiData = json_decode((string) $response->body, true);
                if (is_array($apiData)) {
                    $paymentState  = strtolower((string) ($apiData['paymentState'] ?? 'pending'));
                    $redirectUrl   = (string) ($apiData['redirectUrl'] ?? '');
                    $phPaymentId   = (int) ($apiData['paymentId'] ?? 0);
                    $transactionId = (string) ($apiData['transactionId'] ?? '');
                }
            }

            $this->log('Result page: API state=' . $paymentState . ' orderId=' . $orderId . ' phPaymentId=' . $phPaymentId);
        } catch (\RuntimeException $e) {
            $this->log('Result page: API call failed — ' . $e->getMessage(), Log::WARNING);
        }

        // Fallback: if the webhook was not received, apply the status change here.
        // $terminalOnly=true ensures we never downgrade an order to "pending".
        // syncPaymentStatus() reloads a fresh order row so a concurrent webhook is detected.
        $this->syncPaymentStatus($orderId, $paymentState, $phPaymentId, $transactionId, 'CatchProviderReturn', true);

        $message = $event->getArgument('message');
        $message['payment_nodownload'] = $this->buildConfirmationCard($orderId, $totalAmount, $currencyCode, $date, $paymentState, $redirectUrl);
        $event->setArgument('message', $message);

        // Rehydrate the info session for the redirect to com_phocacart info view.
        $this->restoreInfoSessionForOrder($orderId, $message);
    }

    /**
     * Builds the HTML for the payment confirmation card.
     */
    private function buildConfirmationCard(int $orderId, string $amount, string $currency, string $date, string $paymentState = 'pending', string $redirectUrl = ''): string
    {
        $currencySymbol = match ($currency) {
            'EUR' => '&euro;',
            'GBP' => '&pound;',
            'JPY' => '&yen;',
            default => '$',
        };

        // Determine card variant based on payment state
        // Infynipay states: Captured (success), Failed, Disputed (failed), anything else = Pending
        $isConfirmed = ($paymentState === 'captured');
        $isFailed    = in_array($paymentState, ['failed', 'disputed'], true);
        $isPending   = !$isConfirmed && !$isFailed;

        if ($isConfirmed) {
            $headerTitle = 'Payment Successful';
            $headerSub   = 'Your transaction has been completed';
            $headerIcon  = '<polyline points="20 6 9 17 4 12"/>';
            $badgeBg     = 'background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%)';
            $badgeText   = 'Confirmed';
            $dotColor    = '#34d399';
        } elseif ($isFailed) {
            $headerTitle = 'Payment Failed';
            $headerSub   = 'Your transaction could not be completed';
            $headerIcon  = '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>';
            $badgeBg     = 'background:#dc2626';
            $badgeText   = 'Failed';
            $dotColor    = '#fca5a5';
        } else {
            $headerTitle = 'Payment Pending';
            $headerSub   = 'Your payment is awaiting confirmation';
            $headerIcon  = '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>';
            $badgeBg     = 'background:#f59e0b';
            $badgeText   = 'Pending';
            $dotColor    = '#fde68a';
        }

        // "Continue Payment" button for pending/failed states when redirectUrl is available
        $continueBtn = '';
        if (!$isConfirmed && $redirectUrl !== '') {
            $safeHref    = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');
            $continueBtn = '<a href="' . $safeHref . '" class="phrc-continue">Continue Payment &rarr;</a>';
        }

        $isSandbox = (int) $this->params->get('sandbox', 1) === 1;
        $sandboxHtml = $isSandbox
            ? '<div class="phrc-sandbox"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636-2.87L13.637 3.59a1.914 1.914 0 0 0-3.274 0z"/><path d="M12 17h.01"/></svg>Sandbox</div>'
            : '';

        return <<<HTML
<style>
.alert:has(.phrc){background:none!important;border:none!important;box-shadow:none!important;padding:0!important;margin:0!important;color:inherit!important}
.phrc{max-width:560px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#fff;border-radius:24px;border:1.5px solid #e2e8f0;overflow:hidden;text-align:center}
.phrc-top{padding:40px 32px 28px;background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%)}
.phrc-check{width:64px;height:64px;background:rgba(255,255,255,.15);border:2px solid rgba(255,255,255,.3);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;backdrop-filter:blur(4px)}
.phrc-check svg{width:32px;height:32px;stroke:#fff;fill:none}
.phrc-h{font-size:22px;font-weight:700;color:#fff;margin:0;letter-spacing:-.3px}
.phrc-sub{font-size:15px;color:rgba(255,255,255,.7);margin:8px 0 0;font-weight:400}
.phrc-body{padding:32px 32px 28px}
.phrc-amt{font-size:38px;font-weight:800;color:#111827;margin:0 0 4px;letter-spacing:-.5px}
.phrc-cur{font-size:13px;color:#6b7280;text-transform:uppercase;letter-spacing:1px;font-weight:600;margin-bottom:24px}
.phrc-rows{border-top:1px solid #f1f5f9;padding-top:20px}
.phrc-row{display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid #f8fafc}
.phrc-row:last-child{border-bottom:none}
.phrc-lbl{font-size:14px;color:#94a3b8;font-weight:500}
.phrc-val{font-size:15px;color:#1e293b;font-weight:600}
.phrc-badge{display:inline-flex;align-items:center;gap:6px;margin-top:20px;{$badgeBg};color:#fff;font-size:14px;font-weight:600;padding:10px 20px;border-radius:20px}
.phrc-dot{width:8px;height:8px;background:{$dotColor};border-radius:50%;animation:phrc-p 2s infinite}
@keyframes phrc-p{0%,100%{opacity:1}50%{opacity:.3}}
.phrc-continue{display:inline-flex;align-items:center;justify-content:center;margin-top:20px;padding:14px 28px;border-radius:14px;background:linear-gradient(135deg,#1d4ed8 0%,#0f172a 100%);color:#fff;font-size:15px;font-weight:700;text-decoration:none;letter-spacing:-.01em;transition:opacity .15s,transform .1s}
.phrc-continue:hover{opacity:.92;transform:translateY(-1px)}
.phrc-sandbox{display:inline-flex;align-items:center;gap:4px;margin-top:12px;padding:4px 10px;border-radius:6px;background:rgba(251,191,36,.15);border:1px solid rgba(251,191,36,.4);color:#b45309;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
.phrc-sandbox svg{width:12px;height:12px;flex-shrink:0}
</style>
<div class="phrc">
  <div class="phrc-top">
    <div class="phrc-check"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">{$headerIcon}</svg></div>
    <h3 class="phrc-h">{$headerTitle}</h3>
    <p class="phrc-sub">{$headerSub}</p>
  </div>
  <div class="phrc-body">
    <p class="phrc-amt">{$currencySymbol}{$amount}</p>
    <p class="phrc-cur">{$currency}</p>
    <div class="phrc-rows">
      <div class="phrc-row"><span class="phrc-lbl">Order ID</span><span class="phrc-val">{$sandboxHtml} #{$orderId}</span></div>
      <div class="phrc-row"><span class="phrc-lbl">Date</span><span class="phrc-val">{$date}</span></div>
      <div class="phrc-row"><span class="phrc-lbl">Payment Method</span><span class="phrc-val">Infynipay</span></div>
    </div>
    <div class="phrc-badge"><span class="phrc-dot"></span>{$badgeText}</div>
    {$continueBtn}
  </div>
</div>
HTML;
    }

    /**
     * Builds the HTML card shown when the payment service is unavailable (app inactive).
     */
    private function buildServiceUnavailableCard(): string
    {
        return <<<'HTML'
<style>
.alert:has(.phrc-unavail){background:none!important;border:none!important;box-shadow:none!important;padding:0!important;margin:0!important;color:inherit!important}
.phrc-unavail{max-width:560px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#fff;border-radius:24px;border:1.5px solid #e2e8f0;overflow:hidden;text-align:center}
.phrc-unavail-top{padding:40px 32px 28px;background:linear-gradient(135deg,#7f1d1d 0%,#991b1b 100%)}
.phrc-unavail-icon{width:64px;height:64px;background:rgba(255,255,255,.15);border:2px solid rgba(255,255,255,.3);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;backdrop-filter:blur(4px)}
.phrc-unavail-icon svg{width:32px;height:32px;stroke:#fff;fill:none}
.phrc-unavail h3{font-size:22px;font-weight:700;color:#fff;margin:0;letter-spacing:-.3px}
.phrc-unavail .phrc-unavail-sub{font-size:15px;color:rgba(255,255,255,.7);margin:8px 0 0;font-weight:400}
.phrc-unavail-body{padding:32px 32px 28px}
.phrc-unavail-msg{font-size:16px;color:#1e293b;font-weight:500;line-height:1.6;margin:0 0 16px}
.phrc-unavail-hint{font-size:14px;color:#64748b;margin:0}
</style>
<div class="phrc-unavail">
  <div class="phrc-unavail-top">
    <div class="phrc-unavail-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
    <h3>Service Unavailable</h3>
    <p class="phrc-unavail-sub">Payment service is temporarily offline</p>
  </div>
  <div class="phrc-unavail-body">
    <p class="phrc-unavail-msg">Payment service is temporarily unavailable.<br>Please contact the store administrator.</p>
    <p class="phrc-unavail-hint">We apologize for the inconvenience. Please try again later.</p>
  </div>
</div>
HTML;
    }

    // -----------------------------------------------------------------------
    // onPCPonPaymentWebhook  (server-to-server IPN)
    // -----------------------------------------------------------------------

    /**
     * Handles the server-to-server webhook notification from Infynipay.
     * This fires independently of the buyer's browser session.
     */
    public function onPCPonPaymentWebhook(Event $event): void
    {
        $eventData = $event->getArgument('eventData');

        if (!isset($eventData['pluginname']) || $eventData['pluginname'] !== $this->getPluginName()) {
            return;
        }

        $paymentId = $event->getArgument('paymentId'); // PhocaCart payment method row ID — used only for routing
        $this->log('Webhook received.');

        // ------------------------------------------------------------------
        // Verify the incoming Bearer token Infynipay sends in Authorization.
        // The secret was registered via PATCH /api/apps/{appId} on activation.
        // ------------------------------------------------------------------
        $isSandbox     = (int) $this->params->get('sandbox', 1) === 1;
        $webhookSecret = $isSandbox
            ? (string) $this->params->get('sandbox_webhook_secret', '')
            : (string) $this->params->get('live_webhook_secret', '');

        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        // Also try getallheaders() for servers that don't populate $_SERVER
        if ($authHeader === '' && function_exists('getallheaders')) {
            $headers    = getallheaders();
            $authHeader = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '');
        }

        $receivedToken = '';
        if (stripos($authHeader, 'Bearer ') === 0) {
            $receivedToken = substr($authHeader, 7);
        }

        if ($webhookSecret === '' || !hash_equals($webhookSecret, $receivedToken)) {
            http_response_code(401);
            $this->log('Webhook rejected — invalid token.', Log::WARNING);
            return;
        }

        // Read raw POST body (Infynipay sends JSON)
        $rawBody = file_get_contents('php://input');

        if ($rawBody === false || $rawBody === '') {
            http_response_code(400);
            $this->log('Webhook rejected — empty body.', Log::WARNING);
            return;
        }

        $data = json_decode($rawBody, true);

        if (!is_array($data)) {
            http_response_code(400);
            $this->log('Webhook rejected — invalid JSON.', Log::WARNING);
            return;
        }

        // Infynipay wraps the payload under a "payment" key: {"payment":{...}}
        // Fall back to the top level for forward-compatibility.
        $payload       = isset($data['payment']) && is_array($data['payment']) ? $data['payment'] : $data;
        $orderId       = (int) ($payload['referenceId'] ?? $payload['order_id'] ?? 0);
        $phPaymentId   = (int) ($payload['paymentId'] ?? 0); // Infynipay transaction ID
        $transactionId = (string) ($payload['transactionId'] ?? $payload['id'] ?? '');

        if ($orderId < 1) {
            http_response_code(400);
            $this->log('Webhook rejected — orderId missing. phPaymentId=' . $phPaymentId, Log::WARNING);
            return;
        }

        // ------------------------------------------------------------------
        // Verify payment state by calling the Infynipay API directly
        // GET {PAYMENT_API_URL}/api/apps/{appId}/payments/referenceId:{referenceId}
        // ------------------------------------------------------------------
        $creds  = $this->getActiveCredentials();
        $appId  = $creds['app_id'];
        $token  = $creds['token'];

        $verifyUrl = rtrim($this->infynipay_payment_api_url, '/')
                   . '/api/apps/' . urlencode($appId) . '/payments/referenceId:' . $orderId;

        $paymentState = '';
        try {
            $http        = HttpFactory::getHttp();
            $apiResponse = $http->get(
                $verifyUrl,
                [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
                30
            );

            if ($apiResponse->code !== 200) {
                http_response_code(502);
                $this->log('Webhook rejected — verify API HTTP=' . $apiResponse->code . '. orderId=' . $orderId . ' phPaymentId=' . $phPaymentId, Log::ERROR);
                return;
            }

            $apiData = json_decode((string) $apiResponse->body, true);

            if (!is_array($apiData)) {
                http_response_code(502);
                $this->log('Webhook rejected — verify API invalid JSON. orderId=' . $orderId . ' phPaymentId=' . $phPaymentId, Log::ERROR);
                return;
            }

            $paymentState  = strtolower((string) ($apiData['paymentState'] ?? ''));
            $transactionId = (string) ($apiData['transactionId'] ?? $transactionId);
        } catch (\RuntimeException $e) {
            http_response_code(502);
            $this->log('Webhook rejected — verify exception: ' . $e->getMessage() . '. orderId=' . $orderId . ' phPaymentId=' . $phPaymentId, Log::ERROR);
            return;
        }

        $result = $this->syncPaymentStatus($orderId, $paymentState, $phPaymentId, $transactionId, 'Webhook process');

        if ($result === 'not_found') {
            http_response_code(404);
            return;
        }

        http_response_code(200);
        if ($result === 'skipped') {
            echo json_encode(['received' => true, 'skipped' => 'already_at_status']);
        } else {
            $newStatus = match ($paymentState) {
                'captured' => (int) $this->params->get('order_status_success',   2),
                'failed'   => (int) $this->params->get('order_status_failed',    3),
                'disputed' => (int) $this->params->get('order_status_cancelled', 3),
                'refunded' => (int) $this->params->get('order_status_cancelled', 3),
                default    => (int) $this->params->get('order_status_pending',   1),
            };
            echo json_encode(['received' => true, 'order_id' => $orderId, 'new_status' => $newStatus]);
        }
    }

    // -----------------------------------------------------------------------
    // onPCPafterCancelPayment
    // -----------------------------------------------------------------------

    /**
     * Called when the buyer cancels payment at the gateway and returns via cancel_url.
     */
    public function onPCPafterCancelPayment(Event $event): void
    {
        $eventData = $event->getArgument('eventData');

        if (!isset($eventData['pluginname']) || $eventData['pluginname'] !== $this->getPluginName()) {
            return;
        }

        $messageId = $event->getArgument('messageId');

        $this->log('Payment cancelled by buyer. messageId=' . $messageId);

        $message = [];
        $event->setArgument('message', $message);
    }

    // -----------------------------------------------------------------------
    // onPCPonDisplayPaymentPos
    // -----------------------------------------------------------------------

    /**
     * Renders POS (Point-of-Sale) payment UI when used from the back-end POS view.
     */
    public function onPCPonDisplayPaymentPos(Event $event): void
    {
        $eventData = $event->getArgument('eventData');

        if (!isset($eventData['pluginname']) || $eventData['pluginname'] !== $this->getPluginName()) {
            return;
        }

        $output  = $event->getArgument('output');
        $output .= '<p class="alert alert-info">' . Text::_('PLG_PCP_INFYNIPAY_POS_NOTICE') . '</p>';
        $event->setArgument('output', $output);
    }

    // -----------------------------------------------------------------------
    // onAjaxInfynipay  (com_ajax OAuth callback)
    // -----------------------------------------------------------------------

    /**
     * Called by Joomla's com_ajax when Infynipay redirects the admin back
     * after the merchant has authorised the integration.
     *
     * URL: administrator/index.php?option=com_ajax&plugin=infynipay&group=pcp&format=raw&{csrf}=1
     *      &licenseId={uuid}&authorizationCode={jwt}
     *
     * licenseId        — UUID used as the path param in /api/licenses/{licenseId}
     * authorizationCode — short-lived JWT used as the Bearer token
     */
    public function onAjaxInfynipay(Event $event): void
    {
        $app   = Factory::getApplication();
        $input = $app->getInput();

        $group = $input->getCmd('group', '');
        if ($group !== '' && $group !== 'pcp') {
            return;
        }

        // Shop isolation: both this plugin (group=pcp) and the J2Commerce Infynipay plugin
        // (group=j2commerce) register onAjaxInfynipay. Joomla's system/j2commerce plugin
        // imports all j2commerce plugins on every request, so J2Commerce's handler fires here
        // too. When the 'shop' discriminator is present and names a different shop, return
        // immediately so only the correct plugin processes the request.
        $shop = $input->getString('shop', '');
        if ($shop !== '' && $shop !== 'phocacart') {
            return;
        }

        $action = $input->getCmd('action', '');
        if (in_array($action, ['checkoutmethods', 'pay'], true) && $shop !== 'phocacart') {
            $this->log('Ignoring AJAX action without phocacart shop discriminator. action=' . $action . ' uri=' . Uri::getInstance()->toString(), Log::WARNING);
            return;
        }

        $this->log('Activation callback received. URI: ' . Uri::getInstance()->toString(), Log::INFO);

        // Public endpoint: return checkout methods as JSON (no CSRF needed — read-only API call)
        if ($action === 'checkoutmethods') {
            $this->handleAjaxCheckoutMethods();
        }

        // Pay action: create hosted payment on demand and redirect to Infynipay
        if ($action === 'pay') {
            $this->handleAjaxPay();
        }

        // CSRF check
        if (!Session::checkToken('get')) {
            $this->log('Activation: CSRF token mismatch — possible replay or session expired.', Log::WARNING);
            $this->jsRedirect(
                Uri::base() . 'index.php?option=com_plugins&view=plugins&filter[folder]=pcp',
                Text::_('PLG_PCP_INFYNIPAY_ACTIVATION_CSRF_ERROR'),
                'error'
            );
        }

        $licenseId         = $input->getString('licenseId', '');
        $authorizationCode = $input->getString('authorizationCode', '');

        $this->log('Activation: processing OAuth callback.', Log::INFO);

        if ($licenseId === '') {
            $this->log('Activation: missing licenseId in callback URL.', Log::ERROR);
            $this->jsRedirect(
                Uri::base() . 'index.php?option=com_plugins&view=plugins&filter[folder]=pcp',
                Text::_('PLG_PCP_INFYNIPAY_ACTIVATION_MISSING_CODE'),
                'error'
            );
        }

        if ($authorizationCode === '') {
            $this->log('Activation: missing authorizationCode in callback URL.', Log::ERROR);
            $this->jsRedirect(
                Uri::base() . 'index.php?option=com_plugins&view=plugins&filter[folder]=pcp',
                Text::_('PLG_PCP_INFYNIPAY_ACTIVATION_MISSING_CODE'),
                'error'
            );
        }

        $this->handleActivationReturn($licenseId, $authorizationCode);
    }

    /**
     * Calls GET /api/apps/{appId}/payment-profiles/payment-checkout-methods
     * and returns the decoded JSON array, or [] on any error.
     */
    private function fetchCheckoutMethods(string $appId, string $token): array
    {
        if ($appId === '' || $token === '') {
            return [];
        }

        $apiUrl = rtrim($this->infynipay_payment_app_api_url, '/')
                . '/api/apps/' . urlencode($appId) . '/payment-profiles/payment-checkout-methods';

        try {
            $http     = HttpFactory::getHttp();
            $response = $http->get(
                $apiUrl,
                [
                    'accept'        => 'text/plain',
                    'Authorization' => 'Bearer ' . $token,
                ],
                15
            );

            if ($response->code !== 200) {
                $this->log('fetchCheckoutMethods: HTTP ' . $response->code, Log::WARNING);

                return [];
            }

            $data = json_decode((string) $response->body, true);

            if (!is_array($data)) {
                $this->log('fetchCheckoutMethods: invalid JSON response.', Log::WARNING);

                return [];
            }

            return $this->inlineCheckoutMethodIcons($data);
        } catch (\RuntimeException $e) {
            $this->log('fetchCheckoutMethods: exception — ' . $e->getMessage(), Log::WARNING);

            return [];
        }
    }

    /**
     * POST /api/apps/{appId}/payments/hosted-page
     * Creates a Infynipay hosted payment session and returns the redirectUrl,
     * or null on any error.
     */
    private function createHostedPayment(
        string $appId,
        string $token,
        array  $orderData,
        float  $amount,
        string $currency,
        string $returnUrl,
        string $webhookUrl
    ): ?string {
        $orderId = (int) ($orderData['common']->id ?? 0);
        $userId  = (int) ($orderData['common']->user_id ?? 0);
        $b       = $orderData['bas']['b'] ?? [];

        $r = isset($orderData['common']->currency_exchange_rate)
            && (float) $orderData['common']->currency_exchange_rate > 0
            ? (float) $orderData['common']->currency_exchange_rate
            : 1.0;

        // Build items from products
        $items = [];
        if (!empty($orderData['products'])) {
            foreach ($orderData['products'] as $product) {
                $items[] = [
                    'name'        => (string) ($product->title ?? ''),
                    'description' => '',
                    'amount'      => round((float) ($product->netto ?? 0) * $r, 2),
                    'quantity'    => (int) ($product->quantity ?? 1),
                    'tax'         => 0,
                    'sku'         => (string) ($product->alias ?? ''),
                    'category'    => 'PhysicalGoods',
                ];
            }
        }

        // Extract discount / shipping / tax from totals
        $discount = 0.0;
        $shipping = 0.0;
        $totalTax = 0.0;

        if (!empty($orderData['total'])) {
            foreach ($orderData['total'] as $total) {
                $type = strtolower((string) ($total->type ?? ''));
                $val  = (float) $total->amount_currency != 0
                    ? round((float) $total->amount_currency, 2)
                    : round((float) ($total->amount ?? 0) * $r, 2);
                if (str_contains($type, 'shipping')) {
                    $shipping += $val;
                } elseif (str_contains($type, 'discount') || str_contains($type, 'coupon')) {
                    $discount += abs($val);
                } elseif (str_contains($type, 'tax')) {
                    $totalTax += $val;
                }
            }
        }

        $payload = [
            'referenceId'                 => (string) $orderId,
            'amount'                      => $amount,
            'currency'                    => $currency,
            'autoCapture'                 => true,
            'showPayRecurringInCheckout'  => false,
            'webhookUrl'                  => $webhookUrl,
            'returnUrl'                   => $returnUrl,
            'customerOrder'               => [
                'customer' => [
                    'customerId'         => $userId > 0 ? (string) $userId : $this->generateUuid(),
                    'accountCreatedTime' => null,
                    'firstName'          => (string) ($b['name_first'] ?? ''),
                    'lastName'           => (string) ($b['name_last'] ?? ''),
                    'email'              => (string) ($b['email'] ?? $b['email_contact'] ?? ''),
                    'phoneNumber'        => (string) ($b['phone_1'] ?? $b['phone_mobile'] ?? ''),
                    'mobileNumber'       => (string) ($b['phone_mobile'] ?? $b['phone_1'] ?? ''),
                    'address'            => [
                        'streetAddressLine1' => (string) ($b['address_1'] ?? ''),
                        'streetAddressLine2' => (string) ($b['address_2'] ?? ''),
                        'zipCode'            => (string) ($b['zip'] ?? ''),
                        'city'               => (string) ($b['city'] ?? ''),
                        'state'              => '',
                        'country'            => (string) ($b['countrytitle'] ?? ''),
                        'countryCode'        => (string) ($b['countrycode'] ?? ''),
                    ],
                ],
                'orderId'     => (string) $orderId,
                'description' => 'Order #' . $orderId,
                'customId'    => null,
                'amount'      => [
                    'total'            => $amount,
                    'handling'         => 0,
                    'insurance'        => 0,
                    'discount'         => $discount,
                    'shipping'         => $shipping,
                    'shippingDiscount' => 0,
                    'totalTax'         => $totalTax,
                ],
                'items' => $items,
            ],
        ];

        $apiUrl = rtrim($this->infynipay_payment_api_url, '/')
                . '/api/apps/' . urlencode($appId) . '/payments/hosted-page';

        $this->log(sprintf('[Order #%d] Calling POST %s amount=%.2f %s', $orderId, $apiUrl, $amount, $currency));

        try {
            $http     = HttpFactory::getHttp();
            $response = $http->post(
                $apiUrl,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                [
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
                30
            );

            if ($response->code < 200 || $response->code >= 300) {
                $this->log(sprintf(
                    '[Order #%d] Hosted payment creation failed. HTTP=%d responseBody=%s',
                    $orderId,
                    $response->code,
                    substr((string) $response->body, 0, 4000)
                ), Log::ERROR);

                // If "already used", try to fetch the existing payment's redirectUrl
                if ($response->code === 400) {
                    // Detect AppInactiveException — license expired, app deactivated
                    $errorBody = json_decode((string) $response->body, true);
                    if (is_array($errorBody) && ($errorBody['TypeName'] ?? '') === 'AppInactiveException') {
                        $this->log(sprintf('[Order #%d] App is inactive (license expired).', $orderId), Log::WARNING);
                        return 'APP_INACTIVE';
                    }

                    $existingUrl = $this->fetchExistingPaymentRedirectUrl($appId, $token, $orderId);
                    if ($existingUrl !== null) {
                        return $existingUrl;
                    }
                }

                return null;
            }

            $data = json_decode((string) $response->body, true);

            if (!is_array($data)) {
                $this->log(sprintf('[Order #%d] Hosted payment: unexpected non-JSON response.', $orderId), Log::ERROR);

                return null;
            }

            $redirectUrl = (string) ($data['redirectUrl'] ?? '');

            if ($redirectUrl === '') {
                $this->log(sprintf('[Order #%d] Hosted payment: no redirectUrl in response. paymentId=%s paymentState=%s',
                    $orderId, $data['paymentId'] ?? '?', $data['paymentState'] ?? '?'), Log::ERROR);

                return null;
            }

            $this->log(sprintf('[Order #%d] Hosted payment created. paymentId=%s paymentState=%s expiresAt=%s',
                $orderId, $data['paymentId'] ?? '?', $data['paymentState'] ?? '?', $data['expirationTime'] ?? '?'));

            $phPaymentId = (int) ($data['paymentId'] ?? 0);
            if ($phPaymentId > 0) {
                $this->mergeParamsPayment($orderId, [
                    'method'        => 'infynipay',
                    'ph_payment_id' => $phPaymentId,
                    'ph_detail_url' => $this->buildPanelDetailUrl($phPaymentId),
                ]);
            }

            return $redirectUrl;
        } catch (\RuntimeException $e) {
            $this->log(sprintf('[Order #%d] Hosted payment exception: %s trace=%s',
                $orderId, $e->getMessage(), substr($e->getTraceAsString(), 0, 2000)), Log::ERROR);

            return null;
        }
    }

    /**
     * Fetches the redirectUrl of an existing payment by referenceId (= orderId).
     * Used as fallback when createHostedPayment returns 400 "already used".
     *
     * Returns:
     *  - redirectUrl string if the payment is still pending (user can continue paying)
     *  - 'ALREADY_PAID' sentinel if the payment is already completed/captured
     *  - null if the lookup fails
     */
    private function fetchExistingPaymentRedirectUrl(string $appId, string $token, int $orderId): ?string
    {
        $verifyUrl = rtrim($this->infynipay_payment_api_url, '/')
                   . '/api/apps/' . urlencode($appId) . '/payments/referenceId:' . $orderId;

        $this->log(sprintf('[Order #%d] Fetching existing payment — GET %s', $orderId, $verifyUrl));

        try {
            $http     = HttpFactory::getHttp();
            $response = $http->get($verifyUrl, [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ], 15);

            if ($response->code !== 200) {
                $this->log(sprintf('[Order #%d] Existing payment lookup failed. HTTP=%d', $orderId, $response->code), Log::WARNING);
                return null;
            }

            $data         = json_decode((string) $response->body, true);
            $paymentState = strtolower((string) ($data['paymentState'] ?? ''));
            $redirectUrl  = (string) ($data['redirectUrl'] ?? '');

            // If payment is already captured — don't allow a second payment
            if ($paymentState === 'captured') {
                $this->log(sprintf('[Order #%d] Payment already captured. Blocking duplicate.', $orderId));
                return 'ALREADY_PAID';
            }

            // Payment still pending — return its redirectUrl so user can complete it
            if ($redirectUrl !== '') {
                $this->log(sprintf('[Order #%d] Reusing existing payment redirectUrl. paymentState=%s', $orderId, $paymentState));
                return $redirectUrl;
            }

            $this->log(sprintf('[Order #%d] Existing payment has no redirectUrl (expired?). paymentState=%s', $orderId, $paymentState), Log::WARNING);
            return null;
        } catch (\RuntimeException $e) {
            $this->log(sprintf('[Order #%d] Existing payment lookup exception: %s', $orderId, $e->getMessage()), Log::WARNING);
            return null;
        }
    }

    /** Generate a RFC 4122 v4 UUID. */
    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /** Returns true when the given URL resolves to loopback or a private-network address. */
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

    /**
     * Builds a provider-selection form (with inline CSS + JS).
     * Each card represents one checkout method or hosted-page provider.
     */
    private function buildPaymentMethodSelectionForm(array $methods, ?string $payUrl, float $amount = 0, string $currency = '', int $orderId = 0): string
    {
        // Localhost / private-network warning banner.
        // Check Uri::base() first; fall back to the actual HTTP_HOST so the
        // detection works even when Joomla's configured siteUrl differs from
        // the browser's request host (common on local dev setups).
        $uriBase    = Uri::base();
        $serverHost = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
        $isLocalhost = $this->isLocalhostUrl($uriBase)
            || ($serverHost !== '' && $this->isLocalhostUrl('http://' . $serverHost . '/'));

        $this->log(sprintf(
            'buildPaymentMethodSelectionForm: uriBase=%s serverHost=%s isLocalhost=%s methods=%d payUrl=%s',
            $uriBase,
            $serverHost,
            $isLocalhost ? 'true' : 'false',
            count($methods),
            $payUrl ?? '(null)'
        ));

        $creditCardSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/><line x1="5" y1="15" x2="9" y2="15"/></svg>';

        $cards = '';

        foreach ($methods as $group) {
            $checkoutMethod = (string) ($group['checkoutMethod'] ?? '');
            $items          = $group['paymentCheckoutMethodItems'] ?? [];

            if ($checkoutMethod === 'CreditCard') {
                $cards .= '<div class="ph-method-tile">'
                        . '<span class="ph-icon ph-icon-card">' . $creditCardSvg . '</span>'
                        . '</div>';
            } elseif ($checkoutMethod === 'ProviderHostedPage') {
                foreach ($items as $item) {
                    $profile = $item['paymentProfile'] ?? null;
                    if (!is_array($profile)) {
                        continue;
                    }
                    $profileId = (int) ($profile['paymentProfileId'] ?? 0);
                    $iconUri1  = (string) ($profile['paymentProvider']['iconUri1'] ?? '');
                    $iconUri2  = (string) ($profile['paymentProvider']['iconUri2'] ?? '');
                    $provider  = (string) ($profile['paymentProvider']['provider'] ?? '');
                    $safeProvider = htmlspecialchars($provider !== '' ? $provider : 'Payment Provider', ENT_QUOTES, 'UTF-8');

                    // ProviderIconHelper::inline() already returns either a data: URI,
                    // an https:// URL, or '' — no additional filtering needed here.

                    // iconUri1 = coloured/dark icon for light backgrounds → show directly.
                    // iconUri2 = white/light icon for dark backgrounds → dark pill.
                    // Skip only when neither is available.
                    if ($iconUri1 === '' && $iconUri2 === '') {
                        continue;
                    }

                    if ($iconUri1 !== '') {
                        $safeImg  = htmlspecialchars($iconUri1, ENT_QUOTES, 'UTF-8');
                        $iconHtml = '<img src="' . $safeImg . '" alt="' . $safeProvider . '" class="ph-provider-logo ph-lazy-image">';
                    } else {
                        // Only a white/light icon (iconUri2) — render on dark pill.
                        $safeImg  = htmlspecialchars($iconUri2, ENT_QUOTES, 'UTF-8');
                        $iconHtml = '<span class="ph-dark-pill"><img src="' . $safeImg . '" alt="' . $safeProvider . '" class="ph-provider-logo ph-lazy-image"></span>';
                    }

                    $cards .= '<div class="ph-method-tile ph-skeleton-box">'
                            . '<span class="ph-icon">' . $iconHtml . '</span>'
                            . '</div>';
                }
            }
        }

        $continueText = Text::_('PLG_PCP_INFYNIPAY_CONTINUE_TO_PAY');
        $redirectText = Text::_('PLG_PCP_INFYNIPAY_REDIRECT_TO_INFYNIPAY');
        $logoPng  = JPATH_PLUGINS . '/pcp/infynipay/images/logos/infynipay-logo-dark.png';
        $logoUrl  = is_file($logoPng)
            ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPng))
            : htmlspecialchars(Uri::root() . 'plugins/pcp/infynipay/images/logos/infynipay-logo-dark.png', ENT_QUOTES, 'UTF-8');

        $css = '<style>'
            // Outer wrapper — centred, max width
            . '#ph-wrap{max-width:460px;margin:32px auto;padding:0 16px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}'
            // The card
            . '#ph-card{background:#fff;border-radius:24px;border:1.5px solid rgba(99,91,255,.18);box-shadow:0 0 0 4px rgba(99,91,255,.06),0 8px 40px rgba(15,23,42,.13),0 2px 8px rgba(99,91,255,.08);overflow:hidden}'
            // Card header — dark gradient strip with Infynipay branding
            . '#ph-card-header{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%)}'
            . '#ph-card-header .ph-brand{display:flex;align-items:center;justify-content:center;min-height:80px}'
            . '#ph-card-header .ph-brand-logo{display:block!important;flex:0 0 auto;height:auto!important;max-height:72px!important;width:auto!important;max-width:100%!important;object-fit:contain}'
            . '.ph-order-ref{display:flex;align-items:center;justify-content:center;gap:8px;text-align:center;margin-bottom:14px;font-size:12px;font-weight:600;color:#64748b;letter-spacing:.03em}'
            . '.ph-order-ref span{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:8px;background:#f1f5f9;border:1px solid #e2e8f0;color:#475569;font-weight:700;font-size:12px}'
            . '.ph-order-ref svg{width:12px;height:12px;flex-shrink:0;color:#94a3b8}'

            // Card body
            . '#ph-card-body{padding:24px}'
            . '#ph-card-body .ph-label{display:block;margin-bottom:14px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#94a3b8}'
            // Method tiles — read-only, no interaction
            . '.ph-methods{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:24px}'
            . '.ph-method-tile{display:inline-flex;align-items:center;justify-content:center;width:100%;height:68px;padding:8px;border:1.5px solid #e2e8f0;border-radius:14px;background:#f8fafc}'
            . '.ph-method-tile .ph-icon{display:flex;align-items:center;justify-content:center;width:100%;height:100%}'
            . '.ph-icon-card svg{width:40px;height:40px;color:#334155}'
            . '.ph-provider-logo{display:block;max-height:40px;max-width:64px;width:auto;object-fit:contain;opacity:0;transition:opacity .2s ease;position:relative;z-index:1}'
            . '.ph-method-tile.ph-loaded .ph-provider-logo{opacity:1}'
            . '.ph-dark-pill{display:inline-flex;align-items:center;justify-content:center;width:56px;height:40px;border-radius:8px;background:#1e293b;padding:5px}'
            . '.ph-dark-pill .ph-provider-logo{max-height:30px;max-width:46px}'
            // Divider
            . '.ph-divider{height:1px;background:#f1f5f9;margin:0 0 20px}'
            // Redirect message
            . '.ph-redirect-msg{display:flex;align-items:flex-start;gap:10px;margin-bottom:20px;padding:12px 14px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0}'
            . '.ph-redirect-msg svg{flex-shrink:0;width:15px;height:15px;margin-top:2px;color:#64748b}'
            . '.ph-redirect-msg span{font-size:13px;line-height:1.55;color:#64748b}'
            // Submit button / redirect link
            . '#ph_pay_btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:14px;border:0;border-radius:14px;background:linear-gradient(135deg,#1d4ed8 0%,#0f172a 100%);color:#fff;font-size:15px;font-weight:700;cursor:pointer;letter-spacing:-.01em;transition:opacity .15s,transform .1s;text-decoration:none;box-sizing:border-box}'
            . '#ph_pay_btn:hover{opacity:.92;transform:translateY(-1px)}'
            . '#ph_pay_btn:active{transform:translateY(0)}'
            . '#ph_pay_btn svg{width:16px;height:16px}'
            . '#ph_pay_btn.ph-loading{opacity:.82;pointer-events:none;cursor:wait}'
            . '.ph-sandbox-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;background:rgba(251,191,36,.15);border:1px solid rgba(251,191,36,.4);color:#b45309;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}'
            . '.ph-sandbox-badge svg{width:12px;height:12px;flex-shrink:0}'
            . '@keyframes phshimmer{100%{transform:translateX(100%)}}'
            . '@media(max-width:380px){.ph-methods{grid-template-columns:repeat(2,1fr)}.ph-provider-logo{max-height:34px;max-width:54px}}'
            . '</style>';

        $safeRedirect = htmlspecialchars($redirectText, ENT_QUOTES, 'UTF-8');
        $amountLabel  = $amount > 0 ? ' — ' . htmlspecialchars(number_format($amount, 2) . ' ' . $currency, ENT_QUOTES, 'UTF-8') : '';
        $safeContinue = htmlspecialchars($continueText, ENT_QUOTES, 'UTF-8') . $amountLabel;

        $arrowSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>';

        $isSandbox = (int) $this->params->get('sandbox', 1) === 1;
        $sandboxBadge = $isSandbox
            ? '<span class="ph-sandbox-badge"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636-2.87L13.637 3.59a1.914 1.914 0 0 0-3.274 0z"/><path d="M12 17h.01"/></svg>Sandbox</span>'
            : '';

        // If no checkout methods available — show "service unavailable" message
        if (empty($methods) || $cards === '') {
            return $css
                . '<div id="ph-wrap">'
                . '<div id="ph-card">'
                . '<div id="ph-card-header">'
                .   '<div class="ph-brand">'
                .     '<img src="' . $logoUrl . '" alt="Infynipay" class="ph-brand-logo" style="display:block;height:auto;max-height:72px;width:auto;max-width:100%;object-fit:contain;">'
                .   '</div>'
                . '</div>'
                . '<div id="ph-card-body">'
                .   '<div class="ph-redirect-msg" style="margin-bottom:0">'
                .     '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
                .     '<span>Payment service is temporarily unavailable. Please try again later.</span>'
                .   '</div>'
                . '</div>'
                . '</div>'
                . '</div>';
        }

        if ($payUrl !== null && $payUrl !== '') {
            $safeHref = htmlspecialchars($payUrl, ENT_QUOTES, 'UTF-8');
            $payBtn   = '<a href="' . $safeHref . '" id="ph_pay_btn">'
                      . $arrowSvg
                      . $safeContinue
                      . '</a>';
        } else {
            $payBtn = '<button type="button" id="ph_pay_btn" onclick="alert(\'Payment session could not be created. Please place a new order or try again later.\')">'
                    . $arrowSvg
                    . $safeContinue
                    . '</button>';
        }

        return $css
            . '<div id="ph-wrap">'
            . '<div id="ph-card">'

            // Header
            . '<div id="ph-card-header">'
            .   '<div class="ph-brand ph-skeleton-box">'
            .     '<img src="' . $logoUrl . '" alt="Infynipay" class="ph-brand-logo ph-lazy-image" style="display:block;height:80px;min-height:80px;max-height:80px;width:auto;max-width:none;flex:0 0 auto;">'
            .   '</div>'
            . '</div>'

            // Body
            . '<div id="ph-card-body">'
            .   '<div class="ph-methods">' . $cards . '</div>'
            .   '<div class="ph-divider"></div>'
            .   '<div class="ph-redirect-msg">'
            .     '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
            .     '<span>' . $safeRedirect . '</span>'
            .   '</div>'
            . ($orderId > 0
                ? '<div class="ph-order-ref">' . $sandboxBadge . '<span>'
                .   '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>'
                .   'Order #' . $orderId
                . '</span></div>'
                : '')
            .   $payBtn
            . '</div>'

            . '</div>'
        . '</div>'
            . '<script>(function(){var btn=document.getElementById("ph_pay_btn");if(btn){btn.addEventListener("click",function(){if(this.classList.contains("ph-loading")){return;}this.classList.add("ph-loading");this.setAttribute("aria-busy","true");});}var imgs=document.querySelectorAll("#ph-wrap .ph-lazy-image");if(!imgs.length){return;}imgs.forEach(function(img){var host=img.closest(".ph-method-tile,.ph-brand");if(!host){return;}var done=function(){host.classList.add("ph-loaded");};if(img.complete&&img.naturalWidth>0){done();return;}img.addEventListener("load",done,{once:true});img.addEventListener("error",done,{once:true});});})();</script>';
    }

    private function inlineCheckoutMethodIcons(array $methods): array
    {
        foreach ($methods as &$group) {
            if (!is_array($group) || empty($group['paymentCheckoutMethodItems']) || !is_array($group['paymentCheckoutMethodItems'])) {
                continue;
            }

            foreach ($group['paymentCheckoutMethodItems'] as &$item) {
                if (!is_array($item) || empty($item['paymentProfile']) || !is_array($item['paymentProfile'])) {
                    continue;
                }

                if (empty($item['paymentProfile']['paymentProvider']) || !is_array($item['paymentProfile']['paymentProvider'])) {
                    continue;
                }

                foreach (['iconUri1', 'iconUri2'] as $iconKey) {
                    $item['paymentProfile']['paymentProvider'][$iconKey] = ProviderIconHelper::inline(
                        (string) ($item['paymentProfile']['paymentProvider'][$iconKey] ?? ''),
                        'plg_pcp_infynipay'
                    );
                }
            }

            unset($item);
        }

        unset($group);

        return $methods;
    }


    /**
     * Returns available checkout methods as a JSON array for the frontend checkout page.
     * Called via: GET index.php?option=com_ajax&plugin=infynipay&group=pcp&action=checkoutmethods&format=raw
     * No CSRF is required because this is a read-only operation returning non-sensitive UI data.
     */
    private function handleAjaxCheckoutMethods(): never
    {
        $creds   = $this->getActiveCredentials();
        $methods = $this->fetchCheckoutMethods($creds['app_id'], $creds['token']);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($methods, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Creates a hosted payment on demand (called when user clicks "Continue to Pay")
     * and redirects the browser to the Infynipay checkout page.
     *
     * URL: index.php?option=com_ajax&plugin=infynipay&group=pcp&format=raw&action=pay
     */
    private function handleAjaxPay(): never
    {
        $input   = Factory::getApplication()->getInput();

        // orderId must come from the URL — it is embedded by onPCPbeforeSetPaymentForm.
        // Session is NOT used; it would cause cross-tab data contamination.
        $orderId = $input->getInt('order_id', 0);

        if ($orderId < 1) {
            $this->log('[Pay] No orderId in URL.', Log::WARNING);
            http_response_code(400);
            echo 'Missing order reference. Please return to checkout.';
            exit;
        }

        $creds      = $this->getActiveCredentials();
        $merchantId = $creds['app_id'];
        $token      = $creds['token'];

        // ------------------------------------------------------------------
        // Load ALL order data fresh from DB — never use session-cached values
        // ------------------------------------------------------------------
        $orderView   = new \PhocacartOrderView();
        $orderCommon = $orderView->getItemCommon($orderId);

        if (!$orderCommon || !isset($orderCommon->id)) {
            $this->log('[Pay] Order #' . $orderId . ' not found in database.', Log::ERROR);
            http_response_code(404);
            echo 'Order not found.';
            exit;
        }

        $orderData = [
            'common'   => $orderCommon,
            'bas'      => $orderView->getItemBaS($orderId, 1),
            'products' => $orderView->getItemProducts($orderId),
            'total'    => $orderView->getItemTotal($orderId),
        ];

        // ------------------------------------------------------------------
        // Derive amount, currency, paymentId from DB — same logic as onPCPbeforeSetPaymentForm
        // ------------------------------------------------------------------
        $paymentId = (int) ($orderCommon->payment_id ?? 0);
        $currency  = strtoupper((string) ($orderCommon->currency_code ?? 'USD'));

        $r = isset($orderCommon->currency_exchange_rate) && (float) $orderCommon->currency_exchange_rate > 0
            ? (float) $orderCommon->currency_exchange_rate
            : 1.0;

        $rounding      = PHP_ROUND_HALF_UP;
        $cartBrutto    = 0.0;
        $paymentBrutto = 0.0;

        if (!empty($orderData['products'])) {
            foreach ($orderData['products'] as $product) {
                $paymentBrutto += round((float) $product->netto * $r, 2, $rounding) * (int) $product->quantity;
            }
        }

        if (!empty($orderData['total'])) {
            foreach ($orderData['total'] as $total) {
                if ($total->amount == 0 && $total->amount_currency == 0) {
                    continue;
                }
                if ($total->type === 'brutto') {
                    $cartBrutto = $total->amount_currency != 0
                        ? round((float) $total->amount_currency, 2, $rounding)
                        : round((float) $total->amount * $r, 2, $rounding);
                } else {
                    $paymentBrutto += $total->amount_currency != 0
                        ? round((float) $total->amount_currency, 2, $rounding)
                        : round((float) $total->amount * $r, 2, $rounding);
                }
            }
        }

        $amount = $cartBrutto > 0 ? $cartBrutto : round($paymentBrutto, 2, $rounding);

        if ($amount <= 0) {
            $this->log('[Pay] Order #' . $orderId . ' has zero computed amount.', Log::WARNING);
            http_response_code(400);
            echo 'Order amount is zero. Please return to checkout.';
            exit;
        }

        $baseUrl    = Uri::base();
        $returnUrl  = $baseUrl . 'index.php?option=com_phocacart&view=response&task=response.paymentrecieve&type=infynipay&mid=1&order_id=' . $orderId;
        $webhookUrl = $baseUrl . 'index.php?option=com_phocacart&view=response&task=response.paymentwebhook&type=infynipay&pid=' . $paymentId;

        $this->log(sprintf('[Pay] Creating hosted payment. Order #%d amount=%.2f %s methodId=%d', $orderId, $amount, $currency, $paymentId));

        $redirectUrl = $this->createHostedPayment(
            $merchantId,
            $token,
            $orderData,
            $amount,
            $currency,
            $returnUrl,
            $webhookUrl
        );

        if ($redirectUrl === 'ALREADY_PAID') {
            $this->log('[Pay] Order #' . $orderId . ' already paid. Redirecting to confirmation.');
            // Order already paid (e.g., from another tab) — go to the result page
            $resultUrl = $baseUrl . 'index.php?option=com_phocacart&view=response&task=response.paymentrecieve&type=infynipay&mid=1&order_id=' . $orderId;
            header('Location: ' . $resultUrl);
            exit;
        }

        if ($redirectUrl === 'APP_INACTIVE') {
            $this->log('[Pay] Order #' . $orderId . ' — app inactive, showing unavailable message.', Log::WARNING);
            $resultUrl = $baseUrl . 'index.php?option=com_phocacart&view=response&task=response.paymentrecieve&type=infynipay&mid=1&ph_error=inactive&order_id=' . $orderId;
            header('Location: ' . $resultUrl);
            exit;
        }

        if ($redirectUrl === null || $redirectUrl === '') {
            $this->log('[Pay] Failed to create hosted payment for order #' . $orderId . '. Payment session expired — customer must place a new order.', Log::WARNING);
            // Payment session expired or creation failed. Redirect to checkout so PhocaCart creates a new order on next submit.
            $app = Factory::getApplication();
            $app->enqueueMessage(
                Text::_('PLG_PCP_INFYNIPAY_PAYMENT_SESSION_EXPIRED'),
                'warning'
            );
            $checkoutUrl = $baseUrl . 'index.php?option=com_phocacart&view=checkout';
            header('Location: ' . $checkoutUrl);
            exit;
        }

        // Redirect user to Infynipay checkout page
        header('Location: ' . $redirectUrl);
        exit;
    }

    /**
     * Calls GET /api/licenses/{licenseId} (Bearer: authorizationCode),
     * parses sandbox / live licence objects, saves the four credentials.
     */
    private function handleActivationReturn(string $licenseId, string $authorizationCode): void
    {
        $app    = Factory::getApplication();
        $apiUrl = rtrim($this->infynipay_payment_app_api_url, '/')
                . '/api/licenses/' . urlencode($licenseId);

        $this->log('Activation: calling API — ' . $apiUrl, Log::INFO);

        try {
            $http     = HttpFactory::getHttp();
            $response = $http->get(
                $apiUrl,
                [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $authorizationCode,
                ],
                30
            );
            $httpCode = $response->code;
            $body     = (string) $response->body;
        } catch (\RuntimeException $e) {
            $this->log('Activation: HTTP request failed — ' . $e->getMessage(), Log::ERROR);
            $this->jsRedirect(
                Uri::base() . 'index.php?option=com_plugins&view=plugins&filter[folder]=pcp',
                Text::_('PLG_PCP_INFYNIPAY_ACTIVATION_API_ERROR'),
                'error'
            );
        }

        $this->log('Activation: API response — HTTP ' . $httpCode, Log::INFO);
        $this->log('Activation: API body — ' . substr($body, 0, 500), Log::INFO);

        if ($httpCode !== 200) {
            $this->log('Activation: API call failed. HTTP=' . $httpCode, Log::ERROR);
            $this->jsRedirect(
                Uri::base() . 'index.php?option=com_plugins&view=plugins&filter[folder]=pcp',
                Text::_('PLG_PCP_INFYNIPAY_ACTIVATION_API_ERROR'),
                'error'
            );
        }

        $licenses = json_decode($body, true);

        if (!is_array($licenses)) {
            $this->log('Activation: API returned invalid JSON — ' . substr((string) $response, 0, 200), Log::ERROR);
            $this->jsRedirect(
                Uri::base() . 'index.php?option=com_plugins&view=plugins&filter[folder]=pcp',
                Text::_('PLG_PCP_INFYNIPAY_ACTIVATION_INVALID_RESPONSE'),
                'error'
            );
        }

        $this->log('Activation: received ' . count($licenses) . ' license(s) from API.', Log::INFO);

        $sandboxAppId = '';
        $sandboxToken = '';
        $liveAppId    = '';
        $liveToken    = '';

        foreach ($licenses as $license) {
            if (!is_array($license)) {
                continue;
            }

            $isSandboxEntry = !empty($license['isSandbox']);
            $entryAppId     = (string) ($license['appId']             ?? '');
            $entryToken     = (string) ($license['authorizationCode'] ?? '');

            $this->log(
                'Activation: license — isSandbox=' . ($isSandboxEntry ? 'true' : 'false')
                . ' appId=' . $entryAppId
                . ' token=' . ($entryToken ? '(present)' : '(empty)'),
                Log::INFO
            );

            if ($isSandboxEntry) {
                $sandboxAppId = $entryAppId;
                $sandboxToken = $entryToken;
            } else {
                $liveAppId = $entryAppId;
                $liveToken = $entryToken;
            }
        }

        // Persist the four credentials in #__extensions params
        $db    = Factory::getDbo();
        $query = $db->getQuery(true)
            ->select([$db->quoteName('extension_id'), $db->quoteName('params')])
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('infynipay'))
            ->where($db->quoteName('type')    . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder')  . ' = ' . $db->quote('pcp'));
        $db->setQuery($query);
        $row = $db->loadObject();

        if (!$row) {
            $this->log('Activation: plugin row not found in #__extensions. Is the plugin installed?', Log::ERROR);
            $this->jsRedirect(
                Uri::base() . 'index.php?option=com_plugins&view=plugins&filter[folder]=pcp',
                Text::_('PLG_PCP_INFYNIPAY_ACTIVATION_SAVE_ERROR'),
                'error'
            );
        }

        $params                  = json_decode($row->params ?? '{}', true) ?: [];
        $params['sandbox_app_id'] = $sandboxAppId;
        $params['sandbox_token']  = $sandboxToken;
        $params['live_app_id']    = $liveAppId;
        $params['live_token']     = $liveToken;

        $updateQuery = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($params)))
            ->where($db->quoteName('extension_id') . ' = ' . (int) $row->extension_id);
        $db->setQuery($updateQuery);
        $db->execute();

        $this->log(
            'Activation complete — saved to extension_id=' . (int) $row->extension_id
            . ' liveAppId=' . $liveAppId
            . ' sandboxAppId=' . $sandboxAppId,
            Log::INFO
        );

        $this->jsRedirect(
            Uri::base() . 'index.php?option=com_plugins&task=plugin.edit&extension_id=' . (int) $row->extension_id,
            Text::_('PLG_PCP_INFYNIPAY_ACTIVATION_SUCCESS'),
            'message'
        );
    }
}
