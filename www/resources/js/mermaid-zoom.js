/**
 * Pan/zoom enhancement for rendered mermaid diagrams (VSCode-preview style).
 *
 * After mermaid.run() replaces a `<pre class="mermaid">` with an `<svg>`, call
 * enhanceMermaidZoom(root): each processed diagram is wrapped in a viewport that
 * clips the SVG, and given a control cluster (zoom in / out / fit-reset),
 * drag-to-pan, and wheel-zoom-toward-the-cursor.
 *
 * Vanilla JS, no dependencies. Idempotent: a wrapped diagram carries
 * `data-zoom-ready` so repeated renderMermaid() passes don't double-wrap.
 */

const MIN_SCALE = 0.1;
const MAX_SCALE = 8;
const ZOOM_STEP = 1.2; // multiplicative step for the +/- buttons
const FIT_PADDING = 0.95; // leave a little breathing room when fitting

/**
 * The diagram's intrinsic (scale-1) size in CSS pixels. Mermaid always sets a
 * `viewBox="0 0 W H"`, and we pin the SVG to exactly that width/height (see
 * enhanceOne) so 1 user unit == 1 CSS pixel — which makes the viewBox the
 * authoritative natural size and keeps the fit/zoom math unit-consistent.
 * getBBox / the client rect are fallbacks if the viewBox is ever missing.
 */
function svgNaturalSize(svg) {
    const vb = svg.viewBox && svg.viewBox.baseVal;
    if (vb && vb.width && vb.height) {
        return { w: vb.width, h: vb.height };
    }
    let w = 0;
    let h = 0;
    try {
        const bb = svg.getBBox();
        w = bb.width;
        h = bb.height;
    } catch (e) {
        /* getBBox throws if not rendered; ignore */
    }
    if (!w || !h) {
        const rect = svg.getBoundingClientRect();
        w = w || rect.width;
        h = h || rect.height;
    }
    return { w: w || 1, h: h || 1 };
}

