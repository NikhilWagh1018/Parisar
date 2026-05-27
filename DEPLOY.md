# Parisar Phase 5 — Deployment Guide

## What's in this folder

These are **only the files that changed or were added** in Phase 5.
Copy each one into the matching path in your repo, then follow the steps below.

```
phase5-deploy/
├── DEPLOY.md                          ← this file
├── composer.json                      ← updated (mpdf + phpspreadsheet added)
│
├── api/reports/
│   ├── export-pdf.php                 ← NEW — PDF export endpoint
│   └── export-excel.php               ← NEW — Excel export endpoint
│
├── pages/
│   └── report.php                     ← UPDATED — PDF + Excel buttons added to toolbar
│
├── css/
│   ├── report.css                     ← UPDATED — .tbtn-pdf and .tbtn-excel styles
│   └── modules/
│       ├── form-state.css             ← NEW (from your zip, included for completeness)
│       └── toast.css                  ← NEW (from your zip, included for completeness)
│
└── js/modules/
    ├── form-state.js                  ← NEW (from your zip)
    ├── toast.js                       ← NEW (from your zip)
    └── loading-state.js               ← NEW (from your zip)
```

---

## Step-by-step deployment

### 1. Copy files into your repo

```bash
# From the root of your Parisar repo:

cp phase5-deploy/composer.json                    ./composer.json
cp phase5-deploy/api/reports/export-pdf.php       ./api/reports/export-pdf.php
cp phase5-deploy/api/reports/export-excel.php     ./api/reports/export-excel.php
cp phase5-deploy/pages/report.php                 ./pages/report.php
cp phase5-deploy/css/report.css                   ./css/report.css
cp phase5-deploy/css/modules/form-state.css       ./css/modules/form-state.css
cp phase5-deploy/css/modules/toast.css            ./css/modules/toast.css
cp phase5-deploy/js/modules/form-state.js         ./js/modules/form-state.js
cp phase5-deploy/js/modules/toast.js              ./js/modules/toast.js
cp phase5-deploy/js/modules/loading-state.js      ./js/modules/loading-state.js
```

---

### 2. Install the two new Composer dependencies

```bash
composer require mpdf/mpdf phpoffice/phpspreadsheet
```

This installs:
- **mpdf/mpdf** `^8.2` — HTML-to-PDF renderer (no system binaries needed)
- **phpoffice/phpspreadsheet** `^2.1` — xlsx generator

Both are pure PHP. No extension changes needed. Railway will pick them up
automatically on the next deploy because it runs `composer install`.

---

### 3. Commit and push

```bash
git add \
  composer.json composer.lock \
  api/reports/export-pdf.php \
  api/reports/export-excel.php \
  pages/report.php \
  css/report.css \
  css/modules/form-state.css \
  css/modules/toast.css \
  js/modules/form-state.js \
  js/modules/toast.js \
  js/modules/loading-state.js

git commit -m "feat: Phase 5 steps 1-5 — autosave, toast, score helpers, PDF + Excel export

- js/modules/form-state.js   autosave draft every 30s, restore banner
- js/modules/toast.js        non-blocking toast notification system
- js/modules/loading-state.js button disable-during-submit pattern
- css/modules/form-state.css, toast.css  companion styles
- api/reports/export-pdf.php   mPDF export: header, scores, seg cards
- api/reports/export-excel.php PhpSpreadsheet: Summary / Segments / Details sheets
- pages/report.php             PDF + Excel download buttons in toolbar
- css/report.css               .tbtn-pdf (red) + .tbtn-excel (green) styles
- composer.json                mpdf/mpdf + phpoffice/phpspreadsheet added"

git push origin main
```

---

### 4. Verify on Railway

After the deploy completes:

1. Open any completed audit report in the browser.
2. You should see three buttons in the top toolbar:
   - `⬇ PDF` (red)
   - `⬇ Excel` (dark green)
   - `🖨 Print / Save PDF` (Parisar green)
3. Click **⬇ PDF** — browser should download `CycleAudit-<RoadName>-<Date>.pdf`
4. Click **⬇ Excel** — browser should download `CycleAudit-<RoadName>-<Date>.xlsx`
   - Open in Excel/Sheets and confirm three sheets: **Summary**, **Segments**, **Details**
   - Score cells should be colour-coded (green → red by condition band)

---

## Troubleshooting

| Problem | Fix |
|---|---|
| `Class 'Mpdf\Mpdf' not found` | Run `composer install` — vendor/ is missing the package |
| `Class 'PhpOffice\...' not found` | Same — `composer install` |
| PDF shows blank logo | Normal if `assets/parisar-logo.png` path changed; logo is optional |
| 403 on export endpoint | Session doesn't belong to logged-in user — expected security behaviour |
| mPDF temp dir error | Ensure `sys_get_temp_dir()` is writable on Railway (it always is) |

---

## Phase 5 progress after this deploy

| Step | Feature | Status |
|---|---|---|
| 1 | Autosave (`form-state.js`) | ✅ Files present — wiring to form.js pending |
| 2 | Toast + loading states | ✅ Files present — wiring to form.js pending |
| 3 | ScoreService helpers | ✅ Done (previous session) |
| 4 | PDF export | ✅ Done |
| 5 | Excel export | ✅ Done |
| 6 | Image upload (Cloudflare R2) | ⬜ Next |
| 7 | Analytics dashboard | ⬜ Next |

---

*Generated by Claude · Parisar CycleAudit Phase 5*
