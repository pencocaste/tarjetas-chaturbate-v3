// Modern JavaScript – v3.4.1-full  (solo cambia util addNoCacheParam)
document.addEventListener('DOMContentLoaded', () => {

    const containers   = document.querySelectorAll('.chaturbate-models-container');
    const loadedModels = new Map();   // <id, Set>
    const states       = new Map();   // <id, {button, loading, hasMore, infiniteScroll}>
    let   io           = null;        // IntersectionObserver único

    /* ──────────────── LOG INICIAL ──────────────── */
    console.log('Inicializando plugin de Chaturbate v3.4.1-full');
    console.log('Contenedores encontrados:', containers.length);
    console.log('Ajax config:', chaturbate_ajax);

    /* ──────────────── UTIL anti-cache ──────────────── */
    function addNoCacheParam(url) {
        // Añade ? o & según corresponda
        const sep = url.includes('?') ? '&' : '?';
        return url + sep + '_nocache=' + Math.random().toString(36).slice(2);
    }

    /* ──────────────── FUNCIONES VISUALES ──────────────── */
    function fade(el, cb) {
        el.style.transition = 'opacity .3s';
        el.style.opacity = '0';
        setTimeout(cb, 300);
    }
    function showLoading(btn) {
        if (btn) {
            btn.classList.add('loading');
            btn.disabled = true;
            btn.textContent = 'Loading…';
        }
    }
    function hideLoading(btn) {
        if (btn) {
            btn.classList.remove('loading');
            btn.disabled = false;
            btn.textContent = 'More webcams';
        }
    }

    /* ──────────────── INIT POR CONTENEDOR ──────────────── */
    containers.forEach(cont => {
        const id = cont.id;
        console.log('Inicializando contenedor:', id);

        // modelos precargados
        loadedModels.set(id, new Set(
            [...cont.querySelectorAll('.chaturbate-model-card')].map(c => c.dataset.username)
        ));
        console.log(`Modelos iniciales en ${id}:`, loadedModels.get(id).size);

        // botón
        const btn = document.querySelector('#chaturbate-load-more');
        if (btn) {
            states.set(id, { button: btn, loading: false, hasMore: true, infiniteScroll: false });
            btn.addEventListener('click', e => {
                e.preventDefault();
                console.log('Click More webcams para:', id);
                loadMore(id);
            });
        } else {
            console.warn('No se encontró botón “More webcams” en la página');
        }

        // refresh inmediato + intervalo
        refresh(id);
        const gap = parseInt(chaturbate_ajax.refresh_interval, 10) || 60000;
        setInterval(() => refresh(id), gap);
    });

    /* ──────────────── REFRESH ──────────────── */
    async function refresh(id) {
        const cont = document.getElementById(id);
        if (!cont) return;

        const current = [...cont.querySelectorAll('.chaturbate-model-card')]
            .map(c => c.dataset.username);
        if (current.length === 0) return;

        console.log(`Refresco (${id}) → modelos actuales:`, current.length);

        const fd = new FormData();
        fd.append('action',     'chaturbate_refresh_models');
        fd.append('nonce',      chaturbate_ajax.nonce);
        fd.append('gender',     cont.dataset.gender);
        fd.append('limit',      cont.dataset.limit);
        fd.append('tag',        cont.dataset.tag);
        fd.append('region',     cont.dataset.region);
        fd.append('whitelabel', cont.dataset.whitelabel);
        fd.append('usernames',  JSON.stringify(current));

        try {
            const res = await fetch(addNoCacheParam(chaturbate_ajax.ajaxurl), {
                method: 'POST',
                body  : fd
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const j = await res.json();

            if (j.success) {
                chaturbate_ajax.nonce = j.data.new_nonce;
                applyUpdates(cont, j.data);
            } else {
                console.warn('refresh fail', j);
            }
        } catch (e) {
            console.error('refresh error', e);
        }
    }

    function applyUpdates(cont, data) {
        const id   = cont.id;
        const list = cont.querySelector('.chaturbate-models');
        const set  = loadedModels.get(id);
        if (!list) return;

        console.log(`applyUpdates (${id}) offline:`, data.offline_usernames.length,
                    ' nuevos:', (data.new_models_html || '').length);

        /* offline */
        (Array.isArray(data.offline_usernames) ? data.offline_usernames : [])
            .forEach(u => {
                const card = cont.querySelector(`[data-username="${u}"]`);
                if (card) fade(card, () => { card.remove(); set.delete(u); });
            });

        /* nuevos */
        if (data.new_models_html) {
            const tmp = document.createElement('div');
            tmp.innerHTML = data.new_models_html.trim();
            [...tmp.children].forEach(card => {
                card.style.opacity = '0';
                list.prepend(card);
                set.add(card.dataset.username);
                requestAnimationFrame(() => {
                    card.style.transition = 'opacity .3s';
                    card.style.opacity = '1';
                });
            });
            observePenultimate(cont);
        }
    }

    /* ──────────────── LOAD MORE ──────────────── */
    async function loadMore(id) {
        const cont = document.getElementById(id);
        const st   = states.get(id);
        if (!cont || !st || st.loading || !st.hasMore) return;

        st.loading = true;
        showLoading(st.button);

        const fd = new FormData();
        fd.append('action',     'chaturbate_load_more_models');
        fd.append('nonce',      chaturbate_ajax.nonce);
        fd.append('gender',     cont.dataset.gender);
        fd.append('limit',      cont.dataset.limit);
        fd.append('offset',     cont.dataset.offset);
        fd.append('tag',        cont.dataset.tag);
        fd.append('region',     cont.dataset.region);
        fd.append('whitelabel', cont.dataset.whitelabel);

        try {
            const res = await fetch(addNoCacheParam(chaturbate_ajax.ajaxurl), {
                method: 'POST',
                body  : fd
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const j = await res.json();

            if (j.success) {
                chaturbate_ajax.nonce = j.data.new_nonce;
                appendBatch(cont, j.data);
            } else {
                console.warn('load-more fail', j);
            }
        } catch (e) {
            console.error('load-more error', e);
        } finally {
            st.loading = false;
            hideLoading(st.button);
        }
    }

    function appendBatch(cont, data) {
        const id  = cont.id;
        let list  = cont.querySelector('.chaturbate-models');
        const set = loadedModels.get(id);

        if (!list) {
            list = document.createElement('div');
            list.className = 'chaturbate-models';
            cont.appendChild(list);
        }

        const tmp = document.createElement('div');
        tmp.innerHTML = (data.html || '').trim();
        const nuevas = tmp.children.length;
        console.log(`appendBatch (${id}) → ${nuevas} tarjetas`);

        [...tmp.children].forEach(card => {
            card.style.opacity = '0';
            list.appendChild(card);
            set.add(card.dataset.username);
            requestAnimationFrame(() => {
                card.style.transition = 'opacity .3s';
                card.style.opacity = '1';
            });
        });

        cont.dataset.offset = data.new_offset;

        const st = states.get(id);
        st.hasMore = data.count > 0;
        if (!st.infiniteScroll && st.hasMore) {
            st.infiniteScroll = true;
            initObserver();
        }
        observePenultimate(cont);

        if (!st.hasMore) {
            st.button.disabled = true;
            st.button.classList.add('disabled');
        }
    }

    /* ──────────────── INFINITE SCROLL ──────────────── */
    function initObserver() {
        if (io) return;
        io = new IntersectionObserver(entries => {
            entries.forEach(e => {
                if (e.isIntersecting) {
                    const c  = e.target.closest('.chaturbate-models-container');
                    const st = states.get(c.id);
                    if (st && st.infiniteScroll && !st.loading && st.hasMore) {
                        loadMore(c.id);
                    }
                }
            });
        }, { rootMargin: '150px' });
    }

    function observePenultimate(cont) {
        if (!io) return;
        io.takeRecords();
        [...cont.querySelectorAll('.chaturbate-model-card')].forEach(el => io.unobserve(el));
        const cards = cont.querySelectorAll('.chaturbate-model-card');
        if (cards.length >= 2) io.observe(cards[cards.length - 2]);
    }
});
