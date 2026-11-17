<?php
/**
 * Setup button field layout — renders Sandbox and Live gateway cards.
 *
 * @var array $displayData
 */

defined('_JEXEC') or die;

$activationError = (string) ($displayData['activationError'] ?? '');
$refreshUrl      = htmlspecialchars((string) ($displayData['refreshUrl'] ?? ''), ENT_QUOTES, 'UTF-8');
$isConnected     = !empty($displayData['isConnected']);
$isLocalhost     = !empty($displayData['isLocalhost']);
$setupUrl        = (string) ($displayData['setupUrl'] ?? '');
$logoLight       = (string) ($displayData['logoLight'] ?? '');
$logoDark        = (string) ($displayData['logoDark'] ?? '');
$apps            = is_array($displayData['apps'] ?? null) ? $displayData['apps'] : [];
$text            = $displayData['text'] ?? [];

$connectText       = (string) ($text['connect'] ?? 'Connect');
$manageText        = (string) ($text['manage'] ?? 'Manage Gateways');
$setupText         = (string) ($text['setup'] ?? 'Complete Setup');
$connectedText     = (string) ($text['connected'] ?? 'Connected');
$notConnectedText  = (string) ($text['notConnected'] ?? 'Not connected');
$setupRequiredText = (string) ($text['setupRequired'] ?? 'Setup required');
$sandboxText       = (string) ($text['sandbox'] ?? 'Sandbox');
$liveText          = (string) ($text['live'] ?? 'Live');

