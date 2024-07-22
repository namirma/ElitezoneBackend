<?php

use App\Models\Role;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});


//Route::get('/role',function (\Illuminate\Http\Request $request){

//    $owner = Role::create([
//        'name' => 'owner',
//        'display_name' => 'Project Owner', // optional
//        'description' => 'User is the owner of a given project', // optional
//    ]);
//
//    $admin = Role::create([
//        'name' => 'admin',
//        'display_name' => 'User Administrator', // optional
//        'description' => 'User is allowed to manage and edit other users', // optional
//    ]);
//    dd(\App\Models\Role::find(2));
//    /** @var \App\Models\User $user */
//    $user=\App\Models\User::find(2);
//    $user->addRole(\App\Models\Role::find(2));
//    dd($user->roles());
//});
