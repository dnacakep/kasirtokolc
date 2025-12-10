(() => {
    const overlay = document.getElementById('barcode-scanner');
    if (!overlay) {
        return;
    }

    const video = document.getElementById('barcode-scanner-video');
    const statusEl = overlay.querySelector('[data-scanner-status]');
    const closeBtn = overlay.querySelector('[data-scan-close]');
    const buttons = document.querySelectorAll('[data-scan-target]');
    const fallbackScriptSrc = overlay.dataset?.zxingSrc || '';

    if (!buttons.length) {
        return;
    }

    const hasMedia = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    const params = new URLSearchParams(window.location.search);
    if (params.get('scanner_debug') === '1' && window.localStorage) {
        window.localStorage.setItem('scanner_debug', '1');
    }
    const debugEnabled = window.localStorage?.getItem('scanner_debug') === '1';
    const debugLog = (...args) => {
        if (!debugEnabled) {
            return;
        }
        // eslint-disable-next-line no-console
        console.log('[scanner]', ...args);
    };
    let detector = null;
    let detectorSupported = false;
    let fallbackReader = null;
    let fallbackControls = null;
    let fallbackLoadPromise = null;

    const detectorFormats = [
        'code_128',
        'code_39',
        'code_93',
        'ean_13',
        'ean_8',
        'upc_a',
        'upc_e',
        'itf',
        'qr_code'
    ];

    const ensureDetector = () => {
        if (!('BarcodeDetector' in window)) {
            detector = null;
            detectorSupported = false;
            return false;
        }

        try {
            detector = new window.BarcodeDetector({
                formats: detectorFormats
            });
            detectorSupported = true;
            debugLog('detector ready', detectorFormats);
        } catch (error) {
            detector = null;
            detectorSupported = false;
            debugLog('detector init failed', error);
        }

        return detectorSupported;
    };

    let stream = null;
    let running = false;
    let scanning = false;
    let currentTarget = null;
    let autoStopTimeout = null;
    let activeStartToken = null;
    let overlayShownAt = 0;

    const showOverlay = () => {
        overlay.classList.add('active');
        overlayShownAt = Date.now();
        if (closeBtn) {
            closeBtn.disabled = false;
        }
        debugLog('overlay shown');
    };

    const hideOverlay = () => {
        overlay.classList.remove('active');
        debugLog('overlay hidden');
    };

    const setStatus = (message) => {
        if (statusEl) {
            statusEl.textContent = message;
        }
        debugLog('status', message);
    };

    const loadScript = (src) => {
        if (!src) {
            return Promise.reject(new Error('Script source is empty.'));
        }

        return new Promise((resolve, reject) => {
            const existing = Array.from(document.querySelectorAll('script[data-zxing-fallback="true"]')).find((script) => script.src && script.src.includes(src));
            if (existing) {
                const isLoaded = existing.dataset.loaded === 'true' || existing.readyState === 'complete' || existing.readyState === 'loaded';
                if (isLoaded) {
                    resolve();
                    return;
                }
                existing.addEventListener('load', () => resolve(), { once: true });
                existing.addEventListener('error', () => reject(new Error('Gagal memuat skrip fallback deteksi barcode.')), { once: true });
                return;
            }

            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.dataset.loaded = 'false';
            script.dataset.zxingFallback = 'true';
            script.addEventListener('load', () => {
                script.dataset.loaded = 'true';
                resolve();
            }, { once: true });
            script.addEventListener('error', () => reject(new Error('Gagal memuat skrip fallback deteksi barcode.')), { once: true });
            document.head.appendChild(script);
        });
    };

    const ensureFallbackReader = async () => {
        if (fallbackReader) {
            fallbackReader.reset();
            return fallbackReader;
        }
        if (!fallbackLoadPromise) {
            fallbackLoadPromise = loadScript(fallbackScriptSrc);
        }
        await fallbackLoadPromise;
        if (!window.ZXing || !window.ZXing.BrowserMultiFormatReader) {
            throw new Error('Library ZXing tidak tersedia.');
        }
        fallbackReader = new window.ZXing.BrowserMultiFormatReader();
        return fallbackReader;
    };

    const stopScanner = (reason = 'manual') => {
        if (autoStopTimeout) {
            clearTimeout(autoStopTimeout);
            autoStopTimeout = null;
        }
        debugLog(`stopScanner invoked (${reason})`);
        running = false;
        scanning = false;
        activeStartToken = null;

        if (fallbackControls) {
            fallbackControls.stop();
            fallbackControls = null;
        }
        if (fallbackReader) {
            fallbackReader.reset();
        }
        if (stream) {
            stream.getTracks().forEach((track) => track.stop());
        }
        stream = null;

        if (video) {
            video.pause?.();
            video.srcObject = null;
            video.removeAttribute('src');
            video.load?.();
        }

        currentTarget = null;
        setStatus('');
        hideOverlay();
        debugLog('scanner stopped');
    };

    const emitResult = (value, target) => {
        if (!target) {
            return;
        }
        debugLog('emitResult', { value, target: target.id });
        target.value = value;
        target.dispatchEvent(new Event('input', { bubbles: true }));
        target.dispatchEvent(new Event('change', { bubbles: true }));
        const event = new CustomEvent('barcode-scanned', {
            detail: {
                targetId: target.id,
                value
            }
        });
        document.dispatchEvent(event);
    };

    const scanFrame = async () => {
        if (!running || !detectorSupported || !detector || !video) {
            debugLog('scanFrame skipped', { running, detectorSupported, hasDetector: !!detector });
            return;
        }
        if (video.readyState < 2) {
            requestAnimationFrame(scanFrame);
            return;
        }
        if (scanning) {
            requestAnimationFrame(scanFrame);
            return;
        }
        scanning = true;
        try {
            const barcodes = await detector.detect(video);
            scanning = false;
            if (barcodes && barcodes.length > 0) {
                const detectedValue = (barcodes[0].rawValue || '').trim();
                if (detectedValue) {
                    debugLog('detected (native)', detectedValue);
                    setStatus('Barcode terdeteksi.');
                    emitResult(detectedValue, currentTarget);
                    if (autoStopTimeout) {
                        clearTimeout(autoStopTimeout);
                    }
                    autoStopTimeout = setTimeout(() => {
                        autoStopTimeout = null;
                        stopScanner('auto_stop_after_detection');
                    }, 300);
                    return;
                }
            }
        } catch (error) {
            scanning = false;
            setStatus('Tidak dapat membaca barcode. Coba pusatkan barcode pada kotak.');
            debugLog('detector detect error', error);
        }
        if (running) {
            requestAnimationFrame(scanFrame);
        }
    };

    const startWithNativeDetector = async (startToken) => {
        debugLog('startWithNativeDetector');
        if (!detectorSupported || !detector) {
            debugLog('detector not available, fallback forced');
            await startWithFallback(startToken);
            return;
        }

        try {
            stream = await navigator.mediaDevices.getUserMedia({
                audio: false,
                video: {
                    facingMode: { ideal: 'environment' }
                }
            });
            debugLog('getUserMedia success (native)');

            if (activeStartToken !== startToken) {
                debugLog('native getUserMedia resolved but session no longer active');
                if (stream) {
                    stream.getTracks().forEach((track) => track.stop());
                }
                return;
            }

            if (video) {
                video.srcObject = stream;
                await video.play();
                debugLog('video.play resolved');
            }
            setStatus('Arahkan kamera ke barcode.');
            running = true;
            scanning = false;
            requestAnimationFrame(scanFrame);
        } catch (error) {
            setStatus('Tidak dapat mengakses kamera. Periksa izin atau gunakan pemindai fisik.');
            debugLog('getUserMedia error (native)', error);
        }
    };

    const startWithFallback = async (startToken) => {
        debugLog('startWithFallback');
        try {
            const reader = await ensureFallbackReader();
            if (activeStartToken !== startToken) {
                debugLog('fallback reader ready but session no longer active');
                return;
            }
            setStatus('Arahkan kamera ke barcode.');
            running = true;
            scanning = false;

            if (fallbackControls) {
                fallbackControls.stop();
                fallbackControls = null;
            }

            const constraints = {
                audio: false,
                video: {
                    facingMode: { ideal: 'environment' }
                }
            };

            fallbackControls = await reader.decodeFromConstraints(constraints, video, (result, error) => {
                if (activeStartToken !== startToken) {
                    debugLog('fallback decode callback but session is inactive');
                    return;
                }
                if (!running) {
                    return;
                }
                if (result) {
                    const detectedValue = (typeof result.getText === 'function' ? result.getText() : result.text || '').trim();
                    if (detectedValue) {
                        setStatus('Barcode terdeteksi.');
                        debugLog('detected (fallback)', detectedValue);
                        emitResult(detectedValue, currentTarget);
                        if (autoStopTimeout) {
                            clearTimeout(autoStopTimeout);
                        }
                        autoStopTimeout = setTimeout(() => {
                            autoStopTimeout = null;
                            stopScanner('auto_stop_after_detection');
                        }, 300);
                    }
                    return;
                }
                if (error && window.ZXing && !(error instanceof window.ZXing.NotFoundException)) {
                    setStatus('Tidak dapat membaca barcode. Coba pusatkan barcode pada kotak.');
                    debugLog('fallback decode error', error);
                }
            });
        } catch (error) {
            running = false;
            scanning = false;
            if (!hasMedia) {
                setStatus('Kamera tidak tersedia di perangkat ini.');
                debugLog('fallback start error - no media', error);
                return;
            }
            if (!fallbackScriptSrc) {
                setStatus('Deteksi barcode tidak tersedia. Hubungi administrator.');
                debugLog('fallback start error - script missing', error);
                return;
            }
            setStatus('Kamera atau deteksi barcode tidak didukung di perangkat ini.');
            debugLog('fallback start error', error);
        }
    };

    const startScanner = async (target) => {
        debugLog('startScanner requested', { target: target?.id, running, overlayActive: overlay.classList.contains('active') });
        if (!hasMedia) {
            showOverlay();
            setStatus('Kamera tidak tersedia di perangkat ini.');
            return;
        }

        if (running || overlay.classList.contains('active')) {
            stopScanner('reset_before_start');
        }
        if (autoStopTimeout) {
            clearTimeout(autoStopTimeout);
            autoStopTimeout = null;
        }

        const startToken = Symbol('scan_start');
        activeStartToken = startToken;

        currentTarget = target;
        showOverlay();
        setStatus('Mengaktifkan kamera...');

        const canUseDetector = ensureDetector();
        debugLog('ensureDetector result', { canUseDetector, detectorSupported });

        if (canUseDetector && detector) {
            try {
                await startWithNativeDetector(startToken);
                return;
            } catch (error) {
                debugLog('native start threw', error);
            }
        }

        try {
            await startWithFallback(startToken);
        } catch (error) {
            debugLog('fallback start threw', error);
        }
    };

    if (closeBtn) {
        closeBtn.addEventListener('click', () => {
            const elapsed = Date.now() - overlayShownAt;
            debugLog('close button handler', { elapsed });
            if (elapsed < 400) {
                debugLog('close button click ignored (debounce)');
                return;
            }
            closeBtn.disabled = true;
            stopScanner('close_button');
            setTimeout(() => {
                closeBtn.disabled = false;
            }, 600);
        });
    }

    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
            stopScanner('overlay_click');
        }
    });

    document.addEventListener('keyup', (event) => {
        if (event.key === 'Escape' && overlay.classList.contains('active')) {
            stopScanner('escape_key');
        }
    });

    buttons.forEach((button) => {
        const targetId = button.getAttribute('data-scan-target');
        const target = document.getElementById(targetId);
        if (!target) {
            return;
        }
        button.addEventListener('click', () => {
            debugLog('scan button clicked', { target: targetId, overlayActive: overlay.classList.contains('active'), running });
            if (overlay.classList.contains('active') || running) {
                stopScanner('restart_scan');
            }
            void startScanner(target);
        });
    });
})();
