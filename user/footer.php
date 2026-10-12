<?php
// footer.php — Include at the bottom of all user pages
// Usage: require_once "footer.php"; (user pages) or require_once "../user/footer.php"; (from subdirs)

// Fetch global rating inline for footer display (fast query, cached by browser)
if (isset($conn)) {
    $fr = $conn->query(
        "SELECT ROUND(AVG(rating),1) AS avg_r, COUNT(*) AS total_r FROM ratings"
    );
    $footer_rating = $fr ? $fr->fetch_assoc() : ['avg_r' => 0, 'total_r' => 0];
    $avg_r   = (float)($footer_rating['avg_r']   ?? 0);
    $total_r = (int)($footer_rating['total_r'] ?? 0);
} else {
    $avg_r   = 0;
    $total_r = 0;
}

// Get CSRF token for submit_rating.php (only needed on user pages)
$footer_csrf = $_SESSION['csrf_token'] ?? '';
?>

<footer class="site-footer">
    <div class="footer-inner">

        <!-- Global Rating Badge -->
        <div class="footer-rating-badge">
            <?php if ($total_r > 0): ?>
                <div class="footer-stars-display">
                    <?php
                    $full  = floor($avg_r);
                    $half  = ($avg_r - $full) >= 0.5;
                    $empty = 5 - $full - ($half ? 1 : 0);
                    for ($i = 0; $i < $full;  $i++) echo '<i class="fas fa-star"></i>';
                    if ($half)                       echo '<i class="fas fa-star-half-alt"></i>';
                    for ($i = 0; $i < $empty; $i++) echo '<i class="far fa-star"></i>';
                    ?>
                </div>
                <span class="footer-rating-text">
                    <strong><?= number_format($avg_r, 1) ?></strong>
                    <em>dari <?= number_format($total_r) ?> rider</em>
                </span>
            <?php else: ?>
                <span class="footer-rating-text"><em>Belum ada rating</em></span>
            <?php endif; ?>
        </div>

        <!-- Show All Ratings Button -->
        <button class="footer-show-ratings-btn" onclick="showAllRatings()">
            <i class="fas fa-users"></i>
            Tampilkan Rating User
        </button>

        <p class="footer-copy">&copy; <?= date('Y') ?> Scooter Rental. All rights reserved.</p>
    </div>
</footer>

<!-- ─── RATINGS MODAL ─────────────────────────────────── -->
<div class="ratings-modal-overlay" id="ratingsModalOverlay" onclick="closeRatingsModal(this, event)">
    <div class="ratings-modal" id="ratingsModal">
        <div class="ratings-modal-header">
            <h2><i class="fas fa-star"></i> Rating Semua User</h2>
            <button class="ratings-modal-close" onclick="closeRatingsModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="ratings-modal-global" id="ratingsModalGlobal">
            <!-- Filled by JS -->
        </div>

        <div class="ratings-modal-body" id="ratingsModalBody">
            <div class="ratings-loading">
                <i class="fas fa-spinner fa-spin"></i> Memuat data...
            </div>
        </div>
    </div>
</div>

<!-- ─── INLINE CSS (Footer + Modal) ──────────────────── -->
<style>
/* ── Footer ─────────────────────────────────────────────── */
.site-footer {
    background: #111;
    color: #ccc;
    padding: 28px 24px;
    margin-top: 60px;
    border-top: 3px solid #FF6B00;
}

.footer-inner {
    max-width: 1100px;
    margin: 0 auto;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 16px;
    justify-content: space-between;
}

.footer-rating-badge {
    display: flex;
    align-items: center;
    gap: 10px;
}

.footer-stars-display {
    display: flex;
    gap: 2px;
    color: #FFB800;
    font-size: 1.1rem;
}

.footer-rating-text {
    font-family: 'DM Sans', sans-serif;
    font-size: 0.92rem;
    color: #ddd;
}

.footer-rating-text strong {
    color: #FFB800;
    font-size: 1.1rem;
}

.footer-rating-text em {
    color: #999;
    font-style: normal;
    margin-left: 4px;
}

