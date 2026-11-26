/**
 * ABTestCraft Tracking Script
 * Multi-goal conversion tracking for phone clicks, email clicks,
 * form submissions, file downloads, page visits, and custom events.
 */
(function() {
    'use strict';

    // Configuration injected by PHP
    var config = window.ABTestCraftConfig || {};
    var testHandle = config.testHandle || '';
    var variant = config.variant || '';
    var trackingEndpoint = config.trackingEndpoint || '/actions/abtestcraft/track/convert';
    var enableDataLayer = config.enableDataLayer || false;

    // Goals configuration (new multi-goal system)
    var goals = config.goals || {};

    // Tracking options (global toggles from settings)
    var trackPhoneClicks = config.trackPhoneClicks !== false;
    var trackEmailClicks = config.trackEmailClicks !== false;
    var trackFormSubmissions = config.trackFormSubmissions !== false;
    var trackFileDownloads = config.trackFileDownloads !== false;

    // Track which goals have already fired (prevent duplicates)
    var firedGoals = {};

    // Track active MutationObservers for cleanup
    var activeObservers = [];

    // Default file extensions
    var defaultExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', 'gz', 'tar'];

    /**
     * Send conversion to server, and push it to the dataLayer if enabled.
     *
     * Pass countOnServer = false for conversions the server records itself:
     * smart-mode (Easy mode) form goals are counted when Freeform or Formie
     * accepts the submission, so the browser only pushes the dataLayer event.
     */
    function sendConversion(goalType, goalId, countOnServer) {
        // Prevent duplicate conversions for the same goal type
        var key = goalType + '-' + (goalId || 'default');
        if (firedGoals[key]) return;
        firedGoals[key] = true;

        if (countOnServer !== false) {
            postConversion(goalType, goalId);
        }

        // Push to dataLayer for GA4 if enabled
        if (enableDataLayer && typeof window.dataLayer !== 'undefined') {
            window.dataLayer.push({
                'event': 'abtestcraft_conversion',
                'abtestcraft_name': testHandle,
                'abtestcraft_variant': variant,
                'conversion_type': goalType
            });
        }
    }

    /**
     * POST a conversion to the tracking endpoint
     */
    function postConversion(goalType, goalId) {
        var data = new FormData();
        data.append('testHandle', testHandle);
        data.append('conversionType', goalType);
        if (goalId) {
            data.append('goalId', goalId);
        }

        // Use sendBeacon for reliability (fires even on page unload)
        if (navigator.sendBeacon) {
            navigator.sendBeacon(trackingEndpoint, data);
        } else {
            // Fallback to XMLHttpRequest
            var xhr = new XMLHttpRequest();
            xhr.open('POST', trackingEndpoint, true);
            xhr.send(data);
        }
    }

    /**
     * Get the form element a form-plugin event refers to.
     * Freeform 5 dispatches its events on `document` and passes the form as
     * `event.form`; Formie (and older Freeform) dispatch on the form itself.
     */
    function getEventForm(event) {
        if (event.form && event.form.nodeType === 1) return event.form;
        if (event.target && event.target.nodeType === 1) return event.target;
        return null;
    }

    /**
     * Get a Freeform form's handle (Freeform 5: data-handle; older: data-freeform-form)
     */
    function getFreeformHandle(form) {
        return form.getAttribute('data-handle') || form.getAttribute('data-freeform-form');
    }

    /**
     * Formie 3 keeps its settings as JSON in data-fui-form
     */
    function getFormie3Config(form) {
        var raw = form.getAttribute('data-fui-form');
        if (!raw || raw.charAt(0) !== '{') return {};
        try {
            return JSON.parse(raw) || {};
        } catch (e) {
            return {};
        }
    }

    /**
     * Get a Formie form's handle (Formie 4: data-formie-handle; Formie 3:
     * data-form-handle, with its data-fui-form JSON as a fallback)
     */
    function getFormieHandle(form) {
        return form.getAttribute('data-formie-handle')
            || form.getAttribute('data-form-handle')
            || getFormie3Config(form).formHandle
            || null;
    }

    function isFormieForm(form) {
        return form.hasAttribute('data-formie') || form.hasAttribute('data-fui-form');
    }

    function isFormieAjax(form) {
        var method = form.getAttribute('data-formie-submit-method') || form.getAttribute('data-form-submit-method');
        if (!method) {
            var config = getFormie3Config(form);
            method = config.submitMethod || (config.settings && config.settings.submitMethod);
        }
        return method === 'ajax';
    }

    /**
     * Whether a page-reload Formie submit is not the final submission: an
     * intermediate page of a multi-page form, or a Back/Save action. Formie 3
     * exposes the pages and current page in its data-fui-form settings.
     */
    function isFormieNonFinalSubmit(form) {
        var action = form.querySelector('input[name="submitAction"]');
        if (action && action.value && action.value !== 'submit') return true;

        // Formie 3: pages and current page are in the data-fui-form settings
        var settings = getFormie3Config(form).settings || {};
        var pages = settings.pages || [];
        if (settings.hasMultiplePages && pages.length && settings.currentPageId != null) {
            var lastPage = pages[pages.length - 1];
            if (lastPage && String(settings.currentPageId) !== String(lastPage.id)) return true;
        }

        // Formie 4: a hidden pageId input holds the current page; each page is
        // a [data-formie-page-id] section
        var pageInput = form.querySelector('input[name="pageId"]');
        var pageSections = form.querySelectorAll('[data-formie-page-id]');
        if (pageInput && pageInput.value && pageSections.length > 1) {
            var lastId = pageSections[pageSections.length - 1].getAttribute('data-formie-page-id');
            if (lastId && pageInput.value !== lastId) return true;
        }

        return false;
    }


    /**
     * Smart mode, Freeform page-reload forms: after a successful submission
     * Freeform reloads the page and renders its success message inside the
     * form (once; a refresh doesn't show it again). Count that on page load.
     * If the form redirects elsewhere on success, that page isn't under test
     * and has no tracking script, same as page-visit goals.
     */
    function checkFreeformPageReloadSuccess() {
        var formGoal = goals.form;
        if (!formGoal || !formGoal.enabled) return;
        if (!trackFormSubmissions) return;

        var goalConfig = formGoal.config || {};
        if ((goalConfig.mode || 'advanced') !== 'smart') return;

        var forms = document.querySelectorAll('form[data-freeform]');
        for (var i = 0; i < forms.length; i++) {
            var form = forms[i];
            if (form.hasAttribute('data-ajax')) continue;
            if (!form.querySelector('.freeform-form-success, .ff-form-success')) continue;
            if (!shouldTrackSmartForm(goalConfig, 'freeform', getFreeformHandle(form))) continue;

            sendConversion('form', formGoal.id, false);
            return;
        }
    }

    /**
     * Whether a smart-mode form goal covers this form (no forms selected = all forms)
     */
    function shouldTrackSmartForm(goalConfig, plugin, handle) {
        var formsToTrack = goalConfig.parsedForms || [];
        if (formsToTrack.length === 0) return true;

        return formsToTrack.some(function(f) {
            return f.plugin === plugin && f.handle === handle;
        });
    }

    /**
     * Smart mode, page-reload forms. Freeform and Formie fire no JavaScript
     * success event when a form submits with a full page load, so count the
     * submit of a selected form. AJAX forms are skipped here and counted on
     * their success event, which confirms the submission was accepted.
     */
    function trackSmartPageReloadSubmit(form, formGoal) {
        var plugin = null;
        var handle = null;

        if (form.hasAttribute('data-freeform')) {
            // Freeform cancels this event and re-submits with form.submit(),
            // and only the server knows if the submission succeeded. Page-reload
            // Freeform forms are counted on the reloaded page instead (see
            // checkFreeformPageReloadSuccess); AJAX ones on freeform-ajax-success.
            return;
        } else if (isFormieForm(form)) {
            if (isFormieAjax(form) || isFormieNonFinalSubmit(form)) return;
            plugin = 'formie';
            handle = getFormieHandle(form);
        } else {
            // Not a Freeform/Formie form (e.g. search or newsletter)
            return;
        }

        if (!shouldTrackSmartForm(formGoal.config || {}, plugin, handle)) return;

        sendConversion('form', formGoal.id, false);
    }

    /**
     * Check if element matches CSS selector
     */
    function matchesSelector(element, selector) {
        if (!selector || !element) return false;

        try {
            // Split multiple selectors and check each
            var selectors = selector.split(',').map(function(s) { return s.trim(); });
            for (var i = 0; i < selectors.length; i++) {
                if (element.matches(selectors[i])) return true;
                // Also check if element is inside a matching parent
                if (element.closest(selectors[i])) return true;
            }
        } catch (e) {
            console.warn('Split Test: Invalid selector', selector);
        }

        return false;
    }

    /**
     * Check if element is visible
     */
    function isVisible(el) {
        return el && el.offsetParent !== null &&
               getComputedStyle(el).display !== 'none' &&
               getComputedStyle(el).visibility !== 'hidden';
    }

    /**
     * Check if URL matches goal path
     */
    function urlMatchesPath(url, goalPath, matchType) {
        if (!goalPath) return false;

        // Normalize URLs
        var currentPath = (url || window.location.pathname).toLowerCase();
        var goal = goalPath.toLowerCase();

        // Remove query strings and hashes
        currentPath = currentPath.split('?')[0].split('#')[0];
        goal = goal.split('?')[0].split('#')[0];

        // Remove protocol and domain if present
        currentPath = currentPath.replace(/^https?:\/\/[^\/]+/, '');
        goal = goal.replace(/^https?:\/\/[^\/]+/, '');

        switch (matchType) {
            case 'startsWith':
                return currentPath.indexOf(goal) === 0;
            case 'contains':
                return currentPath.indexOf(goal) !== -1;
            case 'exact':
            default:
                return currentPath === goal;
        }
    }

    /**
     * Get file extension from URL
     */
    function getFileExtension(href) {
        if (!href) return null;
        var path = href.split('?')[0].split('#')[0];
        var parts = path.split('.');
        if (parts.length > 1) {
            return parts.pop().toLowerCase();
        }
        return null;
    }

    /**
     * Handle phone click goal
     */
    function handlePhoneClick(href) {
        var phoneGoal = goals.phone;
        if (!phoneGoal || !phoneGoal.enabled) return false;
        if (!trackPhoneClicks) return false;

        if (href.indexOf('tel:') === 0) {
            sendConversion('phone', phoneGoal.id);
            return true;
        }
        return false;
    }

    /**
     * Handle email click goal
     */
    function handleEmailClick(href) {
        var emailGoal = goals.email;
        if (!emailGoal || !emailGoal.enabled) return false;
        if (!trackEmailClicks) return false;

        if (href.indexOf('mailto:') === 0) {
            sendConversion('email', emailGoal.id);
            return true;
        }
        return false;
    }

    /**
     * Handle download goal
     */
    function handleDownloadClick(href) {
        var downloadGoal = goals.download;
        if (!downloadGoal || !downloadGoal.enabled) return false;
        if (!trackFileDownloads) return false;

        var ext = getFileExtension(href);
        if (!ext) return false;

        var extensions = (downloadGoal.config && downloadGoal.config.extensions) || defaultExtensions;
        if (Array.isArray(extensions) && extensions.indexOf(ext) !== -1) {
            sendConversion('download', downloadGoal.id);
            return true;
        }
        return false;
    }

    /**
     * Handle page visit goal (on link click)
     */
    function handlePageLinkClick(href) {
        var pageGoal = goals.page;
        if (!pageGoal || !pageGoal.enabled) return false;

        var goalConfig = pageGoal.config || {};
        var pageUrl = goalConfig.pageUrl;
        var matchType = goalConfig.matchType || 'exact';

        if (urlMatchesPath(href, pageUrl, matchType)) {
            sendConversion('page', pageGoal.id);
            return true;
        }
        return false;
    }

    /**
     * Handle click events
     */
    function handleClick(event) {
        var target = event.target;

        // Walk up the DOM to find the link
        while (target && target.tagName !== 'A') {
            target = target.parentElement;
        }

        if (!target || target.tagName !== 'A') return;

        var href = target.getAttribute('href');
        if (!href) return;

        // Check each goal type
        handlePhoneClick(href);
        handleEmailClick(href);
        handleDownloadClick(href);
        handlePageLinkClick(href);
    }

    /**
     * Handle form submission with smart success detection
     */
    function handleFormSubmit(event) {
        var formGoal = goals.form;
        if (!formGoal || !formGoal.enabled) return;
        if (!trackFormSubmissions) return;

        var form = event.target;
        var goalConfig = formGoal.config || {};

        // Smart mode is handled by handleSmartSubmit (page-reload forms) and
        // the form plugins' success events (AJAX forms)
        if ((goalConfig.mode || 'advanced') === 'smart') return;

        var formSelector = goalConfig.formSelector;
        var successMethod = goalConfig.successMethod || 'any';
        var successSelector = goalConfig.successSelector;

        // Check if this form matches the configured selector
        if (formSelector && !matchesSelector(form, formSelector)) {
            return;
        }

        // Handle based on success detection method
        if (successMethod === 'any') {
            // Track immediately (not recommended)
            sendConversion('form', formGoal.id);
        } else if (successMethod === 'redirect') {
            // Track immediately - the redirect to thank you page confirms success
            sendConversion('form', formGoal.id);
        } else if (successMethod === 'element') {
            // Wait for success element to appear (AJAX forms)
            observeSuccessElement(form, successSelector, function() {
                sendConversion('form', formGoal.id);
            });
        }
    }

    /**
     * Smart mode, page-reload forms. Registered on window in the bubble phase
     * so it runs after the form plugin's own submit handler: if that handler
     * cancelled the submit (e.g. client-side validation failed), nothing is
     * counted.
     */
    function handleSmartSubmit(event) {
        var formGoal = goals.form;
        if (!formGoal || !formGoal.enabled) return;
        if (!trackFormSubmissions) return;
        if (((formGoal.config || {}).mode || 'advanced') !== 'smart') return;
        if (event.defaultPrevented) return;
        if (!event.target || event.target.nodeType !== 1) return;

        trackSmartPageReloadSubmit(event.target, formGoal);
    }

    /**
     * Observe DOM for success element appearance (for AJAX forms)
     */
    function observeSuccessElement(form, selector, callback) {
        if (!selector) {
            callback();
            return;
        }

        // Cleanup any existing observer for this form
        var existingIndex = -1;
        for (var i = 0; i < activeObservers.length; i++) {
            if (activeObservers[i].form === form) {
                existingIndex = i;
                break;
            }
        }
        if (existingIndex !== -1) {
            activeObservers[existingIndex].observer.disconnect();
            clearTimeout(activeObservers[existingIndex].timeout);
            activeObservers.splice(existingIndex, 1);
        }

        var timeout = null;
        var observer = null;

        function checkSuccess() {
            // Check in form first, then document
            var successEl = form.querySelector(selector) || document.querySelector(selector);
            if (successEl && isVisible(successEl)) {
                cleanup();
                callback();
                return true;
            }
            return false;
        }

        function cleanup() {
            if (observer) observer.disconnect();
            if (timeout) clearTimeout(timeout);
            // Remove from active observers array
            for (var i = 0; i < activeObservers.length; i++) {
                if (activeObservers[i].form === form) {
                    activeObservers.splice(i, 1);
                    break;
                }
            }
        }

        // Check immediately
        if (checkSuccess()) return;

        // Observe for DOM changes
        observer = new MutationObserver(function() {
            checkSuccess();
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class', 'style']
        });

        // Track this observer for cleanup
        activeObservers.push({ form: form, observer: observer, timeout: timeout });

        // Timeout after 10 seconds
        timeout = setTimeout(cleanup, 10000);

        // Update the timeout reference in our tracked observer
        for (var i = 0; i < activeObservers.length; i++) {
            if (activeObservers[i].form === form) {
                activeObservers[i].timeout = timeout;
                break;
            }
        }
    }

    /**
     * Check if current page matches page visit goal
     */
    function checkPageVisitGoal() {
        var pageGoal = goals.page;
        if (!pageGoal || !pageGoal.enabled) return;

        var goalConfig = pageGoal.config || {};
        var pageUrl = goalConfig.pageUrl;
        var matchType = goalConfig.matchType || 'exact';

        if (urlMatchesPath(window.location.pathname, pageUrl, matchType)) {
            sendConversion('page', pageGoal.id);
        }
    }

    /**
     * Smart form tracking using native plugin events
     * Supports: Freeform, Formie
     * Handles multiple form selection (parsedForms array)
     */
    function setupSmartFormTracking() {
        var formGoal = goals.form;
        if (!formGoal || !formGoal.enabled) return;
        if (!trackFormSubmissions) return;

        var goalConfig = formGoal.config || {};
        var mode = goalConfig.mode || 'advanced';

        // Only use smart tracking in 'smart' mode
        if (mode !== 'smart') return;

        // Freeform tracking using native event
        document.addEventListener('freeform-ajax-success', function(event) {
            var form = getEventForm(event);
            if (!form) return;
            var formHandle = getFreeformHandle(form);

            if (!shouldTrackSmartForm(goalConfig, 'freeform', formHandle)) {
                return;
            }

            sendConversion('form', formGoal.id, false);
        });

        // Formie. Formie 3 and Formie 4 (with or without its compatibility
        // mode) signal success differently, so listen for each and dedupe via
        // sendConversion (one form conversion per page load). Capture phase so
        // events that don't bubble are still seen.
        //
        // Page-reload forms: Formie cancels the native submit, validates, then
        // re-submits with form.submit(), which fires no submit event. The
        // "submitting now" signal is onFormieSubmit (Formie 3: fires only once
        // valid; Formie 4: fires on every attempt with detail.ok) or Formie 4's
        // formie:submit:after (detail.ok, code PREFLIGHT_COMPLETE).
        function handleFormiePageReloadSubmit(event) {
            var form = getEventForm(event);
            if (!form || !isFormieForm(form) || isFormieAjax(form)) return;

            var detail = event.detail || {};
            if ('ok' in detail && detail.ok !== true) return; // Formie 4: failed attempt

            if (isFormieNonFinalSubmit(form)) return;
            if (!shouldTrackSmartForm(goalConfig, 'formie', getFormieHandle(form))) return;

            sendConversion('form', formGoal.id, false);
        }

        // AJAX forms: the submission result (Formie 3/4 compat:
        // onAfterFormieSubmit; Formie 4: formie:submit:result)
        function handleFormieAjaxResult(event) {
            var form = getEventForm(event);
            if (!form || !isFormieForm(form) || !isFormieAjax(form)) return;

            var detail = event.detail || {};
            var meta = detail.meta || {};
            if ('ok' in detail && detail.ok !== true) return;
            if (detail.code === 'PREFLIGHT_COMPLETE') return; // not the final result
            // Intermediate page of a multi-page form
            if (detail.nextPageId || meta.nextPageId || detail.nextPage || meta.isFinalPage === false) return;
            // Formie 4 shows spam submissions a fake success; don't count them
            if (detail.outcome === 'rejected' || meta.outcome === 'rejected') return;

            if (!shouldTrackSmartForm(goalConfig, 'formie', getFormieHandle(form))) return;

            sendConversion('form', formGoal.id, false);
        }

        document.addEventListener('onFormieSubmit', handleFormiePageReloadSubmit, true);
        document.addEventListener('formie:submit:after', handleFormiePageReloadSubmit, true);
        document.addEventListener('onAfterFormieSubmit', handleFormieAjaxResult, true);
        document.addEventListener('formie:submit:result', handleFormieAjaxResult, true);
    }

    /**
     * Legacy Freeform listener (for advanced mode backward compatibility)
     */
    function setupFreeformListener() {
        var formGoal = goals.form;
        if (!formGoal || !formGoal.enabled) return;

        var goalConfig = formGoal.config || {};
        var mode = goalConfig.mode || 'advanced';

        // In smart mode, use the dedicated smart tracking instead
        if (mode === 'smart') return;

        document.addEventListener('freeform-ajax-success', function(event) {
            var formSelector = goalConfig.formSelector;

            // Check if this form matches (if selector configured)
            if (formSelector) {
                var form = getEventForm(event);
                if (!form || !matchesSelector(form, formSelector)) return;
            }

            sendConversion('form', formGoal.id);
        });
    }

    /**
     * Public API for custom event tracking
     */
    window.ABTestCraft = window.ABTestCraft || {};
    window.ABTestCraft.trackConversion = function(eventName) {
        var customGoal = goals.custom;
        if (!customGoal || !customGoal.enabled) {
            console.warn('Split Test: Custom goal not configured');
            return;
        }

        var goalConfig = customGoal.config || {};
        // Support both single eventName (string) and multiple eventNames (array)
        var eventNames = goalConfig.eventNames || [];
        // Backward compatibility: also check single eventName string
        if (goalConfig.eventName && eventNames.length === 0) {
            eventNames = [goalConfig.eventName];
        }

        if (eventNames.indexOf(eventName) !== -1) {
            sendConversion('custom', customGoal.id);
        }
    };

    /**
     * Initialize tracking
     */
    function init() {
        if (!testHandle) {
            console.warn('Split Test: No test handle configured');
            return;
        }

        // Add click listener for phone, email, download, and page goals
        document.addEventListener('click', handleClick, true);

        // Add form submit listeners (capture for advanced mode; bubble on
        // window for smart mode so cancelled submits can be skipped)
        document.addEventListener('submit', handleFormSubmit, true);
        window.addEventListener('submit', handleSmartSubmit, false);

        // Setup smart form tracking (for 'smart' mode)
        setupSmartFormTracking();
        checkFreeformPageReloadSuccess();

        // Setup legacy Freeform listener (for 'advanced' mode)
        setupFreeformListener();

        // Check if current page is a goal page
        checkPageVisitGoal();

        // Push initial test data to dataLayer
        if (enableDataLayer && typeof window.dataLayer !== 'undefined') {
            window.dataLayer.push({
                'event': 'abtestcraft_impression',
                'abtestcraft_name': testHandle,
                'abtestcraft_variant': variant
            });
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
