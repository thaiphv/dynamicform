<?php

namespace Acme\DynamicFormBundle\Service;

use Acme\DynamicFormBundle\Entity\DynamicForm;
use Acme\DynamicFormBundle\Exception\DynamicFormInvalidFieldDefinitionException;
use Acme\DynamicFormBundle\Exception\DynamicFormInvalidNameException;
use Acme\DynamicFormBundle\Exception\DynamicFormInvalidTypeException;
use Doctrine\Common\Inflector\Inflector;
use Doctrine\ORM\Mapping\Builder\ClassMetadataBuilder;
use Doctrine\ORM\Mapping\ClassMetadata as OrmClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Date;
use Symfony\Component\Validator\Constraints\DateTime;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Time;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Mapping\ClassMetadata as ValidatorClassMetadata;

class FormService {
  const DYNAMIC_ENTITY_MANAGER = 'dynamic';
  const DYNAMIC_ENTITY_NS = 'Acme\\DynamicFormBundle\\DynamicEntity';
  const DYNAMIC_FORM_NS = 'Acme\\DynamicFormBundle\\DynamicForm\\Type';

  private $allowedFieldTypes = array(
    'text', 'textarea', 'email', 'url',
    'number', 'integer',
    'choice',
    'date', 'time', 'datetime',
    'checkbox', 'radio'
  );

  private $doctrine;

  public function __construct($doctrine) {
    $this->doctrine = $doctrine;
  }

  /**
   * Validate the JSON string
   * @param string $jsonText
   * @return string
   */
  public function validateFieldDefinitions($jsonText) {
    $jsonText = trim($jsonText);
    if (empty($jsonText)) {
      return 'Invalid JSON string';
    }
    $fieldsArray = json_decode($jsonText);
    if (!$fieldsArray) {
      return 'Invalid JSON string';
    }
    if (!is_array($fieldsArray)) {
      return 'The JSON string is not an array';
    }

    $fieldNames = array();
    foreach ($fieldsArray as $fieldDefinition) {
      $result = $this->validateFieldDefinition($fieldDefinition);
      if (is_string($result)) {
        return 'Unable to parse field definition: ' . json_encode($fieldDefinition) . ' with error: ' . $result;
      }
      if (in_array($fieldDefinition->name, $fieldNames)) {
        return 'Duplicate field name detected';
      }
      $fieldNames[] = $fieldDefinition->name;
    }

    return true;
  }

  /**
   * Validate form field description object
   * @param \stdObject $field
   * @return bool|string
   */
  private function validateFieldDefinition($field) {
    $reservedFieldNames = array('id', 'created_time', 'modified_time', 'form_entity', 'form_service');
    if (!is_object($field)) {
      return 'Invalid field object';
    }
    if (!property_exists($field, 'name')) {
      return 'Missing field name property';
    }
    if (!preg_match(DynamicForm::FIELD_NAME_PATTERN, $field->name)) {
      return 'Field name value "' . $field->name . '" does not match the pattern ' . DynamicForm::FIELD_NAME_PATTERN;
    }
    if (in_array($field->name, $reservedFieldNames)) {
      return 'Field name "' . $field->name . '" is reserved';
    }
    if (!property_exists($field, 'type')) {
      return 'Missing field type property';
    }
    if (!in_array($field->type, $this->allowedFieldTypes)) {
      return 'Field type value "' . $field->type . '" is not allowed';
    }
    return true;
  }

  /**
   * Get Dynamic Form entity by name
   * @param string $name
   * @return \Acme\DynamicFormBundle\Entity\DynamicForm
   */
  public function getFormEntity($name) {
    $repo = $this->doctrine->getManager('default')->getRepository('AcmeDynamicFormBundle:DynamicForm');
    $entity = $repo->findOneBy(array('name' => $name));
    return $entity;
  }

  /**
   * Get class name of a dynamic entity based on form name
   * @param string $formName
   * @return string
   */
  public function getDynamicEntityClassName($formName) {
    return Inflector::classify($formName);
  }

  public function getNamespacedDynamicEntityClassName($formName, $absolute = true) {
    $name = self::DYNAMIC_ENTITY_NS . '\\' . $this->getDynamicEntityClassName($formName);
    if ($absolute) $name = '\\' . $name;
    return $name;
  }

