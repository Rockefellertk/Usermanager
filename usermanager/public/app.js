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

