# Math Practice — Addition & Subtraction Within 10

A child-friendly web app for practising basic arithmetic (ages 5–7).
Runs on Apache + PHP with no database required.

---

## File Structure

```
math-practice/
├── index.php      # Home page shell + quiz/success screens
├── api.php        # JSON API: generates questions & checks answers
├── style.css      # All styles (responsive, child-friendly)
├── script.js      # AJAX logic, DOM rendering, result display
├── .htaccess      # Apache security hardening
└── README.md      # This file
```

---

## Deployment on Apache

### Prerequisites
- Apache 2.4+ with `mod_php` (or PHP-FPM)
- PHP 7.4+ (uses `random_int`, `json_decode`, etc.)
- `mod_headers` and `mod_expires` enabled (recommended, not required)

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

## Security Practices Implemented

| Area | Practice |
|---|---|
| Input validation | All submitted answer values are `(int)` cast before arithmetic comparison in PHP |
| Correct answers | Never sent to the browser; computed fresh on every `/check` call server-side |
| Headers | `X-Content-Type-Options`, `X-Frame-Options`, `X-XSS-Protection`, `CSP` set via `.htaccess` and PHP |
| HTTP method | `api.php` rejects non-POST requests with HTTP 405 |
| Operand bounds | PHP checks `0 ≤ a,b ≤ 10` and `0 ≤ result ≤ 10` before computing; invalid data returns HTTP 400 |
| Directory listing | Disabled via `Options -Indexes` in `.htaccess` |
| No SQL | No database used — zero SQL-injection surface |
| No eval | JavaScript uses `'use strict'` and never calls `eval()` |

---

## How It Works

1. Child clicks **Start** → browser `POST`s to `api.php?action=generate`.
2. PHP generates 12 random addition/subtraction questions (server-side) and returns JSON `{questions: [...]}` — **no answers included**.
3. JavaScript renders one input card per question.
4. Child fills in answers and clicks **Submit**.
5. JavaScript validates all inputs are non-empty, then `POST`s `{action:"check", questions:[...], answers:{...}}` to `api.php`.
6. PHP recomputes correct answers from the question operands (ignoring any client manipulation) and returns `{allCorrect: bool, results: [...]}`.
7. JavaScript colours each card green or red, shows correct answers beside wrong ones, and — if all correct — transitions to the success screen.
