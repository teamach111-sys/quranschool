<?php

namespace App\Auth;

use Illuminate\Auth\DatabaseUserProvider;
use Illuminate\Auth\GenericUser;

class SchoolDatabaseUserProvider extends DatabaseUserProvider
{
    protected function getGenericUser($user)
    {
        if (is_null($user)) {
            return null;
        }

        $attributes = (array) $user;

        if (! array_key_exists('name', $attributes)) {
            $attributes['name'] = $attributes['full_name'] ?? null;
        }

        if (! isset($attributes['role']) && isset($attributes['role_id'])) {
            $attributes['role'] = $this->connection
                ->table('roles')
                ->where('id', $attributes['role_id'])
                ->value('role_key');
        }

        return new class($attributes) extends GenericUser
        {
            public function getAuthPasswordName()
            {
                return array_key_exists('password_hash', $this->attributes)
                    ? 'password_hash'
                    : 'password';
            }

            public function getRememberToken()
            {
                return $this->attributes[$this->getRememberTokenName()] ?? null;
            }
        };
    }
}
