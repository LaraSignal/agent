<?php

namespace LaraSignal\Agent\Support;

final class Redactor
{
    private const SENSITIVE = ['authorization', 'cookie', 'password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'credit_card', 'session'];

    public function redact(array $value, array $allowlist = []): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            $normalized = mb_strtolower((string) $key);
            if ($allowlist !== [] && ! in_array($key, $allowlist, true)) {
                continue;
            }

            if ($this->sensitive($normalized)) {
                $result[$key] = '[REDACTED]';
            } elseif (is_array($item)) {
                $result[$key] = $this->redact($item);
            } elseif (is_int($item) || is_float($item) || is_bool($item) || $item === null) {
                $result[$key] = $item;
            } elseif (is_string($item)) {
                $limit = $normalized === 'message'
                    ? (int) config('larasignal.max_exception_message_length', 65536)
                    : 1000;

                $result[$key] = mb_substr($item, 0, $limit);
            }
        }

        return $result;
    }

    private function sensitive(string $key): bool
    {
        foreach (self::SENSITIVE as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
