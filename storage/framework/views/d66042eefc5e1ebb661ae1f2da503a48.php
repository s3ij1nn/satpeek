<?php
    use App\Models\PtcAd;
    $ad = PtcAd::where('is_active', true)->findOrFail($id);
?>

<?php $__env->startPush('head'); ?>
<style>
    /* Viewer fills the viewport so the ad-open CTA isn't crammed into a strip. */
    .viewer { max-width: 80rem; margin: 0 auto; padding: 1.25rem 1.5rem 1.5rem; display: grid; gap: 1rem; min-height: calc(100vh - 4rem); grid-template-rows: auto 1fr; }
    .viewer__head { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; flex-wrap: wrap; }
    .viewer__head h1 { font-family: var(--font-display); font-size: var(--display-md); line-height: 1.05; letter-spacing: -.02em; font-weight: 400; margin: 0; }
    .viewer__head h1 em { color: var(--amber-soft); font-style: italic; }
    .viewer__meta { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; }

    .frame { background: var(--bg-panel); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); overflow: hidden; display: grid; grid-template-rows: auto 1fr auto; min-height: 0; }
    .frame__bar { display: flex; align-items: center; justify-content: space-between; padding: .85rem 1.25rem; border-bottom: 1px solid var(--border-faint); font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); gap: .75rem; }
    .frame__bar .url { color: var(--text-secondary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; flex: 1 1 auto; }

    .frame__body { padding: 2.5rem 2rem; display: grid; align-content: center; justify-items: center; gap: 1.25rem; min-height: 0; }
    /* iframe display mode: full-bleed embed instead of the centered CTA card. */
    .frame--iframe .frame__body { padding: 0; align-content: stretch; justify-items: stretch; gap: 0; }
    .frame--iframe .frame__body iframe { display: block; width: 100%; height: 100%; min-height: 60vh; border: 0; background: #060912; }
    .frame__title { font-family: var(--font-display); font-size: clamp(1.75rem, 1.2rem + 1.6vw, 2.5rem); line-height: 1.1; color: var(--text-primary); margin: 0; text-align: center; max-width: 40rem; }
    .frame__cta { display: inline-flex; align-items: center; justify-content: center; gap: .6rem; padding: 1.1rem 2.25rem; border-radius: var(--radius-md); background: var(--amber); color: #1a0e00; font-weight: 600; text-decoration: none; font-size: var(--text-md); border: 0; cursor: pointer; box-shadow: 0 8px 32px -16px rgba(251,191,36,.5); }
    .frame__cta:hover:not(:disabled) { background: var(--amber-soft); }
    .frame__cta:disabled { opacity: .55; cursor: not-allowed; }
    .frame__hint { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-align: center; line-height: 1.55; max-width: 36rem; }

    .countdown { display: grid; grid-template-columns: 1fr auto auto; gap: 1rem; align-items: center; padding: 1rem 1.25rem; background: var(--bg-elev); border-top: 1px solid var(--border-faint); }
    .countdown__label { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; }
    .countdown__num { font-family: var(--font-display); font-size: 1.75rem; line-height: 1; color: var(--amber-soft); }
    .countdown__num small { font-family: var(--font-mono); font-size: .45em; color: var(--text-tertiary); margin-left: .15rem; }
    .countdown__bar { width: 100%; height: 4px; background: var(--bg-elev-2); border-radius: 999px; overflow: hidden; }
    .countdown__bar > div { height: 100%; width: 0%; background: var(--amber-soft); transition: width 1s linear; }

    /* In-page popup (modal) for captcha + claim — appears once countdown finishes. */
    .modal { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; padding: 1.5rem; z-index: 100; background: rgba(6, 9, 18, .72); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); }
    .modal.open { display: flex; }
    .modal__card { background: var(--bg-panel); border: 1px solid var(--border-strong); border-radius: var(--radius-lg); padding: 1.75rem; width: min(100%, 28rem); display: grid; gap: 1rem; box-shadow: 0 24px 80px -24px rgba(0,0,0,.6); animation: modalIn .25s var(--ease-out-expo); }
    .modal__title { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; margin: 0; font-weight: 500; }
    .modal__reward { font-family: var(--font-display); font-size: 1.5rem; color: var(--amber-soft); margin: 0; }
    .modal__reward small { font-family: var(--font-mono); font-size: .55em; color: var(--text-tertiary); margin-left: .15rem; }
    @keyframes modalIn { from { opacity: 0; transform: translateY(8px) scale(.98); } to { opacity: 1; transform: none; } }

    .alert--ok { padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(52,211,153,.08); border: 1px solid rgba(52,211,153,.3); color: var(--mint); font-size: var(--text-sm); }
    .alert--err { padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(251,113,133,.08); border: 1px solid rgba(251,113,133,.3); color: var(--rose); font-size: var(--text-sm); }
</style>
<?php $__env->stopPush(); ?>

<?php $__env->startSection('content'); ?>
<section class="viewer">
    <header class="viewer__head">
        <div>
            <span class="viewer__meta">/ ptc · view</span>
            <h1><?php echo e($ad->title); ?></h1>
        </div>
        <div class="viewer__meta">reward: <span style="color: var(--amber-soft);"><?php echo e(number_format($ad->reward_sat)); ?> sat</span></div>
    </header>

    <div class="frame <?php echo e($ad->display_mode === 'iframe' ? 'frame--iframe' : ''); ?>">
        <div class="frame__bar">
            <span class="url" title="<?php echo e($ad->target_url); ?>"><?php echo e($ad->target_url); ?></span>
            <span id="ptcOpenStatus" class="viewer__meta"><?php echo e($ad->display_mode === 'iframe' ? 'loading' : 'awaiting open'); ?></span>
        </div>
        <div class="frame__body">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($ad->display_mode === 'iframe'): ?>
                
                <iframe id="ptcIframe"
                        src="<?php echo e($ad->target_url); ?>"
                        sandbox="allow-scripts allow-same-origin allow-popups allow-forms"
                        referrerpolicy="no-referrer"
                        loading="lazy"
                        title="<?php echo e($ad->title); ?>"></iframe>
            <?php else: ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($ad->description): ?>
                    <p class="frame__title"><?php echo e($ad->description); ?></p>
                <?php else: ?>
                    <p class="frame__title"><?php echo e($ad->title); ?></p>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <button type="button" class="frame__cta" id="openAdBtn">
                    Open the ad in a new tab <span aria-hidden="true">↗</span>
                </button>
                <p class="frame__hint">
                    The advertiser site opens in a new tab. Keep <strong>this</strong> tab open — the countdown
                    and reward claim happen here. The advertiser tab can redirect itself; the timer keeps running.
                </p>
                <div id="ptcInlineError" class="alert--err" style="display:none; max-width: 36rem;"></div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
        <div class="countdown">
            <div>
                <div class="countdown__label">Time remaining</div>
                <div class="countdown__bar"><div id="ptcBar"></div></div>
            </div>
            <div class="countdown__num"><span id="ptcSec"><?php echo e($ad->duration_sec); ?></span><small>sec</small></div>
            <div class="viewer__meta">heartbeats: <span id="ptcHb">0</span></div>
        </div>
    </div>

    <p style="font-size: var(--text-xs); color: var(--text-tertiary); text-align: center; margin: 0;">
        ← <a href="<?php echo e(route('ptc.index')); ?>" style="color: var(--text-secondary); text-decoration: underline;">Back to PTC list</a>
    </p>
</section>

<div class="modal" id="claimModal" role="dialog" aria-modal="true" aria-labelledby="claimModalTitle">
    <div class="modal__card">
        <h2 class="modal__title" id="claimModalTitle">Solve captcha to claim</h2>
        <p class="modal__reward"><?php echo e(number_format($ad->reward_sat)); ?><small>sat</small></p>
        <div id="ptcResult" style="display:none;"></div>
        <?php if (isset($component)) { $__componentOriginaldf583abcfdddb50c0a8be6966e5fb5a0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginaldf583abcfdddb50c0a8be6966e5fb5a0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.trajectory-captcha','data' => ['name' => 'ptc']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('trajectory-captcha'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'ptc']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginaldf583abcfdddb50c0a8be6966e5fb5a0)): ?>
<?php $attributes = $__attributesOriginaldf583abcfdddb50c0a8be6966e5fb5a0; ?>
<?php unset($__attributesOriginaldf583abcfdddb50c0a8be6966e5fb5a0); ?>
<?php endif; ?>
<?php if (isset($__componentOriginaldf583abcfdddb50c0a8be6966e5fb5a0)): ?>
<?php $component = $__componentOriginaldf583abcfdddb50c0a8be6966e5fb5a0; ?>
<?php unset($__componentOriginaldf583abcfdddb50c0a8be6966e5fb5a0); ?>
<?php endif; ?>
        <button type="button" class="cta cta--primary" id="claimBtn" style="justify-content:center;">
            Claim reward <span class="cta__arrow">→</span>
        </button>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php $__env->startPush('body'); ?>
<script>
(() => {
    const adId = <?php echo e((int) $ad->id); ?>;
    const targetUrl = <?php echo json_encode($ad->target_url, 15, 512) ?>;
    const duration = <?php echo e((int) $ad->duration_sec); ?>;
    const displayMode = <?php echo json_encode($ad->display_mode, 15, 512) ?>;
    const fp = window.SPCaptcha?.fingerprint || '';
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    const secEl = document.getElementById('ptcSec');
    const barEl = document.getElementById('ptcBar');
    const hbEl  = document.getElementById('ptcHb');
    const openBtn = document.getElementById('openAdBtn'); // null in iframe mode
    const openStatus = document.getElementById('ptcOpenStatus');
    const inlineErr = document.getElementById('ptcInlineError'); // null in iframe mode
    const modal = document.getElementById('claimModal');
    const claim = document.getElementById('claimBtn');
    const result = document.getElementById('ptcResult');

    let viewId = null, epochToken = null, remaining = duration, hbCount = 0;
    let started = false, viewerDone = false, adWindow = null;

    // Tab title state. We restore this on completion / cleanup so the original
    // page title comes back when the user has multiple PTC tabs open.
    const baseTitle = document.title;
    const setTitle = (prefix) => { document.title = prefix ? `${prefix} ${baseTitle}` : baseTitle; };

    // Track whether the user actually keeps the viewer in the foreground.
    // PTC abuse vector: open viewer in a hidden tab, run the timer, claim later.
    // We require the tab to be visible when the modal appears, otherwise the
    // modal is deferred until the user comes back. We also flash the title so
    // the tab strip draws attention.
    let visibleNow = !document.hidden;
    let modalDeferred = false;
    let titleFlashId = null;
    document.addEventListener('visibilitychange', () => {
        visibleNow = !document.hidden;
        if (visibleNow && modalDeferred) {
            modalDeferred = false;
            stopTitleFlash();
            setTitle('✓');
            openModal();
        }
    });
    function startTitleFlash() {
        if (titleFlashId) return;
        let on = true;
        titleFlashId = setInterval(() => {
            document.title = (on ? '✓ Claim ready · ' : '⏰ ') + baseTitle;
            on = !on;
        }, 900);
    }
    function stopTitleFlash() {
        if (titleFlashId) { clearInterval(titleFlashId); titleFlashId = null; }
    }

    // Cross-tab notification: when the claim succeeds, any open /ptc index
    // tab refreshes its remaining-today counts. BroadcastChannel works across
    // same-origin tabs in modern browsers; localStorage 'storage' event is
    // the universal fallback.
    const broadcastCompleted = (payload) => {
        try {
            if ('BroadcastChannel' in window) {
                const ch = new BroadcastChannel('satpeek-ptc');
                ch.postMessage({ type: 'view-completed', ...payload });
                ch.close();
            }
        } catch {}
        try {
            // storage event fires in *other* tabs on the same origin
            localStorage.setItem('satpeek:ptc:view-completed', JSON.stringify({
                at: Date.now(),
                ...payload,
            }));
        } catch {}
    };

    function showResult(state, msg) {
        result.style.display = 'block';
        result.className = state === 'ok' ? 'alert--ok' : 'alert--err';
        result.textContent = msg;
    }

    function showInlineError(msg) {
        if (!inlineErr) {
            // iframe mode has no inline slot — surface the error in the modal so
            // the user still sees it.
            showResult('err', msg);
            openModal();
            return;
        }
        inlineErr.textContent = msg;
        inlineErr.style.display = 'block';
    }
    function clearInlineError() { if (inlineErr) { inlineErr.style.display = 'none'; inlineErr.textContent = ''; } }

    function openModal() {
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
        // Refresh the captcha so the user gets a clean window starting now.
        const cap = modal.querySelector('[data-trajectory-captcha]');
        if (cap?.spReset) cap.spReset();
    }

    function closeModal() {
        modal.classList.remove('open');
        document.body.style.overflow = '';
    }

    // Dismiss modal by clicking the backdrop. The card itself swallows clicks.
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
    modal.querySelector('.modal__card').addEventListener('click', (e) => e.stopPropagation());

    async function startSession() {
        try {
            const r = await fetch(`/api/ptc/${adId}/start`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf, 'Accept': 'application/json',
                    'X-SP-Fingerprint': fp,
                },
                credentials: 'same-origin',
            });
            const data = await r.json();
            if (!r.ok) {
                openStatus.textContent = 'start failed';
                showInlineError(data?.error || 'Could not start view.');
                return false;
            }
            viewId = data.view_id;
            epochToken = data.epoch_token;
            return true;
        } catch (e) {
            openStatus.textContent = 'network error';
            showInlineError('Network error starting view.');
            return false;
        }
    }

    function tick() {
        if (viewerDone) return;
        // Reflect the initial second on the title before any tick fires.
        setTitle(`(${remaining}s)`);
        const id = setInterval(() => {
            remaining = Math.max(0, remaining - 1);
            secEl.textContent = remaining;
            barEl.style.width = (((duration - remaining) / duration) * 100).toFixed(1) + '%';
            setTitle(remaining > 0 ? `(${remaining}s)` : '✓');
            if (remaining === 0) {
                clearInterval(id);
                viewerDone = true;
                openBtn.disabled = true;
                openStatus.textContent = 'watch complete';
                if (visibleNow) {
                    openModal();
                } else {
                    // Tab is in the background — defer the modal until the user
                    // refocuses the viewer, and flash the title so they notice.
                    modalDeferred = true;
                    openStatus.textContent = 'awaiting return';
                    startTitleFlash();
                }
            }
        }, 1000);
    }

    function scheduleHeartbeat() {
        if (viewerDone) return;
        const wait = 1500 + Math.floor(Math.random() * 1000);
        setTimeout(async () => {
            try {
                await fetch(`/api/ptc/${viewId}/heartbeat`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrf, 'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-SP-Fingerprint': fp,
                    },
                    body: JSON.stringify({ epoch_token: epochToken, beacon_at_ms: Date.now() }),
                    credentials: 'same-origin',
                });
                hbCount++;
                hbEl.textContent = hbCount;
            } catch {}
            scheduleHeartbeat();
        }, wait);
    }

    if (displayMode === 'iframe') {
        // iframe mode: the embed already loads on page render, so the session
        // can start immediately — no user gesture required for popup permission.
        (async () => {
            const ok = await startSession();
            if (!ok) return;
            started = true;
            openStatus.textContent = 'watching';
            tick();
            scheduleHeartbeat();
        })();
    } else {
        openBtn.addEventListener('click', async () => {
            // The window.open MUST happen during the user-gesture handler so popup
            // blockers allow it. We deliberately don't pass `noopener` in the features
            // string — that form forces the call to return `null` even on success,
            // which destroys our popup-blocked detection. We sever opener manually
            // afterwards for the same security guarantee.
            adWindow = window.open(targetUrl, '_blank');
            if (!adWindow) {
                openStatus.textContent = 'popup blocked';
                showInlineError('Your browser blocked the new tab. Allow popups for this site, then click Open again.');
                return;
            }
            try { adWindow.opener = null; } catch {}
            clearInlineError();
            if (started) {
                openStatus.textContent = 're-opened';
                return;
            }
            openBtn.disabled = true;
            openBtn.textContent = 'Ad open in new tab ✓';
            openStatus.textContent = 'watching';
            const ok = await startSession();
            if (!ok) {
                openBtn.disabled = false;
                openBtn.innerHTML = 'Retry — open ad in a new tab <span aria-hidden="true">↗</span>';
                openStatus.textContent = 'awaiting open';
                return;
            }
            started = true;
            // Once the session is alive, expose a re-open affordance for the user
            // in case they accidentally close the ad tab partway through.
            openBtn.disabled = false;
            openBtn.innerHTML = 'Re-open ad tab <span aria-hidden="true">↗</span>';
            tick();
            scheduleHeartbeat();
        });
    }

    claim.addEventListener('click', async () => {
        const cap = modal.querySelector('[data-trajectory-captcha]');
        const state = cap?.spGetState ? cap.spGetState() : { challengeId: '', points: '', isReady: false };
        if (!state.isReady) {
            showResult('err', 'Solve the captcha first — drag along the path.');
            return;
        }
        const challengeId = state.challengeId;
        const points = state.points;
        try {
            const v = await fetch('/api/captcha/verify', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf, 'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-SP-Fingerprint': fp,
                },
                body: JSON.stringify({ challengeId, points: JSON.parse(points) }),
                credentials: 'same-origin',
            });
            const vd = await v.json();
            if (!v.ok || !vd.passed) {
                showResult('err', `Captcha rejected (${vd.reason || 'unknown'}). Try again.`);
                if (cap?.spReset) await cap.spReset();
                return;
            }

            const c = await fetch(`/api/ptc/${viewId}/complete`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf, 'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-SP-Fingerprint': fp,
                },
                body: JSON.stringify({ epoch_token: epochToken, captcha_challenge_id: challengeId }),
                credentials: 'same-origin',
            });
            const cd = await c.json();
            if (!c.ok) {
                showResult('err', cd?.error || 'Could not claim reward.');
                return;
            }
            showResult('ok', `Credited ${cd.reward_sat} sat. Returning to PTC list…`);
            stopTitleFlash();
            setTitle(`+${cd.reward_sat} sat ·`);
            broadcastCompleted({ adId, viewId, rewardSat: cd.reward_sat });
            setTimeout(() => location.href = '<?php echo e(route('ptc.index')); ?>', 1400);
        } catch (e) {
            showResult('err', 'Network error during claim.');
        }
    });
})();
</script>
<?php $__env->stopPush(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/resources/views/ptc/view.blade.php ENDPATH**/ ?>