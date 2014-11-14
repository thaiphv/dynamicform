<?php

namespace Acme\DynamicFormBundle;

use Acme\DynamicFormBundle\Exception\DynamicFormNotFoundException;
use Acme\DynamicFormBundle\Service\FormService;
use Doctrine\Common\Inflector\Inflector;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\HttpKernel\Kernel;

class AcmeDynamicFormBundle extends Bundle
{
  private $kernel;

  public function __construct(Kernel $kernel) {
    $this->kernel = $kernel;
    spl_autoload_register(array($this, 'dynamicClassLoader'));
  }

  public function dynamicClassLoader($className) {
    $dynamicNamespaces = array(
      FormService::DYNAMIC_ENTITY_NS . '\\',
      FormService::DYNAMIC_FORM_NS . '\\'
    );

    $ns = null;
    foreach ($dynamicNamespaces as $item) {
      if (strpos($className, $item) === 0) {
        $ns = $item;
        break;
      }
    }
    if (!$ns) {
      return;
    }

    $parts = explode($ns, $className);
    $entityClassName = (count($parts) == 2) ? $parts[1] : null;
    if (!$entityClassName) {
      return;
    }
    $entityClassName = Inflector::tableize($entityClassName);

    $formService = $this->kernel->getContainer()->get('form_service');
    $formEntity = $formService->getFormEntity($entityClassName);
    if (!$formEntity) {
      throw new DynamicFormNotFoundException('Dynamic Form Name: ' . $entityClassName . ' Not Found');
    }

    $formService->loadDynamicEntityClassDefinition($formEntity);
    $formService->loadDynamicFormClassDefinition($formEntity);
  }
}
