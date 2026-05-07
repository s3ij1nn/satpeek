<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-managed IP deny list.
 *
 * Counterpart to BOTSCORE_SHARED_IP_ALLOWLIST: where the allowlist tells
 * SharedIpSignal "this NAT is OK, don't flag cross-account use", this
 * deny list tells the global middleware "any request from this address
 * gets a 403, no exceptions". Used by the on-call operator to immediately
 * cut off an active attacker without waiting for ScoreEngine to escalate
 * the user's tier and certainly without rolling a code change.
 *
 * Each row is one CIDR or single-IP entry plus a free-text note (so the
 * row remembers WHY it was added — the matching ticket / incident ID /
 * ASN under attack). `created_by_admin_id` references the User who
 * blocked it, joined to AdminAuditLog rows for full provenance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_block_entries', function (Blueprint $table) {
            $table->id();
            // Either a single IP (`1.2.3.4`) or a CIDR (`1.2.3.0/24`,
            // `2001:db8::/32`). 64 chars is plenty for any valid IPv6
            // CIDR plus the prefix length.
            $table->string('cidr', 64)->unique();
            // Operator-supplied free-text rationale. Required at the
            // form layer (not at the schema layer — historical imports
            // from a CSV could omit it; nullable keeps that path open).
            $table->string('note', 500)->nullable();
            // The admin who created the row. Nullable so a manual
            // INSERT from psql / a seeder still succeeds, but the
            // Filament form forces it via auth()->id().
            $table->foreignId('created_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Lookup pattern is "is this IP blocked right now" — sorted
            // by created_at desc in the admin list, matched by exact
            // cidr in the allowlist resolver. Cache layer reads the
            // full table so no per-query index hot path matters here.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_block_entries');
    }
};
