<?php

namespace Omnifood\UberEats;

use Omnifood\Config;
use Omnifood\Model\Token;
use Omnifood\PlatformFactory;
use Omnifood\PlatformInterface;

/**
 * Uber Eats, through the Marketplace APIs (Order Fulfillment, Store, Menu):
 *
 *   options:
 *     client_id: '%env(default::UBEREATS_CLIENT_ID)%'         # the Developer Dashboard application's
 *     client_secret: '%env(default::UBEREATS_CLIENT_SECRET)%' # also the key of the webhooks' signature
 *     store_id: '%env(default::UBEREATS_STORE_ID)%'           # the store's id at Uber
 *     scopes: [eats.store, eats.order, eats.store.orders.read, eats.store.status.write]
 *     access_token: ~                                         # a token kept by the site (refresh(), token()), and when it dies:
 *     token_expires_at: ~                                     # ATOM; Uber allows 100 token requests an hour
 *     language: en_us                                         # the menu's texts' translation key
 *     tax_inclusive: true                                     # prices include VAT (vat_rate_percentage), else tax_rate
 *     sandbox: false                                          # test-api.uber.com / sandbox-login.uber.com
 *     base_uri: ~
 *     token_url: ~
 */
final class UberEatsPlatformFactory extends PlatformFactory
{
    protected function populate(Config $config): void
    {
        $config->defaults([
            'omnifood.factory_name' => 'ubereats',
            'omnifood.required_options' => [],
            'client_id' => null,
            'client_secret' => null,
            'store_id' => null,
            'scopes' => Api::SCOPES,
            'access_token' => null,
            'token_expires_at' => null,
            'language' => 'en_us',
            'tax_inclusive' => true,
            'sandbox' => false,
            'base_uri' => null,
            'token_url' => null,
        ]);
    }

    protected function build(Config $config): PlatformInterface
    {
        $sandbox = filter_var($config['sandbox'], \FILTER_VALIDATE_BOOL);
        $str = static fn (string $key) => $config[$key] ? (string) $config[$key] : null;
        $token = $str('access_token') ? new Token((string) $config['access_token'], $str('token_expires_at') ? new \DateTimeImmutable((string) $config['token_expires_at']) : null) : null;
        $scopes = \is_array($config['scopes']) ? array_values($config['scopes']) : array_values(array_filter(preg_split('/[\s,]+/', (string) $config['scopes']) ?: []));

        return new UberEatsPlatform(
            new Api($str('client_id'), $str('client_secret'), $token, $scopes, $str('base_uri') ?? ($sandbox ? Api::SANDBOX_BASE_URI : Api::BASE_URI), $str('token_url') ?? ($sandbox ? Api::SANDBOX_TOKEN_URL : Api::TOKEN_URL), $this->http),
            $str('store_id'),
            $str('client_secret'),
            (string) $config['language'],
            filter_var($config['tax_inclusive'], \FILTER_VALIDATE_BOOL),
        );
    }
}