$setupUrlEscaped  = htmlspecialchars($setupUrl, ENT_QUOTES, 'UTF-8');
$logoLightEscaped = htmlspecialchars($logoLight, ENT_QUOTES, 'UTF-8');
$logoDarkEscaped  = htmlspecialchars($logoDark, ENT_QUOTES, 'UTF-8');
?>
<?php if ($activationError !== '') : ?>
<div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
    <?= htmlspecialchars($activationError, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<?php if ($isLocalhost && $isConnected) : ?>
<div class="alert alert-warning d-flex align-items-start gap-3 mb-3" style="max-width:720px;border:1.5px solid #f59e0b;background:#fffbeb;border-radius:10px" role="alert">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;width:20px;height:20px;color:#b45309;margin-top:2px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <div>
        <strong style="color:#92400e">Webhook delivery unavailable</strong>
        <p class="mb-1" style="font-size:13px;color:#78350f;margin-top:4px">
            Your Joomla site is running on <strong>localhost</strong>. Infynipay webhook notifications cannot reach a local/private network address. Payment status updates will not be received.
        </p>
        <p class="mb-0" style="font-size:13px;color:#78350f">
            To receive webhooks, deploy your site to a public URL or use a tunnel such as Cloudflare Tunnel or ngrok.
        </p>
        <a href="https://github.com/infynipay/infynipay-docs/blob/main/CLOUDFLARE-TUNNEL-INSTRUCTIONS.md" target="_blank" rel="noopener noreferrer" style="font-size:13px;font-weight:600;color:#b45309;text-decoration:underline;margin-top:6px;display:inline-block">
            Learn how to set up a Cloudflare Tunnel &rarr;
        </a>
    </div>
</div>
<?php endif; ?>

<?php if (!$isConnected) : ?>
<div class="card mb-3 ph-card-disconnected">
    <div class="card-header ph-card-disconnected-header d-flex align-items-center gap-3 fw-semibold">
        <div class="ph-logo-wrap">
                <img
                    src="<?= $logoLightEscaped; ?>"
                    class="ph-logo ph-logo--on-light ph-lazy-image"
                    alt="Infynipay"
                    loading="eager"
                    decoding="async"
                >
                <img
                    src="<?= $logoDarkEscaped; ?>"
                    class="ph-logo ph-logo--on-dark ph-lazy-image"
                    alt=""
                    loading="eager"
                    decoding="async"
                >
            </div>
        <span class="ph-card-disconnected-title"><?= htmlspecialchars($setupRequiredText, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <div class="card-body ph-card-disconnected-body">
        <a href="<?= $setupUrlEscaped; ?>" class="btn btn-primary">
            &#128279;&nbsp;<?= htmlspecialchars($connectText, ENT_QUOTES, 'UTF-8'); ?>
        </a>
    </div>
</div>
<?php else : ?>
<?php
$isLicenseExpired = !empty($displayData['isLicenseExpired']);
$renewUrl         = htmlspecialchars((string) ($displayData['renewUrl'] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<?php if ($isLicenseExpired && $renewUrl !== '') : ?>
<div class="alert alert-danger ph-license-expired d-flex align-items-start gap-3 mb-3" style="max-width:720px;border:1.5px solid #fca5a5;background:#fef2f2;border-radius:10px" role="alert">
    <style>.ph-license-expired.alert::before{display:none!important;content:none!important}</style>
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;width:20px;height:20px;color:#dc2626;margin-top:2px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <div>
        <strong style="color:#991b1b">InfyniPay License Expired</strong>
        <p class="mb-2" style="font-size:13px;color:#7f1d1d;margin-top:4px;line-height:1.5">
            Your InfyniPay app is inactive. Renew the license to restore checkout and admin actions.
        </p>
        <a href="<?= $renewUrl; ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-danger" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;border-radius:8px">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
            Renew InfyniPay License
        </a>
    </div>
</div>
<?php endif; ?>
<div class="card mb-3 ph-card-connected ph-connected-hub">
    <div class="card-header ph-card-connected-header d-flex align-items-center gap-3 fw-semibold">
        <div class="ph-logo-wrap">
            <img
                src="<?= $logoLightEscaped; ?>"
                class="ph-logo ph-logo--on-light ph-lazy-image"
                alt="Infynipay"
                loading="eager"
                decoding="async"
            >
            <img
                src="<?= $logoDarkEscaped; ?>"
                class="ph-logo ph-logo--on-dark ph-lazy-image"
                alt=""
                loading="eager"
                decoding="async"
            >
        </div>
        <span class="ph-card-connected-title"><?= htmlspecialchars($connectedText, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <div class="card-body ph-card-connected-body">
<div class="ph-gateway-apps">
<?php foreach ($apps as $app) : ?>
    <?php if (!is_array($app)) { continue; } ?>
    <?php
    $mode       = (string) ($app['mode'] ?? '');
    $modeLabel  = $mode === 'sandbox' ? $sandboxText : $liveText;
    $modeSubtitle = $mode === 'sandbox'
        ? 'Test environment for trial transactions'
        : 'Production environment for real payments';
    $appConnected      = !empty($app['isConnected']);
    $appSetupCompleted = !empty($app['isSetupCompleted']);
    $appUnreachable    = !empty($app['isUnreachable']);
    $url        = htmlspecialchars((string) ($app['url'] ?? ''), ENT_QUOTES, 'UTF-8');

    if ($appUnreachable) {
        $statusText  = '';
        $statusLabel = htmlspecialchars('Cannot reach Infynipay API', ENT_QUOTES, 'UTF-8');
        $statusState = 'incomplete';
        $btnClass    = '';
    } elseif ($appConnected && $appSetupCompleted) {
        $statusText  = $manageText;
        $statusLabel = htmlspecialchars($modeLabel . ' account has been completed', ENT_QUOTES, 'UTF-8');
        $statusState = 'ready';
        $btnClass    = 'btn-primary';
    } elseif ($appConnected) {
        $statusText  = $setupText;
        $statusLabel = htmlspecialchars($notConnectedText, ENT_QUOTES, 'UTF-8');
        $statusState = 'incomplete';
        $btnClass    = 'btn-warning';
    } else {
        $statusText  = $connectText;
        $statusLabel = htmlspecialchars($notConnectedText, ENT_QUOTES, 'UTF-8');
        $statusState = 'disconnected';
        $btnClass    = 'btn-outline-dark';
    }

    $modeIcon = $mode === 'sandbox'
        ? '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 2v6.5L4.5 18a2 2 0 0 0 1.7 3h11.6a2 2 0 0 0 1.7-3L14 8.5V2"/><path d="M9 2h6"/><path d="M7 14h10"/></svg>'
        : '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z"/></svg>';
    ?>
    <div class="ph-gateway-card ph-gateway-card--<?= htmlspecialchars($mode, ENT_QUOTES, 'UTF-8'); ?> ph-gateway-card--<?= $statusState; ?>">
        <div class="ph-gateway-card__accent"></div>
        <div class="ph-gateway-card__body">
            <div class="ph-gateway-card__head">
                <span class="ph-gateway-card__icon"><?= $modeIcon; ?></span>
                <div class="ph-gateway-card__title-wrap">
                    <div class="ph-gateway-card__title"><?= htmlspecialchars($modeLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="ph-gateway-card__subtitle"><?= htmlspecialchars($modeSubtitle, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>
            <div class="ph-gateway-card__status">
                <span class="ph-gateway-card__dot"></span>
                <span class="ph-gateway-card__status-text"><?= $statusLabel; ?></span>
            </div>
            <?php if ($appUnreachable) : ?>
            <p class="ph-gateway-card__action mb-0" style="font-size:12px;color:#b45309;margin-top:2px">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px"><line x1="1" y1="1" x2="23" y2="23"/><path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"/><path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"/><path d="M10.71 5.05A16 16 0 0 1 22.56 9"/><path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg>
                Network error — check server connectivity
            </p>
            <?php elseif ($appConnected && $url !== '') : ?>
            <a class="btn <?= $btnClass; ?> ph-gateway-card__action" href="<?= $url; ?>" target="_blank" rel="noopener noreferrer">
                <?= htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?>
            </a>
            <?php elseif (!$appConnected) : ?>
            <a class="btn <?= $btnClass; ?> ph-gateway-card__action" href="<?= $setupUrlEscaped; ?>">
                <?= htmlspecialchars($connectText, ENT_QUOTES, 'UTF-8'); ?>
            </a>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>
    </div>
</div>
<?php endif; ?>