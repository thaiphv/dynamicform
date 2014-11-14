<?php

namespace Acme\DynamicFormBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

class DefaultController extends Controller
{
  /**
   * @Route("/", name="_homepage")
   */
  public function indexAction()
  {
    return $this->render('AcmeDynamicFormBundle:Default:index.html.twig', array());
  }
}
