<?php

declare(strict_types=1);

namespace Osmium\Services\Stripe\Models;

/**
 * Stripe configuration.
 *
 * File-based config (app/config/services/stripe.json.php), matching the other
 * services. Keys are secrets: the settings page never echoes them back, and a
 * blank submission keeps the stored value.
 */
class StripeConfig
{
    private static ?object $config = null;
    private static string $configPath = 'app/config/services/stripe.json.php';

    public static function get(): object
    {
        $configLoaded = self::$config !== null;
        if ($configLoaded) return self::$config;

        $configExists = \file_exists(self::$configPath);
        if (!$configExists) {
            self::$config = self::defaults();
            return self::$config;
        }

        $content = \file_get_contents(self::$configPath);
        $jsonStart = \strpos(haystack: $content, needle: '{');

        $noJsonFound = $jsonStart === false;
        if ($noJsonFound) {
            self::$config = self::defaults();
            return self::$config;
        }

        $decoded = \json_decode(\substr(string: $content, offset: $jsonStart));
        $stored = (array) ($decoded->stripe ?? []);

        self::$config = (object) ($stored + (array) self::defaults());

        return self::$config;
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function save(array $values): void
    {
        $dir = \dirname(self::$configPath);
        $dirExists = \is_dir($dir);
        if (!$dirExists) \mkdir(directory: $dir, permissions: 0755, recursive: true);

        $json = \json_encode(
            value: ['stripe' => $values],
            flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        \file_put_contents(self::$configPath, "<?php exit(); ?>\n" . $json . "\n");
        self::clearCache();
    }

    public static function clearCache(): void
    {
        self::$config = null;
    }

    public static function client(): StripeClient
    {
        return new StripeClient(self::get());
    }

    private static function defaults(): object
    {
        return (object) [
            'enabled' => false,
            'mode' => 'test',
            'testPublishableKey' => '',
            'testSecretKey' => '',
            'livePublishableKey' => '',
            'liveSecretKey' => '',
            'webhookSigningSecret' => '',
            'statementDescriptor' => '',
        ];
    }
}
