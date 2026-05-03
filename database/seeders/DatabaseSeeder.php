<?php

namespace Database\Seeders;

use App\Models\InternalArticle;
use App\Models\PtcAd;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Admin credentials are read from .env so the production deployment
        // can set strong values without touching code. The defaults are dev-
        // only and intentionally weak — they're fine for local Docker but
        // MUST be overridden in production.
        $adminEmail = (string) env('ADMIN_EMAIL', 'admin@satpeek.local');
        $adminUsername = (string) env('ADMIN_USERNAME', 'admin');
        $adminPassword = (string) env('ADMIN_PASSWORD', 'admin123');

        $admin = User::firstOrCreate(
            ['email' => $adminEmail],
            [
                'username' => $adminUsername,
                'password' => Hash::make($adminPassword),
                'is_admin' => true,
                'email_verified_at' => Carbon::now(),
                'referral_code' => 'ADMIN001',
            ]
        );

        // If the admin row already exists but the env password changed, sync
        // it. Use forceFill so the hashed password isn't double-hashed by the
        // 'password' => 'hashed' cast on update.
        if (! $admin->wasRecentlyCreated && Hash::needsRehash($admin->password)) {
            $admin->forceFill(['password' => Hash::make($adminPassword)])->save();
        }
        // Make sure the admin flag stays on even if it was toggled off accidentally.
        if (! $admin->is_admin) {
            $admin->forceFill(['is_admin' => true])->save();
        }

        PtcAd::firstOrCreate(
            ['source' => 'mock', 'external_id' => 'mock-ptc-1'],
            [
                'title' => 'Local mock PTC ad (15s)',
                'description' => 'Seeded for development.',
                'target_url' => 'https://example.com/mock-ptc-1',
                'reward_sat' => 5,
                'duration_sec' => 15,
                'daily_limit_per_user' => 5,
                'is_active' => true,
                'status' => 'approved',
            ]
        );

        // Sample internal article so /read-articles has something to show
        // on a fresh install. The legacy `Shortlink` row that used to
        // live here was dead — post-v0.6.0 the /shortlinks surface reads
        // from operator-managed ShortlinkProviderCredential rows that the
        // operator pastes API tokens into via /admin/shortlink-provider-credentials.
        InternalArticle::firstOrCreate(
            ['title' => 'Welcome to SatPeek'],
            [
                'body' => "## Welcome\n\nThis is a sample read-and-earn article seeded on install.\n\nReplace it from `/admin/internal-articles` once you log in. Articles render as Markdown — supported tags: headings, lists, **bold**, *italic*, [links](https://example.com), `code`, and > blockquotes.\n\nWhen the read-time countdown finishes, the captcha unlocks and the user can claim the reward.",
                'source_attribution' => 'SatPeek team',
                'reward_sat' => 5,
                'read_seconds' => 30,
                'daily_limit_per_user' => 3,
                'is_active' => true,
            ]
        );
    }
}
