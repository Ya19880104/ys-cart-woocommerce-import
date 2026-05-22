(function () {
    'use strict';

    var cfg = window.ysCwciLicenseNag || {};
    var seed = document.getElementById('ys-cwci-license-nag-seed');

    if (!seed || !cfg.modalClass) {
        return;
    }

    var dismissed = false;

    function closeModal() {
        dismissed = true;
        var node = document.querySelector('[data-ys-cwci-license-modal="1"]');
        if (node && node.parentNode) {
            node.parentNode.removeChild(node);
        }
    }

    function render() {
        if (dismissed || document.querySelector('[data-ys-cwci-license-modal="1"]')) {
            return;
        }

        var backdrop = document.createElement('div');
        backdrop.className = 'ys-cwci-license-nag ' + cfg.modalClass;
        backdrop.setAttribute('data-ys-cwci-license-modal', '1');
        backdrop.setAttribute('role', 'dialog');
        backdrop.setAttribute('aria-modal', 'true');
        backdrop.setAttribute('aria-labelledby', 'ys-cwci-license-nag-title');

        var panel = document.createElement('div');
        panel.className = 'ys-cwci-license-nag__panel';

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'ys-cwci-license-nag__close';
        close.setAttribute('aria-label', cfg.closeLabel || 'Close');
        close.textContent = 'x';
        close.addEventListener('click', closeModal);

        var title = document.createElement('h2');
        title.id = 'ys-cwci-license-nag-title';
        title.textContent = cfg.title || 'YS CART Woo Import is not licensed';

        var body = document.createElement('p');
        body.textContent = cfg.body || 'Enter a license key to activate this add-on.';

        var meta = document.createElement('p');
        meta.className = 'ys-cwci-license-nag__meta';
        meta.textContent = 'Status: ' + (cfg.status || 'unknown') + (cfg.errorCode ? ' / ' + cfg.errorCode : '');

        var link = document.createElement('a');
        link.className = 'button button-primary';
        link.href = cfg.licenseUrl || '#';
        link.textContent = cfg.buttonLabel || 'Open license settings';

        panel.appendChild(close);
        panel.appendChild(title);
        panel.appendChild(body);
        panel.appendChild(meta);
        panel.appendChild(link);
        backdrop.appendChild(panel);
        document.body.appendChild(backdrop);

        close.focus({ preventScroll: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', render, { once: true });
    } else {
        render();
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });
}());
