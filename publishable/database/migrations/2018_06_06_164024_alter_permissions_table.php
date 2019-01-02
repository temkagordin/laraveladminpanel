<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use LaravelAdminPanel\Models\Permission;

class AddSortToDataRowsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        //Create new sorting permission

        Schema::table('data_rows', function (Blueprint $table) {
            $table->boolean('sort')->default(0)->after('delete');
        });
        //save id permission
        $permission_list = [];
        //create new permission
        $permissions = Permission::whereNotNull('slug')->groupBy('slug')->get(['slug']);
        foreach ($permissions as $permission) {
            $new_permission = new Permission();
            $new_permission->key = 'sort_' . $permission->slug;
            $new_permission->slug = $permission->slug;
            $new_permission->save();
            $permission_list[] = $new_permission->id;
        }
        Schema::create('data_sort', function (Blueprint $table) {
            $table->unsignedInteger('data_type_id');
            $table->unsignedInteger('data_row_id');
            $table->unsignedInteger('order');
            $table->foreign('data_type_id')
                ->references('id')->on('data_types')
                ->onDelete('cascade');
            $table->foreign('data_row_id')
                ->references('id')->on('data_rows')
                ->onDelete('cascade');
        });
        /*     //insert permission to table
             foreach ($permission_list as $permission_role){

             }*/

    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('data_rows', function (Blueprint $table) {
            $table->dropColumn('sort');
        });
    }
}
