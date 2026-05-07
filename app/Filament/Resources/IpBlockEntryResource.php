<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\BotDetection\IpDenyList;
use App\Filament\Resources\IpBlockEntryResource\Pages;
use App\Http\Middleware\IpBlocked;
use App\Models\IpBlockEntry;
use App\Services\AdminAuditor;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Operator UI for the IP deny list.
 *
 * Counterpart to the env-driven `BOTSCORE_SHARED_IP_ALLOWLIST`. Each row is
 * one CIDR or single-IP entry that gets a hard 403 from the global
 * {@see IpBlocked} middleware. The use case is the
 * on-call operator response: an attacker is hitting the platform RIGHT
 * NOW, the operator pastes the source IP / range here, and the next
 * request from that address gets rejected at the perimeter without any
 * code change or redeploy.
 *
 * Every create/delete writes an `admin_audit_log` row via AdminAuditor
 * and busts the IpDenyList cache so the change takes effect on the
 * very next request, not the next 30 s tick. Edits are intentionally
 * NOT supported — change-the-CIDR is a delete + re-create so the audit
 * log reflects the exact addresses ever blocked rather than a mutating
 * row history.
 */
class IpBlockEntryResource extends Resource
{
    protected static ?string $model = IpBlockEntry::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'IP block list';

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Schemas\Components\Section::make('Block entry')->schema([
                Forms\Components\TextInput::make('cidr')
                    ->label('IP or CIDR')
                    ->required()
                    ->maxLength(64)
                    ->placeholder('1.2.3.4 or 1.2.3.0/24 or 2001:db8::/32')
                    ->helperText('Single address or CIDR prefix. Both IPv4 and IPv6 supported.')
                    // Same regex shape IpAllowlist accepts — single IP or `<ip>/<bits>`.
                    // Keeps malformed input out of the table; the runtime matcher is
                    // already tolerant but a typo'd row would silently never match.
                    ->rules([
                        function () {
                            return function (string $attribute, mixed $value, \Closure $fail): void {
                                if (! is_string($value) || $value === '') {
                                    $fail('Provide an IP or CIDR.');

                                    return;
                                }
                                if (str_contains($value, '/')) {
                                    [$prefix, $bits] = explode('/', $value, 2);
                                    if (! filter_var($prefix, FILTER_VALIDATE_IP) || ! ctype_digit($bits)) {
                                        $fail('Not a valid CIDR.');

                                        return;
                                    }
                                    $bits = (int) $bits;
                                    $max = filter_var($prefix, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 32 : 128;
                                    if ($bits < 0 || $bits > $max) {
                                        $fail("Prefix length must be 0–{$max} for that address family.");
                                    }
                                } elseif (! filter_var($value, FILTER_VALIDATE_IP)) {
                                    $fail('Not a valid IP address.');
                                }
                            };
                        },
                    ]),
                Forms\Components\Textarea::make('note')
                    ->rows(2)
                    ->maxLength(500)
                    ->helperText('Why this entry exists — incident ID, ticket link, "scraping /shortlinks 100 r/s on 2026-05-07", etc.'),
            ])->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('cidr')
                    ->fontFamily('mono')
                    ->copyable()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('note')
                    ->wrap()
                    ->limit(80)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('createdByAdmin.username')
                    ->label('Added by')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->actions([
                Actions\DeleteAction::make()
                    ->after(function (IpBlockEntry $record): void {
                        AdminAuditor::record('ip_block.delete', $record, [
                            'cidr' => $record->cidr,
                            'note' => $record->note,
                        ]);
                        IpDenyList::flush();
                    }),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()
                        ->after(fn () => IpDenyList::flush()),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('createdByAdmin:id,username');
    }

    /**
     * Edit is disabled by design — change-the-CIDR is a delete + re-add
     * so the audit log reflects the exact set of addresses ever blocked,
     * never a mutating row history. Filament still uses the form for
     * the create page.
     */
    public static function canEdit($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIpBlockEntries::route('/'),
            'create' => Pages\CreateIpBlockEntry::route('/create'),
        ];
    }

    /**
     * Stamp the creator + audit log + cache flush. Wired here rather
     * than on a model observer so the admin context (Auth::id) is
     * present — observers fire from any context (cron, queue worker)
     * where Auth::id() is null.
     */
    public static function recordCreated(IpBlockEntry $entry): void
    {
        $entry->forceFill(['created_by_admin_id' => Auth::id()])->save();
        AdminAuditor::record('ip_block.create', $entry, [
            'cidr' => $entry->cidr,
            'note' => $entry->note,
        ]);
        IpDenyList::flush();
    }
}