.footer-show-ratings-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 20px;
    background: transparent;
    border: 2px solid #FF6B00;
    color: #FF6B00;
    border-radius: 30px;
    font-family: 'DM Sans', sans-serif;
    font-weight: 600;
    font-size: 0.88rem;
    cursor: pointer;
    transition: all 0.2s;
    letter-spacing: 0.5px;
}

.footer-show-ratings-btn:hover {
    background: #FF6B00;
    color: #fff;
}

.footer-copy {
    color: #666;
    font-size: 0.82rem;
    width: 100%;
    text-align: center;
    margin-top: 8px;
}

/* ── Ratings Modal ───────────────────────────────────────── */
.ratings-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.75);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 16px;
    backdrop-filter: blur(4px);
}

.ratings-modal-overlay.open {
    display: flex;
}

.ratings-modal {
    background: #1a1a1a;
    border: 1px solid #333;
    border-radius: 16px;
    width: 100%;
    max-width: 600px;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: modalSlideIn 0.25s ease;
}

@keyframes modalSlideIn {
    from { opacity: 0; transform: translateY(20px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

.ratings-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px 16px;
    border-bottom: 1px solid #2a2a2a;
}

.ratings-modal-header h2 {
    font-family: 'Bebas Neue', sans-serif;
    color: #fff;
    font-size: 1.5rem;
    letter-spacing: 1px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.ratings-modal-header h2 i {
    color: #FFB800;
}

.ratings-modal-close {
    background: #2a2a2a;
    border: none;
    color: #aaa;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 0.9rem;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}

.ratings-modal-close:hover {
    background: #FF6B00;
    color: #fff;
}

.ratings-modal-global {
    padding: 14px 24px;
    background: #141414;
    border-bottom: 1px solid #222;
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}

.modal-global-stars {
    display: flex;
    gap: 3px;
    color: #FFB800;
    font-size: 1.2rem;
}

.modal-global-num {
    font-size: 2rem;
    font-weight: 700;
    color: #FFB800;
    font-family: 'Bebas Neue', sans-serif;
    letter-spacing: 1px;
}

.modal-global-sub {
    color: #888;
    font-size: 0.85rem;
    font-family: 'DM Sans', sans-serif;
}

.ratings-modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 16px 24px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

/* Custom scrollbar */
.ratings-modal-body::-webkit-scrollbar { width: 5px; }
.ratings-modal-body::-webkit-scrollbar-track { background: #111; }
.ratings-modal-body::-webkit-scrollbar-thumb { background: #333; border-radius: 4px; }

.ratings-loading,
.ratings-empty {
    text-align: center;
    color: #666;
    padding: 40px;
    font-family: 'DM Sans', sans-serif;
    font-size: 0.95rem;
}

.ratings-loading i { color: #FF6B00; }

/* Individual Rating Card */
.rating-user-card {
    background: #222;
    border: 1px solid #2d2d2d;
    border-radius: 12px;
    padding: 14px 16px;
    display: flex;
    gap: 14px;
    align-items: flex-start;
    transition: border-color 0.2s;
}

.rating-user-card:hover {
    border-color: #FF6B00;
}

.rating-user-avatar {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #FF6B00, #e55200);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Bebas Neue', sans-serif;
    font-size: 1.1rem;
    color: #fff;
    flex-shrink: 0;
}

.rating-user-info {
    flex: 1;
    min-width: 0;
}

.rating-user-name {
    font-weight: 600;
    font-family: 'DM Sans', sans-serif;
    color: #eee;
    font-size: 0.95rem;
}

.rating-user-stars {
    display: flex;
    gap: 2px;
    color: #FFB800;
    font-size: 0.85rem;
    margin: 4px 0;
}

.rating-user-review {
    font-size: 0.85rem;
    color: #999;
    font-family: 'DM Sans', sans-serif;
    line-height: 1.4;
    margin-top: 4px;
    word-break: break-word;
}

.rating-user-date {
    font-size: 0.75rem;
    color: #555;
    font-family: 'DM Sans', sans-serif;
    margin-top: 4px;
}

/* ── Rating Form (in history.php) ────────────────────────── */
.rating-form-section {
    margin-top: 14px;
    padding: 14px 16px;
    background: rgba(255, 107, 0, 0.06);
    border: 1px solid rgba(255, 107, 0, 0.2);
    border-radius: 10px;
}

.rating-form-title {
    font-weight: 600;
    font-size: 0.9rem;
    color: #ccc;
    margin-bottom: 10px;
    font-family: 'DM Sans', sans-serif;
    display: flex;
    align-items: center;
    gap: 6px;
}

.rating-form-title i {
    color: #FFB800;
}

/* Star picker */
.star-picker {
    display: flex;
    gap: 6px;
    margin-bottom: 10px;
    flex-direction: row-reverse;
    justify-content: flex-end;
}

.star-picker input[type="radio"] { display: none; }

.star-picker label {
    font-size: 1.6rem;
    color: #444;
    cursor: pointer;
    transition: color 0.15s, transform 0.15s;
    line-height: 1;
}

.star-picker label:hover,
.star-picker label:hover ~ label,
.star-picker input:checked ~ label {
    color: #FFB800;
}

.star-picker label:hover { transform: scale(1.2); }

.star-picker input:checked + label { color: #FFB800; }

/* Fix: forward-sibling coloring via CSS trick (row-reverse layout) */
.star-picker input:checked ~ label { color: #FFB800 !important; }

.rating-review-input {
    width: 100%;
    background: #1a1a1a;
    border: 1px solid #333;
    border-radius: 8px;
    color: #eee;
    padding: 9px 12px;
    font-family: 'DM Sans', sans-serif;
    font-size: 0.88rem;
    resize: vertical;
    min-height: 70px;
    margin-bottom: 10px;
    box-sizing: border-box;
    transition: border-color 0.2s;
}

.rating-review-input:focus {
    outline: none;
    border-color: #FF6B00;
}

.rating-review-input::placeholder { color: #555; }

.rating-submit-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 20px;
    background: #FF6B00;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-family: 'DM Sans', sans-serif;
    font-weight: 600;
    font-size: 0.88rem;
    cursor: pointer;
    transition: background 0.2s, transform 0.1s;
}

.rating-submit-btn:hover { background: #e55200; }
.rating-submit-btn:active { transform: scale(0.97); }

.rating-already-done {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.85rem;
    color: #FFB800;
    font-family: 'DM Sans', sans-serif;
    margin-bottom: 6px;
    font-weight: 600;
}

@media (max-width: 480px) {
    .footer-inner { flex-direction: column; text-align: center; align-items: center; }
    .ratings-modal { max-height: 90vh; }
    .star-picker label { font-size: 1.4rem; }
}
</style>

<!-- ─── GLOBAL RATING JAVASCRIPT ─────────────────────────── -->
<script>
// ── Global state ──────────────────────────────────────────
let ratingsModalLoaded = false;

// ── Show all ratings modal ────────────────────────────────
function showAllRatings() {
    const overlay = document.getElementById('ratingsModalOverlay');
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';

    if (!ratingsModalLoaded) {
        loadRatings();
    }
}

function closeRatingsModal(overlay, event) {
    if (overlay && event && event.target !== overlay) return; // Click inside modal = ignore
    document.getElementById('ratingsModalOverlay').classList.remove('open');
    document.body.style.overflow = '';
}

// Escape key closes modal
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeRatingsModal();
});

// ── Load ratings from API ─────────────────────────────────
function loadRatings() {
    const body   = document.getElementById('ratingsModalBody');
    const global = document.getElementById('ratingsModalGlobal');

    fetch('get_all_ratings.php')
        .then(r => r.json())
        .then(data => {
            ratingsModalLoaded = true;

            // Global stats
            if (data.total > 0) {
                const avg = data.ratings.reduce((s, r) => s + r.rating, 0) / data.total;
                global.innerHTML = `
                    <div class="modal-global-num">${avg.toFixed(1)}</div>
                    <div>
                        <div class="modal-global-stars">${renderStars(avg)}</div>
                        <div class="modal-global-sub">dari ${data.total} rider</div>
                    </div>
                `;
            } else {
                global.innerHTML = '<span style="color:#666;font-size:.85rem;font-family:\'DM Sans\',sans-serif">Belum ada rating</span>';
            }

            // List
            if (!data.ratings || data.ratings.length === 0) {
                body.innerHTML = '<div class="ratings-empty"><i class="fas fa-star" style="color:#333;font-size:2rem;display:block;margin-bottom:10px"></i>Belum ada rating dari user.</div>';
                return;
            }

            body.innerHTML = data.ratings.map(r => `
                <div class="rating-user-card">
                    <div class="rating-user-avatar">${escapeHtml(r.username).charAt(0).toUpperCase()}</div>
                    <div class="rating-user-info">
                        <div class="rating-user-name">${escapeHtml(r.username)}${r.scooter_name ? ` <span style="color:#FF6B00;font-weight:400;">· ${escapeHtml(r.scooter_name)}</span>` : ''}</div>
                        <div class="rating-user-stars">${renderStars(r.rating)}</div>
                        ${r.rating_review ? `<div class="rating-user-review">"${escapeHtml(r.rating_review)}"</div>` : ''}
                        ${r.rated_at ? `<div class="rating-user-date"><i class="fas fa-calendar-alt"></i> ${escapeHtml(r.rated_at)}</div>` : ''}
                    </div>
                    <div style="color:#FFB800;font-weight:700;font-family:'Bebas Neue',sans-serif;font-size:1.2rem;flex-shrink:0">${Number(r.rating).toFixed(1)}</div>
                </div>
            `).join('');
        })
        .catch(() => {
            body.innerHTML = '<div class="ratings-empty"><i class="fas fa-exclamation-triangle" style="color:#FF6B00"></i> Gagal memuat data rating.</div>';
        });
}

// ── Render star icons HTML ────────────────────────────────
function renderStars(rating) {
    const full  = Math.floor(rating);
    const half  = (rating - full) >= 0.5;
    const empty = 5 - full - (half ? 1 : 0);
    return '<i class="fas fa-star"></i>'.repeat(full)
         + (half ? '<i class="fas fa-star-half-alt"></i>' : '')
         + '<i class="far fa-star"></i>'.repeat(empty);
}

// ── Submit rating (called from history.php cards) ─────────
function submitRating(rentalId, userId) {
    const form      = document.getElementById(`rating-form-${rentalId}`);
    const selected  = form.querySelector('input[name="star"]:checked');
    const reviewEl  = form.querySelector('.rating-review-input');
    const csrf      = document.getElementById('footer-csrf').value;

    if (!selected) {
        Swal.fire({ icon: 'warning', title: 'Pilih Rating', text: 'Silakan pilih bintang (1–5) terlebih dahulu.', confirmButtonColor: '#FF6B00' });
        return;
    }

    const rating = parseInt(selected.value);
    const review = reviewEl ? reviewEl.value.trim() : '';

    const btn = form.querySelector('.rating-submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';

    fetch('submit_rating.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ rating, review, csrf, rental_id: rentalId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Terima Kasih! ⭐',
                text: data.message,
                confirmButtonColor: '#FF6B00',
                timer: 2500,
                showConfirmButton: false
            }).then(() => {
                // Sederhana & benar untuk rating per-sewa: reload agar form
                // sewa ini tampil sebagai "Update Rating" + footer ter-refresh
                location.reload();
            });
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: data.message, confirmButtonColor: '#FF6B00' });
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Kirim Rating';
        }
    })
    .catch(() => {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Koneksi gagal. Coba lagi.', confirmButtonColor: '#FF6B00' });
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Kirim Rating';
    });
}

// ── Escape HTML untuk cegah stored XSS via username/review ──
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
    });
}

function updateFooterRating(avg, total) {
    const badge = document.querySelector('.footer-rating-badge');
    if (!badge) return;
    const fullS = Math.floor(avg);
    const halfS = (avg - fullS) >= 0.5;
    const emptyS = 5 - fullS - (halfS ? 1 : 0);
    const stars = '<i class="fas fa-star"></i>'.repeat(fullS)
                + (halfS ? '<i class="fas fa-star-half-alt"></i>' : '')
                + '<i class="far fa-star"></i>'.repeat(emptyS);
    badge.innerHTML = `
        <div class="footer-stars-display">${stars}</div>
        <span class="footer-rating-text">
            <strong>${Number(avg).toFixed(1)}</strong>
            <em>dari ${Number(total)} rider</em>
        </span>`;
}
</script>

<!-- Hidden CSRF for AJAX calls -->
<input type="hidden" id="footer-csrf" value="<?= htmlspecialchars($footer_csrf) ?>">