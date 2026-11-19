/*
 * ComfinoPay product widget loader.
 *
 * The config block may be full-page-cached, so per-visitor values (track ID, logging token) are fetched from an
 * uncached endpoint and merged in before the CDN script reads it; the widget still loads without them on failure.
 * Reads data-widget-script-url and data-session-url from #comfino-widget-config.
 */
(() => {
    'use strict';

    const configElement = document.getElementById('comfino-widget-config');

    if (!configElement) {
        return;
    }

    const widgetScriptUrl = configElement.getAttribute('data-widget-script-url');
    const sessionUrl = configElement.getAttribute('data-session-url');

    const loadWidget = () => {
        if (!widgetScriptUrl) {
            return;
        }

        const script = document.createElement('script');
        script.src = widgetScriptUrl;
        script.defer = true;

        document.head.appendChild(script);
    };

    if (!sessionUrl) {
        loadWidget();

        return;
    }

    fetch(sessionUrl, {credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}})
        .then((response) => (response.ok ? response.json() : {}))
        .then((values) => {
            const config = JSON.parse(configElement.textContent);

            ['trackId', 'loggingToken'].forEach((key) => {
                if (values && typeof values[key] === 'string') {
                    config[key] = values[key];
                }
            });

            configElement.textContent = JSON.stringify(config);
        })
        .catch(() => {})
        .then(loadWidget);
})();
