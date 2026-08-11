<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_redirects_guests_to_login()
    {
        User::factory()->create();

        $response = $this->get(route('home'));

        $response->assertRedirect(route('login'));
    }
}
