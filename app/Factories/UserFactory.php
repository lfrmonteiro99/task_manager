<?php

declare(strict_types=1);

namespace App\Factories;

use App\Entities\User;
use DateTime;

class UserFactory
{
    /**
     * @param array<string, mixed> $data
     */
    public static function create(array $data): User
    {
        $user = new User();

        if (isset($data['id'])) {
            $user->setId((int) $data['id']);
        }

        if (isset($data['email'])) {
            $user->setEmail($data['email']);
        }

        if (isset($data['name'])) {
            $user->setName($data['name']);
        }

        if (isset($data['password_hash'])) {
            $user->setPasswordHash($data['password_hash']);
        }

        if (isset($data['created_at'])) {
            $createdAt = $data['created_at'] instanceof DateTime
                ? $data['created_at']
                : new DateTime($data['created_at']);
            $user->setCreatedAt($createdAt);
        }

        if (isset($data['updated_at'])) {
            $updatedAt = $data['updated_at'] instanceof DateTime
                ? $data['updated_at']
                : new DateTime($data['updated_at']);
            $user->setUpdatedAt($updatedAt);
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function createFromDatabaseRow(array $row): User
    {
        return self::create([
            'id' => (int) $row['id'],
            'email' => $row['email'],
            'name' => $row['name'],
            'password_hash' => $row['password_hash'],
            'created_at' => new DateTime($row['created_at']),
            'updated_at' => new DateTime($row['updated_at'])
        ]);
    }

    /**
     * Create User from cached data
     * @param array<string, mixed> $cachedData
     */
    public static function createFromCachedData(array $cachedData): User
    {
        return self::create([
            'id' => (int) $cachedData['id'],
            'email' => $cachedData['email'],
            'name' => $cachedData['name'],
            'created_at' => $cachedData['created_at'] ?? null,
            'updated_at' => $cachedData['updated_at'] ?? null
        ]);
    }

    /**
     * @param array<string, mixed> $validatedData
     */
    public static function createFromValidatedData(array $validatedData): User
    {
        $user = new User();

        $user->setEmail($validatedData['email'])
             ->setName($validatedData['name']);

        if (isset($validatedData['password'])) {
            $user->hashPassword($validatedData['password']);
        }

        return $user;
    }
}
