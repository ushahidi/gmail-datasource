<?php

namespace Ushahidi\Gmail;

use Exception;
use Google_Client;
use Google_Service_Gmail;
use Google_Service_Gmail_Profile;
use Ushahidi\Gmail\Contracts\TokenStorage;

class GmailConnector extends Google_Client
{
    public $user;

    public $service;

    protected $configuration;

    protected $storage;

    public function __construct($config = null, $user = null)
    {
        $this->configuration = $config;

        $this->user = $user;

        $config = $this->getGmailConfig();

        parent::__construct($config);

        $this->setAccessType($config['access_type']);

        $this->setApprovalPrompt($config['approval_prompt']);

        $this->setScopes(Google_Service_Gmail::MAIL_GOOGLE_COM);

        if ($user && $this->hasToken()) {
            $this->refreshTokenIfNeeded();
        }
    }

    public function getGmailConfig()
    {
        return [
            'access_type' => $this->configuration['services.gmail.access_type'] ?? 'offline',
            'approval_prompt' => $this->configuration['services.gmail.approval_prompt'] ?? 'select_account consent',
            'client_secret' => $this->configuration['services.gmail.client_secret'],
            'client_id' => $this->configuration['services.gmail.client_id'],
            'redirect_uri' => $this->configuration['services.gmail.redirect_url'],
            'state' => $this->configuration['services.gmail.state'] ?? null,
        ];
    }

    /**
     * @return array|null
     */
    public function getAccessToken()
    {
        return parent::getAccessToken() ?: $this->getToken();
    }

    /**
     * @param $token
     */
    public function addAccessToken($token)
    {
        $this->setAccessToken($token);
        $this->saveAccessToken($token);
    }

    /**
     * Save the token credentials to storage
     *
     * @param array $token
     */
    public function saveAccessToken(array $token)
    {
        $token['email'] = $this->user;

        $this->storage->save($token);
    }

    /**
     * Delete the credentials in a file
     */
    public function deleteAccessToken()
    {
        $this->storage->delete();
    }

    /**
     * Check if token exists and is expired
     * Throws an AuthException when the auth file its empty or with the wrong token
     *
     * @return bool Returns True if the access_token is expired.
     */
    public function isAccessTokenExpired()
    {
        $token = $this->getAccessToken();

        if ($token) {
            $this->setToken($token);
        }

        return parent::isAccessTokenExpired();
    }

    /**
     * Check and return true if the connection already has a saved token
     *
     * @return bool
     */
    public function hasToken()
    {
        $config = $this->getToken();

        return !empty($config['access_token']);
    }

    /**
     * @param null $key
     * @return mixed
     */
    public function getToken($key = null)
    {
        return $this->storage->get($key);
    }

    /**
     * @param $token
     */
    public function setToken($token)
    {
        $this->setAccessToken($token);
    }

    /**
     * @param $code
     * @return array|string
     * @throws Exception
     */
    public function makeToken($code)
    {
        if (!$this->isAccessTokenExpired()) {
            return $this->getAccessToken();
        }

        if (!is_null($code) && !empty($code)) {
            $accessToken = $this->fetchAccessTokenWithAuthCode($code);
            $me = $this->getProfile();
            if (property_exists($me, 'emailAddress')) {
                $this->user = $me->emailAddress;
                $accessToken['email'] = $me->emailAddress;
            }

            $this->addAccessToken($accessToken);

            return $accessToken;
        } else {
            throw new Exception('No access token');
        }
    }

    /**
     * Gets user profile from Gmail
     *
     * @return Google_Service_Gmail_Profile
     */
    public function getProfile()
    {
        return $this->service->users->getProfile('me');
    }

    /**
     * @return Google_Service_Gmail
     */
    public function getService()
    {
        return $this->service = new Google_Service_Gmail($this);
    }

    /**
     * Updates / sets the current user for the service
     *
     * @param $user
     * @return GmailConnector
     */
    public function setUser($user)
    {
        $this->user = $user;
        return $this;
    }

    /**
     * @param TokenStorage $storage
     * @return GmailConnector
     */
    public function setStorage(TokenStorage $storage)
    {
        $this->storage = $storage;
        return $this;
    }

    /**
     * Refresh the auth token if needed
     *
     * @return mixed|null
     */
    private function refreshTokenIfNeeded()
    {
        if ($this->isAccessTokenExpired()) {
            $this->fetchAccessTokenWithRefreshToken($this->getRefreshToken());
            $token = $this->getAccessToken();
            $this->addAccessToken($token);

            return $token;
        }

        return $this->getAccessToken();
    }
}
