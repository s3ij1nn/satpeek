<?php

namespace App\Http\Controllers;

use App\Mail\AdSubmittedEmail;
use App\Models\BalanceLedger;
use App\Models\PtcAd;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdvertiseController extends Controller
{
    public function index(Request $request): View
    {
        $ads = PtcAd::where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('advertise.index', [
            'ads' => $ads,
            'cfg' => config('satpeek.ads'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('advertise.create', [
            'cfg' => config('satpeek.ads'),
            'balance' => (int) $request->user()->balance_sat,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $cfg = config('satpeek.ads');
        $user = $request->user();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:500'],
            'target_url' => ['required', 'url', 'max:500'],
            // Default `window` because most third-party URLs refuse iframe
            // embedding (X-Frame-Options / CSP). Iframe stays opt-in.
            'display_mode' => ['nullable', 'in:'.implode(',', PtcAd::DISPLAY_MODES)],
            'reward_sat' => ['required', 'integer', "min:{$cfg['reward_min_sat']}", "max:{$cfg['reward_max_sat']}"],
            'duration_sec' => ['required', 'integer', "min:{$cfg['duration_min_sec']}", "max:{$cfg['duration_max_sec']}"],
            'daily_limit_per_user' => ['required', 'integer', 'min:1', 'max:10'],
            'total_views_purchased' => ['required', 'integer', "min:{$cfg['views_min']}", "max:{$cfg['views_max']}"],
            'submission_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $costPerView = self::computeCost((int) $validated['reward_sat']);
        $totalCost = $costPerView * (int) $validated['total_views_purchased'];

        if ($user->balance_sat < $totalCost) {
            return back()
                ->withInput()
                ->withErrors(['balance' => 'Insufficient balance — campaign costs '.number_format($totalCost).' sat, you have '.number_format($user->balance_sat).' sat.']);
        }

        $autoApprove = (bool) $cfg['auto_approve'];
        $status = $autoApprove ? 'approved' : 'pending_review';
        $isActive = $autoApprove;
        $approvedAt = $autoApprove ? Carbon::now() : null;

        $ad = DB::transaction(function () use ($user, $validated, $costPerView, $totalCost, $status, $isActive, $approvedAt) {
            $ad = PtcAd::create([
                'user_id' => $user->id,
                'source' => 'user',
                'external_id' => 'user-'.$user->id.'-'.Str::lower(Str::random(8)),
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'target_url' => $validated['target_url'],
                'display_mode' => $validated['display_mode'] ?? 'window',
                'reward_sat' => $validated['reward_sat'],
                'cost_per_view_sat' => $costPerView,
                'total_views_purchased' => $validated['total_views_purchased'],
                'views_remaining' => $validated['total_views_purchased'],
                'total_cost_sat' => $totalCost,
                'duration_sec' => $validated['duration_sec'],
                'daily_limit_per_user' => $validated['daily_limit_per_user'],
                'is_active' => $isActive,
                'status' => $status,
                'submission_notes' => $validated['submission_notes'] ?? null,
                'approved_at' => $approvedAt,
            ]);
            // Charge the advertiser immediately — funds are reserved for the
            // campaign budget. Rejection paths below issue a full refund.
            BalanceLedger::create([
                'user_id' => $user->id,
                'delta_sat' => -1 * $totalCost,
                'reason' => 'ad_funding',
                'reference_type' => PtcAd::class,
                'reference_id' => $ad->id,
            ]);
            $user->decrement('balance_sat', $totalCost);

            return $ad;
        });

        try {
            Mail::to($user->email)->queue(new AdSubmittedEmail($ad->fresh()));
        } catch (\Throwable $e) {
            Log::warning('ad submitted mail failed', ['ad' => $ad->id, 'err' => $e->getMessage()]);
        }

        $msg = $autoApprove
            ? "Campaign live — {$ad->total_views_purchased} views budgeted at {$ad->reward_sat} sat each."
            : "Campaign submitted for review. We'll email you once it's approved (typically within 24 hours).";

        return redirect()->route('advertise.show', ['id' => $ad->id])->with('status', $msg);
    }

    public function show(Request $request, int $id): View
    {
        $ad = PtcAd::where('user_id', $request->user()->id)->findOrFail($id);

        return view('advertise.show', ['ad' => $ad]);
    }

    public function edit(Request $request, int $id): View
    {
        $ad = PtcAd::where('user_id', $request->user()->id)->findOrFail($id);

        return view('advertise.edit', ['ad' => $ad]);
    }

    /**
     * Self-serve update for the advertiser's own campaign.
     *
     * Editable: title, description, display_mode, daily_limit_per_user, is_active.
     * Locked:   target_url (would change what users click without re-review),
     *           reward_sat / total_views_purchased / cost_per_view_sat
     *           (budget already paid in upfront), status / approved_at
     *           (admin-controlled review state). Pausing is expressed via
     *           is_active=false and intentionally does NOT mutate `status` —
     *           an approved-but-paused ad stays approved when the user resumes.
     */
    public function update(Request $request, int $id): RedirectResponse
    {
        $ad = PtcAd::where('user_id', $request->user()->id)->findOrFail($id);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:500'],
            'display_mode' => ['required', 'in:'.implode(',', PtcAd::DISPLAY_MODES)],
            'daily_limit_per_user' => ['required', 'integer', 'min:1', 'max:10'],
            'is_active' => ['nullable'],
        ]);

        $ad->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'display_mode' => $validated['display_mode'],
            'daily_limit_per_user' => (int) $validated['daily_limit_per_user'],
            'is_active' => filter_var($validated['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ]);

        return redirect()
            ->route('advertise.show', ['id' => $ad->id])
            ->with('status', 'Campaign updated.');
    }

    public static function computeCost(int $rewardSat): int
    {
        $pct = (int) config('satpeek.ads.commission_pct', 25);

        return (int) ceil($rewardSat * (100 + $pct) / 100);
    }
}
