<?php

namespace Joindin\Api\Test\Service;

use Joindin\Api\Service\BaseEmailService;
use Symfony\Component\Mailer\Mailer;

trait ReplaceMailerTrait
{
    public function replaceMailer(BaseEmailService $service): BaseEmailService
    {
        $reflectionObject = new \ReflectionObject($service);

        $reflectionProperty = $reflectionObject->getProperty('mailer');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($service, $this->createMock(Mailer::class));

        return $service;
    }
}
