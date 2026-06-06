# 數學練習 — Math Practice (Addition & Subtraction Within 10)

A child-friendly web app for practising basic arithmetic and word problems
(應用題) in **Traditional Chinese**, designed for ages 5–7.
Runs on Apache + PHP with no database required.

---

## Features

- **Two practice modes** from the home page:
  - **🧮 數學練習** — arithmetic calculation questions (12 per page).
  - **📖 應用題練習** — word problems (4 per page), each requiring the child to
    fill in the full formula **and** the final answer (e.g. `□ + □ = □`).
- **Three difficulty levels** (apply to both modes):
  - **一級** — two numbers (e.g. `3 + 4`).
  - **二級** — three numbers, same operator (e.g. `2 + 3 + 1`).
  - **三級** — three numbers, mixed operators (e.g. `5 + 3 - 2`).
- **Multi-page practice** — the child chooses how many pages (練習頁數) to generate.
- **Cantonese audio (🔊)** for every word problem — reads the question aloud,
  preferring a Hong Kong Cantonese (`zh-HK`) voice.
- **Instant marking** — ✅ / ❌ beside each question, wrong answers highlighted
  in red. Correct answers are **never** shown; the child corrects and resubmits.
- **Responsive, child-friendly UI** — large fonts, high contrast, works on
  desktop, tablet, and mobile.
- **All answers validated server-side** — nothing about the correct answer is
  ever exposed to the browser.

---

## File Structure

```
math-practice/
├── index.php      # Single-page app shell: home, quiz, word-problem & success screens
├── api.php        # JSON API: generates questions / word problems & checks answers
├── tts.php        # Cantonese text-to-speech proxy (Google Cloud TTS, optional)
├── style.css      # All styles (responsive, child-friendly)
├── script.js      # AJAX logic, DOM rendering, audio playback, result display
├── .htaccess      # Apache security hardening
└── README.md      # This file
```

---

## Deployment on Apache

### Prerequisites
- Apache 2.4+ with `mod_php` (or PHP-FPM)
- PHP 7.4+ (uses `random_int`, `json_decode`, typed return types, etc.)
- `mod_headers` and `mod_expires` enabled (recommended, not required)
- **For cloud Cantonese audio (optional):** PHP `cURL` extension + a Google
  Cloud Text-to-Speech API key (see [Cantonese Audio Setup](#cantonese-audio-setup))

### Steps

1. **Copy files** into your Apache web root (or a sub-directory):
   ```
   /var/www/html/math-practice/
   ```
   Or on Windows with XAMPP/WAMP:
   ```
   C:\xampp\htdocs\math-practice\
   ```

2. **Set file permissions** (Linux/macOS):
   ```bash
   chmod 644 *.php *.css *.js .htaccess
   chmod 755 .   # directory must be executable
   ```

3. **Enable AllowOverride** so `.htaccess` is respected.
   In your Apache `VirtualHost` or `httpd.conf`:
   ```apache
   <Directory "/var/www/html/math-practice">
       AllowOverride All
   </Directory>
   ```
   Then reload Apache:
   ```bash
   sudo systemctl reload apache2   # Debian/Ubuntu
   sudo systemctl reload httpd     # RHEL/CentOS
   ```

4. **Browse** to `http://localhost/math-practice/` (or your domain).

---

## Cantonese Audio Setup

Word problems include a 🔊 button that reads the question aloud in Cantonese.
Two levels of support, tried in order:

1. **Browser speech (no setup):** the app first uses the browser's built-in
   Web Speech API, preferring a `zh-HK` / Cantonese voice if one is installed.
2. **Google Cloud TTS (optional, best quality):** if a browser voice is
   unavailable, `script.js` falls back to `tts.php`, which proxies Google Cloud
   Text-to-Speech (voice `yue-Hant-HK`).

To enable the cloud fallback, provide an API key **one** of two ways:

- **Config file** — create `tts-config.php` next to `tts.php`:
  ```php
  <?php
  define('GOOGLE_TTS_API_KEY', 'your-api-key-here');
  ```
- **Environment variable** — set `GOOGLE_TTS_API_KEY` in the server environment.

If no key is configured, `tts.php` returns `503 { useBrowser: true }` and the
app simply relies on the browser voice. The API key never reaches the browser.

> **Note:** different devices ship different speech voices. A device with no
> Cantonese voice installed (and no cloud key) may read in another Chinese
> voice or stay silent — installing a `zh-HK` voice or adding a cloud key fixes this.

---

## Security Practices Implemented

| Area | Practice |
|---|---|
| Correct answers | Stored only in the **PHP Session**; never sent in HTML, hidden fields, or JS. Checking returns only `correct: true/false` per question |
| Input validation | All submitted values are `(int)` cast before comparison in PHP |
| Word-problem checks | Both the formula numbers **and** the final answer are validated server-side against session data |
| Page/level bounds | PHP rejects invalid difficulty levels and page counts (1–50) with HTTP 400 |
| Operand bounds | All generated values stay within `0–10` with no negative results |
| Headers | `X-Content-Type-Options`, `X-Frame-Options`, `X-XSS-Protection`, `CSP` set via `.htaccess` and PHP |
| HTTP method | `api.php` and `tts.php` reject non-POST requests with HTTP 405 |
| TTS input | Text is `strip_tags()`-cleaned and length-capped before any cloud call; API key kept server-side |
| Directory listing | Disabled via `Options -Indexes` in `.htaccess` |
| No SQL | No database used — zero SQL-injection surface |
| No eval | JavaScript uses `'use strict'` and never calls `eval()` |

---

## How It Works

### 數學練習 (arithmetic mode)
1. Child picks a difficulty level + number of pages, then clicks **數學練習**.
2. Browser `POST`s `{action:"generate", level, totalPages}` to `api.php`.
3. PHP generates 12 questions per page (server-side), stores answers in the
   session, and returns only the question text — **no answers**.
4. JavaScript renders one input card per question across the pages.
5. On **提交答案**, the page's answers `POST` to `api.php` (`action:"check"`).
6. PHP compares against the session and returns `{allCorrect, results:[...]}`.
7. JavaScript marks each card ✅ / ❌; all-correct advances to the next page or
   the success screen.

### 應用題練習 (word-problem mode)
1. Child picks a difficulty level + number of pages, then clicks **應用題練習**.
2. Browser `POST`s `{action:"generateWpPages", level, totalPages}` to `api.php`.
3. PHP generates **4 word problems per page** in Traditional Chinese, stores the
   correct formula numbers + answers in the session, and returns only the
   problem text and the formula shape (operators) — **no answers**.
4. JavaScript renders each problem with a 🔊 audio button and formula inputs
   (`□ op □ = □`).
5. On **提交答案**, the page's formulas + answers `POST` to `api.php`
   (`action:"checkWpPage"`).
6. PHP validates both the formula numbers and the final answer against the
   session and returns `{allCorrect, results:[...]}` — never the correct values.
7. JavaScript marks each problem ✅ / ❌; all-correct advances to the next page
   or the success screen.

---

## API Reference (`api.php`)

All requests are `POST` with a JSON body containing an `action` field.

| Action | Purpose |
|---|---|
| `generate` | Generate arithmetic question pages (answers stored in session) |
| `check` | Check one page of arithmetic answers |
| `generateWpPages` | Generate word-problem pages (4 per page; answers stored in session) |
| `checkWpPage` | Check one page of word-problem formulas + answers |
| `checkWordProblems` | Check the legacy inline word problems shown after a multi-page arithmetic run |

`tts.php` accepts `POST { text }` and returns MP3 audio, or `503 { useBrowser: true }`
when no cloud key is configured.
