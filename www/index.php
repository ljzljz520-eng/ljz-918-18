<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>服务管理面板 (Service Panel)</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
        /* 仅在展开/收起时使用动画；自动刷新不会触发重排 */
        .log-body {
            overflow: hidden;
            transition: max-height 0.25s ease;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .spinner {
            animation: spin 0.8s linear infinite;
        }
    </style>
</head>

<body class="bg-slate-50 min-h-screen text-slate-800">

    <!-- Header -->
    <div class="bg-white border-b border-slate-200">
        <div class="max-w-6xl mx-auto px-6 py-4 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center text-white font-bold">SP
                </div>
                <h1 class="text-xl font-semibold text-slate-900">系统服务管理</h1>
            </div>
            <div class="text-sm text-slate-500">
                当前状态: <span id="api-status" class="text-green-600 font-medium">在线</span>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <main class="max-w-6xl mx-auto px-6 py-10">

        <div class="mb-6 flex flex-wrap justify-between items-end gap-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-900">服务列表</h2>
                <p class="text-slate-500 mt-1">监控与管理服务器核心组件运行状态</p>
                <!-- 最近一次成功更新时间：拉取失败时保留不被覆盖 -->
                <p id="last-updated" class="text-xs text-slate-400 mt-2 h-4">尚未更新</p>
            </div>

            <!-- 自动刷新控制区：刷新频率可配置，可暂停 -->
            <div class="flex items-center gap-3">
                <label class="text-sm text-slate-500 flex items-center gap-2">
                    自动刷新
                    <select id="refresh-interval"
                        class="border border-slate-300 rounded-lg text-sm px-2 py-1.5 bg-white text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="0">暂停</option>
                        <option value="5">5 秒（高频率）</option>
                        <option value="10" selected>10 秒（默认）</option>
                        <option value="30">30 秒</option>
                        <option value="60">1 分钟（低性能机器推荐）</option>
                        <option value="300">5 分钟</option>
                    </select>
                </label>
                <button id="refresh-btn" onclick="fetchServices(true)"
                    class="px-4 py-2 bg-white border border-slate-300 text-slate-700 rounded-lg hover:bg-slate-50 text-sm font-medium transition-colors flex items-center gap-2">
                    <svg id="refresh-icon" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12a9 9 0 1 1-2.64-6.36" />
                        <path d="M21 3v6h-6" />
                    </svg>
                    <span>刷新列表</span>
                </button>
            </div>
        </div>

        <div id="service-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <!-- Loading State -->
            <div class="col-span-full text-center py-20 text-slate-400">
                正在加载服务数据...
            </div>
        </div>

    </main>

    <!-- JS Logic -->
    <script>
        /* =========================================================
         * 自动刷新设计说明
         * 1. 使用 setTimeout 链式调度（而非 setInterval），请求慢时
         *    不会堆积，避免拖慢性能较差的本地机器。
         * 2. 刷新采用“原地更新”：只更新已有卡片的状态文字/按钮，
         *    不销毁重建 DOM，因此展开的详情、滚动位置、正在查看的
         *    日志面板都不会被打断或收起。
         * 3. 后台拉取失败时保留现有列表与“最近一次成功更新时间”，
         *    仅在状态栏给出提示，不清空页面。
         * 4. 刷新间隔可在右上角配置，选择保存在 localStorage；
         *    标签页隐藏时自动暂停，回到前台立即拉取一次。
         * ========================================================= */

        const STORAGE_KEY = 'sp_refresh_interval';
        const LOG_LIMIT = 20;

        // 运行状态
        let refreshTimer = null;        // 下一次自动刷新的定时器
        let inFlight = false;           // 是否有请求进行中（防重入）
        let lastUpdatedAt = null;       // 最近一次【成功】更新时间
        let hasLoadedOnce = false;      // 是否曾成功加载
        const cardMap = new Map();      // serviceId -> 卡片内可变元素引用
        const servicesById = new Map(); // serviceId -> 最新数据（供按钮回调使用）

        const grid = document.getElementById('service-grid');
        const refreshBtn = document.getElementById('refresh-btn');
        const refreshIcon = document.getElementById('refresh-icon');
        const intervalSelect = document.getElementById('refresh-interval');
        const lastUpdatedEl = document.getElementById('last-updated');
        const apiStatusEl = document.getElementById('api-status');

        /* ---------- 工具函数 ---------- */

        function escapeHtml(str) {
            return String(str ?? '').replace(/[&<>"']/g, (ch) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[ch]));
        }

        function formatTime(ts) {
            if (!ts) return '';
            // 兼容 MySQL "YYYY-MM-DD HH:MM:SS" 与 ISO 格式
            const d = new String(ts).includes('T') ? new Date(ts) : new String(ts).replace(' ', 'T');
            const date = new Date(d);
            if (isNaN(date.getTime())) return ts;
            const p = (n) => String(n).padStart(2, '0');
            return `${date.getFullYear()}-${p(date.getMonth() + 1)}-${p(date.getDate())} ` +
                `${p(date.getHours())}:${p(date.getMinutes())}:${p(date.getSeconds())}`;
        }

        function statusMeta(status) {
            if (status === 'running') {
                return {
                    text: '运行中 (Running)',
                    textColor: 'text-green-600',
                    dotColor: 'bg-green-500',
                    badgeBg: 'bg-green-100'
                };
            }
            if (status === 'error') {
                return {
                    text: '异常 (Error)',
                    textColor: 'text-red-600',
                    dotColor: 'bg-red-500',
                    badgeBg: 'bg-red-100'
                };
            }
            return {
                text: '已停止 (Stopped)',
                textColor: 'text-slate-500',
                dotColor: 'bg-slate-400',
                badgeBg: 'bg-slate-100'
            };
        }

        /* ---------- 卡片渲染（静态骨架只构建一次） ---------- */

        function buildCard(service) {
            const meta = statusMeta(service.status);
            const card = document.createElement('div');
            card.className = "bg-white rounded-xl shadow-sm border border-slate-200 p-6 flex flex-col transition-all hover:shadow-md";

            card.innerHTML = `
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <h3 class="svc-name font-semibold text-lg text-slate-900 tracking-tight"></h3>
                        <p class="svc-desc text-sm text-slate-500 mt-1"></p>
                    </div>
                    <div class="svc-badge h-10 w-10 rounded-full ${meta.badgeBg} flex items-center justify-center shrink-0">
                        <div class="svc-dot h-2.5 w-2.5 rounded-full ${meta.dotColor}"></div>
                    </div>
                </div>

                <div class="text-xs text-slate-400 mb-3">
                    服务更新于 <span class="svc-updated"></span>
                </div>

                <div class="mt-auto pt-4 border-t border-slate-100 flex items-center justify-between">
                    <span class="svc-status text-sm font-medium ${meta.textColor} flex items-center gap-2"></span>
                    <button class="svc-toggle px-4 py-2 rounded-lg text-sm font-medium transition-colors"></button>
                </div>

                <div class="mt-4 border-t border-slate-100 pt-3">
                    <button class="svc-logs-toggle text-sm text-blue-600 hover:text-blue-700 font-medium flex items-center gap-1">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                            <line x1="8" y1="13" x2="16" y2="13"/>
                            <line x1="8" y1="17" x2="13" y2="17"/>
                        </svg>
                        <span class="svc-logs-label">查看日志</span>
                    </button>
                    <!-- 日志面板：由用户手动展开，自动刷新永不操作此区域 -->
                    <div class="svc-logs-body log-body hidden mt-3">
                        <div class="bg-slate-900 rounded-lg text-xs font-mono text-slate-200 max-h-56 overflow-y-auto">
                            <div class="svc-logs flex justify-between items-center px-3 py-2 text-slate-400 border-b border-slate-700 sticky top-0 bg-slate-900">
                                <span>最近 ${LOG_LIMIT} 条日志</span>
                                <button class="svc-logs-reload text-blue-400 hover:text-blue-300">刷新日志</button>
                            </div>
                            <div class="svc-logs-list px-3 py-2 space-y-1">点击“刷新日志”加载…</div>
                        </div>
                    </div>
                </div>
            `;

            // 文本内容用 textContent 赋值，防止 XSS
            card.querySelector('.svc-name').textContent = service.name;
            card.querySelector('.svc-desc').textContent = service.description || '';

            const refs = {
                root: card,
                status: card.querySelector('.svc-status'),
                dot: card.querySelector('.svc-dot'),
                badge: card.querySelector('.svc-badge'),
                updated: card.querySelector('.svc-updated'),
                toggleBtn: card.querySelector('.svc-toggle'),
                logsToggle: card.querySelector('.svc-logs-toggle'),
                logsLabel: card.querySelector('.svc-logs-label'),
                logsBody: card.querySelector('.svc-logs-body'),
                logsReload: card.querySelector('.svc-logs-reload'),
                logsList: card.querySelector('.svc-logs-list'),
            };

            refs.toggleBtn.addEventListener('click', () => {
                const current = servicesById.get(service.id);
                if (current) {
                    toggleService(current.id, current.status === 'running' ? 'stopped' : 'running');
                }
            });

            // 展开/收起日志（自动刷新不会触碰展开状态）
            refs.logsToggle.addEventListener('click', () => {
                const opening = refs.logsBody.classList.contains('hidden');
                if (opening) {
                    refs.logsBody.classList.remove('hidden');
                    refs.logsLabel.textContent = '收起日志';
                    loadLogs(service.id, refs);
                } else {
                    refs.logsBody.classList.add('hidden');
                    refs.logsLabel.textContent = '查看日志';
                }
            });

            // 日志只在用户主动点击时拉取，轮询不会覆盖用户正在看的内容
            refs.logsReload.addEventListener('click', () => loadLogs(service.id, refs));

            cardMap.set(service.id, refs);
            return card;
        }

        /* ---------- 原地更新：只改状态相关的小块 DOM ---------- */

        function updateCard(service) {
            const refs = cardMap.get(service.id);
            if (!refs) return;

            const meta = statusMeta(service.status);
            const isRunning = service.status === 'running';

            refs.status.textContent = meta.text;
            refs.status.className = `svc-status text-sm font-medium ${meta.textColor} flex items-center gap-2`;
            refs.dot.className = `svc-dot h-2.5 w-2.5 rounded-full ${meta.dotColor} ${isRunning ? 'animate-pulse' : ''}`;
            refs.badge.className = `svc-badge h-10 w-10 rounded-full ${meta.badgeBg} flex items-center justify-center shrink-0`;
            refs.updated.textContent = formatTime(service.updated_at);

            refs.toggleBtn.disabled = false;
            refs.toggleBtn.textContent = isRunning ? '停止' : '启动';
            refs.toggleBtn.className = isRunning
                ? 'svc-toggle px-4 py-2 bg-red-50 text-red-600 hover:bg-red-100 rounded-lg text-sm font-medium transition-colors'
                : 'svc-toggle px-4 py-2 bg-blue-600 text-white hover:bg-blue-700 shadow-sm rounded-lg text-sm font-medium transition-colors';
        }

        function renderServices(services) {
            const seen = new Set();

            services.forEach((service) => {
                seen.add(service.id);
                servicesById.set(service.id, service);

                const existing = cardMap.get(service.id);
                if (existing) {
                    // 已存在：原地更新，展开的日志面板、滚动位置完全不受影响
                    updateCard(service);
                } else {
                    // 新出现的服务：追加卡片
                    grid.appendChild(buildCard(service));
                    updateCard(service);
                }
            });

            // 移除已不存在的服务卡片（通常不会发生）
            for (const [id, refs] of cardMap) {
                if (!seen.has(id)) {
                    refs.root.remove();
                    cardMap.delete(id);
                    servicesById.delete(id);
                }
            }
        }

        /* ---------- 日志加载（仅手动触发） ---------- */

        async function loadLogs(serviceId, refs) {
            refs.logsList.textContent = '正在加载日志…';
            try {
                const res = await fetch(`api.php?action=logs&service_id=${encodeURIComponent(serviceId)}&limit=${LOG_LIMIT}`);
                const json = await res.json();
                if (json.status !== 'success') throw new Error(json.message || '加载失败');

                if (!json.data.length) {
                    refs.logsList.innerHTML = '<span class="text-slate-500">暂无日志记录</span>';
                    return;
                }

                refs.logsList.innerHTML = json.data.map((log) => {
                    const actionColor = log.action === 'START' ? 'text-green-400'
                        : log.action === 'STOP' ? 'text-red-400' : 'text-yellow-400';
                    return `
                        <div class="leading-relaxed">
                            <span class="text-slate-500">${escapeHtml(formatTime(log.created_at))}</span>
                            <span class="${actionColor} font-semibold">[${escapeHtml(log.action || '-')}]</span>
                            <span>${escapeHtml(log.message)}</span>
                        </div>`;
                }).join('');
            } catch (e) {
                refs.logsList.innerHTML =
                    `<span class="text-red-400">日志加载失败：${escapeHtml(e.message || '网络错误')}（已保留上次内容，可点击“刷新日志”重试）</span>`;
            }
        }

        /* ---------- 启动 / 停止 ---------- */

        async function toggleService(id, targetStatus) {
            const refs = cardMap.get(id);
            if (refs) {
                refs.toggleBtn.disabled = true;
                refs.toggleBtn.textContent = '处理中…';
            }
            try {
                const res = await fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'toggle', id, status: targetStatus })
                });
                const json = await res.json();
                if (json.status === 'success') {
                    // 操作后立即拉取一次（不影响展开的面板），并重置轮询计时
                    fetchServices(false);
                } else {
                    alert('操作失败: ' + json.message);
                    const current = servicesById.get(id);
                    if (current) updateCard(current);
                }
            } catch (e) {
                alert('网络请求失败');
                const current = servicesById.get(id);
                if (current) updateCard(current);
            }
        }

        /* ---------- 状态提示 ---------- */

        function setRefreshing(on) {
            refreshIcon.classList.toggle('spinner', on);
            refreshBtn.disabled = on;
        }

        function showPollingState() {
            apiStatusEl.textContent = '刷新中…';
            apiStatusEl.className = 'text-slate-500 font-medium';
        }

        function showOnline() {
            apiStatusEl.textContent = '在线';
            apiStatusEl.className = 'text-green-600 font-medium';
        }

        function showOffline(msg) {
            apiStatusEl.textContent = msg;
            apiStatusEl.className = 'text-amber-600 font-medium';
        }

        /* ---------- 核心：服务列表拉取 ---------- */

        async function fetchServices(manual = false) {
            // 防重入：上一次请求未结束时不重复发起
            if (inFlight) return;
            inFlight = true;
            setRefreshing(true);
            showPollingState();

            let succeeded = false;
            try {
                const res = await fetch('api.php?action=list', { cache: 'no-store' });
                const json = await res.json();

                if (json.status === 'success') {
                    succeeded = true;

                    // 首次成功前若显示过错误占位，需要清空
                    if (!hasLoadedOnce) {
                        grid.innerHTML = '';
                        hasLoadedOnce = true;
                    }

                    renderServices(json.data);

                    // 只在成功时更新“最近一次状态时间”，失败时保留
                    lastUpdatedAt = formatTime(json.server_time) || formatTime(new Date().toISOString());
                    lastUpdatedEl.textContent = `最近更新: ${lastUpdatedAt}（自动刷新：${intervalLabel()}）`;
                    lastUpdatedEl.className = 'text-xs text-slate-400 mt-2 h-4';
                    showOnline();
                } else {
                    throw new Error(json.message || '接口返回异常');
                }
            } catch (e) {
                if (!hasLoadedOnce) {
                    // 首次加载即失败：显示错误占位与重试按钮（此时列表为空，不影响任何用户操作）
                    grid.innerHTML =
                        `<div class="col-span-full text-center py-20">
                            <div class="text-red-500 mb-3">加载失败：${escapeHtml(e.message || '网络错误')}</div>
                            <button onclick="fetchServices(true)"
                                class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">重试</button>
                        </div>`;
                }
                // 关键：失败时保留 lastUpdatedAt，不清空已有列表
                showOffline('拉取失败，已保留最近状态');
                if (lastUpdatedAt) {
                    lastUpdatedEl.textContent = `拉取失败，已保留最近状态（${lastUpdatedAt}），将按间隔重试…`;
                    lastUpdatedEl.className = 'text-xs text-amber-600 mt-2 h-4';
                }
            } finally {
                inFlight = false;
                setRefreshing(false);
                scheduleNext();
            }
            return succeeded;
        }

        /* ---------- 自动刷新调度（setTimeout 链式，避免请求堆积） ---------- */

        function getIntervalSeconds() {
            const v = parseInt(intervalSelect.value, 10);
            return Number.isFinite(v) && v >= 0 ? v : 10;
        }

        function intervalLabel() {
            const v = getIntervalSeconds();
            if (v === 0) return '已暂停';
            if (v < 60) return `每 ${v} 秒`;
            return `每 ${v / 60} 分钟`;
        }

        function scheduleNext() {
            if (refreshTimer) {
                clearTimeout(refreshTimer);
                refreshTimer = null;
            }
            const seconds = getIntervalSeconds();
            if (seconds <= 0) return; // 暂停轮询，给低性能机器留余地

            // 标签页不可见时不调度，可见性恢复时会立即拉取
            if (document.hidden) return;

            refreshTimer = setTimeout(() => fetchServices(false), seconds * 1000);
        }

        intervalSelect.addEventListener('change', () => {
            // 频率持久化，下次打开页面仍生效
            try {
                localStorage.setItem(STORAGE_KEY, intervalSelect.value);
            } catch (e) { /* 忽略隐私模式等存储异常 */ }
            const v = getIntervalSeconds();
            if (v === 0) {
                if (refreshTimer) clearTimeout(refreshTimer);
                refreshTimer = null;
                lastUpdatedEl.textContent = lastUpdatedAt
                    ? `最近更新: ${lastUpdatedAt}（自动刷新：已暂停）`
                    : '自动刷新已暂停，可点击“刷新列表”手动更新';
            } else {
                // 立即拉取一次并按新频率继续，避免“改了频率但迟迟不刷新”
                fetchServices(false);
            }
        });

        // 切到后台标签页：暂停轮询，节省资源；切回来：立即更新并恢复调度
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                if (refreshTimer) {
                    clearTimeout(refreshTimer);
                    refreshTimer = null;
                }
            } else if (getIntervalSeconds() > 0) {
                fetchServices(false);
            }
        });

        // 恢复用户保存的刷新频率
        (function restoreInterval() {
            let saved = null;
            try {
                saved = localStorage.getItem(STORAGE_KEY);
            } catch (e) { /* ignore */ }
            if (saved !== null) {
                const opt = Array.from(intervalSelect.options).find((o) => o.value === String(saved));
                if (opt) intervalSelect.value = opt.value;
            }
        })();

        // Init
        fetchServices(false);
    </script>
</body>

</html>
