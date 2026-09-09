(function () {
    const root = document.getElementById('ys-cwci-app');
    if (!root || !window.wp || !window.wp.apiFetch || !window.ysCwciAdmin) {
        return;
    }

    window.wp.apiFetch.use(window.wp.apiFetch.createNonceMiddleware(window.ysCwciAdmin.nonce));

    const apiPath = String(window.ysCwciAdmin.apiPath || '').replace(/\/+$/, '');
    const restUrl = String(window.ysCwciAdmin.restUrl || '').replace(/\/+$/, '');
    if (!apiPath || !restUrl) {
        return;
    }

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

    const api = async (path, options = {}) => {
        const result = await window.wp.apiFetch({ path, ...options });
        if (/\/jobs\/\d+\/run-next$/.test(path) && result?.status === 'reconciliation_required') {
            throw new Error('匯入已暫停，請確認上一批結果後再重試。');
        }
        return result;
    };
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

    const loadCapabilities = () => api(`${apiPath}/capabilities`)
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
                ? `<a class="ys-cwci-btn ys-cwci-btn--ghost ys-cwci-btn--sm" href="${escapeHtml(restUrl)}/jobs/${Number(job.id)}/download?_wpnonce=${encodeURIComponent(window.ysCwciAdmin.nonce)}">
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

    const loadJobs = () => api(`${apiPath}/jobs`)
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
            const downloadUrl = `${restUrl}/backups/${encodeURIComponent(file)}/download?_wpnonce=${encodeURIComponent(window.ysCwciAdmin.nonce)}`;
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

    const loadBackups = () => api(`${apiPath}/backups`)
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
            const result = await api(`${apiPath}/backups`, { method: 'POST' });
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
            await api(`${apiPath}/backups/${encodeURIComponent(file)}`, { method: 'DELETE' });
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
            const result = await api(`${apiPath}/backups/${encodeURIComponent(file)}/restore`, {
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

    const getJob = (id) => api(`${apiPath}/jobs/${id}`);

    const runNext = async (id, button) => {
        setBusy(button, true);
        setStatus(`#${id} 執行下一批`);
        try {
            await api(`${apiPath}/jobs/${id}/run-next`, { method: 'POST' });
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
                await api(`${apiPath}/jobs/${id}/run-next`, { method: 'POST' });
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
            const errors = await api(`${apiPath}/jobs/${id}/errors`);
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
            api(`${apiPath}/export-jobs`, {
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

    /* v0.7.0 手動模式：建立匯入工作（含 mode 與訂單狀態選項） */
    const manualCreateImport = (entity, fileInfo, mode, statusSel, button) => {
        const options = {
            file_path: fileInfo.file_path,
            source_fingerprint: fileInfo.fingerprint,
            mode: mode === 'overwrite' ? 'overwrite' : 'skip'
        };
        if (entity === 'orders' && statusSel) {
            if (Array.isArray(statusSel.include) && statusSel.include.length) { options.status_include = statusSel.include; }
            if (statusSel.map && Object.keys(statusSel.map).length) { options.status_map = statusSel.map; }
        }
        setStatus('建立匯入工作');
        return api(`${apiPath}/import-jobs`, { method: 'POST', data: { entity, options } })
            .then((job) => {
                const jobId = Number(job?.id || 0);
                setStatus(`${entityLabels[entity] || entity} 匯入工作已建立，開始自動執行`);
                return loadJobs().then(() => (jobId > 0 ? autoRun(jobId, button) : undefined));
            });
    };

    /* v0.7.0 手動模式：訂單狀態選擇（預設全選；未對應狀態詢問對應） */
    const manualRenderStatusPicker = (data, fileInfo, mode) => {
        if (!uploadResult) { return; }
        const statuses = Array.isArray(data.statuses) ? data.statuses : [];
        const ysStatuses = Array.isArray(data.ys_statuses) ? data.ys_statuses : [];
        const options = ysStatuses.map((s) => `<option value="${escapeHtml(s)}">${escapeHtml(s)}</option>`).join('');
        const rows = statuses.map((row) => {
            const status = String(row.status || '');
            const mapped = row.mapped_to ? String(row.mapped_to) : '';
            const mapCell = mapped
                ? `<span class="ys-cwci-status-pick__map">→ ${escapeHtml(mapped)}</span>`
                : `<span class="ys-cwci-status-pick__map is-unmapped">⚠ 未對應，請選擇：</span>
                   <select data-manual-map="${escapeHtml(status)}">${options}</select>`;
            return `
                <li class="ys-cwci-status-pick__row ${mapped ? '' : 'is-unmapped-row'}">
                    <label>
                        <input type="checkbox" data-manual-st value="${escapeHtml(status)}" checked>
                        <strong>${escapeHtml(status)}</strong>
                        <span class="ys-cwci-status-pick__count">${Number(row.count || 0)} 筆</span>
                    </label>
                    ${mapCell}
                </li>`;
        }).join('');
        uploadResult.hidden = false;
        uploadResult.innerHTML = `
            <div class="ys-cwci-status-pick">
                <strong>選擇要匯入的訂單狀態（預設全選）</strong>
                <ul>${rows}</ul>
                <button type="button" class="ys-cwci-btn ys-cwci-btn--primary" data-ys-cwci-manual-import>確認並開始匯入</button>
            </div>`;
        const confirmButton = uploadResult.querySelector('[data-ys-cwci-manual-import]');
        confirmButton.addEventListener('click', () => {
            const boxes = Array.from(uploadResult.querySelectorAll('[data-manual-st]'));
            const checked = boxes.filter((box) => box.checked).map((box) => String(box.value));
            const include = (checked.length === boxes.length) ? [] : checked; // 全選＝不過濾
            const map = {};
            uploadResult.querySelectorAll('[data-manual-map]').forEach((select) => {
                map[String(select.getAttribute('data-manual-map'))] = String(select.value);
            });
            uploadResult.innerHTML = '<strong>開始匯入…</strong>';
            manualCreateImport('orders', fileInfo, mode, { include, map }, confirmButton)
                .catch((error) => {
                    uploadResult.textContent = error.message || '匯入失敗。';
                    setStatus(error.message || '匯入工作建立失敗');
                });
        }, { once: true });
    };

    if (uploadForm) {
        uploadForm.addEventListener('submit', (event) => {
            event.preventDefault();
            const data = new FormData(uploadForm);
            const entity = data.get('entity');
            const mode = String(data.get('mode') || 'skip'); // v0.7.0 覆蓋/忽略
            data.delete('mode'); // upload 端點不需要
            const submitButton = uploadForm.querySelector('button[type="submit"]');

            setBusy(submitButton, true);
            setStatus('上傳套件並檢查 manifest');
            if (uploadResult) {
                uploadResult.hidden = false;
                uploadResult.textContent = '上傳中...';
            }

            fetch(`${restUrl}/packages/upload`, {
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

                    const fileInfo = {
                        file_path: packageInfo.file_path,
                        fingerprint: packageInfo.manifest.source.site_url_hash
                    };

                    // v0.7.0：訂單先列出狀態供勾選（預設全選），未對應狀態詢問對應後才匯入。
                    if (entity === 'orders') {
                        setStatus('掃描套件內的訂單狀態');
                        return api(`${apiPath}/packages/order-statuses`, {
                            method: 'POST',
                            data: { file_path: fileInfo.file_path }
                        }).then((statusData) => {
                            const list = Array.isArray(statusData.statuses) ? statusData.statuses : [];
                            if (!list.length) {
                                return manualCreateImport(entity, fileInfo, mode, null, submitButton);
                            }
                            manualRenderStatusPicker(statusData, fileInfo, mode);
                            setStatus('請選擇要匯入的訂單狀態');
                            return undefined;
                        });
                    }

                    return manualCreateImport(entity, fileInfo, mode, null, submitButton);
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
       v0.7.0 中斷繼續、全部重試（覆蓋/忽略）、訂單狀態選擇與未對應詢問
       手動模式 = 既有工程化 UI（上方全部程式不動）。
       ───────────────────────────────────────────── */

    const MODE_KEY = 'ys_cwci_mode';
    const STATE_KEY = 'ys_cwci_wizard_state';
    const modeTabs = root.querySelectorAll('[data-ys-cwci-mode]');
    const modePanels = root.querySelectorAll('[data-ys-cwci-mode-panel]');

    const safeParse = (raw) => {
        try { const v = JSON.parse(String(raw || '')); return (v && typeof v === 'object') ? v : null; } catch (e) { return null; }
    };

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
        results: {},        // entity -> { success, errors, skippedCount, downloadUrl, skipped }
        running: false,
        autopilot: false,   // v0.7.0 全部重試自動逐步
        retryMode: 'skip',  // v0.7.0 忽略已匯入(skip) / 覆蓋已匯入(overwrite)
        ordersStatus: null, // v0.7.0 訂單狀態選擇 { include:[], map:{} }
        pendingResume: null,
        _statusResolve: null,

        /* ── v0.7.0 進度持久化（中斷繼續） ── */
        saveState() {
            try {
                window.localStorage.setItem(STATE_KEY, JSON.stringify({
                    v: 1, flow: this.flow, index: this.index, results: this.results,
                    retryMode: this.retryMode, ordersStatus: this.ordersStatus, ts: Date.now()
                }));
            } catch (e) { /* 無 storage 不阻擋 */ }
        },
        clearState() {
            try { window.localStorage.removeItem(STATE_KEY); } catch (e) { /* noop */ }
        },
        loadState() {
            try {
                const obj = safeParse(window.localStorage.getItem(STATE_KEY));
                return (obj && obj.v === 1) ? obj : null;
            } catch (e) { return null; }
        },

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

        /* v0.7.0 開機掃描：localStorage 進度 + 未完成的精靈工作 → 顯示「繼續上次進度」 */
        async bootWithResume(caps) {
            this.boot(caps);
            let saved = this.loadState();
            if (saved && saved.flow !== this.flow) { saved = null; }
            let unfinished = [];
            try {
                const items = await api(`${apiPath}/jobs`);
                unfinished = (Array.isArray(items) ? items : [])
                    .filter((job) => !isTerminal(job.status))
                    .map((job) => ({ ...job, opts: safeParse(job.options_json) || {} }))
                    .filter((job) => job.opts.wizard);
            } catch (e) { /* 讀不到工作不阻擋精靈 */ }
            if (saved || unfinished.length) {
                this.pendingResume = { saved, unfinished };
                this.render();
            }
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

        /* v0.7.0 覆蓋/忽略 radio（direct / import 步驟用） */
        modeRadioHtml(step) {
            const name = `wz-mode-${step.key}`;
            const skipChecked = this.retryMode !== 'overwrite' ? 'checked' : '';
            const overChecked = this.retryMode === 'overwrite' ? 'checked' : '';
            return `
                <fieldset class="ys-cwci-wizard__mode">
                    <legend>已匯入過的資料</legend>
                    <label><input type="radio" name="${name}" data-wz-mode value="skip" ${skipChecked}> 忽略已匯入（不更動既有資料）</label>
                    <label><input type="radio" name="${name}" data-wz-mode value="overwrite" ${overChecked}> 覆蓋已匯入（以套件資料更新）</label>
                </fieldset>`;
        },

        panelMode() {
            const checked = this.panel.querySelector('[data-wz-mode]:checked');
            return checked ? String(checked.value) : 'skip';
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
                    import: '本站只有 YS CART：請準備來源站匯出的 ZIP 套件，精靈會依正確順序引導你逐項上傳匯入。可依序匯入多個不同來源站的套件 — 訂單以「來源站＋來源單號」辨識，不會互相覆蓋。',
                    none: '此網站偵測不到 WooCommerce 或 YS CART，無法使用搬家功能。'
                }[this.flow];
                const resume = this.pendingResume;
                const resumeHtml = resume ? `
                    <div class="ys-cwci-resume" data-wz-resume>
                        <strong><span class="dashicons dashicons-backup" aria-hidden="true"></span> 偵測到上次中斷的搬家進度</strong>
                        <span>${resume.saved ? `上次停在第 ${Number(resume.saved.index || 0) + 1} 步` : ''}${resume.unfinished.length ? `${resume.saved ? '，' : ''}未完成工作：${resume.unfinished.map((job) => `#${Number(job.id)} ${escapeHtml(typeLabels[job.type] || job.type)}${escapeHtml(entityLabels[job.entity] || job.entity)}（已處理 ${Number(job.processed_count || 0)} 筆）`).join('、')}` : ''}</span>
                        <div class="ys-cwci-actions">
                            <button type="button" class="ys-cwci-btn ys-cwci-btn--primary" data-wz="resume">繼續上次進度</button>
                            <button type="button" class="ys-cwci-btn ys-cwci-btn--ghost" data-wz="discard">放棄並重新開始</button>
                        </div>
                    </div>` : '';
                p.innerHTML = `
                    ${resumeHtml}
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
                const modeField = step.kind !== 'export' ? this.modeRadioHtml(step) : '';
                const crossSiteNote = (step.kind === 'import' && step.entity === 'orders')
                    ? '<p class="ys-cwci-wizard__desc">可依序匯入多個不同來源站的訂單套件 — 系統以「來源站＋來源單號」辨識，不同站的同號訂單不會互相覆蓋。</p>'
                    : '';
                const statusNote = (step.entity === 'orders' && step.kind !== 'export')
                    ? '<p class="ys-cwci-wizard__desc">開始後會先列出套件內的訂單狀態供勾選（預設全選）；無法自動對應的狀態會詢問要對應到哪個 YS CART 狀態。</p>'
                    : '';
                p.innerHTML = `
                    <p class="ys-cwci-wizard__desc">第 ${this.index + 1} 步：${verb}「${escapeHtml(entityLabels[step.entity])}」。${step.kind === 'direct' ? '已存在的資料依下方選項處理。' : ''}</p>
                    ${crossSiteNote}
                    ${statusNote}
                    ${uploadField}
                    ${modeField}
                    <button type="button" class="ys-cwci-btn ys-cwci-btn--primary" data-wz="run-entity">
                        <span class="dashicons dashicons-controls-play" aria-hidden="true"></span> 開始${verb}
                    </button>
                    <div class="ys-cwci-wizard__progress" data-wz-progress hidden>
                        <div class="ys-cwci-progress"><span style="width:0%" data-wz-bar></span></div>
                        <p data-wz-progress-text>準備中…</p>
                    </div>
                    <div data-wz-status-wrap></div>
                    <div class="ys-cwci-wizard__log" data-wz-log hidden></div>`;
                return;
            }
            if (step.kind === 'done') {
                const rows = Object.entries(this.results).map(([entity, r]) => `
                    <li>
                        <strong>${escapeHtml(entityLabels[entity] || entity)}</strong>：
                        ${r.skipped ? '已略過' : `成功 ${Number(r.success || 0)} 筆、錯誤 ${Number(r.errors || 0)} 筆${Number(r.skippedCount || 0) > 0 ? `、狀態略過 ${Number(r.skippedCount)} 筆` : ''}`}
                        ${r.downloadUrl ? ` · <a href="${escapeHtml(r.downloadUrl)}">下載套件</a>` : ''}
                        ${Number(r.errors || 0) > 0 ? ' · 詳見手動模式工作紀錄' : ''}
                    </li>`).join('');
                const wooNote = (this.caps && this.caps.woocommerce && this.flow !== 'export')
                    ? '<li class="ys-cwci-guidance__warn"><strong>WooCommerce 仍啟用：</strong>驗證資料無誤後請停用 WooCommerce，再依「搬家指引」調整商品網址前綴。</li>'
                    : '';
                const retryHtml = this.flow !== 'export' ? `
                    <fieldset class="ys-cwci-wizard__mode">
                        <legend>全部重試 — 已匯入過的資料</legend>
                        <label><input type="radio" name="wz-retry-mode" data-wz-retry-mode value="skip" ${this.retryMode !== 'overwrite' ? 'checked' : ''}> 忽略已匯入</label>
                        <label><input type="radio" name="wz-retry-mode" data-wz-retry-mode value="overwrite" ${this.retryMode === 'overwrite' ? 'checked' : ''}> 覆蓋已匯入</label>
                    </fieldset>` : '';
                p.innerHTML = `
                    <p class="ys-cwci-wizard__desc"><strong>${this.flow === 'export' ? '打包完成！' : '搬家完成！'}</strong></p>
                    <ul class="ys-cwci-guidance">${rows || '<li>本次沒有執行任何項目。</li>'}${wooNote}</ul>
                    ${retryHtml}
                    <div class="ys-cwci-actions">
                        ${this.flow !== 'export' ? '<button type="button" class="ys-cwci-btn ys-cwci-btn--primary" data-wz="retry-all">全部重試</button>' : ''}
                        <button type="button" class="ys-cwci-btn ys-cwci-btn--ghost" data-wz="restart">重新開始</button>
                        <button type="button" class="ys-cwci-btn ys-cwci-btn--ghost" data-wz="to-manual">查看工作紀錄（手動模式）</button>
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
                await api(`${apiPath}/jobs/${jobId}/run-next`, { method: 'POST' });
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

        /* ── v0.7.0 訂單狀態選擇（預設全選 + 未對應詢問） ── */
        async ordersStatusPrompt(filePath) {
            if (this.ordersStatus && this.autopilot) {
                this.log('全部重試：沿用上次的訂單狀態選擇。');
                return this.ordersStatus;
            }
            let data;
            try {
                data = await api(`${apiPath}/packages/order-statuses`, { method: 'POST', data: { file_path: filePath } });
            } catch (error) {
                this.log(`狀態掃描失敗（${error.message || error}），將匯入全部狀態。`);
                return { include: [], map: {} };
            }
            const statuses = Array.isArray(data.statuses) ? data.statuses : [];
            if (!statuses.length) {
                return { include: [], map: {} };
            }
            return await new Promise((resolve) => {
                this._statusResolve = (selection) => { this._statusResolve = null; resolve(selection); };
                this.renderStatusUI(statuses, Array.isArray(data.ys_statuses) ? data.ys_statuses : []);
            });
        },

        renderStatusUI(statuses, ysStatuses) {
            const wrap = this.panel.querySelector('[data-wz-status-wrap]');
            if (!wrap) { return; }
            const options = ysStatuses.map((s) => `<option value="${escapeHtml(s)}">${escapeHtml(s)}</option>`).join('');
            const rows = statuses.map((row) => {
                const status = String(row.status || '');
                const mapped = row.mapped_to ? String(row.mapped_to) : '';
                const mapCell = mapped
                    ? `<span class="ys-cwci-status-pick__map">→ ${escapeHtml(mapped)}</span>`
                    : `<span class="ys-cwci-status-pick__map is-unmapped">⚠ 未對應，請選擇：</span>
                       <select data-wz-map="${escapeHtml(status)}">${options}</select>`;
                return `
                    <li class="ys-cwci-status-pick__row ${mapped ? '' : 'is-unmapped-row'}">
                        <label>
                            <input type="checkbox" data-wz-st value="${escapeHtml(status)}" checked>
                            <strong>${escapeHtml(status)}</strong>
                            <span class="ys-cwci-status-pick__count">${Number(row.count || 0)} 筆</span>
                        </label>
                        ${mapCell}
                    </li>`;
            }).join('');
            wrap.innerHTML = `
                <div class="ys-cwci-status-pick">
                    <strong>選擇要匯入的訂單狀態（預設全選）</strong>
                    <ul>${rows}</ul>
                    <button type="button" class="ys-cwci-btn ys-cwci-btn--primary" data-wz="confirm-status">確認並開始匯入</button>
                </div>`;
            this.progress(0, '等待確認訂單狀態選擇…');
        },

        collectStatusUI() {
            const boxes = Array.from(this.panel.querySelectorAll('[data-wz-st]'));
            const checked = boxes.filter((box) => box.checked).map((box) => String(box.value));
            const include = (checked.length === boxes.length) ? [] : checked; // 全選＝不過濾
            const map = {};
            this.panel.querySelectorAll('[data-wz-map]').forEach((select) => {
                map[String(select.getAttribute('data-wz-map'))] = String(select.value);
            });
            const wrap = this.panel.querySelector('[data-wz-status-wrap]');
            if (wrap) { wrap.innerHTML = ''; }
            return { include, map };
        },

        /* ── v0.7.0 匯入工作（direct 的 import 階段 / import 流程共用） ── */
        async createAndRunImport(entity, filePath, fingerprint, mode, statusSel) {
            const options = { file_path: filePath, source_fingerprint: fingerprint, wizard: this.flow, mode };
            if (entity === 'orders' && statusSel) {
                if (Array.isArray(statusSel.include) && statusSel.include.length) { options.status_include = statusSel.include; }
                if (statusSel.map && Object.keys(statusSel.map).length) { options.status_map = statusSel.map; }
            }
            const importJob = await api(`${apiPath}/import-jobs`, { method: 'POST', data: { entity, options } });
            const done = await this.runJobToEnd(Number(importJob.id), `匯入${entityLabels[entity]}`);
            if (String(done.status) !== 'completed') {
                throw new Error(`匯入未完成（${statusLabels[done.status] || done.status}）`);
            }
            const cursor = safeParse(done.cursor_json) || {};
            this.results[entity] = {
                success: Number(done.success_count || 0),
                errors: Number(done.error_count || 0),
                skippedCount: Number(cursor.skipped || 0)
            };
            this.log(`匯入完成：成功 ${Number(done.success_count || 0)} 筆、錯誤 ${Number(done.error_count || 0)} 筆${Number(cursor.skipped || 0) > 0 ? `、狀態略過 ${Number(cursor.skipped)} 筆` : ''}`);
        },

        async directImportPhase(step, filePath, mode) {
            let statusSel = null;
            if (step.entity === 'orders') {
                statusSel = await this.ordersStatusPrompt(filePath);
                this.ordersStatus = statusSel;
            }
            this.progress(0, '建立匯入工作…');
            await this.createAndRunImport(step.entity, filePath, String(window.ysCwciAdmin.siteFingerprint || ''), mode, statusSel);
        },

        async runEntityStep(step, button) {
            if (this.running) { return; }
            this.running = true;
            setBusy(button, true);
            this.renderActions(step);
            const entity = step.entity;
            const mode = this.panelMode();
            try {
                if (step.kind === 'export' || step.kind === 'direct') {
                    this.progress(0, '建立匯出工作…');
                    const exportJob = await api(`${apiPath}/export-jobs`, { method: 'POST', data: { entity, options: { wizard: this.flow } } });
                    const doneExport = await this.runJobToEnd(Number(exportJob.id), `匯出${entityLabels[entity]}`);
                    if (String(doneExport.status) !== 'completed') {
                        throw new Error(`匯出未完成（${statusLabels[doneExport.status] || doneExport.status}）`);
                    }
                    this.log(`匯出完成：成功 ${Number(doneExport.success_count || 0)} 筆`);
                    if (step.kind === 'export') {
                        const downloadUrl = `${restUrl}/jobs/${Number(doneExport.id)}/download?_wpnonce=${encodeURIComponent(window.ysCwciAdmin.nonce)}`;
                        this.results[entity] = { success: Number(doneExport.success_count || 0), errors: Number(doneExport.error_count || 0), downloadUrl };
                        this.log('套件已就緒，可於完成頁下載。');
                    } else {
                        await this.directImportPhase(step, String(doneExport.file_path || ''), mode);
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
                    const uploadResponse = await fetch(`${restUrl}/packages/upload`, {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'X-WP-Nonce': window.ysCwciAdmin.nonce }, body: formData
                    });
                    const packageInfo = await uploadResponse.json();
                    if (packageInfo.code) { throw new Error(packageInfo.message || '上傳失敗。'); }
                    this.log(`套件已上傳（來源：${packageInfo.manifest?.source?.site_url || '來源站'}）`);
                    let statusSel = null;
                    if (entity === 'orders') {
                        statusSel = await this.ordersStatusPrompt(packageInfo.file_path);
                        this.ordersStatus = statusSel;
                    }
                    await this.createAndRunImport(entity, packageInfo.file_path, packageInfo.manifest.source.site_url_hash, mode, statusSel);
                }
                this.running = false;
                this.saveState();
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

        /* ── v0.7.0 中斷繼續 ── */
        async resume() {
            const pending = this.pendingResume || {};
            const saved = pending.saved;
            const unfinished = Array.isArray(pending.unfinished) ? pending.unfinished : [];
            this.pendingResume = null;
            if (saved) {
                this.results = saved.results || {};
                this.retryMode = saved.retryMode === 'overwrite' ? 'overwrite' : 'skip';
                this.ordersStatus = saved.ordersStatus || null;
                this.index = Math.min(Number(saved.index || 0), this.steps.length - 1);
            }
            this.render();
            for (const job of unfinished) {
                const entity = String(job.entity || '');
                const stepIdx = this.steps.findIndex((s) => s.entity === entity);
                if (stepIdx >= 0) { this.index = stepIdx; this.render(); }
                const step = this.steps[this.index];
                this.running = true;
                this.renderActions(step);
                try {
                    const done = await this.runJobToEnd(Number(job.id), `繼續${typeLabels[job.type] || ''}${entityLabels[entity] || entity}`);
                    if (String(done.status) !== 'completed') {
                        throw new Error(`#${Number(job.id)} ${statusLabels[done.status] || done.status}`);
                    }
                    if (job.type === 'export' && this.flow === 'direct') {
                        this.log(`匯出已接續完成：成功 ${Number(done.success_count || 0)} 筆，接著匯入…`);
                        this.running = false; // 狀態選擇需要互動
                        const mode = (job.opts && job.opts.mode === 'overwrite') ? 'overwrite' : this.retryMode;
                        await this.directImportPhase(step, String(done.file_path || ''), mode);
                    } else if (job.type === 'export') {
                        const downloadUrl = `${restUrl}/jobs/${Number(done.id)}/download?_wpnonce=${encodeURIComponent(window.ysCwciAdmin.nonce)}`;
                        this.results[entity] = { success: Number(done.success_count || 0), errors: Number(done.error_count || 0), downloadUrl };
                    } else {
                        const cursor = safeParse(done.cursor_json) || {};
                        this.results[entity] = { success: Number(done.success_count || 0), errors: Number(done.error_count || 0), skippedCount: Number(cursor.skipped || 0) };
                    }
                    this.running = false;
                    this.saveState();
                    this.next();
                } catch (error) {
                    this.running = false;
                    this.log(`繼續失敗：${error.message || error}`);
                    this.renderActions(step);
                    break;
                }
            }
            await loadJobs();
        },

        async discard() {
            const pending = this.pendingResume || {};
            const unfinished = Array.isArray(pending.unfinished) ? pending.unfinished : [];
            for (const job of unfinished) {
                try { await api(`${apiPath}/jobs/${Number(job.id)}/cancel`, { method: 'POST' }); } catch (e) { /* 不阻擋 */ }
            }
            this.pendingResume = null;
            this.clearState();
            this.results = {};
            this.ordersStatus = null;
            this.index = 0;
            this.render();
            await loadJobs();
        },

        /* ── v0.7.0 全部重試（autopilot 逐步自動執行） ── */
        retryAll() {
            const checked = this.panel.querySelector('[data-wz-retry-mode]:checked');
            this.retryMode = checked && checked.value === 'overwrite' ? 'overwrite' : 'skip';
            this.results = {};
            this.autopilot = true;
            const firstEntity = this.steps.findIndex((s) => !!s.entity);
            this.index = firstEntity >= 0 ? firstEntity : 0;
            this.render();
            this.maybeAutopilot();
        },

        maybeAutopilot() {
            if (!this.autopilot || this.running) { return; }
            const step = this.steps[this.index];
            if (!step || !step.entity) {
                if (step && step.kind === 'done') { this.autopilot = false; }
                return;
            }
            if (step.kind === 'import') { this.autopilot = false; return; } // 上傳需人工選檔
            const btn = this.panel.querySelector('[data-wz="run-entity"]');
            if (btn) {
                window.setTimeout(() => { this.runEntityStep(step, btn); }, 250);
            }
        },

        next() {
            if (this.index < this.steps.length - 1) {
                this.index++;
                this.render();
                if (this.steps[this.index].kind === 'done') {
                    this.autopilot = false;
                    this.clearState(); // 完成＝進度清除（重新整理回到乾淨精靈）
                } else {
                    this.saveState();
                }
                this.maybeAutopilot();
            }
        },
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
            if (action === 'restart') { wizard.clearState(); wizard.results = {}; wizard.ordersStatus = null; wizard.autopilot = false; wizard.index = 0; wizard.render(); }
            if (action === 'to-manual') { applyMode('manual'); }
            if (action === 'resume') { wizard.resume(); }
            if (action === 'discard') { wizard.discard(); }
            if (action === 'retry-all') { wizard.retryAll(); }
            if (action === 'confirm-status') {
                const selection = wizard.collectStatusUI();
                wizard.ordersStatus = selection;
                wizard.saveState();
                if (wizard._statusResolve) { wizard._statusResolve(selection); }
            }
            if (action === 'backup') {
                setBusy(btn, true);
                try {
                    const result = await api(`${apiPath}/backups`, { method: 'POST' });
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
        return api(`${apiPath}/capabilities`);
    }).then((caps) => {
        if (wizard) { return wizard.bootWithResume(caps); }
        return undefined;
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
