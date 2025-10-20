<?php

namespace App\Services\Notification;

use Google\Client;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Support\Facades\Log;

class FirebaseService
{
    protected string $credentialsPath = 'app/firebase/denthub-d6def-firebase-adminsdk-fbsvc-f4af9caf93.json';
    protected string $projectId = 'denthub-d6def';

    public function send(string $title, string $body, string $token)
    {
        try {
            $client = new Client();
            $client->setAuthConfig(storage_path($this->credentialsPath));
            $client->addScope('https://www.googleapis.com/auth/firebase.messaging');

            $accessToken = $client->fetchAccessTokenWithAssertion()['access_token'];

            $http = new HttpClient();
            $response = $http->post(
                "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send",
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'message' => [
                            'token' => $token,
                            'notification' => [
                                'title' => $title,
                                'body'  => $body,
                            ],
                        ],
                    ],
                ]
            );

            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            Log::error('Firebase Notification Error: ' . $e->getMessage());
            return false;
        }
    }
}
