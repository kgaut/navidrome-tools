<?php

namespace App\Repository;

use App\Entity\Setting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Setting> */
class SettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly EntityManagerInterface $em)
    {
        parent::__construct($registry, Setting::class);
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->findOneBy(['key' => $key])?->getValue() ?? $default;
    }

    public function set(string $key, string $value): void
    {
        $setting = $this->findOneBy(['key' => $key]);
        if ($setting === null) {
            $setting = new Setting($key, $value);
            $this->em->persist($setting);
        } else {
            $setting->setValue($value);
        }
        $this->em->flush();
    }
}
