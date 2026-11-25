/*
 * Cookiebot Embeds: Vue mode activator (BR-19).
 *
 * In Vue mode the server marks gated elements with the plugin's own attributes (data-cbe-consent,
 * data-cbe-src, type="text/plain" on scripts) so that Cookiebot does not release them before Vue
 * has rebuilt the page: a script released before Vue mounts loses its listeners in the rebuild.
 *
 * This file releases them instead, once both
 *   (a) the site has called window.CookiebotEmbeds.ready() (from its root Vue instance's
 *       mounted() hook), and
 *   (b) Cookiebot has reported the visitor's consent,
 * and again on every later ready() call and Cookiebot consent event, so an element Vue creates
 * again later (under a v-if) is released then. It also shows and hides the placeholders.
 *
 * A site that never calls ready() never releases anything (TN-16). No dependencies, no build step,
 * ES5 so it runs wherever Cookiebot does. Reads only Cookiebot's own consent object.
 */
(function (window, document) {
    'use strict';

    if (window.CookiebotEmbeds) {
        return;
    }

    var isReady = false;
    var hasConsent = false;

    function cookiebot() {
        return window.Cookiebot || window.CookieConsent || null;
    }

    function consented(category) {
        var cb = cookiebot();
        return !!(cb && cb.consent && cb.consent[category] === true);
    }

    function each(selector, fn) {
        var list = document.querySelectorAll(selector);
        for (var i = 0; i < list.length; i++) {
            fn(list[i]);
        }
    }

    function hide(el) {
        el.style.display = 'none';
    }

    function show(el) {
        el.style.removeProperty('display');
    }

    // A live copy of a gated script: every attribute and the inline body kept, the server's
    // type="text/plain" and the consent marker dropped, so the browser runs it as written. The
    // copy goes in just after the original, which stays where it is, inert and no longer marked:
    // Vue still holds the original node and may insert siblings relative to it, which would throw
    // if it had been taken out of the document.
    function releaseScript(original) {
        var script = document.createElement('script');
        for (var i = 0; i < original.attributes.length; i++) {
            var name = original.attributes[i].name;
            if (name !== 'type' && name !== 'data-cbe-consent') {
                script.setAttribute(name, original.attributes[i].value);
            }
        }
        script.text = original.text;
        original.removeAttribute('data-cbe-consent');
        original.parentNode.insertBefore(script, original.nextSibling);
    }

    function releaseFrame(el) {
        var src = el.getAttribute('data-cbe-src');
        el.removeAttribute('data-cbe-consent');
        el.removeAttribute('data-cbe-src');
        if (src !== null) {
            el.setAttribute('src', src);
        }
        show(el);
    }

    // Nothing is touched before ready(): Vue would rebuild it. Releasing also waits for
    // Cookiebot's report; hiding a waiting embed and showing its placeholder do not, because a
    // visitor who has not answered the banner has consented to nothing.
    function run() {
        if (!isReady) {
            return;
        }

        each('[data-cbe-consent]', function (el) {
            if (!hasConsent || !consented(el.getAttribute('data-cbe-consent'))) {
                // Like Cookiebot: an embed waiting for consent is hidden; its placeholder shows.
                if (el.tagName !== 'SCRIPT') {
                    hide(el);
                }
                return;
            }
            if (el.tagName === 'SCRIPT') {
                releaseScript(el);
            } else {
                releaseFrame(el);
            }
        });

        each('[data-cbe-placeholder]', function (el) {
            if (hasConsent && consented(el.getAttribute('data-cbe-placeholder'))) {
                hide(el);
            } else {
                show(el);
            }
        });
    }

    function onConsent() {
        hasConsent = true;
        run();
    }

    window.addEventListener('CookiebotOnConsentReady', onConsent);
    window.addEventListener('CookiebotOnAccept', onConsent);
    window.addEventListener('CookiebotOnDecline', onConsent);

    window.CookiebotEmbeds = {
        /**
         * Call from the root Vue instance's mounted() hook, and again after Vue creates gated
         * content later if it should be released at once. Idempotent.
         */
        ready: function () {
            isReady = true;
            var cb = cookiebot();
            if (cb && cb.hasResponse) {
                hasConsent = true;
            }
            run();
        }
    };
})(window, document);
