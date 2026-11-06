<?php

namespace App\Support;

class PaymentMethods
{
    /**
     * @return list<string>
     */
    public static function defaults(): array
    {
        return [
            'Espèces',
            'Virement bancaire',
            'Chèque',
            'Carte bancaire',
            'Paiement mobile',
        ];
    }

    /**
     * @return list<string>
     */
    public static function fromJson(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return self::defaults();
        }

        $methods = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        $methods = is_array($methods)
            ? array_values(array_filter($methods, fn ($method) => is_string($method) && $method !== ''))
            : [];

        return $methods !== [] ? $methods : self::defaults();
    }
}
