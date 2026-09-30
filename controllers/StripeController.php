<?php

declare(strict_types=1);

namespace Osmium\Services\Stripe\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\Stripe\Models\StripeConfig;

/**
 * Stripe settings controller - switch, mode and credentials.
 *
 * Routes:
 *   - index() → /admin/settings/stripe/
 *
 * Choosing Stripe as the active checkout provider happens on the core
 * Settings > Payments page; this page only holds Stripe's own settings.
 */
class StripeController extends AdminController
{
    private const FLASH_KEY = 'stripe_flash';

    private const KEY_ALPHABET = '/^[A-Za-z0-9_]+$/';
    private const KEY_MIN_LENGTH = 20;

    /**
     * Post field => [config key, label, acceptable prefixes]. Every one is a
     * secret: a blank submission keeps the stored value.
     */
    private const SECRET_FIELDS = [
        'test_publishable_key' => ['testPublishableKey', 'Test Publishable Key', ['pk_test_']],
        'test_secret_key' => ['testSecretKey', 'Test Secret Key', ['sk_test_', 'rk_test_']],
        'live_publishable_key' => ['livePublishableKey', 'Live Publishable Key', ['pk_live_']],
        'live_secret_key' => ['liveSecretKey', 'Live Secret Key', ['sk_live_', 'rk_live_']],
        'webhook_signing_secret' => ['webhookSigningSecret', 'Webhook Signing Secret', ['whsec_']],
    ];

    public function index(): void
    {
        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if ($isPost) $this->handleSubmit();

        $config = (array) StripeConfig::get();

        $this->data['admin']['stripe'] = [
            'enabled' => (bool) $config['enabled'],
            'mode' => $config['mode'],
            'statementDescriptor' => $config['statementDescriptor'],
            'has' => \array_map(
                fn(array $field) => $config[$field[0]] !== '',
                self::SECRET_FIELDS,
            ),
            'webhookUrl' => $this->webhookUrl(),
        ];
        $this->data['admin']['flash'] = $_SESSION[self::FLASH_KEY] ?? null;
        unset($_SESSION[self::FLASH_KEY]);

        $this->setView('stripe/index.phtml');
    }

    private function handleSubmit(): void
    {
        $csrfValid = $this->admin->auth->validateCsrf();
        if (!$csrfValid) $this->flashAndRedirect('danger', 'Invalid form submission. Please try again.');

        $current = (array) StripeConfig::get();
        $values = $current;

        foreach (self::SECRET_FIELDS as $postField => [$configKey, $label, $prefixes]) {
            $posted = \trim((string) ($_POST[$postField] ?? ''));

            $keepCurrent = $posted === '';
            if ($keepCurrent) continue;

            $error = $this->shapeError(label: $label, value: $posted, prefixes: $prefixes);
            if ($error !== null) $this->flashAndRedirect('danger', $error);

            $values[$configKey] = $posted;
        }

        $mode = \trim((string) ($_POST['mode'] ?? 'test'));
        $values['enabled'] = isset($_POST['enabled']);
        $values['mode'] = $mode === 'live' ? 'live' : 'test';
        $values['statementDescriptor'] = \mb_substr(string: \trim((string) ($_POST['statement_descriptor'] ?? '')), start: 0, length: 22);

        StripeConfig::save($values);

        $this->admin->model->changelog->log(
            description: 'Updated Stripe settings',
            recordType: 'settings',
        );

        $this->flashAndRedirect('success', 'Settings saved successfully!');
    }

    /**
     * Catches a credential that is the wrong *shape* - truncated, masked, or
     * carrying stray characters - which admin would otherwise accept silently
     * and surface only as "payment unavailable" on the customer's checkout.
     *
     * @param string[] $prefixes
     */
    private function shapeError(string $label, string $value, array $prefixes): ?string
    {
        $hasStrayCharacters = \preg_match(self::KEY_ALPHABET, $value) !== 1;
        if ($hasStrayCharacters) {
            return "{$label} contains characters that no real Stripe key has - usually a sign it was copied "
                . 'from an abbreviated or masked display. Copy the full value using the Stripe dashboard\'s copy button.';
        }

        $tooShort = \strlen($value) < self::KEY_MIN_LENGTH;
        if ($tooShort) {
            return "{$label} is only " . \strlen($value) . ' characters, which is too short to be a real one. '
                . 'It looks truncated - copy the full value from the Stripe dashboard.';
        }

        foreach ($prefixes as $prefix) {
            if (\str_starts_with($value, $prefix)) return null;
        }

        return "{$label} should start with " . \implode(' or ', $prefixes) . '. Check you have not pasted the wrong key, '
            . 'for example a test key into a live field.';
    }

    private function webhookUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        return "{$scheme}://{$_SERVER['HTTP_HOST']}/api/checkout-stripe-webhook";
    }

    private function flashAndRedirect(string $type, string $text): void
    {
        $_SESSION[self::FLASH_KEY] = ['type' => $type, 'text' => $text];
        $this->redirect('settings/stripe/');
    }
}
