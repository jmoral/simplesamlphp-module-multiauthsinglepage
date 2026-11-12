/*
 * reCAPTCHA v3 for the multiauthsinglepage login forms.
 *
 * A form opts in with data-recaptcha-sitekey (and optionally
 * data-recaptcha-action, default "login") and a hidden
 * <input name="g-recaptcha-response">. When it is submitted, the native
 * submission is held back, a fresh token is requested (tokens expire after
 * two minutes, so one is never requested ahead of time), put in the hidden
 * field, and the form is then submitted.
 *
 * The listener sits on window, so it runs after any listener registered on
 * the form itself (e.g. a theme's own validation): if one of those cancels
 * the submission, this script leaves it alone.
 *
 * If reCAPTCHA cannot be loaded or does not answer in time, the form is
 * submitted without a token and the server decides (see RecaptchaVerifier).
 */
"use strict";
(function () {
    const TIMEOUT_MS = 5000;
    const POLL_MS = 100;

    function whenRecaptchaReady(callback, onTimeout) {
        const start = Date.now();
        (function poll() {
            if (window.grecaptcha && typeof window.grecaptcha.ready === 'function') {
                window.grecaptcha.ready(callback);
            } else if (Date.now() - start >= TIMEOUT_MS) {
                onTimeout();
            } else {
                setTimeout(poll, POLL_MS);
            }
        })();
    }

    window.addEventListener('submit', function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.dataset.recaptchaSitekey) {
            return;
        }
        if (event.defaultPrevented) {
            return; // another listener cancelled this submission
        }
        const field = form.querySelector('input[name="g-recaptcha-response"]');
        if (!field) {
            return;
        }

        event.preventDefault();
        if (form.dataset.recaptchaPending === '1') {
            return; // a token is already being requested for this form
        }
        form.dataset.recaptchaPending = '1';

        let sent = false;
        function send(token) {
            if (sent) {
                return;
            }
            sent = true;
            clearTimeout(timer);
            field.value = token || '';
            // Not form.submit(): an input named "submit" would shadow it.
            HTMLFormElement.prototype.submit.call(form);
        }
        const timer = setTimeout(function () { send(''); }, TIMEOUT_MS);

        whenRecaptchaReady(function () {
            try {
                window.grecaptcha
                    .execute(form.dataset.recaptchaSitekey, {action: form.dataset.recaptchaAction || 'login'})
                    .then(send, function () { send(''); });
            } catch (e) {
                send('');
            }
        }, function () { send(''); });
    });

    // Coming back from the bfcache (the "back" button) must not leave a form stuck.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            document.querySelectorAll('form[data-recaptcha-pending]').forEach(function (form) {
                delete form.dataset.recaptchaPending;
            });
        }
    });
})();
