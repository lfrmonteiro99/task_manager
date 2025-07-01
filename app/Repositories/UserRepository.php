<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Entities\User;
use App\Models\Database;
use DateTime;
use PDO;
use PDOException;
use Exception;

class UserRepository
{
    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    public function create(string $email, string $password, string $name): User
    {
        try {
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);

            $sql = "INSERT INTO users (email, password_hash, name, created_at, updated_at) " .
                   "VALUES (?, ?, ?, NOW(), NOW())";
            $stmt = $this->database->getConnection()->prepare($sql);

            $result = $stmt->execute([$email, $passwordHash, $name]);

            if (!$result) {
                throw new Exception("Failed to create user");
            }

            $userId = (int)$this->database->getConnection()->lastInsertId();
            $user = $this->findById($userId);
            if ($user === null) {
                throw new Exception("Failed to retrieve created user");
            }
            return $user;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') { // Duplicate entry
                throw new Exception("Email already exists");
            }
            throw new Exception("Failed to create user: " . $e->getMessage());
        }
    }

    public function findById(int $id): ?User
    {
        try {
            $sql = "SELECT id, email, password_hash, name, created_at, updated_at FROM users WHERE id = ?";
            $stmt = $this->database->getConnection()->prepare($sql);
            $stmt->execute([$id]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                return $this->mapRowToUser($row);
            }

            return null;
        } catch (PDOException $e) {
            throw new Exception("Failed to retrieve user: " . $e->getMessage());
        }
    }

    public function findByEmail(string $email): ?User
    {
        try {
            $sql = "SELECT id, email, password_hash, name, created_at, updated_at FROM users WHERE email = ?";
            $stmt = $this->database->getConnection()->prepare($sql);
            $stmt->execute([$email]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                return $this->mapRowToUser($row);
            }

            return null;
        } catch (PDOException $e) {
            throw new Exception("Failed to retrieve user: " . $e->getMessage());
        }
    }

    public function emailExists(string $email): bool
    {
        try {
            $sql = "SELECT COUNT(*) FROM users WHERE email = ?";
            $stmt = $this->database->getConnection()->prepare($sql);
            $stmt->execute([$email]);

            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            throw new Exception("Failed to check email existence: " . $e->getMessage());
        }
    }

    public function updatePassword(int $id, string $newPassword): bool
    {
        try {
            $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);

            $sql = "UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $this->database->getConnection()->prepare($sql);

            return $stmt->execute([$passwordHash, $id]);
        } catch (PDOException $e) {
            throw new Exception("Failed to update password: " . $e->getMessage());
        }
    }

    public function updateProfile(int $id, string $name, string $email): bool
    {
        try {
            $sql = "UPDATE users SET name = ?, email = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $this->database->getConnection()->prepare($sql);

            return $stmt->execute([$name, $email, $id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') { // Duplicate entry
                throw new Exception("Email already exists");
            }
            throw new Exception("Failed to update profile: " . $e->getMessage());
        }
    }

    public function delete(int $id): bool
    {
        try {
            $sql = "DELETE FROM users WHERE id = ?";
            $stmt = $this->database->getConnection()->prepare($sql);

            return $stmt->execute([$id]);
        } catch (PDOException $e) {
            throw new Exception("Failed to delete user: " . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToUser(array $row): User
    {
        $user = new User();
        $user->setId((int)$row['id'])
             ->setEmail($row['email'])
             ->setPasswordHash($row['password_hash'])
             ->setName($row['name'])
             ->setCreatedAt(new DateTime($row['created_at']))
             ->setUpdatedAt(new DateTime($row['updated_at']));

        return $user;
    }
}
