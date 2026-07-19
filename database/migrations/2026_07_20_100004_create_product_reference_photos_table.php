<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-uploaded product reference photos (sub-project C, spec §4.1) —
     * the catalog side of visual product matching. Tenant-owned (ADR-0019)
     * per the reach_results composite-FK pattern; UNIQUE (id, tenant_id) so
     * product_photo_embeddings can compose-FK to it. Blob deletion is
     * app-managed (collect paths in-transaction, rows cascade, files after
     * commit — house order); cap/mime/size rules live in the upload
     * component, not the DDL.
     */
    public function up(): void
    {
        Schema::create('product_reference_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('storage_disk', 50);
            // tenants/{tenant}/product-photos/{product}/{uuid}.{ext} on the private media disk.
            $table->string('storage_path', 500);
            $table->string('view_label', 20)->nullable();
            // sha256 of the stored bytes.
            $table->char('checksum', 64);
            // Best-effort image metadata (getimagesize; null when undecodable).
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Per-product listing + the T10 photo-count badge.
            $table->index('product_id');
        });

        // Closed label set mirroring PhotoViewLabel; NULL passes (label optional).
        DB::statement("ALTER TABLE product_reference_photos ADD CONSTRAINT product_reference_photos_view_label_check CHECK (view_label IN ('front', 'back', 'side', 'packaging', 'in_use', 'other'))");

        // Composite-parent target so product_photo_embeddings can FK (photo_id, tenant_id).
        DB::statement('ALTER TABLE product_reference_photos ADD CONSTRAINT product_reference_photos_id_tenant_unique UNIQUE (id, tenant_id)');

        // The photo must belong to the same tenant as its product; CASCADE on
        // the composite too so a product delete is order-independent across
        // both FKs. Uploader stamp follows the reach_configurations audit
        // pattern (plain FK null-on-delete + composite tenant check).
        DB::statement('ALTER TABLE product_reference_photos ADD CONSTRAINT product_reference_photos_product_id_tenant_fk FOREIGN KEY (product_id, tenant_id) REFERENCES products (id, tenant_id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE product_reference_photos ADD CONSTRAINT product_reference_photos_uploaded_by_tenant_fk FOREIGN KEY (uploaded_by, tenant_id) REFERENCES users (id, tenant_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reference_photos');
    }
};
