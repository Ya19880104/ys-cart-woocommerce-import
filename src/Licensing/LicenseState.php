<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\Licensing;

defined('ABSPATH') || exit;

final class LicenseState
{
    public const OPTION_KEY = 'ys_cwci_license_state';
    public const STATUS_UNCONFIGURED = 'unconfigured';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_NETWORK = 'network_error';

    /**
     * @return array<string,mixed>
     */
    public static function get(): array
    {
        $state = get_option(self::OPTION_KEY, []);
        if (!is_array($state)) {
            $state = [];
        }

        return array_merge(self::defaults(), $state);
    }

    public static function isAllowed(): bool
    {
        return self::STATUS_ACTIVE === (string)self::get()['status'];
    }

    /**
     * @return array<string,mixed>
     */
    public static function publicState(): array
    {
        $state = self::get();
        unset($state['license_key'], $state['license_key_hash']);
        $state['allowed'] = self::isAllowed();

        return $state;
    }

    /**
     * @return array<string,mixed>
     */
    private static function defaults(): array
    {
        return [
            'status' => self::STATUS_UNCONFIGURED,
            'license_key_mask' => '',
            'last_checked_at' => '',
            'last_error_code' => '',
            'last_error_message' => '',
            'updated_at' => '',
        ];
    }
}
