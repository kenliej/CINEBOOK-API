<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiPagesTest extends TestCase
{
    public function test_api_health_endpoint_works(): void
    {
        $response = $this->getJson('/api/health');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'ok');
    }

    public function test_movies_endpoint_returns_list(): void
    {
        $response = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->getJson('/api/movies');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'genres',
                    ],
                ],
            ]);
    }

    public function test_security_headers_are_present(): void
    {
        $response = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->getJson('/api/health');

        $response->assertHeader('x-content-type-options', 'nosniff');
        $response->assertHeader('x-frame-options', 'DENY');
    }

    public function test_favorites_and_transactions_are_user_scoped(): void
    {
        $favoritesResponse = $this->getJson('/api/favorites?email=admin@cinebook.test');
        $favoritesResponse
            ->assertOk()
            ->assertJsonPath('success', true);

        $transactionsResponse = $this->getJson('/api/transactions?email=admin@cinebook.test');
        $transactionsResponse
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_reservation_lifecycle_creates_pending_then_completed_booking(): void
    {
        $createResponse = $this->postJson('/api/reservations/create', [
            'email' => 'admin@cinebook.test',
            'movie_id' => '1',
            'movie_title' => 'Demon Slayer: Infinity Castle',
            'location' => 'SM City Cebu',
            'price' => 350,
            'ticket_count' => 2,
            'customer_name' => 'Jane Doe',
            'phone' => '+63 912 345 6789',
            'gender' => 'Female',
        ]);

        $createResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'Pending');

        $transactionId = $createResponse->json('data.id');

        $pendingResponse = $this->getJson('/api/transactions?email=admin@cinebook.test&status=Pending');
        $pendingResponse->assertOk()->assertJsonPath('success', true);

        $completeResponse = $this->postJson('/api/reservations/' . $transactionId . '/complete', [
            'payment_method' => 'GCash',
            'seats' => ['B12', 'B13'],
        ]);

        $completeResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'Completed');

        $this->assertTrue(str_starts_with((string) $completeResponse->json('data.ref_code'), 'CB-'));
    }
}
