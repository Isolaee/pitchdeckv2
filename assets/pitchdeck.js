/* pitchdeck.js — upload PPTX, auto-generate scripts, produce voiceover and video */
(function () {
    'use strict';

    // pitchdeck_config is injected by wp_localize_script in pitchdeck.php
    const { rest_url, nonce } = window.pitchdeck_config;

    let currentJobId = null;

    document.addEventListener('DOMContentLoaded', function () {
        const form     = document.getElementById('pitchdeck-upload-form');
        const audioBtn = document.getElementById('pitchdeck-audio-btn');
        const videoBtn = document.getElementById('pitchdeck-video-btn');
        if (form)     form.addEventListener('submit', handleUpload);
        if (audioBtn) audioBtn.addEventListener('click', handleGenerateAudio);
        if (videoBtn) videoBtn.addEventListener('click', handleGenerateVideo);

        document.addEventListener('click', function (e) {
            if (e.target.matches('.pitchdeck-generate-slide-audio-btn')) {
                handleGenerateSlideAudio(parseInt(e.target.dataset.slide, 10));
            }
        });
    });

    /**
     * Handle form submission: upload → save slides → generate scripts → show for editing.
     */
    async function handleUpload(event) {
        event.preventDefault();

        const fileInput     = document.getElementById('pitchdeck-file');
        const langSelect    = document.getElementById('pitchdeck-language');
        const scriptSection = document.getElementById('pitchdeck-script-section');

        if (!fileInput.files.length) {
            setStatus('Please select a .pptx or .pdf file.', 'error');
            return;
        }

        const language = langSelect ? langSelect.value : 'Finnish';
        const submitBtn = event.target.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        scriptSection.style.display = 'none';

        // --- Step 1: upload and extract ---
        setStatus('Uploading and extracting slides\u2026', 'info');

        let slides;
        try {
            const formData = new FormData();
            formData.append('pptx_file', fileInput.files[0]);

            const uploadResp = await fetch(`${rest_url}/upload`, {
                method: 'POST',
                headers: { 'X-WP-Nonce': nonce },
                body: formData,
            });
            const uploadData = await uploadResp.json();

            if (!uploadResp.ok) {
                setStatus(`Upload failed: ${uploadData.message || 'Unknown error.'}`, 'error');
                return;
            }

            currentJobId = uploadData.job_id;
            slides       = uploadData.slides;
        } catch (err) {
            setStatus('Upload failed: ' + err.message, 'error');
            console.error('Pitchdeck upload error:', err);
            return;
        } finally {
            submitBtn.disabled = false;
        }

        // --- Step 2: save slides to DB ---
        setStatus(`Extracted ${slides.length} slide(s). Saving\u2026`, 'info');

        try {
            const slidesToSave = slides.map(function (s) {
                return { slide_number: s.slide_number, slide_text: s.slide_text, extra_info: '' };
            });

            const saveResp = await fetch(`${rest_url}/save-slides`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body: JSON.stringify({ job_id: currentJobId, slides: slidesToSave }),
            });

            if (!saveResp.ok) {
                const saveData = await saveResp.json();
                setStatus(`Save failed: ${saveData.message || 'Unknown error.'}`, 'error');
                return;
            }
        } catch (err) {
            setStatus('Network error saving slides. Please try again.', 'error');
            console.error('Pitchdeck save error:', err);
            return;
        }

        // --- Step 3: generate scripts ---
        setStatus('Generating scripts via OpenAI\u2026 this may take a few seconds.', 'info');

        try {
            const scriptResp = await fetch(`${rest_url}/generate-script`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body: JSON.stringify({ job_id: currentJobId, language }),
            });
            const scriptData = await scriptResp.json();

            if (!scriptResp.ok) {
                setStatus(`Script generation failed: ${scriptData.message || 'Unknown error.'}`, 'error');
                return;
            }

            renderScripts(scriptData.scripts);
            scriptSection.style.display = 'block';
            setStatus(`Scripts ready for ${scriptData.scripts.length} slide(s). Review and edit, then generate audio.`, 'success');

        } catch (err) {
            setStatus('Network error during script generation. Please try again.', 'error');
            console.error('Pitchdeck generate error:', err);
        }
    }

    /**
     * Render generated scripts as editable textareas, one per slide.
     */
    function renderScripts(scripts) {
        const container = document.getElementById('pitchdeck-scripts-container');
        container.innerHTML = '';

        scripts.forEach(function (item) {
            const card = document.createElement('div');
            card.className = 'pitchdeck-script-card';

            card.innerHTML = `
                <h3>Slide ${item.slide_number}</h3>
                <textarea
                    id="script-text-${item.slide_number}"
                    class="pitchdeck-script-textarea"
                    rows="4"
                >${escapeHtml(item.script_text || '')}</textarea>
                <button class="pitchdeck-generate-slide-audio-btn" data-slide="${item.slide_number}">Generate VO for this slide</button>`;

            container.appendChild(card);
        });
    }

    /**
     * Collect current textarea values as [{slide_number, script_text}].
     */
    function collectScripts() {
        return Array.from(document.querySelectorAll('.pitchdeck-script-textarea')).map(function (ta) {
            return {
                slide_number: parseInt(ta.id.replace('script-text-', ''), 10),
                script_text:  ta.value,
            };
        });
    }

    /**
     * Attach an audio player to a slide card.
     */
    function attachAudioPlayer(item) {
        const card = document.getElementById(`script-text-${item.slide_number}`);
        if (!card) return;
        const existing = document.getElementById(`audio-player-${item.slide_number}`);
        if (existing) existing.remove();
        const player = document.createElement('audio');
        player.id       = `audio-player-${item.slide_number}`;
        player.controls = true;
        player.src      = item.audio_url;
        card.parentNode.appendChild(player);
    }

    /**
     * Generate VO for a single slide using the current textarea value.
     */
    async function handleGenerateSlideAudio(slideNumber) {
        if (!currentJobId) {
            setStatus('No job loaded. Please upload and save slides first.', 'error');
            return;
        }

        const btn = document.querySelector(`.pitchdeck-generate-slide-audio-btn[data-slide="${slideNumber}"]`);
        if (btn) btn.disabled = true;

        setStatus(`Generating voiceover for slide ${slideNumber}\u2026`, 'info');

        const scripts = collectScripts();

        try {
            const response = await fetch(`${rest_url}/generate-audio`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body: JSON.stringify({ job_id: currentJobId, slide_number: slideNumber, scripts }),
            });

            const data = await response.json();

            if (!response.ok) {
                setStatus(`Audio generation failed: ${data.message || 'Unknown error.'}`, 'error');
                return;
            }

            data.audio.forEach(attachAudioPlayer);
            setStatus(`Voiceover generated for slide ${slideNumber}.`, 'success');

        } catch (err) {
            setStatus('Network error during audio generation. Please try again.', 'error');
            console.error('Pitchdeck audio error:', err);
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    /**
     * Call POST /generate-audio, then attach <audio> players to each script card.
     */
    async function handleGenerateAudio() {
        if (!currentJobId) {
            setStatus('No job loaded. Please upload and save slides first.', 'error');
            return;
        }

        const audioBtn = document.getElementById('pitchdeck-audio-btn');
        audioBtn.disabled = true;

        setStatus('Generating voiceover audio via OpenAI\u2026 this may take a while.', 'info');

        // Pass current textarea values directly so the backend uses what the user sees,
        // not a potentially stale version from the database.
        const scripts = collectScripts();

        try {
            const response = await fetch(`${rest_url}/generate-audio`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':   nonce,
                },
                body: JSON.stringify({ job_id: currentJobId, scripts }),
            });

            const data = await response.json();

            if (!response.ok) {
                setStatus(`Audio generation failed: ${data.message || 'Unknown error.'}`, 'error');
                return;
            }

            data.audio.forEach(attachAudioPlayer);

            setStatus(`Voiceover audio generated for ${data.audio.length} slide(s). You can now generate the video.`, 'success');

            const videoBtn = document.getElementById('pitchdeck-video-btn');
            if (videoBtn) videoBtn.style.display = 'inline-block';

        } catch (err) {
            setStatus('Network error during audio generation. Please try again.', 'error');
            console.error('Pitchdeck audio error:', err);
        } finally {
            audioBtn.disabled = false;
        }
    }

    /**
     * Call POST /generate-video, then show the final MP4 in a video player.
     */
    async function handleGenerateVideo() {
        if (!currentJobId) {
            setStatus('No job loaded. Please upload and save slides first.', 'error');
            return;
        }

        const videoBtn     = document.getElementById('pitchdeck-video-btn');
        const videoSection = document.getElementById('pitchdeck-video-section');
        const videoPlayer  = document.getElementById('pitchdeck-video-player');
        const videoDownload = document.getElementById('pitchdeck-video-download');

        videoBtn.disabled = true;
        setStatus('Rendering slide images and encoding video\u2026 this may take a minute.', 'info');

        try {
            const response = await fetch(`${rest_url}/generate-video`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':   nonce,
                },
                body: JSON.stringify({ job_id: currentJobId }),
            });

            const data = await response.json();

            if (!response.ok) {
                setStatus(`Video generation failed: ${data.message || 'Unknown error.'}`, 'error');
                return;
            }

            videoPlayer.src      = data.video_url;
            videoDownload.href   = data.video_url;
            videoSection.style.display = 'block';
            videoPlayer.load();

            setStatus('Video ready!', 'success');

        } catch (err) {
            setStatus('Network error during video generation. Please try again.', 'error');
            console.error('Pitchdeck video error:', err);
        } finally {
            videoBtn.disabled = false;
        }
    }

    function setStatus(message, type) {
        const el = document.getElementById('pitchdeck-status');
        if (el) {
            el.textContent = message;
            el.className   = `pitchdeck-status pitchdeck-status--${type}`;
        }
    }

    /** Minimal HTML escaping to prevent XSS in rendered slide text. */
    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
})();