  /**
   * Get FQNS class name of a dynamic form based on form name
   * @param string $formName
   * @param bool $absolute
   * @return string
   */
  public function getDynamicFormClassName($formName) {
    return Inflector::classify($formName) . 'Type';
  }

  public function getNamespacedDynamicFormClassName($formName, $absolute = true) {
    $name = self::DYNAMIC_FORM_NS . '\\' . $this->getDynamicFormClassName($formName);
    if ($absolute) $name = '\\' . $name;
    return $name;
  }

  /**
   * Generate dynamic entity class based on definitions defined in DynamicForm entity
   * @param DynamicForm $dynamicForm
   * @throws DynamicFormInvalidNameException
   */
  public function loadDynamicEntityClassDefinition(DynamicForm $dynamicForm) {
    $formName = $dynamicForm->getName();
    if (!preg_match(DynamicForm::FORM_NAME_PATTERN, $formName)) {
      throw new DynamicFormInvalidNameException('Invalid Form Name ' . $formName . ' detected');
    }
    $namespace = self::DYNAMIC_ENTITY_NS;
    $dynamicEntityClassName = $this->getDynamicEntityClassName($formName);

    $script = <<<SCRIPT
namespace {$namespace};

class {$dynamicEntityClassName} {
  private static \$formEntity = null;
  private static \$formService = null;

  private \$id;
  private \$createdTime;
  private \$modifiedTime;

  public function __construct() {
    \$this->setId(null)->setCreatedTime(new \\DateTime())->setModifiedTime(new \\DateTime());
  }

  public static function loadMetadata(\\Doctrine\\ORM\\Mapping\\ClassMetadata \$metadata) {
    if (!self::\$formEntity || !self::\$formService) throw new \Exception("Form Entity and/or Form Service not assigned to dynamic entity");
    return self::\$formService->buildOrmMetadata(\$metadata, self::\$formEntity);
  }

  public static function loadValidatorMetadata(\\Symfony\\Component\\Validator\\Mapping\\ClassMetadata \$metadata) {
    if (!self::\$formEntity || !self::\$formService) throw new \Exception("Form Entity and/or Form Service not assigned to dynamic entity");
    return self::\$formService->buildValidatorMetadata(\$metadata, self::\$formEntity);
  }

  public static function setFormEntity(\\Acme\\DynamicFormBundle\\Entity\\DynamicForm \$formEntity) {
    self::\$formEntity = \$formEntity;
  }

  public static function getFormEntity() {
    return self::\$formEntity;
  }

  public static function setFormService(\$formService) {
    self::\$formService = \$formService;
  }

  public static function getFormService() {
    return self::\$formService;
  }

  public function getId() { return \$this->id; }
  public function setId(\$id) { \$this->id = \$id; return \$this; }
  public function getCreatedTime() { return \$this->createdTime; }
  public function setCreatedTime(\$createdTime) { \$this->createdTime = \$createdTime; return \$this; }
  public function getModifiedTime() { return \$this->modifiedTime; }
  public function setModifiedTime(\$modifiedTime) { \$this->modifiedTime = \$modifiedTime; return \$this; }
SCRIPT;

    $formFields = json_decode($dynamicForm->getFields());
    foreach ($formFields as $fieldDefinition) {
      $fieldName = $fieldDefinition->name;
      if (!preg_match(DynamicForm::FIELD_NAME_PATTERN, $fieldName)) {
        throw new DynamicFormInvalidNameException('Invalid Field Name ' . $fieldName . ' detected');
      }
      $camelName = Inflector::camelize($fieldName);
      $classifyName = Inflector::classify($fieldName);
      $script .= <<<SCRIPT

  private \${$camelName};
  public function get{$classifyName}() { return \$this->{$camelName}; }
  public function set{$classifyName}(\$value) { \$this->{$camelName} = \$value; return \$this; }
SCRIPT;
    }

    $script .= <<<SCRIPT

}
SCRIPT;

    eval($script);

    // Assign dynamic form entity to the class so that Doctrine ORM metadata can be generated
    $entityClassName = '\\' . $namespace . '\\' . $dynamicEntityClassName;
    $entityClassName::setFormEntity($dynamicForm);
    $entityClassName::setFormService($this);
  }

