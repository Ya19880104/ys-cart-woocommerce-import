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
    const backups = root.querySelector('[data-ys-cwci-backups]');

    let autoRunningJobId = null;

    const entityLabels = {
        customers: '客戶',
        products: '商品',
        orders: '訂單',
        all: '全部'
    };

    const typeLabels = {
        export: '匯出',
        import: '匯入',
        direct: '直接移轉',
        job: '工作'
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

    const formatBytes = (bytes) => {
        const value = Number(bytes || 0);
        if (value < 1024) {
            return `${value} B`;
        }
        if (value < 1024 * 1024) {
            return `${(value / 1024).toFixed(1)} KB`;
        }
        return `${(value / 1024 / 1024).toFixed(1)} MB`;
    };

    const formatDate = (value) => {
        if (!value) {
            return '';
        }
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return value;
        }
        return date.toLocaleString();
    };

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
        const items = [
            {
                label: 'WooCommerce',
                enabled: !!data.woocommerce,
                ok: '可匯出',
                missing: '未啟用，只能使用匯入端'
            },
            {
                label: 'YS CART',
                enabled: !!data.ys_cart,
                ok: '可匯入',
                missing: '未啟用，只能使用 Woo 匯出'
            },
            {
                label: '同站直接移轉',
                enabled: !!data.can_direct_transfer,
                ok: '同站可用',
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
            capabilities.innerHTML = `<div class="ys-cwci-alert is-error">${escapeHtml(error.message || '無法讀取狀態。')}</div>`;
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
            const entity = entityLabels[job.entity] || job.entity || '未知';
            const type = typeLabels[job.type] || job.type || '工作';
            const processed = Number(job.processed_count || 0);
            const success = Number(job.success_count || 0);
            const errors = Number(job.error_count || 0);
            const active = !isTerminal(status);
            const download = job.file_name
                ? `<a class="ys-cwci-btn ys-cwci-btn--ghost ys-cwci-btn--sm" href="${escapeHtml(window.ysCwciAdmin.restUrl)}/jobs/${Number(job.id)}/download?_wpnonce=${encodeURIComponent(window.ysCwciAdmin.nonce)}">
                    <span class="dashicons dashicons-download" aria-hidden="true"></span>下載
                </a>`
                : '';
            const errorButton = errors > 0
                ? `<button type="button" class="ys-cwci-btn ys-cwci-btn--ghost ys-cwci-btn--sm" data-ys-cwci-errors="${Number(job.id)}">錯誤</button>`
                : '';

            return `
                <article class="ys-cwci-job ${active ? 'is-active' : ''}" data-job-id="${Number(job.id)}">
                    <div class="ys-cwci-job__main">
                        <div>
                            <div class="ys-cwci-job__title">
                                <strong>#${Number(job.id)} ${escapeHtml(type)}/${escapeHtml(entity)}</strong>
                                <span class="ys-cwci-badge ys-cwci-badge--${escapeHtml(status)}">${escapeHtml(statusLabels[status] || status)}</span>
                            </div>
                            <div class="ys-cwci-job__meta">
                                <span>已處理 ${processed}</span>
                                <span>成功 ${success}</span>
                                <span class="${errors > 0 ? 'is-danger' : ''}">錯誤 ${errors}</span>
                                <span>更新 ${escapeHtml(job.updated_at || '')}</span>
                            </div>
                        </div>
                        <div class="ys-cwci-job__actions">
                            ${download}
                            ${errorButton}
                            <button type="button" class="ys-cwci-btn ys-cwci-btn--ghost ys-cwci-btn--sm" data-ys-cwci-run="${Number(job.id)}" ${canRun(status) ? '' : 'disabled'}>執行下一批</button>
                            <button type="button" class="ys-cwci-btn ys-cwci-btn--primary ys-cwci-btn--sm" data-ys-cwci-auto-run="${Number(job.id)}" ${canRun(status) ? '' : 'disabled'}>自動執行</button>
                        </div>
                    </div>
                    <div class="ys-cwci-progress" aria-label="工作進度">
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
            jobs.innerHTML = `<div class="ys-cwci-alert is-error">${escapeHtml(error.message || '無法讀取工作。')}</div>`;
            setStatus('讀取工作失敗');
            return [];
        });

    const renderBackups = (items) => {
        if (!backups) {
            return;
        }

        if (!Array.isArray(items) || !items.length) {
            backups.innerHTML = `
                <div class="ys-cwci-empty ys-cwci-empty--compact">
                    <strong>尚未建立備份</strong>
                    <span>匯入前建議先建立 SQL 備份。</span>
                </div>
            `;
            return;
        }

        backups.innerHTML = items.map((backup) => {
            const file = String(backup.file || '');
            const downloadUrl = `${window.ysCwciAdmin.restUrl}/backups/${encodeURIComponent(file)}/download?_wpnonce=${encodeURIComponent(window.ysCwciAdmin.nonce)}`;
            return `
                <article class="ys-cwci-backup">
                    <div>
                        <strong>${escapeHtml(file)}</strong>
                        <span>${escapeHtml(formatBytes(backup.size))} · ${escapeHtml(formatDate(backup.created_at))}</span>
                    </div>
                    <div class="ys-cwci-backup__actions">
                        <a class="ys-cwci-btn ys-cwci-btn--ghost ys-cwci-btn--sm" href="${escapeHtml(downloadUrl)}">
                            <span class="dashicons dashicons-download" aria-hidden="true"></span>下載
                        </a>
                        <button type="button" class="ys-cwci-btn ys-cwci-btn--ghost ys-cwci-btn--sm" data-ys-cwci-backup-restore="${escapeHtml(file)}">還原</button>
                        <button type="button" class="ys-cwci-btn ys-cwci-btn--danger ys-cwci-btn--sm" data-ys-cwci-backup-delete="${escapeHtml(file)}">刪除</button>
                    </div>
                </article>
            `;
        }).join('');
    };

    const loadBackups = () => api('/ys-cart-wc-import/v1/backups')
        .then((data) => {
            renderBackups(data.backups || []);
            return data.backups || [];
        })
        .catch((error) => {
            if (backups) {
                backups.innerHTML = `<div class="ys-cwci-alert is-error">${escapeHtml(error.message || '無法讀取備份。')}</div>`;
            }
            return [];
        });

    const createBackup = async (button) => {
        setBusy(button, true);
        setStatus('正在建立 SQL 備份');
        try {
            const result = await api('/ys-cart-wc-import/v1/backups', { method: 'POST' });
            if (result.error) {
                throw new Error(result.message || '建立備份失敗。');
            }
            setStatus(`備份已建立：${result.backup?.file || ''}`);
            await loadBackups();
        } catch (error) {
            setStatus(error.message || '建立備份失敗。');
        } finally {
            setBusy(button, false);
        }
    };

    const deleteBackup = async (file, button) => {
        if (!window.confirm(`確定要刪除備份？\n${file}`)) {
            return;
        }
        setBusy(button, true);
        try {
            await api(`/ys-cart-wc-import/v1/backups/${encodeURIComponent(file)}`, { method: 'DELETE' });
            setStatus('備份已刪除');
            await loadBackups();
        } catch (error) {
            setStatus(error.message || '刪除備份失敗。');
        } finally {
            setBusy(button, false);
        }
    };

    const restoreBackup = async (file, button) => {
        const confirmText = window.prompt(`還原會覆蓋目前資料庫資料。\n若確定要還原 ${file}，請輸入 RESTORE`);
        if (confirmText !== 'RESTORE') {
            setStatus('已取消還原');
            return;
        }
        setBusy(button, true);
        setStatus('正在還原 SQL 備份');
        try {
            const result = await api(`/ys-cart-wc-import/v1/backups/${encodeURIComponent(file)}/restore`, {
                method: 'POST',
                data: { confirm: 'RESTORE' }
            });
            setStatus(`還原完成，已執行 ${Number(result.restored?.statements || 0)} 個 SQL statements`);
        } catch (error) {
            setStatus(error.message || '還原備份失敗。');
        } finally {
            setBusy(button, false);
        }
    };

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
                    ? errors.map((error) => `<div class="ys-cwci-error-line"><strong>${escapeHtml(entityLabels[error.entity] || error.entity || '工作')} ${escapeHtml(error.source_id || '')}</strong><span>${escapeHtml(error.message || '')}</span></div>`).join('')
                    : '<div class="ys-cwci-error-line">沒有回傳錯誤內容。</div>';
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
        const backupCreateButton = event.target.closest('[data-ys-cwci-backup-create]');
        const backupRefreshButton = event.target.closest('[data-ys-cwci-backup-refresh]');
        const backupDeleteButton = event.target.closest('[data-ys-cwci-backup-delete]');
        const backupRestoreButton = event.target.closest('[data-ys-cwci-backup-restore]');

        if (exportButton) {
            const entity = exportButton.getAttribute('data-ys-cwci-export');
            setBusy(exportButton, true);
            setStatus(`建立 ${entityLabels[entity] || entity} 匯出工作`);
            api('/ys-cart-wc-import/v1/export-jobs', {
                method: 'POST',
                data: { entity, options: {} }
            }).then((job) => {
                const jobId = Number(job?.id || 0);
                setStatus(`${entityLabels[entity] || entity} 匯出工作已建立，開始自動執行`);
                return loadJobs().then(() => {
                    if (jobId > 0) {
                        return autoRun(jobId, exportButton);
                    }
                    return undefined;
                });
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
            Promise.all([loadCapabilities(), loadJobs(), loadBackups()]).finally(() => setBusy(refreshButton, false));
        }

        if (errorButton) {
            showErrors(Number(errorButton.getAttribute('data-ys-cwci-errors')), errorButton);
        }

        if (backupCreateButton) {
            createBackup(backupCreateButton);
        }

        if (backupRefreshButton) {
            setBusy(backupRefreshButton, true);
            loadBackups().finally(() => setBusy(backupRefreshButton, false));
        }

        if (backupDeleteButton) {
            deleteBackup(backupDeleteButton.getAttribute('data-ys-cwci-backup-delete'), backupDeleteButton);
        }

        if (backupRestoreButton) {
            restoreBackup(backupRestoreButton.getAttribute('data-ys-cwci-backup-restore'), backupRestoreButton);
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
                uploadResult.textContent = '上傳中...';
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
                        throw new Error(packageInfo.message || '上傳失敗。');
                    }

                    if (uploadResult) {
                        uploadResult.innerHTML = `
                            <strong>套件已就緒</strong>
                            <span>${escapeHtml(entityLabels[packageInfo.manifest?.entity] || packageInfo.manifest?.entity || entityLabels[entity] || entity)}，來源：${escapeHtml(packageInfo.manifest?.source?.site_url || '來源網站')}</span>
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
                .then((job) => {
                    const jobId = Number(job?.id || 0);
                    setStatus(`${entityLabels[entity] || entity} 匯入工作已建立，開始自動執行`);
                    return loadJobs().then(() => {
                        if (jobId > 0) {
                            return autoRun(jobId, submitButton);
                        }
                        return undefined;
                    });
                })
                .catch((error) => {
                    if (uploadResult) {
                        uploadResult.textContent = error.message || '匯入失敗。';
                    }
                    setStatus(error.message || '匯入工作建立失敗');
                })
                .finally(() => {
                    setBusy(submitButton, false);
                });
        });
    }

    /* ─────────────────────────────────────────────
       v0.6.0 精靈模式（步驟式引導）＋ 模式切換
       手動模式 = 既有工程化 UI（上方全部程式不動）。
       ───────────────────────────────────────────── */

    const MODE_KEY = 'ys_cwci_mode';
    const modeTabs = root.querySelectorAll('[data-ys-cwci-mode]');
    const modePanels = root.querySelectorAll('[data-ys-cwci-mode-panel]');

    const applyMode = (mode) => {
        const target = mode === 'manual' ? 'manual' : 'wizard';
        modePanels.forEach((panel) => {
            panel.hidden = panel.getAttribute('data-ys-cwci-mode-panel') !== target;
        });
        modeTabs.forEach((tab) => {
            tab.classList.toggle('is-active', tab.getAttribute('data-ys-cwci-mode') === target);
            tab.setAttribute('aria-selected', tab.getAttribute('data-ys-cwci-mode') === target ? 'true' : 'false');
        });
        try { window.localStorage.setItem(MODE_KEY, target); } catch (e) { /* storage 不可用就不記憶 */ }
    };

    modeTabs.forEach((tab) => {
        tab.addEventListener('click', () => applyMode(tab.getAttribute('data-ys-cwci-mode')));
    });

    const wizardRoot = root.querySelector('[data-ys-cwci-wizard]');
    const wizard = wizardRoot ? {
        rail: wizardRoot.querySelector('[data-ys-cwci-wizard-rail]'),
        panel: wizardRoot.querySelector('[data-ys-cwci-wizard-panel]'),
        actions: wizardRoot.querySelector('[data-ys-cwci-wizard-actions]'),
        title: root.querySelector('[data-ys-cwci-wizard-title]'),
        steps: [],
        index: 0,
        caps: null,
        results: {},   // entity -> { exported, imported, errors, downloadUrl }
        running: false,

        boot(caps) {
            this.caps = caps;
            const flow = caps.can_direct_transfer ? 'direct' : (caps.can_export ? 'export' : (caps.can_import ? 'import' : 'none'));
            this.flow = flow;
            if (this.title) {
                this.title.textContent = {
                    direct: '同站搬家精靈（WooCommerce → YS CART）',
                    export: '匯出精靈（來源站：打包 WooCommerce 資料）',
                    import: '匯入精靈（目標站：匯入套件到 YS CART）',
                    none: '此網站沒有 WooCommerce 也沒有 YS CART'
                }[flow];
            }
            this.steps = this.buildSteps(flow);
            this.index = 0;
            this.render();
        },

        buildSteps(flow) {
            const entitySteps = (kind) => ['customers', 'products', 'orders'].map((entity) => ({
                key: `${kind}-${entity}`, title: `${entityLabels[entity]}`, kind, entity, skippable: true
            }));
            if (flow === 'direct') {
                return [
                    { key: 'env', title: '環境檢查', kind: 'env' },
                    { key: 'backup', title: 'SQL 備份', kind: 'backup', skippable: true },
                    ...entitySteps('direct'),
                    { key: 'done', title: '完成', kind: 'done' }
                ];
            }
            if (flow === 'export') {
                return [
                    { key: 'env', title: '環境檢查', kind: 'env' },
                    ...entitySteps('export'),
                    { key: 'done', title: '完成', kind: 'done' }
                ];
            }
            if (flow === 'import') {
                return [
                    { key: 'env', title: '環境檢查', kind: 'env' },
                    { key: 'backup', title: 'SQL 備份', kind: 'backup', skippable: true },
                    ...entitySteps('import'),
                    { key: 'done', title: '完成', kind: 'done' }
                ];
            }
            return [{ key: 'env', title: '環境檢查', kind: 'env' }];
        },

        render() {
            const step = this.steps[this.index];
            this.rail.innerHTML = this.steps.map((s, i) => `
                <li class="ys-cwci-wizard__step ${i === this.index ? 'is-current' : ''} ${i < this.index ? 'is-done' : ''}">
                    <span class="ys-cwci-wizard__num">${i < this.index ? '✓' : i + 1}</span>
                    <span>${escapeHtml(s.title)}</span>
                </li>
            `).join('');
            this.renderPanel(step);
            this.renderActions(step);
        },

        renderActions(step) {
            const last = this.index >= this.steps.length - 1;
            const parts = [];
            if (this.index > 0 && !this.running) {
                parts.push('<button type="button" class="ys-cwci-btn ys-cwci-btn--ghost" data-wz="prev">上一步</button>');
            }
            if (step.skippable && !this.running) {
                parts.push('<button type="button" class="ys-cwci-btn ys-cwci-btn--ghost" data-wz="skip">略過</button>');
            }
            if (!last && !this.running && step.kind === 'env') {
                parts.push('<button type="button" class="ys-cwci-btn ys-cwci-btn--primary" data-wz="next">開始 →</button>');
            }
            this.actions.innerHTML = parts.join('');
        },

        renderPanel(step) {
            const p = this.panel;
            if (step.kind === 'env') {
                const c = this.caps;
                const row = (label, on, okText, noText) => `
                    <div class="ys-cwci-capability ${on ? 'is-ready' : 'is-muted'}">
                        <span class="ys-cwci-capability__dot" aria-hidden="true"></span>
                        <div><strong>${escapeHtml(label)}</strong><span>${escapeHtml(on ? okText : noText)}</span></div>
                    </div>`;
                const flowDesc = {
                    direct: '本站同時有 WooCommerce 與 YS CART：精靈會依「客戶 → 商品 → 訂單」順序，逐項匯出並直接匯入本站。可重複執行，已搬過的資料不會重複。',
                    export: '本站只有 WooCommerce：精靈會逐項打包成 ZIP 套件，完成後請下載並到目標站（裝有 YS CART）以匯入精靈上傳。',
                    import: '本站只有 YS CART：請準備來源站匯出的 ZIP 套件，精靈會依正確順序引導你逐項上傳匯入。',
                    none: '此網站偵測不到 WooCommerce 或 YS CART，無法使用搬家功能。'
                }[this.flow];
                p.innerHTML = `
                    <div class="ys-cwci-capabilities">
                        ${row('WooCommerce', !!c.woocommerce, '可匯出', '未啟用')}
                        ${row('YS CART', !!c.ys_cart, '可匯入', '未啟用')}
                        ${row('同站直接移轉', !!c.can_direct_transfer, '可用', '不可用')}
                    </div>
                    <p class="ys-cwci-wizard__desc">${escapeHtml(flowDesc)}</p>`;
                return;
            }
            if (step.kind === 'backup') {
                p.innerHTML = `
                    <p class="ys-cwci-wizard__desc">匯入會寫入資料庫。<strong>強烈建議先建立 SQL 備份</strong>，若結果不符預期可在「手動模式」一鍵還原。</p>
                    <button type="button" class="ys-cwci-btn ys-cwci-btn--primary" data-wz="backup">
                        <span class="dashicons dashicons-database-export" aria-hidden="true"></span> 建立 SQL 備份並繼續
                    </button>
                    <div class="ys-cwci-wizard__log" data-wz-log hidden></div>`;
                return;
            }
            if (step.kind === 'direct' || step.kind === 'export' || step.kind === 'import') {
                const verb = { direct: '匯出並匯入', export: '匯出打包', import: '上傳並匯入' }[step.kind];
                const uploadField = step.kind === 'import'
                    ? `<label class="ys-cwci-field"><span>選擇 ${escapeHtml(entityLabels[step.entity])}套件（ZIP）</span><input type="file" accept=".zip" data-wz-file></label>`
                    : '';
                p.innerHTML = `
                    <p class="ys-cwci-wizard__desc">第 ${this.index + 1} 步：${verb}「${escapeHtml(entityLabels[step.entity])}」。${step.kind === 'direct' ? '已存在的資料會自動略過或更新，不會重複。' : ''}</p>
                    ${uploadField}
                    <button type="button" class="ys-cwci-btn ys-cwci-btn--primary" data-wz="run-entity">
                        <span class="dashicons dashicons-controls-play" aria-hidden="true"></span> 開始${verb}
                    </button>
                    <div class="ys-cwci-wizard__progress" data-wz-progress hidden>
                        <div class="ys-cwci-progress"><span style="width:0%" data-wz-bar></span></div>
                        <p data-wz-progress-text>準備中…</p>
                    </div>
                    <div class="ys-cwci-wizard__log" data-wz-log hidden></div>`;
                return;
            }
            if (step.kind === 'done') {
                const rows = Object.entries(this.results).map(([entity, r]) => `
                    <li>
                        <strong>${escapeHtml(entityLabels[entity] || entity)}</strong>：
                        ${r.skipped ? '已略過' : `成功 ${Number(r.success || 0)} 筆、錯誤 ${Number(r.errors || 0)} 筆`}
                        ${r.downloadUrl ? ` · <a href="${escapeHtml(r.downloadUrl)}">下載套件</a>` : ''}
                        ${Number(r.errors || 0) > 0 ? ' · 詳見手動模式工作紀錄' : ''}
                    </li>`).join('');
                const wooNote = (this.caps && this.caps.woocommerce && this.flow !== 'export')
                    ? '<li class="ys-cwci-guidance__warn"><strong>WooCommerce 仍啟用：</strong>驗證資料無誤後請停用 WooCommerce，再依「搬家指引」調整商品網址前綴。</li>'
                    : '';
                p.innerHTML = `
                    <p class="ys-cwci-wizard__desc"><strong>${this.flow === 'export' ? '打包完成！' : '搬家完成！'}</strong></p>
                    <ul class="ys-cwci-guidance">${rows || '<li>本次沒有執行任何項目。</li>'}${wooNote}</ul>
                    <div class="ys-cwci-actions">
                        <button type="button" class="ys-cwci-btn ys-cwci-btn--ghost" data-wz="restart">重新開始</button>
                        <button type="button" class="ys-cwci-btn ys-cwci-btn--primary" data-wz="to-manual">查看工作紀錄（手動模式）</button>
                    </div>`;
                return;
            }
        },

        log(msg) {
            const el = this.panel.querySelector('[data-wz-log]');
            if (el) {
                el.hidden = false;
                el.innerHTML += `<div>${escapeHtml(msg)}</div>`;
            }
        },

        progress(pct, text) {
            const wrap = this.panel.querySelector('[data-wz-progress]');
            const bar = this.panel.querySelector('[data-wz-bar]');
            const label = this.panel.querySelector('[data-wz-progress-text]');
            if (wrap) { wrap.hidden = false; }
            if (bar) { bar.style.width = `${Math.max(0, Math.min(100, pct))}%`; }
            if (label) { label.textContent = text; }
        },

        async runJobToEnd(jobId, phaseLabel) {
            for (let i = 0; i < 2000; i++) {
                await api(`/ys-cart-wc-import/v1/jobs/${jobId}/run-next`, { method: 'POST' });
                const job = await getJob(jobId);
                const pct = progressOf(job);
                this.progress(pct, `${phaseLabel}：已處理 ${Number(job.processed_count || 0)} 筆（成功 ${Number(job.success_count || 0)}、錯誤 ${Number(job.error_count || 0)}）`);
                if (isTerminal(job.status)) {
                    return job;
                }
                await pause(120);
            }
            throw new Error('執行逾時');
        },

        async runEntityStep(step, button) {
            if (this.running) { return; }
            this.running = true;
            setBusy(button, true);
            this.renderActions(step);
            const entity = step.entity;
            try {
                if (step.kind === 'export' || step.kind === 'direct') {
                    this.progress(0, '建立匯出工作…');
                    const exportJob = await api('/ys-cart-wc-import/v1/export-jobs', { method: 'POST', data: { entity, options: {} } });
                    const doneExport = await this.runJobToEnd(Number(exportJob.id), `匯出${entityLabels[entity]}`);
                    if (String(doneExport.status) !== 'completed') {
                        throw new Error(`匯出未完成（${statusLabels[doneExport.status] || doneExport.status}）`);
                    }
                    this.log(`匯出完成：成功 ${Number(doneExport.success_count || 0)} 筆`);
                    if (step.kind === 'export') {
                        const downloadUrl = `${window.ysCwciAdmin.restUrl}/jobs/${Number(doneExport.id)}/download?_wpnonce=${encodeURIComponent(window.ysCwciAdmin.nonce)}`;
                        this.results[entity] = { success: Number(doneExport.success_count || 0), errors: Number(doneExport.error_count || 0), downloadUrl };
                        this.log('套件已就緒，可於完成頁下載。');
                    } else {
                        this.progress(0, '建立匯入工作…');
                        const importJob = await api('/ys-cart-wc-import/v1/import-jobs', {
                            method: 'POST',
                            data: { entity, options: { file_path: String(doneExport.file_path || ''), source_fingerprint: String(window.ysCwciAdmin.siteFingerprint || '') } }
                        });
                        const doneImport = await this.runJobToEnd(Number(importJob.id), `匯入${entityLabels[entity]}`);
                        if (String(doneImport.status) !== 'completed') {
                            throw new Error(`匯入未完成（${statusLabels[doneImport.status] || doneImport.status}）`);
                        }
                        this.results[entity] = { success: Number(doneImport.success_count || 0), errors: Number(doneImport.error_count || 0) };
                        this.log(`匯入完成：成功 ${Number(doneImport.success_count || 0)} 筆、錯誤 ${Number(doneImport.error_count || 0)} 筆`);
                    }
                } else if (step.kind === 'import') {
                    const fileInput = this.panel.querySelector('[data-wz-file]');
                    if (!fileInput || !fileInput.files || !fileInput.files.length) {
                        throw new Error('請先選擇套件 ZIP 檔。');
                    }
                    this.progress(0, '上傳套件…');
                    const formData = new FormData();
                    formData.append('package', fileInput.files[0]);
                    formData.append('entity', entity);
                    const uploadResponse = await fetch(`${window.ysCwciAdmin.restUrl}/packages/upload`, {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'X-WP-Nonce': window.ysCwciAdmin.nonce }, body: formData
                    });
                    const packageInfo = await uploadResponse.json();
                    if (packageInfo.code) { throw new Error(packageInfo.message || '上傳失敗。'); }
                    this.log(`套件已上傳（來源：${packageInfo.manifest?.source?.site_url || '來源站'}）`);
                    const importJob = await api('/ys-cart-wc-import/v1/import-jobs', {
                        method: 'POST',
                        data: { entity, options: { file_path: packageInfo.file_path, source_fingerprint: packageInfo.manifest.source.site_url_hash } }
                    });
                    const doneImport = await this.runJobToEnd(Number(importJob.id), `匯入${entityLabels[entity]}`);
                    if (String(doneImport.status) !== 'completed') {
                        throw new Error(`匯入未完成（${statusLabels[doneImport.status] || doneImport.status}）`);
                    }
                    this.results[entity] = { success: Number(doneImport.success_count || 0), errors: Number(doneImport.error_count || 0) };
                    this.log(`匯入完成：成功 ${Number(doneImport.success_count || 0)} 筆、錯誤 ${Number(doneImport.error_count || 0)} 筆`);
                }
                this.running = false;
                await loadJobs();
                this.next();
            } catch (error) {
                this.running = false;
                setBusy(button, false);
                this.log(`發生錯誤：${error.message || error}`);
                this.progress(0, '已停止，可修正後重試或略過此步。');
                this.renderActions(step);
            }
        },

        next() { if (this.index < this.steps.length - 1) { this.index++; this.render(); } },
        prev() { if (this.index > 0 && !this.running) { this.index--; this.render(); } },
        skip() {
            const step = this.steps[this.index];
            if (step.entity) { this.results[step.entity] = { skipped: true }; }
            this.next();
        }
    } : null;

    if (wizardRoot && wizard) {
        wizardRoot.addEventListener('click', async (event) => {
            const btn = event.target.closest('[data-wz]');
            if (!btn) { return; }
            const action = btn.getAttribute('data-wz');
            const step = wizard.steps[wizard.index];
            if (action === 'next') { wizard.next(); }
            if (action === 'prev') { wizard.prev(); }
            if (action === 'skip') { wizard.skip(); }
            if (action === 'restart') { wizard.results = {}; wizard.index = 0; wizard.render(); }
            if (action === 'to-manual') { applyMode('manual'); }
            if (action === 'backup') {
                setBusy(btn, true);
                try {
                    const result = await api('/ys-cart-wc-import/v1/backups', { method: 'POST' });
                    if (result.error) { throw new Error(result.message || '建立備份失敗。'); }
                    wizard.log(`備份已建立：${result.backup?.file || ''}`);
                    await loadBackups();
                    wizard.next();
                } catch (error) {
                    wizard.log(`備份失敗：${error.message || error}`);
                    setBusy(btn, false);
                }
            }
            if (action === 'run-entity') {
                wizard.runEntityStep(step, btn);
            }
        });
    }

    let storedMode = 'wizard';
    try { storedMode = window.localStorage.getItem(MODE_KEY) || 'wizard'; } catch (e) { /* 預設精靈 */ }
    applyMode(storedMode);

    Promise.all([loadCapabilities(), loadJobs(), loadBackups()]).then(() => {
        // 精靈需要 capabilities 決定流程 — 再抓一次（renderCapabilities 沒回傳資料）
        return api('/ys-cart-wc-import/v1/capabilities');
    }).then((caps) => {
        if (wizard) { wizard.boot(caps); }
    }).catch(() => {
        if (wizard && wizard.panel) {
            wizard.panel.innerHTML = '<div class="ys-cwci-alert is-error">無法讀取環境狀態，請改用手動模式。</div>';
        }
    });

    window.setInterval(() => {
        if (!document.hidden && !autoRunningJobId) {
            loadJobs();
        }
    }, 15000);
})();
