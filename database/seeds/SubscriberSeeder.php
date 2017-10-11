<?php

use Common\Models\Subscriber;
use Illuminate\Database\Seeder;

class SubscriberSeeder extends Seeder
{

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Subscriber::truncate();
        factory(Subscriber::class, 10)->create();
    }
}