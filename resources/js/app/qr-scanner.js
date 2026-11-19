import jsQR from 'jsqr';

/**
 * The QR scanner for attendance codes: the camera modal, decoding frames
 * and photos with jsQR, and the scan tools a page binds to its code input.
 *
 * Methods of PortalApp (resources/js/app.js mixes them in): `this` is the
 * app, and every field they use is set in its constructor.
 */
export class QrScannerFeature {
    bindQrScannerUi() {
        const modal = document.querySelector('[data-qr-scanner-modal]');

        if (!modal) {
            return;
        }

        const panel = modal.querySelector('[data-qr-scanner-panel]');

        this.qrScannerState.modal = {
            // A dialog like the app sheet: focus moves in and stays there, the
            // page behind stops scrolling, and focus returns on close.
            show: () => {
                this.qrScannerState.returnFocus = document.activeElement instanceof HTMLElement && document.activeElement !== document.body
                    ? document.activeElement
                    : null;
                modal.classList.remove('hidden');
                modal.setAttribute('aria-hidden', 'false');
                document.documentElement.classList.add('overflow-hidden');
                (modal.querySelector('[data-qr-scanner-close]') || panel)?.focus();
            },
            hide: () => {
                const returnFocus = this.qrScannerState.returnFocus;
                const wasOpen = !modal.classList.contains('hidden');

                this.qrScannerState.returnFocus = null;
                modal.classList.add('hidden');
                modal.setAttribute('aria-hidden', 'true');

                if (!wasOpen) {
                    return;
                }

                if (!this.sheetState.host || this.sheetState.host.classList.contains('hidden')) {
                    document.documentElement.classList.remove('overflow-hidden');
                }

                if (returnFocus?.isConnected) {
                    returnFocus.focus();
                }
            },
        };

        modal.querySelector('[data-qr-scanner-close]')?.addEventListener('click', () => {
            void this.closeQrScanner();
        });

        modal.querySelector('[data-qr-scanner-backdrop]')?.addEventListener('click', () => {
            void this.closeQrScanner();
        });

        modal.addEventListener('keydown', (event) => {
            if (event.key === 'Tab') {
                this.trapTabFocus(event, panel);
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape' || modal.classList.contains('hidden')) {
                return;
            }

            void this.closeQrScanner();
        });
    }

    getQrCanvasContext(width, height) {
        if (!this.qrScannerState.canvas || !this.qrScannerState.context) {
            this.qrScannerState.canvas = document.createElement('canvas');
            this.qrScannerState.context = this.qrScannerState.canvas.getContext('2d', {
                willReadFrequently: true,
            });
        }

        const canvas = this.qrScannerState.canvas;
        const context = this.qrScannerState.context;

        if (!context) {
            return null;
        }

        canvas.width = Math.max(1, Math.floor(width));
        canvas.height = Math.max(1, Math.floor(height));

        return { canvas, context };
    }

    decodeQrImageData(imageData) {
        try {
            return jsQR(imageData.data, imageData.width, imageData.height, {
                inversionAttempts: 'attemptBoth',
            })?.data || '';
        } catch (error) {
            return '';
        }
    }

    async decodeQrFromFile(file, input, options = {}) {
        try {
            const imageUrl = URL.createObjectURL(file);
            const image = await new Promise((resolve, reject) => {
                const candidate = new Image();
                candidate.onload = () => resolve(candidate);
                candidate.onerror = () => reject(new Error('Image load failed'));
                candidate.src = imageUrl;
            });

            const maxDimension = 1600;
            const scale = Math.min(1, maxDimension / Math.max(image.naturalWidth || image.width, image.naturalHeight || image.height));
            const width = (image.naturalWidth || image.width) * scale;
            const height = (image.naturalHeight || image.height) * scale;
            const canvasKit = this.getQrCanvasContext(width, height);

            URL.revokeObjectURL(imageUrl);

            if (!canvasKit) {
                this.showNotice('Kanvas browser tidak tersedia untuk memproses foto QR.', 'error');

                return false;
            }

            canvasKit.context.clearRect(0, 0, canvasKit.canvas.width, canvasKit.canvas.height);
            canvasKit.context.drawImage(image, 0, 0, canvasKit.canvas.width, canvasKit.canvas.height);

            const imageData = canvasKit.context.getImageData(0, 0, canvasKit.canvas.width, canvasKit.canvas.height);
            const rawValue = this.decodeQrImageData(imageData);

            if (!rawValue) {
                this.showNotice('QR tidak terbaca dari foto yang dipilih.', 'warning');

                return false;
            }

            const didFill = this.fillAttendanceCode(input, rawValue);

            if (didFill) {
                options.onDetected?.(input.value, {
                    source: 'file',
                    input,
                });
            }

            return didFill;
        } catch (error) {
            this.showNotice('Foto QR gagal diproses.', 'error');

            return false;
        }
    }

