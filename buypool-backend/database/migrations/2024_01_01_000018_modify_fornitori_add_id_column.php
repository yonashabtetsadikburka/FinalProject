<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fait évoluer "fornitori" pour permettre des fiches fournisseur sans compte
 * utilisateur lié :
 *  - nouvelle PK auto-incrémentée "id" (identité de la fiche)
 *  - "id_utente" devient nullable + unique (NULL = fiche libre, à activer/collegare)
 *  - les lignes existantes sont backfillées avec id = id_utente pour préserver
 *    la compatibilité des données et des endpoints publics (/fornitori/{id}).
 *
 * Les instructions DDL (MySQL) sont commitées implicitement une à une même si
 * la migration échoue ensuite, et ont pu être exécutées partiellement lors
 * d'un premier passage : la méthode est donc écrite pour REPRENDRE proprement
 * l'état intermédiaire (FK déjà retirée, id déjà PK, id_utente encore NOT NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Retire la FK id_utente -> utenti (déjà partie absent) : la suppression
        //    explicite est nécessaire pour pouvoir modifier la colonne en dessous.
        if ($this->foreignKeyExists('fornitori', 'fornitori_id_utente_foreign')) {
            Schema::table('fornitori', function (Blueprint $table) {
                $table->dropForeign(['id_utente']);
            });
        }

        // 2. Bascule de la PK : de id_utente vers id.
        //    En cas de reprise, la PK est déjà sur "id" : ne rien faire.
        if (!$this->primaryIsOn('fornitori', 'id')) {
            Schema::table('fornitori', function (Blueprint $table) {
                $table->dropPrimary();
            });
            DB::statement('ALTER TABLE fornitori ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST, ADD PRIMARY KEY (id)');
        }

        // 3. Backfill : id = id_utente pour les lignes existantes, sauf si déjà fait.
        //    Un offset élevé est d'abord appliqué pour éviter les collisions MySQL
        //    ("Duplicate entry") quand l'UPDATE écrase des ids encore en place.
        $nonBackfilled = (int) DB::table('fornitori')
            ->whereNotNull('id_utente')
            ->whereColumn('id', '!=', 'id_utente')
            ->count();

        if ($nonBackfilled > 0) {
            DB::statement('UPDATE fornitori SET id = id + 1000000');
            DB::statement('UPDATE fornitori SET id = id_utente WHERE id_utente IS NOT NULL');
        }

        // 4. id_utente nullable + unique.
        $nu = (array) DB::select('SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?', [config('database.connections.mysql.database'), 'fornitori', 'id_utente']);
        if (!$nu || ($nu[0]->IS_NULLABLE ?? 'NO') !== 'YES') {
            Schema::table('fornitori', function (Blueprint $table) {
                $table->unsignedBigInteger('id_utente')->nullable()->unique()->change();
            });
        }

        // 5. Recrée la FK id_utente -> utenti si absente.
        if (!$this->foreignKeyExists('fornitori', 'fornitori_id_utente_foreign')) {
            Schema::table('fornitori', function (Blueprint $table) {
                $table->foreign('id_utente')->references('id')->on('utenti')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        if ($this->foreignKeyExists('fornitori', 'fornitori_id_utente_foreign')) {
            Schema::table('fornitori', function (Blueprint $table) {
                $table->dropForeign(['id_utente']);
            });
        }

        if ($this->columnHasKey('fornitori', 'id_utente')) {
            Schema::table('fornitori', function (Blueprint $table) {
                $table->dropUnique(['id_utente']);
            });
        }

        if ($this->primaryIsOn('fornitori', 'id')) {
            Schema::table('fornitori', function (Blueprint $table) {
                $table->dropPrimary();
            });
        }

        // Restauration : les fiches non liées (id_utente NULL) sont supprimées
        // car l'ancien schéma exige une fiche liée à un compte (PK NOT NULL).
        DB::statement('DELETE FROM fornitori WHERE id_utente IS NULL');

        if ($this->columnExists('fornitori', 'id')) {
            DB::statement('ALTER TABLE fornitori DROP COLUMN id');
        }

        Schema::table('fornitori', function (Blueprint $table) {
            $table->unsignedBigInteger('id_utente')->nullable(false)->change();
            $table->primary('id_utente');
        });

        if (!$this->foreignKeyExists('fornitori', 'fornitori_id_utente_foreign')) {
            Schema::table('fornitori', function (Blueprint $table) {
                $table->foreign('id_utente')->references('id')->on('utenti')->onDelete('cascade');
            });
        }
    }

    private function tableExists(string $t): bool
    {
        return Schema::hasTable($t);
    }

    private function columnExists(string $t, string $c): bool
    {
        $db = config('database.connections.mysql.database');

        return (bool) DB::select('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1', [$db, $t, $c]);
    }

    private function primaryIsOn(string $t, string $c): bool
    {
        $db = config('database.connections.mysql.database');
        $rows = DB::select('SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND CONSTRAINT_NAME=? AND TABLE_NAME=?', [$db, 'PRIMARY', $t]);

        return count($rows) === 1 && $rows[0]->COLUMN_NAME === $c;
    }

    private function foreignKeyExists(string $t, string $name): bool
    {
        $db = config('database.connections.mysql.database');
        $rows = DB::select('SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_NAME=? LIMIT 1', [$db, $t, $name]);

        return (bool) $rows;
    }

    private function columnHasKey(string $t, string $c): bool
    {
        $db = config('database.connections.mysql.database');
        $rows = DB::select('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1', [$db, $t, $c]);

        return (bool) $rows;
    }
};