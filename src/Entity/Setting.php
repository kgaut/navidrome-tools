<?php

namespace App\Entity;

use App\Repository\SettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SettingRepository::class)]
#[ORM\Table(name: 'setting')]
class Setting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: '`key`', length: 64, unique: true)]
    private string $key;

    #[ORM\Column(type: Types::TEXT)]
    private string $value = '';

    public function __construct(string $key = '', string $value = '')
    {
        $this->key = $key;
        $this->value = $value;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getKey(): string
    {
        return $this->key;
    }
    public function getValue(): string
    {
        return $this->value;
    }

    public function setKey(string $key): self
    {
        $this->key = $key;
        return $this;
    }
    public function setValue(string $value): self
    {
        $this->value = $value;
        return $this;
    }
}
