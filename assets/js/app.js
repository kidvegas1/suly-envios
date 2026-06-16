const App = {
    csrf: '',
    user: null,
    stores: [],
    currentStore: null,
    lang: 'en',

    t(key, vars = {}) {
        const safeVars = vars && typeof vars === 'object' ? vars : {};
        const table = window.SULY_I18N?.[this.lang] || window.SULY_I18N?.en || {};
        let text = table[key] ?? window.SULY_I18N?.en?.[key] ?? key;
        Object.keys(safeVars).forEach((k) => {
            text = text.replace(new RegExp(`\\{${k}\\}`, 'g'), String(safeVars[k]));
        });
        return text;
    },

    initLanguage() {
        const saved = localStorage.getItem('suly_lang');
        this.lang = saved === 'es' ? 'es' : 'en';
        document.documentElement.lang = this.lang;
        this.applyI18n();
        this.initLanguageToggle();
    },

    setLanguage(lang) {
        const next = lang === 'es' ? 'es' : 'en';
        if (this.lang === next) return;
        this.lang = next;
        localStorage.setItem('suly_lang', next);
        document.documentElement.lang = next;
        this.applyI18n();
        this.updateUserMenu();
        window.dispatchEvent(new CustomEvent('language-changed', { detail: { lang: next } }));
        this.toast(next === 'es' ? this.t('lang.switch_to_es') : this.t('lang.switch_to_en'), 'info');
    },

    applyI18n() {
        document.querySelectorAll('[data-i18n]').forEach((el) => {
            const key = el.getAttribute('data-i18n');
            if (!key) return;
            const text = this.t(key);
            el.textContent = text;
        });

        document.querySelectorAll('[data-i18n-placeholder]').forEach((el) => {
            const key = el.getAttribute('data-i18n-placeholder');
            if (key) el.placeholder = this.t(key);
        });

        document.querySelectorAll('[data-i18n-title]').forEach((el) => {
            const key = el.getAttribute('data-i18n-title');
            if (key) el.title = this.t(key);
        });

        document.querySelectorAll('[data-i18n-alt]').forEach((el) => {
            const key = el.getAttribute('data-i18n-alt');
            if (key) el.alt = this.t(key);
        });

        document.querySelectorAll('.sidebar-link').forEach((link) => {
            const href = link.getAttribute('href');
            const key = window.SULY_NAV_I18N?.[href];
            if (!key) return;
            Array.from(link.childNodes).forEach((node) => {
                if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) {
                    node.remove();
                }
            });
            let label = link.querySelector('.nav-label');
            if (!label) {
                label = document.createElement('span');
                label.className = 'nav-label';
                link.appendChild(label);
            }
            label.textContent = this.t(key);
        });

        const page = this.pageRouteKey();
        const pageKeys = window.SULY_PAGE_I18N?.[page];
        if (pageKeys?.title) {
            document.title = this.t(pageKeys.title);
        }
        if (pageKeys?.heading) {
            const h = document.getElementById('page-title');
            if (h) h.textContent = this.t(pageKeys.heading);
        }
        const pageSub = document.getElementById('page-subtitle');
        if (pageSub && pageKeys?.subtitle) pageSub.textContent = this.t(pageKeys.subtitle);

        this.applySidebarBranding();

        const headerH2 = document.querySelector('header h2.text-xl');
        if (headerH2 && pageKeys?.heading && !document.getElementById('page-title')) {
            headerH2.textContent = this.t(pageKeys.heading);
        }

        const langBtn = document.getElementById('lang-toggle');
        if (langBtn) {
            langBtn.textContent = this.lang === 'es' ? 'EN' : 'ES';
            langBtn.title = this.lang === 'es' ? this.t('lang.switch_to_en') : this.t('lang.switch_to_es');
            langBtn.setAttribute('aria-label', this.t('lang.toggle'));
        }
    },

    pageRouteKey() {
        const path = location.pathname.replace(/\/$/, '');
        if (path === '' || path === '/' || path.endsWith('/login') || path === '/login') return 'login';
        return this.currentRouteKey();
    },

    currentRouteKey() {
        const path = window.location.pathname.split('/').pop().replace('.html', '');
        return path === 'index' ? 'dashboard' : path;
    },

    applySidebarBranding() {
        const header = document.querySelector('#sidebar .border-b.border-border');
        if (!header) return;
        const h1 = header.querySelector('h1');
        if (h1) h1.textContent = this.t('app.name');
        const sub = header.querySelector('p.text-xs');
        if (sub) {
            const route = this.currentRouteKey();
            const nav = window.SULY_NAV_I18N || {};
            sub.textContent = (route === '' || route === 'dashboard')
                ? this.t('app.subtitle')
                : this.t(nav[route] || 'app.subtitle');
        }
    },

    initLanguageToggle() {
        if (document.getElementById('lang-toggle')) return;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.id = 'lang-toggle';
        btn.className = 'lang-toggle-btn';
        btn.addEventListener('click', () => this.setLanguage(this.lang === 'es' ? 'en' : 'es'));

        const sidebarFooter = document.querySelector('#sidebar .p-4.border-t');
        if (sidebarFooter) {
            const wrap = document.createElement('div');
            wrap.className = 'px-1 pb-3';
            wrap.innerHTML = `<p class="text-[10px] font-semibold text-text-muted uppercase tracking-wider mb-2 px-2" data-i18n="lang.toggle"></p>`;
            wrap.appendChild(btn);
            sidebarFooter.parentNode.insertBefore(wrap, sidebarFooter);
            this.applyI18n();
            return;
        }

        const loginCard = document.querySelector('#login-form');
        if (loginCard) {
            const wrap = document.createElement('div');
            wrap.className = 'flex justify-center mb-4';
            wrap.appendChild(btn);
            loginCard.parentNode.insertBefore(wrap, loginCard);
            this.applyI18n();
        }
    },

    async init() {
        this.initLanguage();
        window.t = (key, vars) => this.t(key, vars);
        const res = await this.api('GET', '/api/auth');
        if (!res.authenticated) {
            if (!location.pathname.endsWith('/login') && location.pathname !== '/') {
                location.href = 'login';
            }
            return false;
        }
        this.user = res.user;
        this.csrf = res.csrf;
        this.stores = res.stores;
        const sessionStoreId = Number(res.user.store_id) || 0;
        this.currentStore = res.stores.find(s => s.id == sessionStoreId) || res.stores[0];
        if (
            this.user?.role === 'admin'
            && this.currentStore
            && sessionStoreId <= 0
        ) {
            const switched = await this.api('POST', '/api/auth', {
                action: 'switch_store',
                store_id: this.currentStore.id,
            });
            this.user.store_id = switched.store_id ?? this.currentStore.id;
        }
        return true;
    },

    async api(method, url, body = null) {
        const opts = {
            method,
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
        };
        if (this.csrf) {
            opts.headers['X-CSRF-Token'] = this.csrf;
        }
        if (body) {
            opts.body = JSON.stringify(body);
        }
        const res = await fetch(url, opts);
        const data = await res.json();
        if (!res.ok) {
            throw new Error(data.error || `HTTP ${res.status}`);
        }
        return data;
    },

    async apiForm(url, formData) {
        const opts = {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {},
        };
        if (this.csrf) {
            opts.headers['X-CSRF-Token'] = this.csrf;
        }
        const res = await fetch(url, opts);
        let data = {};
        const raw = await res.text();
        if (raw) {
            try {
                data = JSON.parse(raw);
            } catch (e) {
                if (!res.ok) {
                    throw new Error(raw.slice(0, 200) || `HTTP ${res.status}`);
                }
            }
        }
        if (!res.ok) {
            throw new Error(data.error || raw.slice(0, 200) || `HTTP ${res.status}`);
        }
        return data;
    },

    toastKey(key, type = 'info', vars = null) {
        this.toast(this.t(key, vars || {}), type);
    },

    confirmKey(key, vars = null) {
        return confirm(this.t(key, vars || {}));
    },

    toast(message, type = 'info') {
        const container = document.getElementById('toast-container') || this._createToastContainer();
        const toast = document.createElement('div');
        toast.className = 'toast';
        const colors = {
            success: 'border-l-4 border-l-[var(--color-success)]',
            danger: 'border-l-4 border-l-[var(--color-danger)]',
            warning: 'border-l-4 border-l-[var(--color-warning)]',
            info: 'border-l-4 border-l-[var(--color-accent)]',
        };
        toast.classList.add(...(colors[type] || colors.info).split(' '));
        toast.textContent = message;
        container.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('show'));
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3500);
    },

    _createToastContainer() {
        const c = document.createElement('div');
        c.id = 'toast-container';
        c.className = 'toast-container';
        document.body.appendChild(c);
        return c;
    },

    initSidebar() {
        const toggle = document.getElementById('sidebar-toggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        if (toggle && sidebar) {
            toggle.addEventListener('click', () => {
                sidebar.classList.toggle('open');
                overlay?.classList.toggle('open');
            });
            overlay?.addEventListener('click', () => {
                sidebar.classList.remove('open');
                overlay.classList.remove('open');
            });
        }
        const current = location.pathname.split('/').pop() || 'dashboard';
        document.querySelectorAll('.sidebar-link').forEach(link => {
            const href = link.getAttribute('href');
            if (href === current || (current === 'dashboard' && href === 'dashboard')) {
                link.classList.add('active');
            }
        });
        this.applyRoleNav();
    },

    /** Hide admin-only nav entries and redirect if a non-admin opens a restricted URL. */
    applyRoleNav() {
        const role = this.user?.role;
        const page = location.pathname.split('/').pop() || 'dashboard';
        if (['events', 'plates'].includes(page)) {
            location.href = 'dashboard';
            return;
        }
        if (role === 'admin') return;
        const adminOnly = ['stores', 'reports-center', 'analytics', 'import'];
        document.querySelectorAll('.sidebar-link').forEach(link => {
            const href = link.getAttribute('href');
            if (adminOnly.includes(href)) {
                link.style.display = 'none';
            }
        });
        if (adminOnly.includes(page)) {
            location.href = 'dashboard';
        }
    },

    initStoreSelector() {
        const sels = document.querySelectorAll('select#store-selector');
        if (!sels.length) return;
        const isAdmin = this.user?.role === 'admin';
        sels.forEach(sel => {
            sel.innerHTML = this.stores.map(s =>
                `<option value="${s.id}" ${s.id == this.currentStore?.id ? 'selected' : ''}>${s.name}</option>`
            ).join('');
        });
        if (!isAdmin) {
            sels.forEach(sel => {
                sel.disabled = true;
                sel.title = this.t('store.locked');
                sel.classList.add('opacity-70', 'cursor-not-allowed');
            });
            document.querySelectorAll('header select#store-selector').forEach(sel => {
                const wrap = sel.closest('.flex');
                if (wrap) wrap.classList.add('hidden');
            });
            return;
        }
        sels.forEach(sel => {
            sel.addEventListener('change', async (e) => {
                try {
                    await this.api('POST', '/api/auth', { action: 'switch_store', store_id: parseInt(e.target.value) });
                    this.currentStore = this.stores.find(s => s.id == e.target.value);
                    this.toast(this.t('store.switched', { name: this.currentStore.name }), 'success');
                    window.dispatchEvent(new CustomEvent('store-changed'));
                } catch (err) {
                    this.toast(err.message, 'danger');
                }
            });
        });
    },

    async logout() {
        try {
            await this.api('POST', '/api/auth', { action: 'logout' });
        } catch (_) { /* session may already be cleared */ }
        location.href = 'login';
    },

    updateUserMenu() {
        const nameEl = document.getElementById('user-name');
        const roleEl = document.getElementById('user-role');
        const employee = this.user?.employee;
        if (nameEl) {
            nameEl.textContent = employee?.name || this.user?.name || '';
        }
        if (roleEl) {
            const role = this.user?.role || '';
            const roleLabel = role ? this.t(`role.${role}`) : '';
            const details = [roleLabel || role];
            if (employee?.phone) details.push(employee.phone);
            roleEl.textContent = details.join(' · ');
        }

        const logoutBtn = document.getElementById('logout-btn');
        if (logoutBtn) logoutBtn.title = this.t('action.sign_out');
    },

    initUserMenu() {
        this.updateUserMenu();

        const logoutBtn = document.getElementById('logout-btn');
        if (logoutBtn && !logoutBtn.dataset.bound) {
            logoutBtn.dataset.bound = '1';
            logoutBtn.addEventListener('click', () => this.logout());
        }
    },

    openModal(id) {
        document.getElementById(id)?.classList.add('open');
        document.getElementById(id + '-backdrop')?.classList.add('open');
    },

    closeModal(id) {
        document.getElementById(id)?.classList.remove('open');
        document.getElementById(id + '-backdrop')?.classList.remove('open');
    },

    money(val) {
        const locale = this.lang === 'es' ? 'es-US' : 'en-US';
        return new Intl.NumberFormat(locale, { style: 'currency', currency: 'USD' }).format(val || 0);
    },

    formatDate(str) {
        if (!str) return this.t('empty.none');
        const locale = this.lang === 'es' ? 'es-US' : 'en-US';
        return new Date(str).toLocaleDateString(locale, { month: 'short', day: 'numeric', year: 'numeric' });
    },

    formatDateTime(str) {
        if (!str) return this.t('empty.none');
        const locale = this.lang === 'es' ? 'es-US' : 'en-US';
        return new Date(str).toLocaleString(locale, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    },

    debounce(fn, ms = 300) {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), ms);
        };
    },
};