    async openQrScanner(input, options = {}) {
        if (!navigator.mediaDevices?.getUserMedia) {
            this.showNotice('Kamera tidak tersedia di perangkat ini.', 'warning');

            return;
        }

        const modal = document.querySelector('[data-qr-scanner-modal]');
        const video = modal?.querySelector('[data-qr-scanner-video]');

        if (!modal || !video) {
            return;
        }

        // One camera at a time: a second tap while the camera starts says so.
        // A request the browser never settled (a permission prompt dismissed
        // without an answer) is given up after a while, so a tap can retry;
        // its stream, should it still arrive, is stopped as stale.
        if (this.qrScannerState.stream) {
            return;
        }

        if (this.qrScannerState.opening && Date.now() - this.qrScannerState.openingSince < this.qrScannerOpeningLimitMs) {
            this.showNotice('Kamera sedang dibuka. Izinkan akses kamera bila diminta.', 'info');

            return;
        }

        // Shortcuts (the Absen button, ?qr=1, ?absen=1) scan with what the
        // page bound to this input, so a scan sends attendance from anywhere.
        const scanOptions = { ...this.qrScannerOptionsFor(input), ...options };
        const attempt = this.qrScannerState.attempt + 1;

        this.qrScannerState.attempt = attempt;
        this.qrScannerState.opening = true;
        this.qrScannerState.openingSince = Date.now();

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: { ideal: 'environment' },
                },
                audio: false,
            });

            // Closed while the camera was starting: release it right away.
            if (attempt !== this.qrScannerState.attempt) {
                stream.getTracks().forEach((track) => track.stop());

                return;
            }

            this.qrScannerState.stream = stream;
            this.qrScannerState.input = input;
            this.qrScannerState.busy = false;

            video.srcObject = stream;
            await video.play();

            if (attempt !== this.qrScannerState.attempt) {
                return;
            }

            this.qrScannerState.modal?.show();
            this.setQrScannerStatus('Arahkan kamera ke QR absensi untuk mengambil 8 karakter pertama kode.');

            const scanFrame = async () => {
                if (!this.qrScannerState.stream || attempt !== this.qrScannerState.attempt) {
                    return;
                }

                if (!this.qrScannerState.busy && video.readyState >= 2) {
                    this.qrScannerState.busy = true;

                    try {
                        const canvasKit = this.getQrCanvasContext(video.videoWidth, video.videoHeight);

                        if (!canvasKit) {
                            this.setQrScannerStatus('Kanvas browser tidak tersedia untuk scan QR.', 'warning');
                            await this.closeQrScanner();

                            return;
                        }

                        canvasKit.context.drawImage(video, 0, 0, canvasKit.canvas.width, canvasKit.canvas.height);
                        const imageData = canvasKit.context.getImageData(0, 0, canvasKit.canvas.width, canvasKit.canvas.height);
                        const rawValue = this.decodeQrImageData(imageData);

                        if (rawValue && this.fillAttendanceCode(input, rawValue)) {
                            await this.closeQrScanner();
                            scanOptions.onDetected?.(input.value, {
                                source: 'camera',
                                input,
                            });

                            return;
                        }
                    } catch (error) {
                        this.setQrScannerStatus('QR belum terbaca. Arahkan kamera lebih dekat ke kode.', 'warning');
                    } finally {
                        this.qrScannerState.busy = false;
                    }
                }

                this.qrScannerState.rafId = window.requestAnimationFrame(() => {
                    void scanFrame();
                });
            };

            await scanFrame();
        } catch (error) {
            // Closed meanwhile (play() is aborted when the stream goes away).
            if (attempt !== this.qrScannerState.attempt) {
                return;
            }

            this.showNotice('Kamera tidak bisa dibuka. Pastikan izin kamera diberikan.', 'error');
            await this.closeQrScanner();
        } finally {
            if (attempt === this.qrScannerState.attempt) {
                this.qrScannerState.opening = false;
            }
        }
    }

    /**
     * What a scan into this input does, as the page bound it with
     * bindAttendanceQrTools (the page's form handler sends the attendance).
     * An input the page never bound is only filled in.
     */
    qrScannerOptionsFor(input) {
        return (input && this.qrScannerOptions.get(input)) || {};
    }

    async closeQrScanner({ preserveHiddenState = false } = {}) {
        const modal = document.querySelector('[data-qr-scanner-modal]');
        const video = modal?.querySelector('[data-qr-scanner-video]');

        // A camera still starting is released as soon as it arrives.
        this.qrScannerState.attempt += 1;
        this.qrScannerState.opening = false;

        if (this.qrScannerState.rafId) {
            window.cancelAnimationFrame(this.qrScannerState.rafId);
            this.qrScannerState.rafId = null;
        }

        this.qrScannerState.stream?.getTracks().forEach((track) => track.stop());
        this.qrScannerState.stream = null;
        this.qrScannerState.input = null;
        this.qrScannerState.busy = false;

        if (video) {
            video.pause();
            video.srcObject = null;
        }

        if (modal && !preserveHiddenState) {
            this.qrScannerState.modal?.hide();
        } else if (modal) {
            modal.setAttribute('aria-hidden', 'true');
        }

        this.setQrScannerStatus('Arahkan kamera ke QR absensi untuk mengambil 8 karakter pertama kode.');
    }

    setQrScannerStatus(message, tone = 'info') {
        const status = document.querySelector('[data-qr-scanner-status]');

        if (!status) {
            return;
        }

        status.textContent = message;
        status.dataset.tone = tone;
        status.className = this.alertTone(tone);
    }

    bindAttendanceQrTools(container, input, options = {}) {
        const scanButton = container.querySelector('[data-qr-scan]');
        const fileInput = container.querySelector('[data-qr-file]');

        if (input) {
            this.qrScannerOptions.set(input, options);
        }

        scanButton?.addEventListener('click', async () => {
            await this.openQrScanner(input, options);
        });

        fileInput?.addEventListener('change', async () => {
            const file = fileInput.files?.[0];

            if (!file) {
                return;
            }

            await this.decodeQrFromFile(file, input, options);
            fileInput.value = '';
        });
    }
}
