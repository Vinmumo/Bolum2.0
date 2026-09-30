<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PostmanCollectionTest extends TestCase
{
    public function test_collection_covers_every_application_route_and_http_method(): void
    {
        $collection = json_decode(file_get_contents(base_path('docs/postman/Bolum.postman_collection.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Bolum API', $collection['info']['name']);
        $requests = [];
        $walk = function (array $items) use (&$walk, &$requests) {
            foreach ($items as $item) {
                if (isset($item['item'])) {
                    $walk($item['item']);
                } else {
                    $url = explode('?', str_replace('{{base_url}}', '', $item['request']['url']))[0];
                    $requests[] = $item['request']['method'].' '.preg_replace('/\{\{[^}]+\}\}/', '{}', trim($url, '/'));
                }
            }
        };
        $walk($collection['item']);

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->getActionName(), 'App\\') && ! in_array($route->uri(), ['/', 'profile', 'up', 'sanctum/csrf-cookie'])) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $uri = preg_replace('/\{[^}]+\}/', '{}', trim($route->uri(), '/'));
                $this->assertContains($method.' '.$uri, $requests, 'Missing Postman request: '.$method.' '.$route->uri());
            }
        }
    }
}
