<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utilisateurs', function (Blueprint $t) {
            // Une ville de travail, et non un texte libre : elle doit pouvoir
            // être comparée de façon fiable avec celle de la boutique.
            $t->unsignedInteger('ville_id')->nullable()->after('pays_id');
            $t->foreign('ville_id')->references('id')->on('villes')->nullOnDelete();
            $t->index(['ville_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('utilisateurs', function (Blueprint $t) {
            $t->dropForeign(['ville_id']);
            $t->dropIndex(['ville_id', 'role']);
            $t->dropColumn('ville_id');
        });
    }
};
