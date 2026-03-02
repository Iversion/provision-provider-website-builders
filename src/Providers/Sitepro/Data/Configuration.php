<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\WebsiteBuilders\Providers\Sitepro\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Site.pro API credentials and configuration.
 *
 * @property-read string $username API username
 * @property-read string $password API password
 * @property-read string|null $api_url Custom server base URL. Leave empty for Site.pro cloud (eu.site.pro).
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'username' => ['required', 'string', 'min:3'],
            'password' => ['required', 'string', 'min:6'],
            'api_url' => ['nullable', 'string'],
        ]);
    }
}