  /**
   * Generate dynamic form class based on definitions defined in DynamicForm entity
   * @param DynamicForm $dynamicForm
   * @throws DynamicFormInvalidNameException
   */
  public function loadDynamicFormClassDefinition(DynamicForm $dynamicForm) {
    $formName = $dynamicForm->getName();
    if (!preg_match(DynamicForm::FORM_NAME_PATTERN, $formName)) {
      throw new DynamicFormInvalidNameException('Invalid Form Name ' . $formName . ' detected');
    }
    $namespace = self::DYNAMIC_FORM_NS;
    $dynamicFormClassName = $this->getDynamicFormClassName($formName);

    $script = <<<SCRIPT
namespace {$namespace};

class {$dynamicFormClassName} extends \\Symfony\\Component\\Form\\AbstractType {
  public function buildForm(\\Symfony\\Component\\Form\\FormBuilderInterface \$builder, array \$options) {

SCRIPT;

    $formFields = json_decode($dynamicForm->getFields());
    foreach ($formFields as $fieldDefinition) {
      $fieldName = $fieldDefinition->name;
      if (!preg_match(DynamicForm::FIELD_NAME_PATTERN, $fieldName)) {
        throw new DynamicFormInvalidNameException('Invalid Field Name ' . $fieldName . ' detected');
      }
      $camelName = Inflector::camelize($fieldName);
      $type = $fieldDefinition->type;
      if (!in_array($type, $this->allowedFieldTypes)) {
        throw new DynamicFormInvalidTypeException('Invalid Type ' . $type . ' detected');
      }
      $options = array();
      if (property_exists($fieldDefinition, 'label')) {
        $options['label'] = $fieldDefinition->label;
      }
      if (property_exists($fieldDefinition, 'attributes')) {
        $options['attr'] = $fieldDefinition->attributes;
      }
      if (property_exists($fieldDefinition, 'max_length')) {
        $options['max_length'] = $fieldDefinition->max_length;
      }
      if (property_exists($fieldDefinition, 'not_blank') || property_exists($fieldDefinition, 'not_null')) {
        $options['required'] = true;
      } else {
        $options['required'] = false;
      }
      if (property_exists($fieldDefinition, 'choices')) {
        $options['choices'] = get_object_vars($fieldDefinition->choices);
      }
      if (in_array($type, array('date', 'time', 'datetime'))) {
        $options['widget'] = 'single_text';
      }
      $optionString = var_export($options, true);

      $script .= <<<SCRIPT

    \$builder->add('{$camelName}', '{$type}', {$optionString});
SCRIPT;
    }

    $dynamicEntityClassName = $this->getNamespacedDynamicEntityClassName($formName, false);
    $script .= <<<SCRIPT

  }

  public function getName() { return '{$formName}'; }

  public function setDefaultOptions(\\Symfony\Component\\OptionsResolver\\OptionsResolverInterface \$resolver) {
    \$resolver->setDefaults(array(
      'data_class' => '{$dynamicEntityClassName}',
    ));
  }
}
SCRIPT;

    eval($script);
  }

