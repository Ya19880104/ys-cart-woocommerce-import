(function () {
    const root = document.getElementById('ys-cwci-app');
    if (!root || !window.wp || !window.wp.apiFetch || !window.ysCwciAdmin) {
        return;
    }

    window.wp.apiFetch.use(window.wp.apiFetch.createNonceMiddleware(window.ysCwciAdmin.nonce));

    const capabilities = root.querySelector('[data-ys-cwci-capabilities]');
    const jobs = root.querySelector('[data-ys-cwci-jobs]');
    const uploadForm = root.querySelector('[data-ys-cwci-upload]');
    const uploadResult = root.querySelector('[data-ys-cwci-upload-result]');
    const statusText = root.querySelector('[data-ys-cwci-status-text]');

    let latestCapabilities = {};
    let autoRunningJobId = null;

    const entityLabels = {
        customers: 'Customers',
        products: 'Products',
        orders: 'Orders',
        all: 'All'
    };

    const statusLabels = {
        pending: '等待中',
        running: '執行中',
        completed: '完成',
        failed: '失敗',
        cancelled: '已取消'
    };

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const setStatus = (message) => {
        if (statusText) {
            statusText.textContent = message;
        }
    };

    const setBusy = (button, busy) => {
        if (!button) {
            return;
        }
        button.disabled = busy;
        button.classList.toggle('is-loading', busy);
        button.setAttribute('aria-busy', busy ? 'true' : 'false');
    };

    const api = (path, options = {}) => window.wp.apiFetch({ path, ...options });
    const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const isTerminal = (status) => ['completed', 'failed', 'cancelled'].includes(String(status || ''));
    const canRun = (status) => !isTerminal(status);

    const progressOf = (job) => {
        const total = Number(job.total_count || 0);
        const processed = Number(job.processed_count || 0);
        if (total > 0) {
            return Math.max(0, Math.min(100, Math.round((processed / total) * 100)));
        }
        if (job.status === 'completed') {
            return 100;
        }
        return processed > 0 ? 65 : 0;
    };

    const renderCapabilities = (data) => {
        latestCapabilities = data || {};
        const items = [
            {
                key: 'woocommerce',
                label: 'WooCommerce',
                enabled: !!data.woocommerce,
                ok: '可匯出',
                missing: '未啟用，只能執行匯入端'
            },
            {
                key: 'ys_cart',
                label: 'YS CART',
                enabled: !!data.ys_cart,
                ok: '可匯入',
                missing: '未啟用，只能執行 Woo 匯出'
            },
            {
                key: 'can_direct_transfer',
                label: 'Direct Transfer',
                enabled: !!data.can_direct_transfer,
                ok: '同站可直接轉換',
                missing: '跨站請使用 ZIP 匯出/匯入'
            }
        ];

        capabilities.innerHTML = items.map((item) => `
            <div class="ys-cwci-capability ${item.enabled ? 'is-ready' : 'is-muted'}">
                <span class="ys-cwci-capability__dot" aria-hidden="true"></span>
                <div>
                    <strong>${escapeHtml(item.label)}</strong>
                    <span>${escapeHtml(item.enabled ? item.ok : item.missing)}</span>
                </div>
            </div>
        `).join('');

        root.querySelectorAll('[data-ys-cwci-export]').forEach((button) => {
            button.disabled = !data.can_export;
        });
        if (uploadForm) {
            uploadForm.querySelectorAll('input, select, button').forEach((field) => {
                field.disabled = !data.can_import;
            });
        }
    };

    const loadCapabilities = () => api('/ys-cart-wc-import/v1/capabilities')
        .then((data) => {
            renderCapabilities(data);
            setStatus('狀態已更新');
        })
        .catch((error) => {
            capabilities.innerHTML = `<div class="ys-cwci-alert is-error">${escapeHtml(error.message || 'Unable to load capabilities.')}</div>`;
            setStatus('讀取狀態失敗');
        });

    const renderEmptyJobs = () => {
        jobs.innerHTML = `
            <div class="ys-cwci-empty">
                <span class="dashicons dashicons-database" aria-hidden="true"></span>
                <strong>目前沒有工作</strong>
                <span>建立匯出或匯入工作後，這裡會顯示批次進度。</span>
            </div>
        `;
    };

    const renderJobs = (items) => {
        if (!Array.isArray(items) || !items.length) {
            renderEmptyJobs();
            return;
        }

        jobs.innerHTML = items.map((job) => {
            const status = String(job.status || 'pending');
            const progress = progressOf(job);
            const entity = entityLabels[job.entity] || job.entity || 'Unknown';
            const type = job.type || 'job';
            const processed = Number(job.processed_count || 0);
            const success = Number(job.success_count || 0);
            const errors = Number(job.error_count || 0);
            const active = !isTerminal(status);
            const download = job.file_name
                ? `<a class="ysca-btn ysca-btn--ghost ysca-btn--sm" href="${escapeHtml(window.ysCwciAdmin.restUrl)}/jobs/${Number(job.id)}/download">
                    <span class="dashicons dashicons-download" aria-hidden="true"></span>Download
                </a>`
                : '';
            const errorButton = errors > 0
                ? `<button type="button" class="ysca-btn ysca-btn--ghost ysca-btn--sm" data-ys-cwci-errors="${Number(job.id)}">Errors</button>`
                : '';

            return `
                <article class="ys-cwci-job ${active ? 'is-active' : ''}" data-job-id="${Number(job.id)}">
                    <div class="ys-cwci-job__main">
                        <div>
                            <div class="ys-cwci-job__title">
                                <strong>#${Number(job.id)} ${escapeHtml(type)}/${escapeHtml(entity)}</strong>
                                <span class="ysca-badge ysca-badge--${escapeHtml(status)}">${escapeHtml(statusLabels[status] || status)}</span>
                            </div>
                            <div class="ys-cwci-job__meta">
                                <span>Processed ${processed}</span>
                                <span>Success ${success}</span>
                                <span class="${errors > 0 ? 'is-danger' : ''}">Errors ${errors}</span>
                                <span>Updated ${escapeHtml(job.updated_at || '')}</span>
                            </div>
                        </div>
                        <div class="ys-cwci-job__actions">
                            ${download}
                            ${errorButton}
                            <button type="button" class="ysca-btn ysca-btn--ghost ysca-btn--sm" data-ys-cwci-run="${Number(job.id)}" ${canRun(status) ? '' : 'disabled'}>Run Next</button>
                            <button type="button" class="ysca-btn ysca-btn--primary ysca-btn--sm" data-ys-cwci-auto-run="${Number(job.id)}" ${canRun(status) ? '' : 'disabled'}>Auto Run</button>
                        </div>
                    </div>
                    <div class="ys-cwci-progress" aria-label="Job progress">
                        <span style="width:${progress}%"></span>
                    </div>
                    <div class="ys-cwci-job__details" data-ys-cwci-job-details="${Number(job.id)}" hidden></div>
                </article>
            `;
        }).join('');
    };

    const loadJobs = () => api('/ys-cart-wc-import/v1/jobs')
        .then((items) => {
            renderJobs(items);
            return items;
        })
        .catch((error) => {
            jobs.innerHTML = `<div class="ys-cwci-alert is-error">${escapeHtml(error.message || 'Unable to load jobs.')}</div>`;
            setStatus('讀取工作失敗');
            return [];
        });

    const getJob = (id) => api(`/ys-cart-wc-import/v1/jobs/${id}`);

    const runNext = async (id, button) => {
        setBusy(button, true);
        setStatus(`#${id} 執行下一批`);
        try {
            await api(`/ys-cart-wc-import/v1/jobs/${id}/run-next`, { method: 'POST' });
            await loadJobs();
            setStatus(`#${id} 批次完成，狀態已更新`);
        } catch (error) {
            setStatus(error.message || `#${id} 執行失敗`);
        } finally {
            setBusy(button, false);
        }
    };

    const autoRun = async (id, button) => {
        if (autoRunningJobId) {
            setStatus(`已有 #${autoRunningJobId} 正在自動執行`);
            return;
        }

        autoRunningJobId = id;
        setBusy(button, true);
        setStatus(`#${id} 自動執行中`);

        try {
            for (let step = 0; step < 1000; step++) {
                await api(`/ys-cart-wc-import/v1/jobs/${id}/run-next`, { method: 'POST' });
                const job = await getJob(id);
                await loadJobs();
                setStatus(`#${id} ${statusLabels[job.status] || job.status}，已處理 ${Number(job.processed_count || 0)} 筆`);
                if (isTerminal(job.status)) {
                    break;
                }
                await pause(180);
            }
        } catch (error) {
            setStatus(error.message || `#${id} 自動執行中斷`);
        } finally {
            autoRunningJobId = null;
            setBusy(button, false);
            await loadJobs();
        }
    };

    const showErrors = async (id, button) => {
        setBusy(button, true);
        const panel = root.querySelector(`[data-ys-cwci-job-details="${id}"]`);
        try {
            const errors = await api(`/ys-cart-wc-import/v1/jobs/${id}/errors`);
            if (panel) {
                panel.hidden = false;
                panel.innerHTML = Array.isArray(errors) && errors.length
                    ? errors.map((error) => `<div class="ys-cwci-error-line"><strong>${escapeHtml(error.entity || 'job')} ${escapeHtml(error.source_id || '')}</strong><span>${escapeHtml(error.message || '')}</span></div>`).join('')
                    : '<div class="ys-cwci-error-line">No errors returned.</div>';
            }
        } catch (error) {
            setStatus(error.message || `#${id} 讀取錯誤失敗`);
        } finally {
            setBusy(button, false);
        }
    };

    root.addEventListener('click', (event) => {
        const exportButton = event.target.closest('[data-ys-cwci-export]');
        const runButton = event.target.closest('[data-ys-cwci-run]');
        const autoRunButton = event.target.closest('[data-ys-cwci-auto-run]');
        const refreshButton = event.target.closest('[data-ys-cwci-refresh]');
        const errorButton = event.target.closest('[data-ys-cwci-errors]');

        if (exportButton) {
            const entity = exportButton.getAttribute('data-ys-cwci-export');
            setBusy(exportButton, true);
            setStatus(`建立 ${entityLabels[entity] || entity} 匯出工作`);
            api('/ys-cart-wc-import/v1/export-jobs', {
                method: 'POST',
                data: { entity, options: {} }
            }).then(() => {
                setStatus(`${entityLabels[entity] || entity} 匯出工作已建立`);
                return loadJobs();
            }).catch((error) => {
                setStatus(error.message || '建立匯出工作失敗');
            }).finally(() => {
                setBusy(exportButton, false);
            });
        }

        if (runButton) {
            runNext(Number(runButton.getAttribute('data-ys-cwci-run')), runButton);
        }

        if (autoRunButton) {
            autoRun(Number(autoRunButton.getAttribute('data-ys-cwci-auto-run')), autoRunButton);
        }

        if (refreshButton) {
            setBusy(refreshButton, true);
            Promise.all([loadCapabilities(), loadJobs()]).finally(() => setBusy(refreshButton, false));
        }

        if (errorButton) {
            showErrors(Number(errorButton.getAttribute('data-ys-cwci-errors')), errorButton);
        }
    });

    if (uploadForm) {
        uploadForm.addEventListener('submit', (event) => {
            event.preventDefault();
            const data = new FormData(uploadForm);
            const entity = data.get('entity');
            const submitButton = uploadForm.querySelector('button[type="submit"]');

            setBusy(submitButton, true);
            setStatus('上傳套件並檢查 manifest');
            if (uploadResult) {
                uploadResult.hidden = false;
                uploadResult.textContent = 'Uploading...';
            }

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

                    if (uploadResult) {
                        uploadResult.innerHTML = `
                            <strong>Package ready</strong>
                            <span>${escapeHtml(packageInfo.manifest?.entity || entity)} from ${escapeHtml(packageInfo.manifest?.source?.site_url || 'source site')}</span>
                        `;
                    }

                    setStatus('套件已上傳，建立匯入工作');
                    return api('/ys-cart-wc-import/v1/import-jobs', {
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
                .then(() => {
                    setStatus(`${entityLabels[entity] || entity} 匯入工作已建立`);
                    return loadJobs();
                })
                .catch((error) => {
                    if (uploadResult) {
                        uploadResult.textContent = error.message || 'Import failed.';
                    }
                    setStatus(error.message || '匯入工作建立失敗');
                })
                .finally(() => {
                    setBusy(submitButton, false);
                });
        });
    }

    Promise.all([loadCapabilities(), loadJobs()]);

    window.setInterval(() => {
        if (!document.hidden && !autoRunningJobId) {
            loadJobs();
        }
    }, 15000);
})();
