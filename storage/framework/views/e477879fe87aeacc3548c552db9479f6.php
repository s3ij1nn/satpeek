<?php
    use App\Http\Controllers\Api\PtcController;
    use App\Models\PtcView;
    use Illuminate\Support\Carbon;

    $u = auth()->user();
    $today = Carbon::now()->startOfDay();

    // servableAdsQuery filters: approved + active + not expired + budget remaining
    // + excludes the viewer's own ads. Same filter the API uses.
    $ads = PtcController::servableAdsQuery($u->id)
        ->orderByDesc('reward_sat')
        ->limit(50)
        ->get();

    $usedToday = PtcView::where('user_id', $u->id)
        ->where('status', 'verified')
        ->where('created_at', '>=', $today)
        ->selectRaw('ptc_ad_id, count(*) as used')
        ->groupBy('ptc_ad_id')
        ->pluck('used', 'ptc_ad_id');
?>

<?php $__env->startPush('head'); ?>
<style>
    .ptc { max-width: 64rem; margin: 0 auto; padding: 3rem 1.5rem; display: grid; gap: 2rem; }
    .ptc__head h1 { font-family: var(--font-display); font-size: var(--display-md); line-height: 1.05; letter-spacing: -.02em; font-weight: 400; margin: .5rem 0 .25rem; }
    .ptc__head h1 em { color: var(--amber-soft); font-style: italic; }
    .ptc__head .meta { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; }
    .ad-list { display: grid; gap: 1rem; }
    .ad { background: var(--bg-panel); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 1.25rem 1.5rem; display: grid; grid-template-columns: 1fr auto auto; gap: 1.25rem; align-items: center; transition: border-color var(--dur-fast) var(--ease-out-expo); }
    .ad:hover { border-color: var(--border-strong); }
    .ad.exhausted { opacity: .55; }
    .ad__title { font-size: var(--text-lg); color: var(--text-primary); margin: 0 0 .25rem; }
    .ad__desc { color: var(--text-secondary); font-size: var(--text-sm); margin: 0; line-height: 1.5; max-width: 28rem; }
    .ad__source { font-family: var(--font-mono); font-size: .65rem; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary); margin-top: .35rem; }
    .ad__reward { font-family: var(--font-display); font-size: 1.5rem; color: var(--amber-soft); white-space: nowrap; }
    .ad__reward small { font-family: var(--font-mono); font-size: .55em; color: var(--text-tertiary); margin-left: .15rem; }
    .ad__meta { display: grid; gap: .15rem; text-align: right; font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); white-space: nowrap; }
    .ad__cta { display: inline-flex; align-items: center; gap: .4rem; padding: .55rem 1rem; border-radius: var(--radius-md); background: var(--amber); color: #1a0e00; font-weight: 500; text-decoration: none; font-size: var(--text-sm); }
    .ad__cta:hover { background: var(--amber-soft); color: #1a0e00; }
    .ad__cta--disabled { background: var(--bg-elev); color: var(--text-tertiary); cursor: not-allowed; pointer-events: none; }
    @media (max-width: 640px) {
        .ad { grid-template-columns: 1fr; gap: .75rem; }
        .ad__meta { text-align: left; }
    }

    .empty { background: var(--bg-panel); border: 1px dashed var(--border-strong); border-radius: var(--radius-lg); padding: 3rem 1.5rem; text-align: center; color: var(--text-tertiary); }
    .empty h2 { font-family: var(--font-display); font-size: 1.5rem; color: var(--text-secondary); font-weight: 400; margin: 0 0 .5rem; }
</style>
<?php $__env->stopPush(); ?>

<?php $__env->startSection('content'); ?>
<section class="ptc">
    <header class="ptc__head">
        <span class="meta">/ paid-to-click</span>
        <h1>Pick an <em>ad</em>.</h1>
        <p style="color: var(--text-secondary); margin: 0;">Each ad pays the listed reward after a fully-watched countdown and one captcha solve. Daily limits reset at midnight UTC.</p>
    </header>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($ads->isEmpty()): ?>
        <div class="empty">
            <h2>No ads available right now.</h2>
            <p>Check back shortly — or <a href="<?php echo e(route('advertise.create')); ?>" style="color: var(--amber-soft); text-decoration: underline;">launch your own campaign</a> to start filling the inventory.</p>
        </div>
    <?php else: ?>
        <div class="ad-list">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $ads; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $ad): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <?php
                    $left = max(0, (int) $ad->daily_limit_per_user - (int) ($usedToday[$ad->id] ?? 0));
                    $exhausted = $left <= 0;
                ?>
                <article class="ad <?php echo e($exhausted ? 'exhausted' : ''); ?>">
                    <div>
                        <h3 class="ad__title"><?php echo e($ad->title); ?></h3>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($ad->description): ?>
                            <p class="ad__desc"><?php echo e($ad->description); ?></p>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        <p class="ad__source"><?php echo e($ad->source); ?> · <?php echo e($ad->duration_sec); ?>s · <?php echo e($ad->daily_limit_per_user); ?>/day</p>
                    </div>
                    <div class="ad__meta">
                        <div class="ad__reward"><?php echo e(number_format($ad->reward_sat)); ?><small>sat</small></div>
                        <div><?php echo e($left); ?>/<?php echo e($ad->daily_limit_per_user); ?> left today</div>
                    </div>
                    <div>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($exhausted): ?>
                            <span class="ad__cta ad__cta--disabled">Done for today</span>
                        <?php else: ?>
                            
                            <a href="<?php echo e(route('ptc.view', ['id' => $ad->id])); ?>" target="_blank" rel="noopener" class="ad__cta">Watch <span aria-hidden="true">→</span></a>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                </article>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</section>

<?php $__env->startPush('body'); ?>
<script>
(() => {
    // The viewer page (/ptc/{id}) broadcasts when a reward is claimed so this
    // index can refresh remaining-today counts without the user reloading manually.
    // Two channels for resilience: BroadcastChannel where supported, storage
    // events as the universal fallback.
    let reloading = false;
    const reload = () => {
        if (reloading) return;
        reloading = true;
        // small delay so the viewer's success toast is visible before we churn
        setTimeout(() => location.reload(), 600);
    };
    try {
        if ('BroadcastChannel' in window) {
            const ch = new BroadcastChannel('satpeek-ptc');
            ch.addEventListener('message', (e) => {
                if (e?.data?.type === 'view-completed') reload();
            });
        }
    } catch {}
    window.addEventListener('storage', (e) => {
        if (e.key === 'satpeek:ptc:view-completed' && e.newValue) reload();
    });
})();
</script>
<?php $__env->stopPush(); ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/resources/views/ptc/index.blade.php ENDPATH**/ ?>