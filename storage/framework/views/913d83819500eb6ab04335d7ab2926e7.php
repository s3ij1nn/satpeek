<?php $__env->startPush('head'); ?>
<style>
    .adv { max-width: 64rem; margin: 0 auto; padding: 3rem 1.5rem; display: grid; gap: 2rem; }
    .adv__head { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; flex-wrap: wrap; }
    .adv__head h1 { font-family: var(--font-display); font-size: var(--display-md); line-height: 1.05; letter-spacing: -.02em; font-weight: 400; margin: .5rem 0 .25rem; }
    .adv__head h1 em { color: var(--amber-soft); font-style: italic; }
    .adv__head .meta { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .14em; }
    .alert--ok { padding: .875rem 1.125rem; border-radius: var(--radius-md); background: rgba(52,211,153,.08); border: 1px solid rgba(52,211,153,.3); color: var(--mint); font-size: var(--text-sm); }

    .row-list { display: grid; gap: .75rem; }
    .row { background: var(--bg-panel); border: 1px solid var(--border-subtle); border-radius: var(--radius-md); padding: 1rem 1.25rem; display: grid; grid-template-columns: 1fr auto auto; gap: 1rem; align-items: center; transition: border-color var(--dur-fast) var(--ease-out-expo); }
    .row:hover { border-color: var(--border-strong); }
    .row__title { color: var(--text-primary); margin: 0 0 .15rem; }
    .row__meta { font-family: var(--font-mono); font-size: .65rem; text-transform: uppercase; letter-spacing: .12em; color: var(--text-tertiary); }
    .row__progress { display: grid; gap: .25rem; min-width: 10rem; text-align: right; }
    .row__progress .num { font-family: var(--font-mono); font-size: var(--text-sm); color: var(--text-secondary); }
    .row__progress .bar { width: 100%; height: 4px; background: var(--bg-elev-2); border-radius: 999px; overflow: hidden; }
    .row__progress .bar > div { height: 100%; background: var(--amber-soft); }
    .row__cta { padding: .45rem .9rem; border-radius: var(--radius-md); background: var(--bg-elev); color: var(--text-primary); font-size: var(--text-sm); text-decoration: none; }
    .row__cta:hover { background: var(--bg-elev-2); color: var(--amber-soft); }
    .status-badge { display: inline-block; padding: .12rem .5rem; border-radius: 4px; font-family: var(--font-mono); font-size: .65rem; text-transform: uppercase; letter-spacing: .1em; margin-left: .35rem; }
    .status-approved   { background: rgba(52,211,153,.12); color: var(--mint); }
    .status-pending_review { background: rgba(252,211,77,.12); color: var(--amber-soft); }
    .status-completed  { background: rgba(103,232,249,.12); color: var(--cyan); }
    .status-rejected   { background: rgba(251,113,133,.12); color: var(--rose); }
    .status-paused     { background: rgba(170,180,194,.12); color: var(--text-secondary); }

    .empty { background: var(--bg-panel); border: 1px dashed var(--border-strong); border-radius: var(--radius-lg); padding: 3rem 1.5rem; text-align: center; color: var(--text-tertiary); }
    .empty h2 { font-family: var(--font-display); font-size: 1.5rem; color: var(--text-secondary); font-weight: 400; margin: 0 0 .5rem; }
    @media (max-width: 640px) { .row { grid-template-columns: 1fr; } .row__progress { text-align: left; } }
</style>
<?php $__env->stopPush(); ?>

<?php $__env->startSection('content'); ?>
<section class="adv">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('status')): ?> <div class="alert--ok"><?php echo e(session('status')); ?></div> <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <header class="adv__head">
        <div>
            <span class="meta">/ advertise</span>
            <h1>Your <em>campaigns</em>.</h1>
            <p style="color: var(--text-secondary); margin: .25rem 0 0;">Pay other SatPeek users in sats to view your link. Cost = reward × <strong style="color: var(--text-primary);"><?php echo e(100 + (int) $cfg['commission_pct']); ?>%</strong> (incl. <?php echo e((int) $cfg['commission_pct']); ?>% platform fee).</p>
        </div>
        <a href="<?php echo e(route('advertise.create')); ?>" class="cta cta--primary">+ New campaign</a>
    </header>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($ads->isEmpty()): ?>
        <div class="empty">
            <h2>No campaigns yet.</h2>
            <p>You can run your own ads using the same balance you earn from viewing. <a href="<?php echo e(route('advertise.create')); ?>" style="color: var(--amber-soft); text-decoration: underline;">Launch your first one →</a></p>
        </div>
    <?php else: ?>
        <div class="row-list">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $ads; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $ad): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <?php
                    $progress = $ad->total_views_purchased > 0
                        ? min(100, max(0, 100 * ($ad->total_views_purchased - $ad->views_remaining) / $ad->total_views_purchased))
                        : 0;
                ?>
                <article class="row">
                    <div>
                        <h3 class="row__title">
                            <?php echo e($ad->title); ?>

                            <span class="status-badge status-<?php echo e($ad->status); ?>"><?php echo e(str_replace('_', ' ', $ad->status)); ?></span>
                        </h3>
                        <div class="row__meta">
                            <?php echo e(number_format($ad->reward_sat)); ?> sat / view · <?php echo e($ad->duration_sec); ?>s · created <?php echo e($ad->created_at->diffForHumans()); ?>

                        </div>
                    </div>
                    <div class="row__progress">
                        <div class="num"><?php echo e(number_format($ad->total_views_purchased - $ad->views_remaining)); ?> / <?php echo e(number_format($ad->total_views_purchased)); ?> views</div>
                        <div class="bar"><div style="width: <?php echo e(number_format($progress, 1)); ?>%;"></div></div>
                    </div>
                    <a href="<?php echo e(route('advertise.show', ['id' => $ad->id])); ?>" class="row__cta">Details →</a>
                </article>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</section>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/resources/views/advertise/index.blade.php ENDPATH**/ ?>