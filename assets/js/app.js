const App = {
    csrf: '',
    user: null,
    stores: [],
    currentStore: null,

    async init() {
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
        this.currentStore = res.stores.find(s => s.id == res.user.store_id) || res.stores[0];
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
        const data = await res.json();
        if (!res.ok) {
            throw new Error(data.error || `HTTP ${res.status}`);
        }
        return data;
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
    },

    initStoreSelector() {
        const sel = document.getElementById('store-selector');
        if (!sel) return;
        const isAdmin = this.user?.role === 'admin';
        sel.innerHTML = this.stores.map(s =>
            `<option value="${s.id}" ${s.id == this.currentStore?.id ? 'selected' : ''}>${s.name}</option>`
        ).join('');
        if (!isAdmin) {
            sel.disabled = true;
            sel.title = 'Your account is locked to this store';
            sel.classList.add('opacity-70', 'cursor-not-allowed');
            return;
        }
        sel.addEventListener('change', async (e) => {
            try {
                await this.api('POST', '/api/auth', { action: 'switch_store', store_id: parseInt(e.target.value) });
                this.currentStore = this.stores.find(s => s.id == e.target.value);
                this.toast(`Switched to ${this.currentStore.name}`, 'success');
                window.dispatchEvent(new CustomEvent('store-changed'));
            } catch (err) {
                this.toast(err.message, 'danger');
            }
        });
    },

    initUserMenu() {
        const nameEl = document.getElementById('user-name');
        const roleEl = document.getElementById('user-role');
        if (nameEl) nameEl.textContent = this.user?.name || '';
        if (roleEl) roleEl.textContent = this.user?.role || '';

        document.getElementById('logout-btn')?.addEventListener('click', async () => {
            await this.api('POST', '/api/auth', { action: 'logout' });
            location.href = 'login';
        });
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
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(val || 0);
    },

    formatDate(str) {
        if (!str) return '—';
        return new Date(str).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    },

    formatDateTime(str) {
        if (!str) return '—';
        return new Date(str).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    },

    debounce(fn, ms = 300) {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), ms);
        };
    },
};
