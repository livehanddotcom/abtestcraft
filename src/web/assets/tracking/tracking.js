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
     * Send conversion to server
     */
    function sendConversion(goalType, goalId) {
        // Prevent duplicate conversions for the same goal type
        var key = goalType + '-' + (goalId || 'default');
        if (firedGoals[key]) return;
        firedGoals[key] = true;

        var data = new FormData();
        data.append('testHandle', testHandle);
        data.append('conversionType', goalType);
        if (goalId) {
            data.append('goalId', goalId);
        }
        data.append(config.csrfTokenName || 'CRAFT_CSRF_TOKEN', config.csrfToken || '');

        // Use sendBeacon for reliability (fires even on page unload)
        if (navigator.sendBeacon) {
            navigator.sendBeacon(trackingEndpoint, data);
        } else {
            // Fallback to XMLHttpRequest
            var xhr = new XMLHttpRequest();
            xhr.open('POST', trackingEndpoint, true);
            xhr.send(data);
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

        // Get array of forms to track (empty = track all forms)
        var formsToTrack = goalConfig.parsedForms || [];

        /**
         * Check if a form matches the selected forms list
         * @param {string} plugin - Form plugin ('freeform' or 'formie')
         * @param {string} handle - Form handle
         * @returns {boolean} - True if form should be tracked
         */
        function shouldTrackForm(plugin, handle) {
            // If no forms selected, track all forms
            if (formsToTrack.length === 0) {
                return true;
            }

            // Check if this specific form is in the selected list
            return formsToTrack.some(function(f) {
                return f.plugin === plugin && f.handle === handle;
            });
        }

        // Freeform tracking using native event
        document.addEventListener('freeform-ajax-success', function(event) {
            var form = event.target;
            var formHandle = form.getAttribute('data-freeform-form');

            if (!shouldTrackForm('freeform', formHandle)) {
                return;
            }

            sendConversion('form', formGoal.id);
        });

        // Formie tracking using native event
        document.addEventListener('onAfterFormieSubmit', function(event) {
            // Skip multi-page form intermediate pages
            if (event.detail && event.detail.nextPageId) return;

            var form = event.target;
            var formHandle = form.getAttribute('data-fui-form');

            if (!shouldTrackForm('formie', formHandle)) {
                return;
            }

            sendConversion('form', formGoal.id);
        });
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
                var form = event.target;
                if (!matchesSelector(form, formSelector)) return;
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

        // Add form submit listener
        document.addEventListener('submit', handleFormSubmit, true);

        // Setup smart form tracking (for 'smart' mode)
        setupSmartFormTracking();

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
