<?php

use Modules\CentralUi\app\Http\Livewire\Form\ProxtopServer;

return [
    'version'              => '2.0',
    // class of eloquent model
    'model'                => ProxtopServer::class,
    // update data if exists and data differ (default false)
    'can_update'           => true,
    // if update true only: don't update this fields
    'ignore_update_fields' => [
        'url',
    ],
    // columns to check if data already exists (AND WHERE)
    'uniques'              => ['code'],
    // data rows itself
    'data'                 => [
        //[
        //    'name' => 'NoName',
        //],
    ],
];

