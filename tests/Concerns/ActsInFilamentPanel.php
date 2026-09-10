<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Filament\Facades\Filament;

/**
 * Enter a Filament panel before driving one of its Livewire components.
 *
 * An HTTP request into `/admin/*` sets the current panel from the panel's
 * route middleware. `Livewire::test()` bypasses routing entirely, so the
 * component boots with no current panel unless the test says which one.
 *
 * Under Filament 4 / Livewire 3 that was survivable — the default panel was
 * picked up implicitly. Under Filament 5 / Livewire 4 it isn't: the initial
 * render never reaches `dehydrate`, so `Testable::instance()` stays null,
 * `set()` writes nowhere, and `call()` silently does nothing. Tests then fail
 * with misleading messages ("Component has no errors", "Attempt to read
 * property ... on null") that look like application bugs.
 *
 * Call this from `setUp()` in any test that reaches for `Livewire::test()` on
 * a panel page or resource page.
 */
trait ActsInFilamentPanel
{
    protected function enterFilamentPanel(string $panelId = 'admin'): void
    {
        Filament::setCurrentPanel(Filament::getPanel($panelId));
    }
}
