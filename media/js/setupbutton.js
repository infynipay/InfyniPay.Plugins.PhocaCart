document.addEventListener('DOMContentLoaded', function () {
    var licenseInput = document.querySelector('input[name="jform[params][license_id]"]');
    var sandboxInput = document.querySelector('input[name="jform[params][sandbox]"]');

    var isDarkTheme = function () {
        var root = document.documentElement;

        if (root.getAttribute('data-bs-theme') === 'dark' || document.body.classList.contains('joomla-color-scheme-dark')) {
            return true;
        }

        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    };

    var initProviderImages = function () {
        var useDark = isDarkTheme();

        document.querySelectorAll('.ph-provider-art').forEach(function (image) {
            var lightSrc = image.dataset.lightSrc || '';
            var darkSrc = image.dataset.darkSrc || '';
            var preferredSrc = useDark ? (darkSrc || lightSrc) : (lightSrc || darkSrc);
            var fallbackSrc = useDark ? (lightSrc || '') : (darkSrc || '');

            if (preferredSrc) {
                image.style.display = '';
                var item = image.closest('.ph-provider-item');

                if (item) {
                    item.style.display = '';
                }

                image.dataset.fallbackSrc = fallbackSrc;

                if (image.getAttribute('src') !== preferredSrc) {
                    image.setAttribute('src', preferredSrc);
                }
            }
        });
    };

    var initSkeletonImages = function () {
        document.querySelectorAll('.ph-lazy-image').forEach(function (img) {
            var host = img.closest('.ph-provider-item, .ph-logo-wrap');

            if (!host) {
                return;
            }

            var markLoaded = function () {
                host.classList.add('ph-loaded');
            };

            if (img.complete && img.naturalWidth > 0) {
                markLoaded();
                return;
            }

            img.addEventListener('load', markLoaded, { once: true });
            img.addEventListener('error', markLoaded, { once: true });
        });
    };

    var syncProviderGrid = function () {
        document.querySelectorAll('.ph-providers-grid').forEach(function (grid) {
            var width = grid.clientWidth;
            var columns = 5;

            if (width <= 420) {
                columns = 2;
            } else if (width <= 560) {
                columns = 3;
            } else if (width <= 660) {
                columns = 4;
            }

            grid.style.display = 'grid';
            grid.style.gridTemplateColumns = 'repeat(' + columns + ', minmax(0, 1fr))';
            grid.style.gridAutoRows = 'minmax(64px, auto)';
            grid.style.gap = '0.8rem';

            grid.querySelectorAll('.ph-provider-item').forEach(function (item) {
                item.style.width = '100%';
                item.style.maxWidth = 'none';
                item.style.minWidth = '0';
                item.style.flex = 'none';
            });
        });
    };

    var initCardRows = function () {
        var label = document.querySelector('input[name="jform[params][sandbox]"]');
        var labelRow = label ? label.closest('.control-group') : null;
        var labelColumn = labelRow ? labelRow.querySelector('.control-label') : null;

        if (labelColumn) {
            document.documentElement.style.setProperty('--ph-infynipay-label-offset', labelColumn.offsetWidth + 'px');
        }

        document.querySelectorAll('.ph-card-connected, .ph-card-disconnected, .ph-providers-card, .ph-log-card').forEach(function (card) {
            var row = card.closest('.control-group');

            if (row) {
                row.classList.add('ph-infynipay-control-group');

                if (!row.querySelector('.control-label label') && !card.classList.contains('ph-providers-card') && !card.classList.contains('ph-log-card') && (card.classList.contains('ph-card-connected') || card.classList.contains('ph-card-disconnected'))) {
                    row.classList.add('ph-infynipay-setup-control-group');
                }
            }
        });

        [
            'sandbox',
            'setup_button',
            'providers_overview',
            'order_status_success',
            'order_status_pending',
            'order_status_failed',
            'order_status_cancelled',
            'log_viewer'
        ].forEach(function (fieldName) {
            var field = document.querySelector('[name="jform[params][' + fieldName + ']"]');
            var row = field ? field.closest('.control-group') : null;

            if (row) {
                row.classList.add('ph-infynipay-control-group');
            }
        });
    };

    var svgBlobCache = {};

    var fixSvgImages = function (onComplete) {
        if (!window.fetch || !window.Blob || !window.URL || !URL.createObjectURL) {
            if (onComplete) { onComplete(); }
            return;
        }

        var isSvgUrl = function (url) {
            if (!url || url.slice(0, 5) === 'blob:' || url.slice(0, 5) === 'data:') { return false; }
            return url.split('?')[0].toLowerCase().slice(-4) === '.svg';
        };

        var blobify = function (url) {
            if (svgBlobCache[url] !== undefined) {
                return Promise.resolve(svgBlobCache[url]);
            }
            return fetch(url, { mode: 'cors', credentials: 'omit' })
                .then(function (r) {
                    var ct = (r.headers.get('content-type') || '').toLowerCase();
                    if (ct.indexOf('svg') !== -1) {
                        return Promise.resolve(null); // already correct MIME, keep URL as-is
                    }
                    return r.text();
                })
                .then(function (text) {
                    if (!text) {
                        svgBlobCache[url] = url;
                        return url;
                    }
                    var blob = new Blob([text], { type: 'image/svg+xml' });
                    var blobUrl = URL.createObjectURL(blob);
                    svgBlobCache[url] = blobUrl;
                    return blobUrl;
                })
                .catch(function () {
                    svgBlobCache[url] = url;
                    return url;
                });
        };

        var promises = [];

        document.querySelectorAll('.ph-provider-art').forEach(function (img) {
            var lightUrl = img.dataset.lightSrc || '';
            var darkUrl  = img.dataset.darkSrc  || '';
            var srcUrl   = img.getAttribute('src') || '';

            var process = function (url, attr) {
                if (!isSvgUrl(url)) { return; }
                var p = blobify(url).then(function (blobUrl) {
                    if (!blobUrl || blobUrl === url) { return; } // no change needed
                    if (attr === 'light') { img.dataset.lightSrc = blobUrl; }
                    if (attr === 'dark')  { img.dataset.darkSrc  = blobUrl; }
                    if (img.getAttribute('src') === url) {
                        img.removeAttribute('onerror');
                        img.src = blobUrl;
                    }
                });
                promises.push(p);
            };

            process(lightUrl, 'light');
            process(darkUrl,  'dark');
            // Also fix current src if it differs from both data attrs (e.g. after onerror swap)
            if (srcUrl !== lightUrl && srcUrl !== darkUrl) {
                process(srcUrl, 'src');
            }
        });

        if (!promises.length) {
            if (onComplete) { onComplete(); }
            return;
        }

        Promise.all(promises).then(function () {
            if (onComplete) { onComplete(); }
        }).catch(function () {
            if (onComplete) { onComplete(); }
        });
    };

    initCardRows();
    initProviderImages();
    initSkeletonImages();
    fixSvgImages(function () {
        initProviderImages(); // re-run now that data-light-src / data-dark-src hold blob URLs
        initSkeletonImages();
        syncProviderGrid();
    });
    syncProviderGrid();

    window.addEventListener('resize', syncProviderGrid);

    if (window.ResizeObserver) {
        document.querySelectorAll('.ph-providers-grid').forEach(function (grid) {
            new ResizeObserver(syncProviderGrid).observe(grid);
        });
    }

    if (!licenseInput) {
        return;
    }

    var connectedRows = [];

    document.querySelectorAll('.ph-connected-only').forEach(function (element) {
        var row = element.closest('.control-group, .control-wrapper, .mb-3, .field-spacer, .form-group');

        if (!row) {
            row = element.closest('fieldset, .controls, .control-field');
        }

        if (row) {
            connectedRows.push(row);
        }
    });

    var syncVisibility = function () {
        var isConnected = licenseInput.value.trim() !== '';
        connectedRows.forEach(function (row) {
            row.style.display = isConnected ? '' : 'none';
        });
    };

    syncVisibility();

    var observer = new MutationObserver(syncVisibility);
    observer.observe(licenseInput, {
        attributes: true,
        attributeFilter: ['value']
    });

    licenseInput.addEventListener('change', syncVisibility);
});
