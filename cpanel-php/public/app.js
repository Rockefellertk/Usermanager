document.querySelectorAll('[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        if (!window.confirm(form.getAttribute('data-confirm'))) event.preventDefault();
    });
});

document.querySelectorAll('[data-dismiss]').forEach(function (button) {
    button.addEventListener('click', function () { button.closest('.alert').remove(); });
});

document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
    button.addEventListener('click', function () {
        var input = document.getElementById(button.getAttribute('data-password-toggle'));
        if (input) input.type = input.type === 'password' ? 'text' : 'password';
    });
});

var toggle = document.querySelector('[data-menu-toggle]');
var sidebar = document.querySelector('[data-sidebar]');
var backdrop = document.querySelector('[data-sidebar-backdrop]');
function closeMenu() { document.body.classList.remove('menu-open'); }
if (toggle) toggle.addEventListener('click', function () { document.body.classList.toggle('menu-open'); });
if (backdrop) backdrop.addEventListener('click', closeMenu);

document.querySelectorAll('[data-auto-filter]').forEach(function (form) {
    var timer;
    var search = form.querySelector('input[name="q"]');
    form.querySelectorAll('select').forEach(function (select) {
        select.addEventListener('change', function () { form.submit(); });
    });
    if (search) search.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { form.submit(); }, 350);
    });
});

(function filterPlansByRouter() {
    var routerSelect = document.querySelector('[data-router-select]');
    var planSelect = document.querySelector('[data-plan-select]');
    if (!routerSelect || !planSelect) return;
    function updatePlans() {
        var routerId = String(routerSelect.value || '');
        var selectedStillValid = false;
        Array.from(planSelect.options).forEach(function (option, index) {
            if (index === 0) return;
            var matches = routerId !== '' && option.dataset.routerId === routerId;
            option.hidden = !matches;
            option.disabled = !matches;
            if (matches && option.selected) selectedStillValid = true;
        });
        if (!selectedStillValid) planSelect.value = '';
        planSelect.disabled = routerId === '';
    }
    routerSelect.addEventListener('change', updatePlans);
    updatePlans();
})();

(function enableLiveSync() {
    var urlMeta = document.querySelector('meta[name="live-sync-url"]');
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (!urlMeta || !csrfMeta) return;
    var routesToRefresh = ['dashboard', 'users', 'routers', 'router-data', 'user-detail'];
    var busy = false;
    async function refresh() {
        if (busy || document.hidden) return;
        busy = true;
        try {
            var body = new URLSearchParams({csrf_token: csrfMeta.content});
            var response = await fetch(urlMeta.content, {method: 'POST', body: body, credentials: 'same-origin'});
            if (response.ok && routesToRefresh.indexOf(document.body.dataset.route) !== -1
                && !['INPUT','SELECT','TEXTAREA'].includes(document.activeElement.tagName)) {
                window.location.reload();
            }
        } catch (error) {
            // Keep the current page usable when a router is temporarily offline.
        } finally {
            busy = false;
        }
    }
    setInterval(refresh, 20000);
})();
