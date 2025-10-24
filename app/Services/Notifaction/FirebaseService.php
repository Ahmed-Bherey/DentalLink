<?php

namespace App\Services\Notifaction;

use Google\Client;
use GuzzleHttp\Client as HttpClient;

class FirebaseService
{
    public function send($title, $body, $token, $type)
    {
        $client = new Client();
        $client->setAuthConfig(storage_path('app/firebase/denthub-52578-firebase-adminsdk-fbsvc-eb760c9c00.json'));
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging');

        $accessToken = $client->fetchAccessTokenWithAssertion()['access_token'];

        $http = new HttpClient();
        $response = $http->post(
            'https://fcm.googleapis.com/v1/projects/denthub-52578/messages:send',
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
                        'data' => [
                            'type' => $type,
                        ],
                    ],
                ],
            ]
        );

        return json_decode($response->getBody(), true);
    }
}
