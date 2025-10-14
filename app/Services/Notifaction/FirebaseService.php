<?php

namespace App\Services;

use Google\Client;
use GuzzleHttp\Client as HttpClient;

class FirebaseService
{
    protected $projectId;
    protected $client;
    protected $httpClient;

    public function __construct()
    {
        $this->projectId = 'denthub-e6b7a';
        $this->client = new Client();
        $this->client->setAuthConfig(base_path('public/firebase/denthub-e6b7a-firebase-adminsdk-fbsvc-c84a0b9631.json'));
        $this->client->addScope('https://www.googleapis.com/auth/firebase.messaging');

        $this->httpClient = new HttpClient();
    }

    protected function getAccessToken()
    {
        $token = $this->client->fetchAccessTokenWithAssertion();
        return $token['access_token'];
    }

    public function sendNotification($deviceToken, $title, $body, $data = [])
    {
        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";
        $accessToken = $this->getAccessToken();

        $message = [
            "message" => [
                "token" => $deviceToken,
                "notification" => [
                    "title" => $title,
                    "body" => $body,
                ],
                "data" => $data,
            ]
        ];

        $response = $this->httpClient->post($url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => $message,
        ]);

        return json_decode($response->getBody(), true);
    }
}
