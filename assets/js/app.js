document.addEventListener('DOMContentLoaded', () => {
    // Dark mode functionality
    const darkModeToggle = document.getElementById('darkModeToggle');
    const body = document.body;

    // Check for saved preference or default to light mode
    const isDarkMode = localStorage.getItem('darkMode') === 'true';
    if (isDarkMode) {
        body.classList.add('dark-mode');
        updateDarkModeIcon(true);
    }

    // Toggle dark mode function
    function toggleDarkMode() {
        const isCurrentlyDark = body.classList.contains('dark-mode');
        
        if (isCurrentlyDark) {
            body.classList.remove('dark-mode');
            localStorage.setItem('darkMode', 'false');
            updateDarkModeIcon(false);
        } else {
            body.classList.add('dark-mode');
            localStorage.setItem('darkMode', 'true');
            updateDarkModeIcon(true);
        }
    }

    function updateDarkModeIcon(isDark) {
        if (darkModeToggle) {
            darkModeToggle.textContent = isDark ? '☀️' : '🌙';
        }
    }

    if (darkModeToggle) {
        darkModeToggle.addEventListener('click', toggleDarkMode);
        updateDarkModeIcon(isDarkMode);
    }

    // Register Service Worker for PWA
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js')
            .then(registration => {
                console.log('Service Worker registered successfully:', registration);
            })
            .catch(error => {
                console.log('Service Worker registration failed:', error);
            });
    }

    // Handle beforeinstallprompt for PWA installation
    let deferredPrompt;
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        
        // Show install button if needed
        const installButton = document.getElementById('installButton');
        if (installButton) {
            installButton.style.display = 'block';
            installButton.addEventListener('click', (e) => {
                if (deferredPrompt) {
                    deferredPrompt.prompt();
                    deferredPrompt.userChoice.then((choiceResult) => {
                        if (choiceResult.outcome === 'accepted') {
                            console.log('User accepted the A2HS prompt');
                        } else {
                            console.log('User dismissed the A2HS prompt');
                        }
                        deferredPrompt = null;
                    });
                }
            });
        }
    });

    const toggles = document.querySelectorAll('[data-toggle-modal]');
    toggles.forEach(toggle => {
        toggle.addEventListener('click', () => {
            const target = document.querySelector(toggle.dataset.toggleModal);
            if (target) {
                target.classList.toggle('active');
            }
        });
    });

    document.querySelectorAll('.modal [data-close-modal]').forEach(button => {
        button.addEventListener('click', () => {
            button.closest('.modal')?.classList.remove('active');
        });
    });

    const sidebar = document.querySelector('.sidebar');
    const backdrop = document.querySelector('.sidebar-backdrop');
    const sidebarButtons = document.querySelectorAll('[data-toggle-sidebar]');

    const closeSidebar = () => {
        if (!sidebar) return;
        sidebar.classList.remove('active');
        backdrop?.classList.remove('active');
        sidebarButtons.forEach(btn => btn.setAttribute('aria-expanded', 'false'));
        document.body.classList.remove('no-scroll');
    };

    const openSidebar = () => {
        if (!sidebar) return;
        sidebar.classList.add('active');
        backdrop?.classList.add('active');
        sidebarButtons.forEach(btn => btn.setAttribute('aria-expanded', 'true'));
        document.body.classList.add('no-scroll');
    };

    sidebarButtons.forEach(button => {
        button.addEventListener('click', () => {
            if (sidebar?.classList.contains('active')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
    });

    backdrop?.addEventListener('click', closeSidebar);

    window.addEventListener('resize', () => {
        if (window.innerWidth > 1024) {
            closeSidebar();
        }
    });

    document.addEventListener('keyup', (event) => {
        if (event.key === 'Escape') {
            closeSidebar();
        }
    });
});