function enhanceOne(pre) {
    if (pre.dataset.zoomReady === 'true') return;
    const svg = pre.querySelector('svg');
    if (!svg) return; // not actually rendered yet
    pre.dataset.zoomReady = 'true';

    // Mermaid constrains the svg to width:100% (useMaxWidth) and centers it; we
    // instead pin it to its viewBox size in CSS pixels (1 user unit = 1px) so our
    // own transform fully controls placement and the fit math stays unit-true.
    const vb = svg.viewBox && svg.viewBox.baseVal;
    svg.style.maxWidth = 'none';
    svg.style.transformOrigin = '0 0';
    svg.style.transform = 'translate(0px, 0px) scale(1)';
    svg.style.display = 'block';
    if (vb && vb.width && vb.height) {
        svg.style.width = vb.width + 'px';
        svg.style.height = vb.height + 'px';
        svg.removeAttribute('width');
        svg.removeAttribute('height');
    }

    // Build the viewport wrapper around the <pre> in the DOM.
    const viewport = document.createElement('div');
    viewport.className = 'mermaid-viewport';
    pre.parentNode.insertBefore(viewport, pre);
    viewport.appendChild(pre);
    // Give the viewport a definite height so it's a real box to pan within (the
    // absolutely-sized SVG can't size it). Use the diagram's natural height,
    // clamped — the CSS max-height (70vh) still caps a tall one. Without a
    // definite height the wheel-zoom/fit math has nothing to center against.
    if (vb && vb.height) {
        viewport.style.height = vb.height + 'px';
    }
    // Neutralise the prose `pre` styling (bg/padding/overflow) so it's just a
    // transparent transform host filling the viewport.
    pre.classList.add('mermaid-zoom-host');

    // --- transform state ---
    const state = { scale: 1, tx: 0, ty: 0 };

    const apply = () => {
        svg.style.transform = `translate(${state.tx}px, ${state.ty}px) scale(${state.scale})`;
    };

    const clampScale = (s) => Math.min(MAX_SCALE, Math.max(MIN_SCALE, s));

    /**
     * Zoom by `factor` while keeping the viewport point (cx, cy) — given in
     * viewport-local CSS pixels — pinned under itself. The content point under
     * the cursor is (cx - tx) / scale; after changing scale we re-solve tx/ty so
     * that same content point still maps to (cx, cy).
     */
    const zoomAt = (factor, cx, cy) => {
        const newScale = clampScale(state.scale * factor);
        if (newScale === state.scale) return;
        const k = newScale / state.scale;
        state.tx = cx - (cx - state.tx) * k;
        state.ty = cy - (cy - state.ty) * k;
        state.scale = newScale;
        apply();
    };

    /**
     * Fit the whole diagram into the viewport and center it. Scale is the lesser
     * of the width/height ratios (so nothing is clipped), capped at 1 so a small
     * diagram isn't blown up past its natural size.
     */
    const fit = () => {
        const vw = viewport.clientWidth;
        const vh = viewport.clientHeight;
        const { w, h } = svgNaturalSize(svg);
        if (!vw || !vh) return;
        const s = clampScale(Math.min(vw / w, vh / h, 1) * FIT_PADDING);
        state.scale = s;
        state.tx = (vw - w * s) / 2;
        state.ty = (vh - h * s) / 2;
        apply();
    };

    // --- controls ---
    const controls = document.createElement('div');
    controls.className = 'mermaid-zoom-controls';

    const mkBtn = (label, title, onClick) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'mermaid-zoom-btn';
        b.setAttribute('aria-label', title);
        b.title = title;
        b.innerHTML = label;
        // Stop pointerdown so a click on a button never starts a pan.
        b.addEventListener('pointerdown', (e) => e.stopPropagation());
        b.addEventListener('click', (e) => {
            e.stopPropagation();
            e.preventDefault();
            onClick();
        });
        return b;
    };

    const centerZoom = (factor) =>
        zoomAt(factor, viewport.clientWidth / 2, viewport.clientHeight / 2);

    controls.appendChild(mkBtn('+', 'Zoom in', () => centerZoom(ZOOM_STEP)));
    controls.appendChild(mkBtn('&minus;', 'Zoom out', () => centerZoom(1 / ZOOM_STEP)));
    // Fit/reset glyph (corners-out arrows).
    controls.appendChild(mkBtn('&#x2922;', 'Fit to view', fit));
    viewport.appendChild(controls);

    // --- drag to pan ---
    let dragging = false;
    let lastX = 0;
    let lastY = 0;

    viewport.addEventListener('pointerdown', (e) => {
        if (e.button !== 0) return;
        dragging = true;
        lastX = e.clientX;
        lastY = e.clientY;
        viewport.classList.add('is-grabbing');
        viewport.setPointerCapture(e.pointerId);
    });
    viewport.addEventListener('pointermove', (e) => {
        if (!dragging) return;
        state.tx += e.clientX - lastX;
        state.ty += e.clientY - lastY;
        lastX = e.clientX;
        lastY = e.clientY;
        apply();
    });
    const endDrag = (e) => {
        if (!dragging) return;
        dragging = false;
        viewport.classList.remove('is-grabbing');
        try {
            viewport.releasePointerCapture(e.pointerId);
        } catch (err) {
            /* pointer may already be released */
        }
    };
    viewport.addEventListener('pointerup', endDrag);
    viewport.addEventListener('pointercancel', endDrag);

    // --- wheel zoom toward cursor ---
    viewport.addEventListener(
        'wheel',
        (e) => {
            e.preventDefault(); // don't scroll the modal/page while zooming
            const rect = viewport.getBoundingClientRect();
            const cx = e.clientX - rect.left;
            const cy = e.clientY - rect.top;
            // Smooth, direction-aware factor; deltaY<0 = scroll up = zoom in.
            const factor = Math.exp(-e.deltaY * 0.0015);
            zoomAt(factor, cx, cy);
        },
        { passive: false }
    );

    // Fit once on first render so a big diagram starts fully visible. Defer a
    // frame so layout (viewport size) has settled.
    requestAnimationFrame(fit);
}

/**
 * Enhance every processed mermaid diagram under `root` (defaults to document)
 * that hasn't been wrapped yet.
 */
export function enhanceMermaidZoom(root) {
    const scope = root || document;
    scope
        .querySelectorAll('pre.mermaid[data-processed="true"]:not([data-zoom-ready="true"])')
        .forEach(enhanceOne);
}
