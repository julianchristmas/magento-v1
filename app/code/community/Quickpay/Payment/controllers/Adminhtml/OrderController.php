<?php

class Quickpay_Payment_Adminhtml_OrderController extends Mage_Adminhtml_Controller_Action
{
    public function indexAction()
    {
        $request = $this->getRequest()->getParams();
        $order_id = isset($request['id']) ? $request['id'] : 0;
        $info_type = Mage::helper('quickpaypayment')->getInfoType($order_id);

        if ($info_type == 'normal') {
            $fields = Mage::helper('quickpaypayment')->getFields($order_id);
        } else {
            $fields = array();
        }

        $content = $this
          ->getLayout()
          ->createBlock('core/template')
          ->setTemplate('quickpaypayment/grid/info.phtml')
          ->setFields($fields)
          ->setInfoType($info_type)
          ->toHtml();

        $this->getResponse()->setBody($content);
    }
}