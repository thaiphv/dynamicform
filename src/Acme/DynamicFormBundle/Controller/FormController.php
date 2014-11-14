<?php

namespace Acme\DynamicFormBundle\Controller;

use Acme\DynamicFormBundle\Entity\DynamicForm;
use Acme\DynamicFormBundle\Exception\DynamicFormNotFoundException;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;

class FormController extends Controller
{
  /**
   * @Route("/describe", name="_form_describe")
   */
  public function describeFormAction(Request $request)
  {
    $formEntity = new DynamicForm();
    $form = $this->createFormBuilder($formEntity)
        ->setAction($this->generateUrl('_form_describe'))
        ->add('name', 'text')
        ->add('fields', 'textarea')
        ->getForm();
    if ($request->getMethod() == 'POST') {
      $form->handleRequest($request);
      if ($form->isValid()) {
        do {
          $em = $this->getDoctrine()->getManager();
          $entity = $em->getRepository('AcmeDynamicFormBundle:DynamicForm')->findOneBy(array('name' => $formEntity->getName()));
          if ($entity) {
            $form->get('name')->addError(new FormError('Form name already exists'));
            break;
          }

          $formService = $this->get('form_service');
          $fields = $formService->validateFieldDefinitions($form->get('fields')->getData());
          if (is_string($fields)) {
            $form->get('fields')->addError(new FormError($fields));
            break;
          }

          $em = $this->getDoctrine()->getManager();
          $em->persist($formEntity);
          $em->flush();

          $formService->createDynamicEntitySchema($formEntity);

          $this->get('session')->getFlashBag()->add('notice', 'New dynamic form was saved');
          return $this->redirect($this->generateUrl('_form_list'));
        } while (false);
      }
    }
    return $this->render('AcmeDynamicFormBundle:Form:describe.html.twig', array('form' => $form->createView()));
  }

  /**
   * @Route("/list", name="_form_list")
   */
  public function listFormsAction(Request $request) {
    $repo = $this->getDoctrine()->getManager()->getRepository('AcmeDynamicFormBundle:DynamicForm');
    $formEntities = $repo->findAll();

    return $this->render('AcmeDynamicFormBundle:Form:list.html.twig', array('formEntities' => $formEntities));
  }

  /**
   * @Route("/consume/{dynamicFormName}/list", name="_consume_form_list", requirements={"dynamicFormName"="[a-z][a-z0-9_]+"})
   */
  public function listConsumedFormAction(Request $request, $dynamicFormName) {
    $formService = $this->get('form_service');
    $dynamicEntityClassName = $formService->getDynamicEntityClassName($dynamicFormName);
    $dynamicForm = $formService->getFormEntity($dynamicFormName);
    if (!$dynamicForm) {
      throw $this->createNotFoundException('Dynamic Form Entity not found');
    }
    $entityFieldTypes = $dynamicForm->getFieldTypes();

    $repo = $this->getDoctrine()->getManager('dynamic')->getRepository('AcmeDynamicFormBundle:' . $dynamicEntityClassName);
    $entities = $repo->findAll();

    return $this->render('AcmeDynamicFormBundle:Form:consumeList.html.twig', array(
        'entities' => $entities,
        'fieldTypes' => $entityFieldTypes,
        'dynamicFormName' => $dynamicFormName
    ));
  }

  /**
   * @Route("/consume/{dynamicFormName}/new", name="_consume_form_new", requirements={"dynamicFormName"="[a-z][a-z0-9_]+"})
   */
  public function newConsumedFormAction(Request $request, $dynamicFormName) {
    $formService = $this->get('form_service');
    $dynamicEntityClassName = $formService->getNamespacedDynamicEntityClassName($dynamicFormName);
    $dynamicFormClassName = $formService->getNamespacedDynamicFormClassName($dynamicFormName);
    try {
      $dynamicEntity = new $dynamicEntityClassName();
      $dynamicForm = new $dynamicFormClassName();
    } catch (DynamicFormNotFoundException $e) {
      throw $this->createNotFoundException($e->getMessage());
    }

    $form = $this->createForm($dynamicForm, $dynamicEntity);
    if ($request->getMethod() == 'POST') {
      $form->handleRequest($request);
      if ($form->isValid()) {
        $em = $this->getDoctrine()->getManager('dynamic');
        $em->persist($dynamicEntity);
        $em->flush();

        $this->get('session')->getFlashBag()->add('notice', 'New dynamic item was saved');
        return $this->redirect($this->generateUrl('_consume_form_list', array('dynamicFormName' => $dynamicFormName)));
      }
    }

    return $this->render('AcmeDynamicFormBundle:Form:consumeNew.html.twig', array(
        'form' => $form->createView(),
        'dynamicFormName' => $dynamicFormName
    ));
  }

  /**
   * @Route("/consume/{dynamicFormName}/edit/{id}", name="_consume_form_edit", requirements={"dynamicFormName"="[a-z][a-z0-9_]+", "id"="[0-9]+"})
   */
  public function editConsumedFormAction(Request $request, $dynamicFormName, $id) {
    $formService = $this->get('form_service');
    $dynamicEntityClassName = $formService->getDynamicEntityClassName($dynamicFormName);
    $dynamicFormClassName = $formService->getNamespacedDynamicFormClassName($dynamicFormName);

    $repo = $this->getDoctrine()->getManager('dynamic')->getRepository('AcmeDynamicFormBundle:' . $dynamicEntityClassName);
    $entity = $repo->find($id);
    if (!$entity) {
      throw $this->createNotFoundException('Dynamic Item "' . $id . '" not found');
    }

    try {
      $dynamicForm = new $dynamicFormClassName();
    } catch (DynamicFormNotFoundException $e) {
      throw $this->createNotFoundException($e->getMessage());
    }

    $form = $this->createForm($dynamicForm, $entity);
    if ($request->getMethod() == 'POST') {
      $form->handleRequest($request);
      if ($form->isValid()) {
        $em = $this->getDoctrine()->getManager('dynamic');
        $em->persist($entity);
        $em->flush();

        $this->get('session')->getFlashBag()->add('notice', 'Dynamic item was updated');
        return $this->redirect($this->generateUrl('_consume_form_list', array('dynamicFormName' => $dynamicFormName)));
      }
    }

    return $this->render('AcmeDynamicFormBundle:Form:consumeEdit.html.twig', array(
        'form' => $form->createView(),
        'dynamicFormName' => $dynamicFormName
    ));
  }
}
