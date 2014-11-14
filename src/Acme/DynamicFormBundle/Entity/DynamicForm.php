<?php

namespace Acme\DynamicFormBundle\Entity;

use Doctrine\Common\Inflector\Inflector;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Mapping\ClassMetadata;

/**
 * @ORM\Entity()
 * @ORM\Table(name="dynamic_form")
 */
class DynamicForm {
  const FIELD_NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';
  const FORM_NAME_PATTERN = '/^[a-z][a-z0-9_]+$/';

  /**
   * @ORM\Id
   * @ORM\Column(type="bigint")
   * @ORM\GeneratedValue(strategy="AUTO")
   */
  protected $id;

  /**
   * @ORM\Column(type="string", unique=TRUE)
   * @Assert\NotBlank()
   * @Assert\Length(
   *    min="5",
   *    max="255",
   *    minMessage="Form name must be at least {{ limit }} characters long",
   *    maxMessage="Form name cannot be longer than {{ limit }} characters long"
   * )
   * @Assert\Regex(
   *    pattern="/^[a-z][a-z0-9]+$/",
   *    message="Form name cannot contain non-alphanumeric characters"
   * )
   */
  protected $name;

  /**
   * @ORM\Column(type="text")
   * @Assert\NotBlank()
   */
  protected $fields;

  /**
   * @return string
   */
  public function getFields()
  {
    return $this->fields;
  }

  /**
   * @param string $fields
   */
  public function setFields($fields)
  {
    $this->fields = $fields;
  }

  /**
   * @return int
   */
  public function getId()
  {
    return $this->id;
  }

  /**
   * @param int $id
   */
  public function setId($id)
  {
    $this->id = $id;
  }

  /**
   * @return string
   */
  public function getName()
  {
    return $this->name;
  }

  /**
   * @param string $name
   */
  public function setName($name)
  {
    $this->name = $name;
  }

  public function getFieldTypes($camelising = true) {
    $fields = json_decode(trim($this->getFields()));
    $types = array();
    if (is_array($fields)) {
      foreach ($fields as $field) {
        if (property_exists($field, 'name') && strlen($field->name) && property_exists($field, 'type') && strlen($field->type)) {
          if ($camelising) {
            $name = Inflector::camelize($field->name);
          } else {
            $name = $field->name;
          }
          if (in_array($field->type, array('text', 'textarea', 'email', 'url', 'choice'))) {
            $type = 'string';
          } elseif ($field->type == 'number') {
            $type = 'float';
          } elseif ($field->type == 'integer') {
            $type = 'integer';
          } elseif (in_array($field->type, array('date', 'time', 'datetime'))) {
            $type = $field->type;
          } elseif (in_array($field->type, array('checkbox', 'radio'))) {
            $type = 'boolean';
          }

          $types[$name] = $type;
        }
      }
    }

    return $types;
  }
}