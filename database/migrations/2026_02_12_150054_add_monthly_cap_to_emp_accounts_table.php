<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emp_accounts', function (Blueprint $table) {
            $table->decimal('monthly_cap', 12, 2)->nullable()->after('is_active');
        });

        // Seed initial caps
        DB::table('emp_accounts')->where('name', 'Elariosso')->update(['monthly_cap' => 450000]);
        DB::table('emp_accounts')->where('name', 'Optivest')->update(['monthly_cap' => 400000]);
        DB::table('emp_accounts')->where('name', 'SmartThings Ventures')->update(['monthly_cap' => 300000]);
        DB::table('emp_accounts')->where('name', 'Corellia Ads')->update(['monthly_cap' => 300000]);
        DB::table('emp_accounts')->where('name', 'Lunaro')->update(['monthly_cap' => 300000]);
        DB::table('emp_accounts')->where('name', 'Danieli Soft')->update(['monthly_cap' => 300000]);
    }

    public function down(): void
    {
        Schema::table('emp_accounts', function (Blueprint $table) {
            $table->dropColumn('monthly_cap');
        });
    }
};
