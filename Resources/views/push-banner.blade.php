<div class="push-banner" id="pushBanner" role="dialog" aria-label="{{ __('browser-push.banner_title') }}">
    <div class="push-banner__card">
        <button class="push-banner__close" id="pushBannerClose" type="button" aria-label="Close">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                <path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
            </svg>
        </button>

        <p class="push-banner__title">{{ __('browser-push.banner_title') }}</p>
        <p class="push-banner__text">{{ __('browser-push.banner_text') }}</p>

        <div class="push-banner__actions">
            <button class="push-banner__btn push-banner__btn--ghost" id="pushBannerDismiss" type="button">
                {{ __('browser-push.not_now') }}
            </button>
            <button class="push-banner__btn push-banner__btn--primary" id="pushBannerEnable" type="button">
                {{ __('browser-push.enable') }}
            </button>
        </div>
    </div>
</div>

<script>
    (function() {
        if (window.__flutePushInit) return;
        window.__flutePushInit = true;

        var DISMISS_KEY = 'flute_push_dismissed';
        var DISMISS_DAYS = 14;
        var I18N = {
            enabled: @json(__('browser-push.enabled')),
            error: @json(__('browser-push.error')),
            denied: @json(__('browser-push.denied')),
            unsupported: @json(__('browser-push.unsupported')),
            enable: @json(__('browser-push.enable'))
        };

        function getCsrfToken() {
            var meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? meta.getAttribute('content') : '';
        }

        function apiFetch(path, method, body) {
            var headers = {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': getCsrfToken()
            };
            var opts = {
                method: method || 'GET',
                headers: headers
            };
            if (body) {
                headers['Content-Type'] = 'application/json';
                opts.body = JSON.stringify(body);
            }
            return fetch(u(path), opts).then(function(resp) {
                var newToken = resp.headers.get('X-CSRF-Token');
                if (newToken) {
                    var meta = document.querySelector('meta[name="csrf-token"]');
                    if (meta) meta.setAttribute('content', newToken);
                    var el = document.body;
                    if (el && el.hasAttribute('hx-headers')) {
                        try {
                            var h = JSON.parse(el.getAttribute('hx-headers'));
                            h['X-CSRF-Token'] = newToken;
                            el.setAttribute('hx-headers', JSON.stringify(h));
                        } catch (e) {}
                    }
                }
                return resp;
            });
        }

        function toast(message, type) {
            if (window.flute && window.flute.notyf) {
                window.flute.notyf.open({
                    type: type,
                    message: message
                });
            }
        }

        function urlBase64ToUint8Array(base64String) {
            var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
            var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            var raw = atob(base64);
            var arr = new Uint8Array(raw.length);
            for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
            return arr;
        }

        function isDismissed() {
            try {
                var ts = localStorage.getItem(DISMISS_KEY);
                if (!ts) return false;
                return (Date.now() - parseInt(ts, 10)) / 86400000 < DISMISS_DAYS;
            } catch (e) {
                return false;
            }
        }

        function setDismissed() {
            try {
                localStorage.setItem(DISMISS_KEY, Date.now().toString());
            } catch (e) {}
        }

        function removeBanner(el) {
            if (!el || !el.parentNode) return;
            el.classList.add('is-leaving');
            var remove = function() {
                if (el && el.parentNode) el.parentNode.removeChild(el);
            };
            el.addEventListener('animationend', remove, {
                once: true
            });
            setTimeout(remove, 350);
        }

        function initBanner() {
            var banner = document.getElementById('pushBanner');
            if (!banner) return;

            if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                banner.parentNode.removeChild(banner);
                return;
            }

            if (Notification.permission === 'granted') {
                banner.parentNode.removeChild(banner);
                syncExistingSubscription();
                return;
            }

            if (Notification.permission === 'denied' || isDismissed()) {
                banner.parentNode.removeChild(banner);
                return;
            }

            var closeBtn = document.getElementById('pushBannerClose');
            var dismissBtn = document.getElementById('pushBannerDismiss');
            var enableBtn = document.getElementById('pushBannerEnable');

            function dismiss() {
                setDismissed();
                removeBanner(banner);
            }

            if (closeBtn) closeBtn.addEventListener('click', dismiss);
            if (dismissBtn) dismissBtn.addEventListener('click', dismiss);
            if (enableBtn) enableBtn.addEventListener('click', function() {
                requestPush(banner, enableBtn);
            });

            requestAnimationFrame(function() {
                banner.classList.add('is-visible');
            });
        }

        async function requestPush(banner, btn) {
            if (btn) {
                btn.disabled = true;
                btn.textContent = '...';
            }

            try {
                var permission = await Notification.requestPermission();
                if (permission === 'granted') {
                    removeBanner(banner);
                    toast(I18N.enabled, 'success');
                    subscribePush().catch(function() {});
                } else if (permission === 'denied') {
                    toast(I18N.denied, 'warning');
                    removeBanner(banner);
                } else {
                    setDismissed();
                    removeBanner(banner);
                }
            } catch (e) {
                toast(I18N.error, 'error');
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = I18N.enable;
                }
            }
        }

        async function subscribePush() {
            var reg = await navigator.serviceWorker.ready;
            var resp = await apiFetch('api/push/vapid-key', 'GET');
            var data = await resp.json();

            if (!data.publicKey) throw new Error('No VAPID key');

            var sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(data.publicKey)
            });

            var subJson = sub.toJSON();
            await apiFetch('api/push/subscribe', 'POST', {
                endpoint: subJson.endpoint,
                keys: subJson.keys
            });
        }

        async function syncExistingSubscription() {
            try {
                var reg = await navigator.serviceWorker.ready;
                var existing = await reg.pushManager.getSubscription();
                if (existing) {
                    var subJson = existing.toJSON();
                    await apiFetch('api/push/subscribe', 'POST', {
                        endpoint: subJson.endpoint,
                        keys: subJson.keys
                    });
                } else {
                    await subscribePush();
                }
            } catch (e) {}
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initBanner);
        } else {
            initBanner();
        }

        document.body.addEventListener('htmx:afterSettle', function(evt) {
            if (evt.detail && evt.detail.target === document.body) {
                initBanner();
            }
        });
    })();
</script>
