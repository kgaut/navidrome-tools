<?php

namespace App\Security;

use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Identifier-based equality so the firewall doesn't log the user out
 * after the env-derived password hash gets re-generated on each request
 * (bcrypt/argon use a random salt → different hash every time).
 */
final class EnvUser implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    /** @param list<string> $roles */
    public function __construct(
        private readonly string $identifier,
        private readonly string $password,
        private readonly array $roles = ['ROLE_USER'],
    ) {
    }

    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->roles;
    }

    public function eraseCredentials(): void
    {
    }

    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self) {
            return false;
        }

        return $user->getUserIdentifier() === $this->getUserIdentifier()
            && $user->getRoles() === $this->getRoles();
    }
}
