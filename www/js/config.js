// Local server configuration
const SERVER_URL = 'https://192.168.11.45:2020';

// Check if running in Capacitor
const isCapacitor = window.Capacitor?.isNativeApp || false;

// Keep inside app instead of opening external browser
function handleLinksInApp() {
    // Intercept all link clicks
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a');
        if (link && link.href) {
            // Only intercept external links (same domain)
            if (link.href.includes(SERVER_URL.replace('http://', '').replace('https://', ''))) {
                e.preventDefault();
                // Load in same WebView
                window.location.href = link.href;
            } else if (link.href.startsWith('http')) {
                e.preventDefault();
                // Open external links in system browser
                if (window.Capacitor?.plugins?.Browser) {
                    window.Capacitor.plugins.Browser.open({ url: link.href });
                } else {
                    window.open(link.href, '_system');
                }
            }
        }
    });

    // Handle form submissions to stay in app
    document.addEventListener('submit', function(e) {
        const form = e.target;
        if (form.action && form.action.includes(SERVER_URL)) {
            // Let normal form submission happen
            console.log('Staying in app for form submission');
        }
    });
}

// Inject this into the loaded page
function injectAppController() {
    // This will be injected into the webview content
    const script = `
        <script>
            // Override window.open to stay in WebView
            const originalOpen = window.open;
            window.open = function(url, name, features) {
                if (url.startsWith('http') && !url.includes('${SERVER_URL.replace('http://', '').replace('https://', '')}')) {
                    // External links - use Capacitor Browser
                    if (window.Capacitor?.plugins?.Browser) {
                        window.Capacitor.plugins.Browser.open({ url: url });
                    } else {
                        originalOpen.call(this, url, name, features);
                    }
                } else {
                    // Internal links - stay in WebView
                    window.location.href = url;
                }
                return null;
            };
            
            // Prevent default browser back button behavior
            window.addEventListener('popstate', function(e) {
                e.preventDefault();
                history.pushState({}, '', window.location.href);
            });
        </script>
    `;
    return script;
}

// Redirect to local server
function redirectToServer() {
    if (isCapacitor) {
        // Use HTTP GET instead of window.location.replace
        setTimeout(() => {
            window.location.href = SERVER_URL + '/index.php?page=dashboard';
        }, 2000);
    } else {
        // Fallback for browser testing
        window.location.replace(SERVER_URL + '/index.php?page=dashboard');
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    if (isCapacitor) {
        console.log('Running in Capacitor app');
        handleLinksInApp();
        
        // Add meta to stay in app
        const meta = document.createElement('meta');
        meta.name = 'viewport';
        meta.content = 'width=device-width, initial-scale=1, user-scalable=no, viewport-fit=cover';
        document.head.appendChild(meta);
    }
    
    redirectToServer();
});
