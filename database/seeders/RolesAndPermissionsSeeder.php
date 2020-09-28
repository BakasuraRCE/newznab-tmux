<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeders.
     *
     * @return void
     */
    public function run()
    {
        // Reset cached roles and permissions
        app('cache')->forget('spatie.permission.cache');

        // create permissions
        Permission::create(['name' => 'preview']);
        Permission::create(['name' => 'hideads']);
        Permission::create(['name' => 'edit release']);
        Permission::create(['name' => 'view console']);
        Permission::create(['name' => 'view movies']);
        Permission::create(['name' => 'view audio']);
        Permission::create(['name' => 'view pc']);
        Permission::create(['name' => 'view tv']);
        Permission::create(['name' => 'view adult']);
        Permission::create(['name' => 'view books']);
        Permission::create(['name' => 'view other']);

        // create roles and assign created permissions

        $roleUser = Role::create(
            [
                'name' => 'User',
                'apirequests' => 10,
                'downloadrequests' => 10,
                'defaultinvites' => 1,
                'isdefault' => 1,
                'donation' => 0,
                'addyears' => 0,
                'rate_limit' => 60,
            ]
        );

        $roleUser->save();
        $roleUser->givePermissionTo(['preview', 'view console', 'view movies', 'view audio', 'view pc', 'view tv', 'view adult', 'view books']);

        $roleAdmin = Role::create(
            [
                'name' => 'Admin',
                'apirequests' => 1000,
                'downloadrequests' => 1000,
                'defaultinvites' => 1000,
                'isdefault' => 0,
                'donation' => 0,
                'addyears' => 0,
                'rate_limit' => 60,
            ]
        );

        $roleAdmin->save();
        $roleAdmin->givePermissionTo(Permission::all());

        $roleDisabled = Role::create(
            [
                'name' => 'Disabled',
                'apirequests' => 0,
                'downloadrequests' => 0,
                'defaultinvites' => 0,
                'isdefault' => 0,
                'donation' => 0,
                'addyears' => 0,
                'rate_limit' => 0,
            ]
        );

        $roleDisabled->save();

        $roleMod = Role::create(
            [
                'name' => 'Moderator',
                'apirequests' => 1000,
                'downloadrequests' => 1000,
                'defaultinvites' => 1000,
                'isdefault' => 0,
                'donation' => 0,
                'addyears' => 0,
                'rate_limit' => 60,
            ]
        );
        $roleMod->save();
        $roleMod->givePermissionTo(Permission::all());

        $roleFriend = Role::create(
            [
                'name' => 'Friend',
                'apirequests' => 100,
                'downloadrequests' => 100,
                'defaultinvites' => 5,
                'isdefault' => 0,
                'donation' => 0,
                'addyears' => 0,
                'rate_limit' => 60,
            ]
        );
        $roleFriend->save();
        $roleFriend->givePermissionTo(['preview', 'hideads', 'view console', 'view movies', 'view audio', 'view pc', 'view tv', 'view adult', 'view books', 'view other']);
    }
}
