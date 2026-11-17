<?php
/**
 * Supported gateways field layout — renders all available provider icons.
 *
 * @var array $displayData
 */

defined('_JEXEC') or die;

$providers = is_array($displayData['providers'] ?? null) ? $displayData['providers'] : [];
$emptyText = htmlspecialchars((string) ($displayData['emptyText'] ?? ''), ENT_QUOTES, 'UTF-8');
$visibleProviders = array_values(array_filter($providers, static function ($provider) {
    if (!is_array($provider)) {
        return false;
    }

    return (string) ($provider['iconUri1'] ?? '') !== '' || (string) ($provider['iconUri2'] ?? '') !== '';
}));
?>
<?php if (!empty($visibleProviders)) : ?>
<div class="ph-providers-card">
<div class="ph-providers-grid">
    <?php foreach ($visibleProviders as $provider) : ?>
        <?php
        $name      = htmlspecialchars((string) ($provider['provider'] ?? ''), ENT_QUOTES, 'UTF-8');
        $iconLight = htmlspecialchars((string) ($provider['iconUri1'] ?? ''), ENT_QUOTES, 'UTF-8');
        $iconDark  = htmlspecialchars((string) ($provider['iconUri2'] ?? ''), ENT_QUOTES, 'UTF-8');
        ?>
        <div class="ph-provider-item ph-skeleton-box" title="<?= $name; ?>">
            <img
                src="<?= $iconLight !== '' ? $iconLight : $iconDark; ?>"
                data-light-src="<?= $iconLight; ?>"
                data-dark-src="<?= $iconDark; ?>"
                data-fallback-src="<?= $iconDark !== '' ? $iconDark : $iconLight; ?>"
                class="ph-provider-art ph-lazy-image"
                alt="<?= $name; ?>"
                loading="eager"
                decoding="async"
                onerror="if (this.dataset.fallbackSrc && this.dataset.fallbackSrc !== this.src) { this.src = this.dataset.fallbackSrc; this.dataset.fallbackSrc = ''; }"
            >
        </div>
    <?php endforeach; ?>
</div>
</div>
<?php elseif ($emptyText !== '') : ?>
<div class="ph-providers-card">
    <p class="ph-providers-empty"><?= $emptyText; ?></p>
</div>
<?php endif; ?>