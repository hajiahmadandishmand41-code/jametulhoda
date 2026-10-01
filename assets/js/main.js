/**
 * main.js — Jametulhoda content-centered
 * Like/View systems removed completely per spec 17. Only video/audio, UI, toast.
 */
document.addEventListener('DOMContentLoaded', function () {
    var scrollBtn = document.getElementById('scrollTop');
    if (scrollBtn) {
        window.addEventListener('scroll', function () {
            scrollBtn.style.display = window.pageYOffset > 300 ? 'flex' : 'none';
        });
        scrollBtn.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }
    var currentPath = window.location.pathname;
    document.querySelectorAll('.navbar-nav .nav-link').forEach(function (link) {
        if (link.getAttribute('href') && link.getAttribute('href') !== '#') {
            try {
                var linkPath = new URL(link.href).pathname;
                if (linkPath === currentPath) link.classList.add('active');
            } catch (e) {}
        }
    });
    var navbarCollapse = document.querySelector('.navbar-collapse');
    if (navbarCollapse) {
        document.querySelectorAll('.navbar-nav .nav-link:not(.dropdown-toggle)').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth < 992) {
                    var bsCollapse = bootstrap.Collapse.getInstance(navbarCollapse);
                    if (bsCollapse) bsCollapse.hide();
                }
            });
        });
    }
    document.querySelectorAll('.btn-copy-link').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var url = this.dataset.url || window.location.href;
            if (navigator.clipboard) {
                navigator.clipboard.writeText(url).then(function () {
                    showToast('لینک کپی شد!', 'success');
                });
            }
        });
    });
    if ('IntersectionObserver' in window) {
        var lazyImages = document.querySelectorAll('img[loading="lazy"]');
        var imageObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    var img = entry.target;
                    if (img.dataset.src) {
                        img.src = img.dataset.src;
                        img.removeAttribute('data-src');
                    }
                    imageObserver.unobserve(img);
                }
            });
        });
        lazyImages.forEach(function (img) { imageObserver.observe(img); });
    }
    window.showToast = function (message, type) {
        type = type || 'info';
        var colors = { success: '#198754', error: '#dc3545', info: '#0d6efd', warning: '#e6a817' };
        var toast = document.createElement('div');
        toast.textContent = message;
        toast.style.cssText = [
            'position:fixed', 'bottom:28px', 'left:50%', 'transform:translateX(-50%)',
            'background:' + (colors[type] || colors.info), 'color:#fff', 'padding:10px 28px',
            'border-radius:30px', 'font-size:.9rem', 'z-index:99999',
            'box-shadow:0 4px 24px rgba(0,0,0,.25)', 'transition:opacity .35s',
            'font-family:Vazirmatn,sans-serif', 'direction:rtl', 'pointer-events:none'
        ].join(';');
        document.body.appendChild(toast);
        setTimeout(function () {
            toast.style.opacity = '0';
            setTimeout(function () { if (toast.parentNode) toast.remove(); }, 350);
        }, 2600);
    };
    document.querySelectorAll('img').forEach(function (img) {
        img.addEventListener('error', function () {
            if (this.dataset.errorHandled) return;
            this.dataset.errorHandled = '1';
            // Editorial media must remain truthful: an unavailable image is not
            // replaced with a stock illustration. Remove only the failed media
            // element (or its dedicated card-media wrapper) and keep the real
            // text/content visible.
            var wrapper = this.closest('.jhd-card-media, .news-card-img-wrap, .media-thumb');
            if (wrapper && wrapper.querySelectorAll('img').length === 1) wrapper.remove();
            else this.remove();
        });
    });
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!confirm(this.dataset.confirm || 'آیا اطمینان دارید؟')) {
                e.preventDefault();
            }
        });
    });

    // ─── Video Player Modal با Plyr ─────────────────────────
    var activePlyr  = null;
    var activeModal = null;
    function getVideoMime(url) {
        var ext = (url.split('?')[0].split('.').pop() || '').toLowerCase();
        var map = { mp4: 'video/mp4', webm: 'video/webm', ogv: 'video/ogg', ogg: 'video/ogg', mov: 'video/mp4', m4v: 'video/mp4' };
        return map[ext] || 'video/mp4';
    }
    function closeVideoModal() {
        if (activePlyr) { try { activePlyr.destroy(); } catch (ex) {} activePlyr = null; }
        if (activeModal) {
            activeModal.classList.remove('vmodal--open');
            var vid = activeModal.querySelector('video');
            if (vid) { try { vid.pause(); vid.src = ''; } catch (ex) {} }
            setTimeout(function () {
                if (activeModal && activeModal.parentNode) activeModal.remove();
                activeModal = null;
            }, 280);
        }
        document.body.style.overflow = '';
        document.body.classList.remove('video-modal-open');
        document.querySelectorAll('.carousel').forEach(function (c) {
            try { var bsC = bootstrap.Carousel.getInstance(c); if (bsC) bsC.cycle(); } catch (ex) {}
        });
        document.removeEventListener('keydown', _escListener);
    }
    function _escListener(e) { if (e.key === 'Escape') closeVideoModal(); }
    function openVideoModal(videoUrl, posterUrl, carouselEl) {
        if (carouselEl) { try { var bsC = bootstrap.Carousel.getInstance(carouselEl); if (bsC) bsC.pause(); } catch (ex) {} }
        closeVideoModal();
        var modal = document.createElement('div');
        modal.className = 'vmodal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-label', 'پخش ویدیو');
        var mime = getVideoMime(videoUrl);
        var posterAttr = posterUrl ? ' poster="' + posterUrl.replace(/"/g, '&quot;') + '"' : '';
        modal.innerHTML = [
            '<div class="vmodal__backdrop"></div>',
            '<div class="vmodal__wrap">',
            '  <button class="vmodal__close" type="button" aria-label="بستن"><i class="bi bi-x-lg"></i></button>',
            '  <div class="vmodal__player-wrap">',
            '    <video class="vmodal__video" controls playsinline' + posterAttr + '>',
            '      <source src="' + videoUrl.replace(/"/g, '&quot;') + '" type="' + mime + '">مرورگر شما از پخش ویدیو پشتیبانی نمی‌کند.</video>',
            '  </div>','</div>'
        ].join('');
        document.body.appendChild(modal);
        document.body.style.overflow = 'hidden';
        document.body.classList.add('video-modal-open');
        activeModal = modal;
        requestAnimationFrame(function () { requestAnimationFrame(function () { modal.classList.add('vmodal--open'); }); });
        var videoEl = modal.querySelector('video');
        if (typeof Plyr !== 'undefined' && videoEl) {
            try {
                activePlyr = new Plyr(videoEl, {
                    iconUrl: document.querySelector('meta[name=plyr-sprite]')?.content,
                    controls: ['play-large','play','rewind','fast-forward','progress','current-time','duration','mute','volume','settings','fullscreen'],
                    settings: ['speed','quality'],
                    speed: { selected: 1, options: [0.5, 0.75, 1, 1.25, 1.5, 2] },
                    i18n: { play:'پخش', pause:'توقف', mute:'بی‌صدا', unmute:'صدادار', volume:'میزان صدا', fullscreen:'تمام صفحه', exitFullscreen:'خروج از تمام صفحه', settings:'تنظیمات', speed:'سرعت', normal:'عادی', quality:'کیفیت', loop:'تکرار' },
                    autoplay: true, ratio: '16:9'
                });
                activePlyr.on('ready', function () { var p=activePlyr.play(); if(p&&p.catch) p.catch(function(){}); });
            } catch (plyrErr) { var fp=videoEl.play(); if(fp&&fp.catch) fp.catch(function(){}); }
        } else if (videoEl) { var fp=videoEl.play(); if(fp&&fp.catch) fp.catch(function(){}); }
        modal.querySelector('.vmodal__close').addEventListener('click', closeVideoModal);
        modal.querySelector('.vmodal__backdrop').addEventListener('click', closeVideoModal);
        document.addEventListener('keydown', _escListener);
    }
    document.body.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-video]');
        if (!trigger) return;
        var videoUrl = trigger.getAttribute('data-video');
        if (!videoUrl) return;
        e.preventDefault(); e.stopPropagation();
        var posterUrl = trigger.getAttribute('data-poster') || '';
        var carouselEl = trigger.closest('.carousel');
        openVideoModal(videoUrl, posterUrl, carouselEl);
    });
    window.copyPostLink = function (url) {
        if (navigator.clipboard) { navigator.clipboard.writeText(url).then(function(){ showToast('لینک کپی شد!','success'); }); }
        else { prompt('لینک پست:', url); }
    };
});
(function injectVideoModalCSS() {
    var style = document.createElement('style');
    style.textContent = [
        '.vmodal{position:fixed;inset:0;z-index:99900;display:flex;align-items:center;justify-content:center}',
        '.vmodal__backdrop{position:absolute;inset:0;background:rgba(0,0,0,.88);backdrop-filter:blur(6px);opacity:0;transition:opacity .28s ease}',
        '.vmodal--open .vmodal__backdrop{opacity:1}',
        '.vmodal__wrap{position:relative;z-index:1;width:94vw;max-width:960px;transform:scale(.92) translateY(20px);opacity:0;transition:transform .3s cubic-bezier(.25,.8,.25,1),opacity .3s ease}',
        '.vmodal--open .vmodal__wrap{transform:scale(1) translateY(0);opacity:1}',
        '.vmodal__close{position:absolute;top:-44px;left:0;background:rgba(255,255,255,.12);color:#fff;border:none;border-radius:50%;width:38px;height:38px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;cursor:pointer;transition:background .2s;z-index:10}',
        '.vmodal__close:hover{background:rgba(255,255,255,.28)}',
        '.vmodal__player-wrap{border-radius:12px;overflow:hidden;box-shadow:0 24px 80px rgba(0,0,0,.65);background:#000;aspect-ratio:16/9}',
        '.vmodal__video,.vmodal__player-wrap .plyr{width:100%!important;height:100%!important;border-radius:12px}',
        '.plyr{direction:ltr}', '.plyr__controls{direction:ltr}',
        'body.video-modal-open{overflow:hidden!important}',
        '.video-thumb{position:relative;display:block;cursor:pointer;overflow:hidden}',
        '.video-thumb__poster{width:100%;height:100%;object-fit:cover;display:block;transition:transform .35s ease}',
        '.video-thumb:hover .video-thumb__poster{transform:scale(1.04)}',
        '.video-thumb__native{width:100%;height:100%;object-fit:cover;display:block;pointer-events:none}',
        '.video-thumb__placeholder{width:100%;height:100%;min-height:inherit;background:linear-gradient(135deg,#1a2a3a 0%,#0d1b2a 60%,#162232 100%);display:flex;align-items:center;justify-content:center}',
        '.video-thumb__placeholder i{font-size:3.5rem;color:rgba(255,255,255,.18)}',
        '.video-play-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0);transition:background .25s}',
        '.video-thumb:hover .video-play-overlay,.hero-media-wrap:hover .video-play-overlay{background:rgba(0,0,0,.32)}',
        '.play-btn-circle{width:64px;height:64px;border-radius:50%;background:rgba(229,57,53,.9);border:3px solid rgba(255,255,255,.8);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.8rem;padding-right:2px;box-shadow:0 4px 20px rgba(0,0,0,.45);transition:transform .25s,box-shadow .25s,background .25s}',
        '.video-thumb:hover .play-btn-circle,.hero-media-wrap:hover .play-btn-circle{transform:scale(1.12);box-shadow:0 8px 32px rgba(229,57,53,.55);background:rgba(229,57,53,1)}',
        '.play-btn-circle--sm{width:46px;height:46px;font-size:1.25rem}',
        '.video-badge-card{position:absolute;top:10px;right:10px;z-index:3;background:rgba(229,57,53,.9);color:#fff;font-size:.72rem;padding:3px 9px;border-radius:20px;font-weight:600;display:flex;align-items:center;gap:4px;pointer-events:none}',
        '.hero-media-wrap{position:relative;display:block;cursor:pointer;width:100%;overflow:hidden}',
        '.hero-media-wrap video.hero-img{object-fit:cover}',
        '.hero-media-wrap .play-btn-circle{width:84px;height:84px;font-size:2.4rem}',
        '@media(max-width:576px){.hero-media-wrap .play-btn-circle{width:56px;height:56px;font-size:1.6rem}}',
        '.hero-video-badge{position:absolute;top:16px;right:16px;z-index:5;background:rgba(229,57,53,.88);color:#fff;font-size:.78rem;padding:4px 12px;border-radius:20px;font-weight:700;display:flex;align-items:center;gap:6px;pointer-events:none;box-shadow:0 2px 8px rgba(0,0,0,.3)}',
    ].join('');
    document.head.appendChild(style);
})();
