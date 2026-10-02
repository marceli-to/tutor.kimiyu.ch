# Teil 3b – Upload-Extras: Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Adding photos gets easier and safer: drag & drop, paste from the clipboard, reorder, take a photo directly on the phone, and a warning for dark or blurry photos before anything is uploaded.

**Architecture:** The photo part of `resources/js/pages/lessons/Create.vue` moves into a new component `resources/js/components/PhotoPicker.vue` (`v-model` on `File[]`), so the form stays readable. All image work stays in the browser: `resizeImage()` as today, plus a new pure function `checkImageQuality()` in `resources/js/lib/imageQuality.ts`. No new libraries: reordering uses native HTML5 drag & drop for mouse, and arrow buttons for touch and keyboard (native DnD doesn't work on touch screens). The backend only gets one prompt line about the photo order.

**Tech Stack:** Vue 3 (`<script setup>`, TS), Tailwind 4, Laravel 13, Pest 4.

**Design:** `docs/plans/2026-10-02-neue-lernseite-design.md`, section «3b Upload-Extras».

**Conventions:** as in `docs/plans/2026-10-02-teil-1-auftrag.md`. There is no JS test runner in this repo; keep logic in small pure functions, verify with `npm run types:check`, `npm run check`, `npm run build` and in the browser.

---

### Task 1: Photo order in the analysis prompt (backend)

**Files:** `app/Lessons/Ai/Prompts.php` (`analysis`), `tests/Feature/Lessons/LessonGenerationTest.php`

Images are already sent in `position` order (`LessonImage` ordered by `position`, `images()` relation). Make the order explicit for the model: when there are 2+ photos, add the line `Die Fotos sind in der Reihenfolge der Seiten: Foto 1 ist die erste Seite.` directly below the source sentence.

**Test (first):** upload with two photos (`'images' => [photo('a.jpg'), photo('b.jpg')]`) → the analysis prompt contains «Reihenfolge der Seiten»; with one photo it does not. Also assert the stored `lesson_images.position` is 0 and 1 in upload order (if not already covered).

**Commit:** «Analyse: Reihenfolge der Fotos nennen»

---

### Task 2: `checkImageQuality()`

**Files:** Create `resources/js/lib/imageQuality.ts`

```ts
export type ImageQuality = { dark: boolean; blurry: boolean };

// Schwellen von Hand an Handyfotos von Buchseiten abgestimmt; lieber zu selten warnen als zu oft
const DARK_BELOW = 70; // mittlere Helligkeit 0–255
const BLURRY_BELOW = 60; // Varianz des Laplace-Filters
const SAMPLE_EDGE = 400;

/**
 * Grobe Prüfung eines Fotos im Browser: zu dunkel oder unscharf?
 * Arbeitet auf einer verkleinerten Graustufen-Kopie, damit es auch auf dem Handy schnell ist.
 */
export async function checkImageQuality(file: Blob): Promise<ImageQuality> { … }

/** Reine Berechnung, getrennt vom Canvas, damit sie nachvollziehbar bleibt. */
export function measure(gray: Uint8ClampedArray, width: number, height: number): { brightness: number; sharpness: number } { … }
```

- `checkImageQuality`: `createImageBitmap(file)`, draw into a canvas scaled so the longer edge is `SAMPLE_EDGE`, `getImageData`, convert to gray (`0.299 r + 0.587 g + 0.114 b`), call `measure`, compare with the thresholds. On any error return `{ dark: false, blurry: false }` (a failing check must never block the upload).
- `measure`: `brightness` = mean gray; `sharpness` = variance of the 4-neighbour Laplacian (`4·p − up − down − left − right`) over interior pixels.
- Keep the thresholds as named constants with the comment above; Task 5 tunes them in the browser.

Verify: `npm run types:check`, `npm run check`.

**Commit:** «Fotos im Browser auf Helligkeit und Schärfe prüfen»

---

### Task 3: `PhotoPicker` component (move existing behaviour, no new features)

**Files:** Create `resources/js/components/PhotoPicker.vue`; modify `resources/js/pages/lessons/Create.vue`

- Props: `modelValue: File[]`, `maxImages: number`, `maxEdge: number`, `error?: string` (server error to show). Emits: `update:modelValue`, `busy` (boolean, while resizing).
- Internal state: `items: { id: number; file: File; url: string; quality: ImageQuality | null }[]` (stable `id` from a counter for `:key`). Every change emits `items.map(i => i.file)`.
- Move from Create.vue: hint text, preview grid, remove button, «Foto hinzufügen» tile, hidden file input, resize loop with the `maxImages` limit and the error message, `URL.revokeObjectURL` on remove and on unmount.
- `addFiles(files: File[])` is the single entry point for all sources (file input now; drop, paste, camera in Task 4). It filters to `image/*`, respects the remaining room, resizes, pushes, then runs `checkImageQuality` per item without blocking (set `quality` when done).
- Create.vue: replace the photo block with `<PhotoPicker v-model="form.images" :max-images="maxImages" :max-edge="maxEdge" :error="imageErrors()" @busy="preparing = $event" />`; remove now-unused imports/state (`previews`, `fileInput`, `addImages`, `removeImage`, `canAddMore`, `ImagePlus`, `X`, `resizeImage`). Keep `preparing` (submit button) and `imageErrors()`.

Verify: types, check, build; in the browser, adding/removing photos works exactly as before.

**Commit:** «Fotoauswahl als eigene Komponente»

---

### Task 4: Drag & drop, paste, reorder, camera, quality hint

**Files:** `resources/js/components/PhotoPicker.vue`

1. **Drop zone:** the whole picker area (`div` around hint + grid) handles `dragenter`/`dragover`/`dragleave`/`drop` for files (`event.dataTransfer?.types.includes('Files')`). While a file drag is over it: dashed accent outline and an overlay text «Fotos hier ablegen». Use a counter for enter/leave so child elements don't flicker. `drop` → `addFiles(Array.from(dataTransfer.files))`. Prevent the browser from opening a file dropped next to the zone: add `dragover`/`drop` `preventDefault` on `window` while the component is mounted (only for file drags; remove in `onBeforeUnmount`).
2. **Paste:** a `paste` listener on `window` while mounted: if `clipboardData.files` contains images, `preventDefault()` and `addFiles(...)`, unless the paste target is a text field (`textarea`, `input`) **and** the clipboard has no files (so pasting text into the Auftrag still works). Add to the hint: «Du kannst Fotos auch hierher ziehen oder mit Cmd/Ctrl+V einfügen.»
3. **Reorder (mouse):** each preview tile is `draggable="true"`; on `dragstart` store its index in component state (not `dataTransfer`, to keep it separate from file drops; set `effectAllowed = 'move'`). `dragover` on another tile shows an insert marker; `drop` moves the item (`splice` out, `splice` in). File drops and tile drags must not interfere: a tile drag sets `draggingIndex`; the zone ignores drops while `draggingIndex !== null`.
4. **Reorder (touch/keyboard):** on each tile, two small buttons «nach links» / «nach rechts» (`ChevronLeft`/`ChevronRight` from `@lucide/vue`, `aria-label="Foto {n} nach vorne/hinten"`), hidden for the first/last tile respectively. Each tile shows its number («1», «2», …) in a small badge top-left, so the order is visible.
5. **Camera:** next to «Foto hinzufügen», a second tile/button «Foto aufnehmen» (`Camera` icon) that clicks a second hidden input `type="file" accept="image/*" capture="environment"`. Show it only on touch devices (`window.matchMedia('(pointer: coarse)').matches`, evaluated in `onMounted`), because desktop browsers ignore `capture` and would show a duplicate file dialog.
6. **Quality hint:** when `quality.dark || quality.blurry`, show a small warning strip at the bottom of the tile (`bg-amber-100 text-amber-900`, dark-mode variant) with `TriangleAlert` icon and «Zu dunkel» / «Wirkt unscharf» / «Dunkel und unscharf», plus `title` «Lieber nochmals aufnehmen: gerade von oben, gutes Licht.» The upload stays possible.
7. **Accessibility:** keep all buttons real `<button type="button">`, visible focus rings, `aria-live="polite"` region that announces «Foto 2 nach vorne verschoben» etc. after keyboard moves.

Verify: types, check, build. Browser (desktop): drop 2 files, paste a screenshot, drag tile 2 before tile 1, use the arrow buttons, check the number badges, try a 5th photo (limit message), drop a PDF (ignored, message «Nur Fotos»). If you can, emulate a touch device in DevTools to see the camera tile.

**Commit:** «Fotos per Drag & drop, Einfügen, Kamera; Reihenfolge ändern; Hinweis bei schlechter Qualität»

---

### Task 5: Tune thresholds and finish

- In the browser, try a sharp well-lit page photo, a dark one and a deliberately blurry one (any book page photo works; a blurry version can be made by downscaling and upscaling in Preview). Adjust `DARK_BELOW`/`BLURRY_BELOW` so the sharp photo gets no warning and the bad ones do. Note the measured values in the comment.
- Design doc «3b»: mark as implemented, note native DnD + arrow buttons, camera tile only on touch devices.
- Full check: `php artisan test --compact`, phpstan, `npm run types:check`, `npm run check`, `npm run build`.

**Commit:** «Docs: Teil 3b»
