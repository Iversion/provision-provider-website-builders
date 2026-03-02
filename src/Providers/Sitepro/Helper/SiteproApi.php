<?php

namespace Upmind\ProvisionProviders\WebsiteBuilders\Providers\Sitepro\Helper;

use GuzzleHttp\Client;
use Upmind\ProvisionProviders\WebsiteBuilders\Data\CreateParams;
use Upmind\ProvisionProviders\WebsiteBuilders\Providers\Sitepro\Data\Configuration;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;

class SiteproApi
{
    protected Client $client;

    protected Configuration $configuration;

    protected ?string $loginHash = null;

    protected ?string $builderApiUrl = null;

    public function __construct(Client $client, Configuration $configuration)
    {
        $this->client = $client;
        $this->configuration = $configuration;
        if ($configuration->api_url) {
            $this->builderApiUrl = $configuration->api_url;
        }
    }

    /**
     * @param string $domain
     * @param bool $more
     * @param array $extraBody
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function createSession(string $domain, bool $more = false, array $extraBody = []): array
    {
        $body = [
            'type' => 'http',
            'domain' => $domain,
            'apiUrl' => $this->configuration->api_url ?? "https://site.pro/api/",
            'username' => $this->configuration->username,
            'password' => $this->configuration->password,
        ];

        if ($more) {
            $body['more'] = true;
        }

        if ($extraBody) {
            $body = array_merge($body, $extraBody);
        }

        $response = $this->makeRequest('requestLogin', null, $body, 'POST');

        if ($more) {
            $this->loginHash = $response['loginHash'];
            if (isset($response['builderApiUrl']) && !isset($this->builderApiUrl)) {
                $this->builderApiUrl = $response['builderApiUrl'];
            }
        }

        return $response;
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function makeRequest(
        string $command,
        ?array $params = null,
        ?array $body = null,
        string $method = 'GET',
        bool   $useBuilderApi = false
    ): ?array
    {
        if ($useBuilderApi) {
            if (!$this->loginHash || !$this->builderApiUrl) {
                throw ProvisionFunctionError::create('No active session. Call createSession() with more=true first.');
            }

            $command = $this->builderApiUrl . $command;
            $body = array_merge($body ?? [], ['loginHash' => $this->loginHash]);
        }

        $requestParams = [];

        if ($params) {
            $requestParams['query'] = $params;
        }

        if ($body) {
            $requestParams['body'] = json_encode($body);
        }

        $response = $this->client->request($method, $command, $requestParams);
        $result = $response->getBody()->getContents();

        $response->getBody()->close();

        if ($result === "") {
            return null;
        }

        return $this->parseResponseData($result);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function parseResponseData(string $result): array
    {
        $parsedResult = json_decode($result, true);

        if (!$parsedResult) {
            throw ProvisionFunctionError::create('Unknown Provider API Error')
                ->withData([
                    'response' => $result,
                ]);
        }

        if ($error = $this->getResponseErrorMessage($parsedResult)) {
            throw ProvisionFunctionError::create($error)
                ->withData([
                    'response' => $parsedResult,
                ]);
        }

        return $parsedResult;
    }

    protected function getResponseErrorMessage($responseData): ?string
    {
        if (isset($responseData['error'])) {
            return $responseData['error'];
        }

        return null;
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function createSite(CreateParams $params): ?array
    {
        $sessionBody = array_filter([
            'lang' => $params->language_code ?? null,
            'hostingPlan' => $params->package_reference ?? null,
            'resellerClientAccountId' => isset($params->site_builder_user_id) ? (int)$params->site_builder_user_id : null,
            'clientId' => isset($params->customer_id) ? (string)$params->customer_id : null,
            'clientEmail' => $params->customer_email ?? null,
        ]);

        $this->createSession($params->domain_name, true, $sessionBody);

        $extra = $params->extra ?? [];
        return $this->createSiteFromTemplate($extra);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function createSiteFromTemplate(array $extra): array
    {
        $body = [];

        if (isset($extra['template'])) {
            $body['template'] = $extra['template'];
        }

        if (isset($extra['ai_query'])) {
            $body['aiQuery'] = $extra['ai_query'];
        }

        if (isset($extra['page_types'])) {
            $body['pageTypes'] = $extra['page_types'];
        }

        if (isset($extra['variables'])) {
            $body['variables'] = $extra['variables'];
        }

        return $this->makeRequest('website/create', null, $body, 'POST', true);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getInfo(string $domain): array
    {
        $this->createSession($domain, true);

        $settings = $this->makeRequest('website/get-settings', null, null, 'POST', true);
        $domainsInfo = $this->makeRequest('hosting-accounts/get-domains', null, ['domain' => $domain], 'POST');

        $domainData = $domainsInfo['domains'][0] ?? null;

        return [
            'account_reference' => $domain,
            'domain_name' => $domain,
            'package_reference' => null,
            'suspended' => isset($domainData['blocked']) ? (bool)$domainData['blocked'] : null,
            'ip_address' => null,
            'is_published' => isset($domainData['live']) ? (bool)$domainData['live'] : null,
            'has_ssl' => $settings['data']['publishWithForcedHttps'] ?? null,
        ];
    }

    /**
     * Block a published website.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function suspend(string $domain): void
    {
        $this->makeRequest('hosting-accounts/set-blocked', null, [
            'domain' => $domain,
            'value' => true,
        ], 'POST');
    }

    /**
     * Unblock a published website.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function unsuspend(string $domain): void
    {
        $this->makeRequest('hosting-accounts/set-blocked', null, [
            'domain' => $domain,
            'value' => false,
        ], 'POST');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function terminate(string $domain): void
    {
        $this->createSession($domain, true);

        $this->makeRequest('delete-site', null, ['domain' => $domain], 'POST', true);
    }

    /**
     * Create a session and return the SSO login URL.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function login(string $domain): string
    {
        $response = $this->createSession($domain);

        return $response['url'];
    }
}
