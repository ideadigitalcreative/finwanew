<?php

namespace App\Services\Ledger;

/**
 * Siapa/dari mana perubahan transaksi berasal, untuk jejak audit.
 *
 *   AuditContext::run(['via' => 'whatsapp', 'message_id' => 12], fn () => ...);
 *
 * Tanpa konteks eksplisit, observer menebak dari request (dashboard/api) atau 'system'.
 */
final class AuditContext
{
    /** @var array<int, array{via?: string, user_id?: int|null, message_id?: int|null}> */
    private static array $stack = [];

    public static function run(array $context, callable $callback): mixed
    {
        self::$stack[] = $context;
        try {
            return $callback();
        } finally {
            array_pop(self::$stack);
        }
    }

    /** @return array{via: string, user_id: int|null, message_id: int|null} */
    public static function current(): array
    {
        $ctx = [];
        foreach (self::$stack as $frame) {
            $ctx = array_merge($ctx, array_filter($frame, fn ($v) => $v !== null));
        }

        if (! isset($ctx['via'])) {
            $ctx['via'] = self::guessVia();
        }
        if (! array_key_exists('user_id', $ctx)) {
            try {
                $ctx['user_id'] = auth()->id();
            } catch (\Throwable) {
                $ctx['user_id'] = null;
            }
        }

        return [
            'via' => (string) $ctx['via'],
            'user_id' => $ctx['user_id'] ?? null,
            'message_id' => $ctx['message_id'] ?? null,
        ];
    }

    private static function guessVia(): string
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return 'system';
        }
        try {
            $request = request();
            if ($request && $request->route()) {
                return $request->is('api/*') ? 'api' : 'dashboard';
            }
        } catch (\Throwable) {
        }

        return 'system';
    }
}
