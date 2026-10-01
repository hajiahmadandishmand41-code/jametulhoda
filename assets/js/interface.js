'use strict';

/**
 * theme.js handles initial theme application.
 * interface.js handles interactive theme toggle, mobile navigation drawer, and admin sidebar.
 */

// ─── 1. Theme Toggle ──────────────────────────────────────────────────────────
document.querySelectorAll('[data-theme-toggle]').forEach(button => {
    const update = () => {
        const isDark = document.documentElement.dataset.theme === 'dark';
        button.setAttribute('aria-pressed', String(isDark));
        button.innerHTML = isDark ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon"></i>';
    };
    update();
    button.addEventListener('click', () => {
        const newTheme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = newTheme;
        document.documentElement.setAttribute('data-bs-theme', newTheme);
        try { localStorage.setItem('jhd-theme', newTheme); } catch (_) {}
        update();
    });
});

// ─── 2. Accessible Mobile Navigation Drawer (Spec stage 7) ────────────────────
(function initMobileDrawer() {
    const toggleBtn = document.getElementById('menuToggle');
    const drawer = document.getElementById('siteDrawer');
    const overlay = document.getElementById('drawerOverlay');
    const closeBtn = document.getElementById('drawerClose');

    if (!toggleBtn || !drawer || !overlay) return;

    let previousActiveElement = null;
    let previousBodyOverflow = '';
    let closeTimer = null;

    function openDrawer() {
        if (closeTimer) window.clearTimeout(closeTimer);
        previousActiveElement = document.activeElement;
        previousBodyOverflow = document.body.style.overflow;
        drawer.removeAttribute('inert');
        drawer.setAttribute('aria-hidden', 'false');
        drawer.classList.add('open');
        overlay.hidden = false;
        // Reflow for transition
        void overlay.offsetWidth;
        overlay.classList.add('show');
        toggleBtn.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';

        // Focus close button or first link
        const firstFocusable = drawer.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        if (firstFocusable) {
            firstFocusable.focus();
        }
    }

    function closeDrawer() {
        drawer.setAttribute('aria-hidden', 'true');
        drawer.classList.remove('open');
        overlay.classList.remove('show');
        toggleBtn.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = previousBodyOverflow;

        closeTimer = window.setTimeout(() => {
            if (drawer.classList.contains('open')) return;
            overlay.hidden = true;
            drawer.setAttribute('inert', '');
            closeTimer = null;
        }, 280);

        if (previousActiveElement && typeof previousActiveElement.focus === 'function') {
            previousActiveElement.focus();
        } else if (toggleBtn) {
            toggleBtn.focus();
        }
    }

    // Toggle button click
    toggleBtn.addEventListener('click', () => {
        if (drawer.classList.contains('open')) {
            closeDrawer();
        } else {
            openDrawer();
        }
    });

    // Close button click
    if (closeBtn) {
        closeBtn.addEventListener('click', closeDrawer);
    }

    // Overlay click (click outside)
    overlay.addEventListener('click', closeDrawer);

    // Keyboard dismissal and focus containment for the modal drawer.
    document.addEventListener('keydown', (e) => {
        if (!drawer.classList.contains('open')) return;
        if (e.key === 'Escape') {
            closeDrawer();
            return;
        }
        if (e.key !== 'Tab') return;
        const focusable = [...drawer.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), summary, [tabindex]:not([tabindex="-1"])')]
            .filter(element => element.getClientRects().length > 0);
        if (!focusable.length) {
            e.preventDefault();
            drawer.focus();
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (e.shiftKey && (document.activeElement === first || !drawer.contains(document.activeElement))) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && (document.activeElement === last || !drawer.contains(document.activeElement))) {
            e.preventDefault();
            first.focus();
        }
    });

    // Close drawer when any navigation link inside it is clicked
    drawer.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => {
            // Give time for user to see click, then close
            closeDrawer();
        });
    });

    // Resize cleanup: if screen resized to desktop size, close drawer and reset overflow
    const desktopMedia = window.matchMedia('(min-width: 1200px)');
    desktopMedia.addEventListener('change', (e) => {
        if (e.matches && drawer.classList.contains('open')) {
            closeDrawer();
        }
    });
})();

