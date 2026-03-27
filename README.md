# Pitchdeck

WordPress plugin that turns a PPTX or PDF presentation into a narrated MP4 video.

## How it works

1. User uploads a `.pptx` or `.pdf` file via the `[pitchdeck]` shortcode on any page.
2. The plugin extracts text from each slide.
3. OpenAI generates a voiceover script per slide (Finnish, English, or Swedish).
4. Scripts can be reviewed and edited before audio generation.
5. OpenAI TTS converts each script to an MP3.
6. ffmpeg renders each slide as an image and combines it with its audio into a clip, then concatenates all clips into a final `output.mp4`.

## Requirements

- WordPress 6.0+
- PHP 8.1+
- OpenAI API key
- Server binaries: `libreoffice`, `pdftoppm`, `ffmpeg`

## Installation

1. Clone or copy this directory into `wp-content/plugins/pitchdeck/`.
2. Run `composer install` inside the plugin directory.
3. Activate the plugin in **Plugins > Installed Plugins**.
4. Go to **Settings > Pitchdeck** and enter your OpenAI API key.
5. Add the `[pitchdeck]` shortcode to any page.

## REST API

All endpoints are under `/wp-json/pitchdeck/v1/`:

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/upload` | Upload PPTX/PDF, returns parsed slides |
| POST | `/save-slides` | Persist slide text to DB |
| POST | `/generate-script` | Generate voiceover scripts via OpenAI |
| POST | `/save-scripts` | Persist edited scripts to DB |
| POST | `/generate-audio` | Generate MP3 per slide via OpenAI TTS |
| POST | `/generate-video` | Render slide images and produce output.mp4 |

## Dependencies

- [smalot/pdfparser](https://github.com/smalot/pdfparser) — PDF text extraction (via Composer)
