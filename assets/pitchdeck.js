/* pitchdeck.js — upload PPTX/PDF, generate scripts, produce voiceover and video */
(function () {
    'use strict';

    const { rest_url, nonce } = window.pitchdeck_config;

    let currentJobId = null;

    document.addEventListener('DOMContentLoaded', function () {
        document.getElementById('pd-get-started-btn')
            .addEventListener('click', function () { showStep(2); });

        document.getElementById('pd-start-over-btn')
            .addEventListener('click', handleStartOver);

        document.getElementById('pitchdeck-upload-form')
            .addEventListener('submit', handleUpload);

        document.getElementById('pitchdeck-audio-btn')
            .addEventListener('click', handleGenerateAudio);

        document.getElementById('pitchdeck-video-btn')
            .addEventListener('click', handleGenerateVideo);

        document.getElementById('pitchdeck-buy-btn')
            .addEventListener('click', handleBuyVideo);

        // Per-slide VO button delegation
        document.addEventListener('click', function (e) {
            if (e.target.matches('.pitchdeck-generate-slide-audio-btn')) {
                handleGenerateSlideAudio(parseInt(e.target.dataset.slide, 10));
            }

            // Voice preview button
            if (e.target.matches('.pd-voice-preview-btn')) {
                e.stopPropagation();
                handleVoicePreview(e.target.dataset.voice, e.target);
                return;
            }

            // Clicking anywhere on the voice card selects it
            const card = e.target.closest('.pd-voice-option');
            if (card) {
                const radio = card.querySelector('input[type="radio"]');
                if (radio) {
                    radio.checked = true;
                    document.querySelectorAll('.pd-voice-option').forEach(function (c) {
                        c.classList.toggle('is-selected', c === card);
                    });
                }
            }
        });

        // Set initial selected state
        document.querySelectorAll('.pd-voice-option').forEach(function (card) {
            const radio = card.querySelector('input[type="radio"]');
            if (radio && radio.checked) card.classList.add('is-selected');
        });

        wireDropzone();
    });

    /* ── Step navigation ──────────────────────────────────────────── */

    function showStep(n) {
        document.querySelectorAll('.pd-step').forEach(function (li) {
            const s = parseInt(li.dataset.step, 10);
            li.classList.toggle('pd-step--active', s === n);
            li.classList.toggle('pd-step--done',   s < n);
        });

        for (let i = 1; i <= 4; i++) {
            const panel = document.getElementById('pd-panel-' + i);
            if (panel) panel.hidden = (i !== n);
        }

        clearStatus();

        const app = document.getElementById('pitchdeck-app');
        if (app) window.scrollTo({ top: app.getBoundingClientRect().top + window.scrollY - 24, behavior: 'smooth' });
    }

    /* ── Loading overlay ──────────────────────────────────────────── */

    function showOverlay(msg) {
        document.getElementById('pd-overlay-msg').textContent = msg || '';
        document.getElementById('pd-overlay').hidden = false;
    }

    function hideOverlay() {
        document.getElementById('pd-overlay').hidden = true;
    }

    /* ── Status messages ──────────────────────────────────────────── */

    function setStatus(msg, type) {
        const el = document.getElementById('pitchdeck-status');
        if (!el) return;
        el.textContent = msg;
        el.className   = 'pitchdeck-status pitchdeck-status--' + type;
        el.hidden      = false;
    }

    function clearStatus() {
        const el = document.getElementById('pitchdeck-status');
        if (!el) return;
        el.hidden      = true;
        el.textContent = '';
        el.className   = 'pitchdeck-status';
    }

    /* ── Upload flow ──────────────────────────────────────────────── */

    async function handleUpload(event) {
        event.preventDefault();

        const fileInput  = document.getElementById('pitchdeck-file');
        const langSelect = document.getElementById('pitchdeck-language');

        if (!fileInput.files.length) {
            setStatus('Lataa esitystiedosto (.pptx tai .pdf) ennen jatkamista.', 'error');
            return;
        }

        const language = langSelect ? langSelect.value : 'Finnish';

        // 1. Upload and extract
        showOverlay('Ladataan ja puretaan dioja,\u2026');

        let slides;
        try {
            const formData = new FormData();
            formData.append('pptx_file', fileInput.files[0]);

            const uploadResp = await fetch(rest_url + '/upload', {
                method:  'POST',
                headers: { 'X-WP-Nonce': nonce },
                body:    formData,
            });
            const uploadData = await uploadResp.json();

            if (!uploadResp.ok) {
                hideOverlay();
                setStatus('Lataus epäonnistui: ' + (uploadData.message || 'Tuntematon virhe.'), 'error');
                return;
            }

            currentJobId = uploadData.job_id;
            slides       = uploadData.slides;
        } catch (err) {
            hideOverlay();
            setStatus('Lataus epäonnistui: ' + err.message, 'error');
            return;
        }

        // 2. Save slides
        showOverlay('Tallennetaan ' + slides.length + ' dia\u2026');

        try {
            const slidesToSave = slides.map(function (s) {
                return { slide_number: s.slide_number, slide_text: s.slide_text, extra_info: '' };
            });

            const saveResp = await fetch(rest_url + '/save-slides', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body:    JSON.stringify({ job_id: currentJobId, slides: slidesToSave }),
            });

            if (!saveResp.ok) {
                const saveData = await saveResp.json();
                hideOverlay();
                setStatus('Tallennus epäonnistui: ' + (saveData.message || 'Tuntematon virhe.'), 'error');
                return;
            }
        } catch (err) {
            hideOverlay();
            setStatus('Verkkovirhe diojen tallennuksessa. Yritä uudelleen.', 'error');
            return;
        }

        // 3. Generate scripts
        showOverlay('Luodaan käsikirjoitusta,\u2026 tämä voi kestää hetken.');

        try {
            const scriptResp = await fetch(rest_url + '/generate-script', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body:    JSON.stringify({ job_id: currentJobId, language: language }),
            });
            const scriptData = await scriptResp.json();

            if (!scriptResp.ok) {
                hideOverlay();
                setStatus('Skriptien luonti epäonnistui: ' + (scriptData.message || 'Tuntematon virhe.'), 'error');
                return;
            }

            renderScripts(scriptData.scripts);
            hideOverlay();
            showStep(3);
            setStatus('Skriptit valmiina ' + scriptData.scripts.length + ' dialle. Tarkista ja muokkaa, sitten luo äänitykset.', 'success');
        } catch (err) {
            hideOverlay();
            setStatus('Network error during script generation. Please try again.', 'error');
        }
    }

    /* ── Render script cards ──────────────────────────────────────── */

    function renderScripts(scripts) {
        const container = document.getElementById('pitchdeck-scripts-container');
        container.innerHTML = '';

        scripts.forEach(function (item) {
            const card = document.createElement('div');
            card.className      = 'pitchdeck-script-card';
            card.dataset.slide  = item.slide_number;

            card.innerHTML =
                '<h3>Dia ' + item.slide_number + '</h3>' +
                '<textarea' +
                '  id="script-text-' + item.slide_number + '"' +
                '  class="pitchdeck-script-textarea"' +
                '  rows="4"' +
                '>' + escapeHtml(item.script_text || '') + '</textarea>' +
                '<div class="pd-card-footer">' +
                '  <button class="pitchdeck-generate-slide-audio-btn" data-slide="' + item.slide_number + '">Luo äänitys tälle dialle</button>' +
                '</div>';

            container.appendChild(card);
        });
    }

    function selectedVoice() {
        const checked = document.querySelector('input[name="pitchdeck-voice"]:checked');
        return checked ? checked.value : 'alloy';
    }

    function selectedProvider() {
        const card = document.querySelector('.pd-voice-option.is-selected');
        return card ? (card.dataset.provider || 'openai') : 'openai';
    }

    // Cache of voice -> Audio object so repeated previews don't re-fetch.
    const voiceAudioCache = {};

    async function handleVoicePreview(voice, btn) {
        // If already loaded, just replay.
        if (voiceAudioCache[voice]) {
            voiceAudioCache[voice].currentTime = 0;
            voiceAudioCache[voice].play();
            return;
        }

        btn.classList.add('is-loading');
        btn.disabled = true;

        try {
            const provider = btn.dataset.provider || 'openai';
        const resp = await fetch(rest_url + '/preview-voice?voice=' + encodeURIComponent(voice) + '&provider=' + encodeURIComponent(provider), {
                headers: { 'X-WP-Nonce': nonce },
            });
            const data = await resp.json();

            if (!resp.ok) {
                setStatus('Ääninäytteen lataus epäonnistui: ' + (data.message || 'Tuntematon virhe.'), 'error');
                return;
            }

            const audio = new Audio(data.url);
            voiceAudioCache[voice] = audio;
            audio.play();
        } catch (err) {
            setStatus('Verkkovirhe ääninäytteen latauksessa.', 'error');
        } finally {
            btn.classList.remove('is-loading');
            btn.disabled = false;
        }
    }

    function collectScripts() {
        return Array.from(document.querySelectorAll('.pitchdeck-script-textarea')).map(function (ta) {
            return {
                slide_number: parseInt(ta.id.replace('script-text-', ''), 10),
                script_text:  ta.value,
            };
        });
    }

    function attachAudioPlayer(item) {
        const card = document.querySelector('.pitchdeck-script-card[data-slide="' + item.slide_number + '"]');
        if (!card) return;

        const existing = card.querySelector('#audio-player-' + item.slide_number);
        if (existing) existing.remove();

        const player    = document.createElement('audio');
        player.id       = 'audio-player-' + item.slide_number;
        player.controls = true;
        player.src      = item.audio_url + '?t=' + Date.now();

        const footer = card.querySelector('.pd-card-footer');
        (footer || card).appendChild(player);
    }

    /* ── Per-slide VO ─────────────────────────────────────────────── */

    async function handleGenerateSlideAudio(slideNumber) {
        if (!currentJobId) {
            setStatus('Ei ladattua työtä. Lataa ensin esitys.', 'error');
            return;
        }

        showOverlay('Luodaan äänitys dialle ' + slideNumber + '\u2026');
        const scripts = collectScripts();

        try {
            const response = await fetch(rest_url + '/generate-audio', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body:    JSON.stringify({ job_id: currentJobId, slide_number: slideNumber, scripts: scripts, voice: selectedVoice(), provider: selectedProvider() }),
            });
            const data = await response.json();
            hideOverlay();

            if (!response.ok) {
                setStatus('Äänityksen luonti epäonnistui: ' + (data.message || 'Tuntematon virhe.'), 'error');
                return;
            }

            data.audio.forEach(attachAudioPlayer);
        } catch (err) {
            hideOverlay();
            setStatus('Verkkovirhe äänityksen luonnin aikana. Yritä uudelleen.', 'error');
        }
    }

    /* ── Generate all voiceovers ──────────────────────────────────── */

    async function handleGenerateAudio() {
        if (!currentJobId) {
            setStatus('Ei ladattua työtä. Lataa ensin esitys.', 'error');
            return;
        }

        showOverlay('Luodaan äänityksiä\u2026 tämä voi kestää hetken.');
        const scripts = collectScripts();

        try {
            const response = await fetch(rest_url + '/generate-audio', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body:    JSON.stringify({ job_id: currentJobId, scripts: scripts, voice: selectedVoice(), provider: selectedProvider() }),
            });
            const data = await response.json();
            hideOverlay();

            if (!response.ok) {
                setStatus('Äänityksen luonti epäonnistui: ' + (data.message || 'Tuntematon virhe.'), 'error');
                return;
            }

            data.audio.forEach(attachAudioPlayer);

            const videoBtn = document.getElementById('pitchdeck-video-btn');
            if (videoBtn) videoBtn.hidden = false;

            setStatus('Äänitykset luotu ' + data.audio.length + ' dialle. Voit nyt luoda videon.', 'success');
        } catch (err) {
            hideOverlay();
            setStatus('Verkkovirhe äänityksen luonnin aikana. Yritä uudelleen.', 'error');
        }
    }

    /* ── Generate video ───────────────────────────────────────────── */

    async function handleGenerateVideo() {
        if (!currentJobId) {
            setStatus('Ei ladattua työtä. Lataa ensin esitys.', 'error');
            return;
        }

        showOverlay('Luodaan videota.\u2026 tämä voi kestää useita minuutteja.');

        try {
            const response = await fetch(rest_url + '/generate-video', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body:    JSON.stringify({ job_id: currentJobId }),
            });
            const data = await response.json();
            hideOverlay();

            if (!response.ok) {
                setStatus('Videon luonti epäonnistui: ' + (data.message || 'Tuntematon virhe.'), 'error');
                return;
            }

            const player = document.getElementById('pitchdeck-video-player');
            if (player) { player.src = data.video_url; player.load(); }

            showStep(4);
        } catch (err) {
            hideOverlay();
            setStatus('Verkkovirhe videon luonnin aikana. Yritä uudelleen.', 'error');
        }
    }

    /* ── Buy video (WooCommerce checkout) ────────────────────────── */

    async function handleBuyVideo() {
        if (!currentJobId) {
            setStatus('Ei ladattua työtä. Lataa ensin esitys.', 'error');
            return;
        }

        showOverlay('Siirrytään kassalle\u2026');

        try {
            const response = await fetch(rest_url + '/checkout', {
                method:      'POST',
                credentials: 'same-origin',
                headers:     { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body:        JSON.stringify({ job_id: currentJobId }),
            });
            const data = await response.json();
            hideOverlay();

            if (!response.ok) {
                setStatus('Kassalle siirtyminen epäonnistui: ' + (data.message || 'Tuntematon virhe.'), 'error');
                return;
            }

            window.location.href = data.checkout_url;
        } catch (err) {
            hideOverlay();
            setStatus('Verkkovirhe kassalle siirtyessä. Yritä uudelleen.', 'error');
        }
    }

    /* ── Start over ───────────────────────────────────────────────── */

    function handleStartOver() {
        currentJobId = null;

        const form = document.getElementById('pitchdeck-upload-form');
        if (form) form.reset();

        const fileNameEl = document.getElementById('pd-file-name');
        if (fileNameEl) fileNameEl.textContent = '';

        const container = document.getElementById('pitchdeck-scripts-container');
        if (container) container.innerHTML = '';

        const videoBtn = document.getElementById('pitchdeck-video-btn');
        if (videoBtn) videoBtn.hidden = true;

        const player = document.getElementById('pitchdeck-video-player');
        if (player) player.src = '';

        showStep(1);
    }

    /* ── Dropzone wiring ──────────────────────────────────────────── */

    function wireDropzone() {
        const zone     = document.querySelector('.pd-dropzone');
        const input    = document.getElementById('pitchdeck-file');
        const fileNameEl = document.getElementById('pd-file-name');

        if (!zone || !input) return;

        input.addEventListener('change', function () {
            if (fileNameEl) fileNameEl.textContent = this.files[0] ? this.files[0].name : '';
        });

        zone.addEventListener('dragover',  function (e) { e.preventDefault(); zone.classList.add('pd-dropzone--over'); });
        zone.addEventListener('dragenter', function (e) { e.preventDefault(); zone.classList.add('pd-dropzone--over'); });
        zone.addEventListener('dragleave', function ()  { zone.classList.remove('pd-dropzone--over'); });

        zone.addEventListener('drop', function (e) {
            e.preventDefault();
            zone.classList.remove('pd-dropzone--over');
            const file = e.dataTransfer.files[0];
            if (file) {
                const dt = new DataTransfer();
                dt.items.add(file);
                input.files = dt.files;
                if (fileNameEl) fileNameEl.textContent = file.name;
            }
        });
    }

    /* ── Helpers ──────────────────────────────────────────────────── */

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

})();