// ─── 3. Accessible admin navigation drawer ────────────────────────────────────
(function initAdminSidebar() {
    const adminMenu = document.getElementById('adminSidebar');
    const adminToggle = document.getElementById('sidebarToggle');
    const adminClose = document.getElementById('sidebarClose');
    const adminMain = document.getElementById('adminMain');
    const adminOverlay = document.getElementById('adminSidebarOverlay');
    if (!adminMenu || !adminToggle) return;

    const mobileMedia = window.matchMedia('(max-width: 991px)');
    let previousBodyOverflow = '';
    let overlayCloseTimer = null;

    function syncAccessibility(isOpen) {
        adminToggle.setAttribute('aria-expanded', String(isOpen));
        if (mobileMedia.matches) {
            adminMenu.toggleAttribute('inert', !isOpen);
            adminMenu.setAttribute('aria-hidden', String(!isOpen));
            if (adminMain) {
                adminMain.toggleAttribute('inert', isOpen);
                if (isOpen) adminMain.setAttribute('aria-hidden', 'true');
                else adminMain.removeAttribute('aria-hidden');
            }
        } else {
            adminMenu.removeAttribute('inert');
            adminMenu.removeAttribute('aria-hidden');
            if (adminMain) {
                adminMain.removeAttribute('inert');
                adminMain.removeAttribute('aria-hidden');
            }
        }
    }

    function close(restoreFocus = false) {
        const wasOpen = adminMenu.classList.contains('open');
        adminMenu.classList.remove('open');
        if (wasOpen) document.body.style.overflow = previousBodyOverflow;
        syncAccessibility(false);
        if (overlayCloseTimer) window.clearTimeout(overlayCloseTimer);
        if (adminOverlay) {
            adminOverlay.classList.remove('show');
            if (!mobileMedia.matches) {
                adminOverlay.hidden = true;
                overlayCloseTimer = null;
            } else {
                overlayCloseTimer = window.setTimeout(() => {
                    if (!adminMenu.classList.contains('open')) adminOverlay.hidden = true;
                    overlayCloseTimer = null;
                }, 240);
            }
        }
        if (restoreFocus) adminToggle.focus();
    }

    function open() {
        if (!mobileMedia.matches || adminMenu.classList.contains('open')) return;
        if (overlayCloseTimer) window.clearTimeout(overlayCloseTimer);
        previousBodyOverflow = document.body.style.overflow;
        adminMenu.classList.add('open');
        syncAccessibility(true);
        document.body.style.overflow = 'hidden';
        if (adminOverlay) {
            adminOverlay.hidden = false;
            void adminOverlay.offsetWidth;
            adminOverlay.classList.add('show');
        }
        const firstFocusable = adminMenu.querySelector('a[href], summary, button:not([disabled])');
        if (firstFocusable) firstFocusable.focus();
    }

    syncAccessibility(adminMenu.classList.contains('open'));
    adminToggle.addEventListener('click', () => {
        if (adminMenu.classList.contains('open')) close();
        else open();
    });
    if (adminClose) adminClose.addEventListener('click', () => close(true));

    document.addEventListener('keydown', (event) => {
        if (!adminMenu.classList.contains('open') || !mobileMedia.matches) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            close(true);
            return;
        }
        if (event.key !== 'Tab') return;
        const focusable = [...adminMenu.querySelectorAll('a[href], button:not([disabled]), summary, input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
            .filter(element => element.getClientRects().length > 0);
        if (!focusable.length) {
            event.preventDefault();
            adminToggle.focus();
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && (document.activeElement === first || !adminMenu.contains(document.activeElement))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && (document.activeElement === last || !adminMenu.contains(document.activeElement))) {
            event.preventDefault();
            first.focus();
        }
    });

    if (adminOverlay) adminOverlay.addEventListener('click', () => close(true));
    document.addEventListener('click', (event) => {
        if (!mobileMedia.matches || !adminMenu.classList.contains('open')) return;
        if (!adminMenu.contains(event.target) && !adminToggle.contains(event.target) && event.target !== adminOverlay) close();
    });

    mobileMedia.addEventListener('change', (event) => {
        // Closing also restores scroll lock and returns the drawer to the right
        // accessibility state when crossing the desktop breakpoint.
        if (event.matches || adminMenu.classList.contains('open')) close();
        else syncAccessibility(false);
    });
})();

// Show/hide password fields (login, register, profile) — one accessible toggle.
document.querySelectorAll('[data-password-toggle]').forEach(button => {
  button.addEventListener('click', () => {
    const input = document.getElementById(button.dataset.passwordToggle);
    if (!input) return;
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    button.setAttribute('aria-pressed', show ? 'true' : 'false');
    const icon = button.querySelector('i');
    if (icon) icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    input.focus({ preventScroll: true });
  });
});

// ─── 4. Copy-link buttons (share rows) ──────────────────────────────────────
document.querySelectorAll('[data-copy-link]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var url = btn.getAttribute('data-copy-link') || '';
        var done = function () {
            var icon = btn.querySelector('i');
            if (!icon) return;
            var prev = icon.className;
            icon.className = 'bi bi-check2';
            setTimeout(function () { icon.className = prev; }, 1800);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(done).catch(done);
        } else {
            var tmp = document.createElement('textarea');
            tmp.value = url; document.body.appendChild(tmp); tmp.select();
            try { document.execCommand('copy'); } catch (e) { /* noop */ }
            document.body.removeChild(tmp); done();
        }
    });
});
