<?php

namespace App\Services\Notifaction;

use Exception;
use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client as HttpClient;

class FirebaseRealtimeService
{
    protected $projectUrl;
    protected $credentialsPath;

    public function __construct()
    {
        $this->projectUrl = 'https://denthub-52578-default-rtdb.firebaseio.com/';
        $this->credentialsPath = storage_path('app/firebase/denthub-52578-firebase-adminsdk-fbsvc-eb760c9c00.json');
    }

    /**
     * Send data to Firebase Realtime Database
     *
     * @param string $path المسار داخل قاعدة البيانات (مثلاً payments/5 أو orders/12)
     * @param array $data البيانات المُرسلة
     */
    public function send(string $path, array $data): bool
    {
        try {
            // توليد Access Token من ملف الخدمة
            $googleClient = new GoogleClient();
            $googleClient->setAuthConfig($this->credentialsPath);
            $googleClient->addScope('https://www.googleapis.com/auth/firebase.database');
            $accessToken = $googleClient->fetchAccessTokenWithAssertion()['access_token'];

            // تجهيز رابط المسار داخل قاعدة البيانات
            $url = rtrim($this->projectUrl, '/') . '/' . ltrim($path, '/') . '.json';

            // إرسال الطلب عبر Guzzle
            $http = new HttpClient();
            $http->put($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => $data,
            ]);

            return true;
        } catch (Exception $e) {
            Log::error('Firebase Realtime Error: ' . $e->getMessage());
            return false;
        }
    }
}