  public function buildValidatorMetadata(ValidatorClassMetadata $metadata, DynamicForm $dynamicForm) {
    $jsonText = trim($dynamicForm->getFields());
    if (empty($jsonText)) {
      throw new DynamicFormInvalidFieldDefinitionException('Invalid JSON string');
    }
    $fieldsArray = json_decode($jsonText);
    if (!$fieldsArray) {
      throw new DynamicFormInvalidFieldDefinitionException('Invalid JSON string');
    }
    if (!is_array($fieldsArray)) {
      throw new DynamicFormInvalidFieldDefinitionException('Invalid JSON string');
    }

    foreach ($fieldsArray as $fieldDefinition) {
      if (!($result = $this->validateFieldDefinition($fieldDefinition))) {
        throw new DynamicFormInvalidFieldDefinitionException('Unable to parse field definition: ' . json_encode($fieldDefinition) . ' with error: ' . $result);
      }
      $fieldName = Inflector::camelize($fieldDefinition->name);
      $fieldType = $fieldDefinition->type;
      if (property_exists($fieldDefinition, 'not_null')) {
        $metadata->addPropertyConstraint($fieldName, new NotNull());
      }
      if (property_exists($fieldDefinition, 'not_blank')) {
        $metadata->addPropertyConstraint($fieldName, new NotBlank());
      }
      if (property_exists($fieldDefinition, 'min_length') && is_int($fieldDefinition->min_length) && $fieldDefinition->min_length > 0) {
        $metadata->addPropertyConstraint($fieldName, new Length(array('min' => $fieldDefinition->min_length)));
        $metadata->addPropertyConstraint($fieldName, new NotBlank());
      }
      if (property_exists($fieldDefinition, 'max_length') && is_int($fieldDefinition->max_length)) {
        $metadata->addPropertyConstraint($fieldName, new Length(array('max' => $fieldDefinition->max_length)));
      }
      if (property_exists($fieldDefinition, 'email')) {
        $metadata->addPropertyConstraint($fieldName, new Email());
      }
      if (property_exists($fieldDefinition, 'url')) {
        $metadata->addPropertyConstraint($fieldName, new Url(array('protocols' => array('http', 'https', 'ftp', 'ftps'))));
      }
      if (property_exists($fieldDefinition, 'regex') && strlen($fieldDefinition->regex)) {
        $metadata->addPropertyConstraint($fieldName, new Regex(array(
          'pattern' => $fieldDefinition->regex,
          'message' => 'The value does not match the pattern ' . $fieldDefinition->regex
        )));
      }
      if (property_exists($fieldDefinition, 'choices')) {
        if (is_object($fieldDefinition->choices)) {
          $choices = get_object_vars($fieldDefinition->choices);
        } elseif (is_array($fieldDefinition->choices)) {
          $choices = $fieldDefinition->choices;
        } else {
          throw new DynamicFormInvalidFieldDefinitionException('choices value is neither object nor array');
        }
        $metadata->addPropertyConstraint($fieldName, new Choice(array('choices' => $choices)));
      }
      if ($fieldType == 'date') {
        $metadata->addPropertyConstraint($fieldName, new Date());
      } elseif ($fieldType == 'time') {
        $metadata->addPropertyConstraint($fieldName, new Time());
      } elseif ($fieldType == 'datetime') {
        $metadata->addPropertyConstraint($fieldName, new DateTime());
      }
    }
  }

  public function buildOrmMetadata(OrmClassMetadata $metadata = null, DynamicForm $dynamicForm) {
    $name = 'df_' . $dynamicForm->getName();

    if (!$metadata) {
      $metadata = new OrmClassMetadata($name);
    }

    $builder = new ClassMetadataBuilder($metadata);
    $builder->setTable($name);
    $builder->createField('id', 'bigint')->isPrimaryKey()->generatedValue('IDENTITY')->build();

    $jsonText = trim($dynamicForm->getFields());
    $fieldsArray = json_decode($jsonText);
    foreach ($fieldsArray as $fieldDefinition) {
      $fieldName = $fieldDefinition->name;
      $camelisedFieldName = Inflector::camelize($fieldName);
      $type = $fieldDefinition->type;

      if (in_array($type, array('text', 'textarea', 'email', 'url', 'choice'))) {
        $length = (property_exists($fieldDefinition, 'max_length')) ? $fieldDefinition->max_length : 255;
        $builder->createField($camelisedFieldName, 'string')->nullable()->length($length)->columnName($fieldName)->build();
      } elseif (in_array($type, array('checkbox', 'radio'))) {
        $builder->createField($camelisedFieldName, 'boolean')->nullable()->columnName($fieldName)->build();
      } elseif ($type == 'number') {
        $builder->createField($camelisedFieldName, 'decimal')->nullable()->columnName($fieldName)->build();
      } elseif (in_array($type, array('integer', 'date', 'time', 'datetime'))) {
        $builder->createField($camelisedFieldName, $type)->nullable()->columnName($fieldName)->build();
      }
    }

    $builder->createField('createdTime', 'datetime')->columnName('created_time')->build();
    $builder->createField('modifiedTime', 'datetime')->columnName('modified_time')->build();

    return $builder->getClassMetadata();
  }

  public function createDynamicEntitySchema(DynamicForm $dynamicForm) {
    $em = $this->doctrine->getManager(self::DYNAMIC_ENTITY_MANAGER);
    $tool = new SchemaTool($em);
    $metadata = $this->buildOrmMetadata(null, $dynamicForm);
    $tool->updateSchema(array($metadata), true);
  }

}