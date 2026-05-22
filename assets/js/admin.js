(function () {
    const root = document.getElementById('ys-cwci-app');
    if (!root || !window.wp || !window.wp.apiFetch) {
        return;
    }

    window.wp.apiFetch.use(window.wp.apiFetch.createNonceMiddleware(window.ysCwciAdmin.nonce));

    const capabilities = root.querySelector('[data-ys-cwci-capabilities]');
    const jobs = root.querySelector('[data-ys-cwci-jobs]');
    const uploadForm = root.querySelector('[data-ys-cwci-upload]');
    const uploadResult = root.querySelector('[data-ys-cwci-upload-result]');

    const loadCapabilities = () => window.wp.apiFetch({ path: '/ys-cart-wc-import/v1/capabilities' })
        .then((data) => {
            capabilities.textContent = JSON.stringify(data, null, 2);
        })
        .catch((error) => {
            capabilities.textContent = error && error.message ? error.message : 'Unable to load capabilities.';
        });

    const renderJobs = (items) => {
        if (!items.length) {
            jobs.textContent = 'No jobs yet.';
            return;
        }

        jobs.innerHTML = items.map((job) => {
            const download = job.file_name
                ? `<a class="button" href="${window.ysCwciAdmin.restUrl}/jobs/${job.id}/download">Download</a>`
                : '';
            return `<div class="ys-cwci-job">
                <strong>#${job.id}</strong>
                <span>${job.type}/${job.entity}</span>
                <span>${job.status}</span>
                <span>${job.processed_count || 0}/${job.total_count || 0}</span>
                ${download}
                <button type="button" class="button" data-ys-cwci-run="${job.id}">Run Next</button>
            </div>`;
        }).join('');
    };

    const loadJobs = () => window.wp.apiFetch({ path: '/ys-cart-wc-import/v1/jobs' })
        .then(renderJobs)
        .catch((error) => {
            jobs.textContent = error && error.message ? error.message : 'Unable to load jobs.';
        });

    root.addEventListener('click', (event) => {
        const exportButton = event.target.closest('[data-ys-cwci-export]');
        const runButton = event.target.closest('[data-ys-cwci-run]');
        const refreshButton = event.target.closest('[data-ys-cwci-refresh]');

        if (exportButton) {
            exportButton.disabled = true;
            window.wp.apiFetch({
                path: '/ys-cart-wc-import/v1/export-jobs',
                method: 'POST',
                data: { entity: exportButton.getAttribute('data-ys-cwci-export'), options: {} }
            }).then(loadJobs).finally(() => {
                exportButton.disabled = false;
            });
        }

        if (runButton) {
            window.wp.apiFetch({
                path: `/ys-cart-wc-import/v1/jobs/${runButton.getAttribute('data-ys-cwci-run')}/run-next`,
                method: 'POST'
            }).then(loadJobs);
        }

        if (refreshButton) {
            loadJobs();
        }
    });

    if (uploadForm) {
        uploadForm.addEventListener('submit', (event) => {
            event.preventDefault();
            const data = new FormData(uploadForm);
            const entity = data.get('entity');

            fetch(`${window.ysCwciAdmin.restUrl}/packages/upload`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': window.ysCwciAdmin.nonce },
                body: data
            })
                .then((response) => response.json())
                .then((packageInfo) => {
                    if (packageInfo.code) {
                        throw new Error(packageInfo.message || 'Upload failed.');
                    }

                    uploadResult.textContent = JSON.stringify(packageInfo.manifest, null, 2);
                    return window.wp.apiFetch({
                        path: '/ys-cart-wc-import/v1/import-jobs',
                        method: 'POST',
                        data: {
                            entity,
                            options: {
                                file_path: packageInfo.file_path,
                                source_fingerprint: packageInfo.manifest.source.site_url_hash
                            }
                        }
                    });
                })
                .then(loadJobs)
                .catch((error) => {
                    uploadResult.textContent = error.message || 'Import failed.';
                });
        });
    }

    loadCapabilities();
    loadJobs();
})();
