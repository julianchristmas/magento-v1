<?php
class Quickpay_Payment_PaymentController extends Mage_Core_Controller_Front_Action {
	// Flag only used for callback
	protected $_callbackAction = false;

	protected function _expireAjax() {
		if (! $this->_getSession()->getQuote()->hasItems()) {
			$this->getResponse()->setHeader('HTTP/1.1', '403 Session Expired');
			exit ;
		}
	}

	public function getPayment() {
		return Mage::getSingleton('quickpaypayment/payment');
	}

	public function redirectAction() {
		$session = $this->_getSession();

		$incrementId = $session->getLastRealOrderId();

		if ($incrementId === null) {
			throw new Exception('No order increment id registered.');
		}

		$session->setQuickpayQuoteId($session->getQuoteId());

		$order = Mage::getModel('sales/order')->loadByIncrementId($incrementId);

		$order->addStatusToHistory(Mage::getModel('quickpaypayment/payment')->getConfigData('order_status'), $this->__("Ordren er oprettet og afventer betaling."));

		$order->save();

		$block = Mage::getSingleton('core/layout')->createBlock('quickpaypayment/payment_redirect');
		$block->toHtml();

		$session->unsQuoteId();
		$session->unsRedirectUrl();
	}

	public function cancelAction() {
		$session = $this->_getSession();
		$session->setQuoteId($session->getQuickpayQuoteId(true));
		if ($session->getLastRealOrderId()) {
			$order = Mage::getModel('sales/order')->loadByIncrementId($session->getLastRealOrderId());
			if ($order->getId()) {
				$order->cancel()->save();
			}

			Mage::helper('quickpaypayment/checkout')->restoreQuote();
		}

		$this->_redirect('checkout/cart');
	}

	public function successAction() {
		$order = Mage::getModel('sales/order')->loadByIncrementId($this->_getSession()->getLastRealOrderId());

		$payment = Mage::getModel('quickpaypayment/payment');
		// might be it's already set by the callback-action
		if ($order->getStatus() != $payment->getConfigData('order_status_after_payment')) {
			//$order->addStatusToHistory($payment->getConfigData('order_status_after_payment'));
			$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, $payment->getConfigData('order_status_after_payment'));
			$order->save();
		}

		$quoteID = Mage::getSingleton("checkout/cart")->getQuote()->getId();
		if ($quoteID) {
			$quote = Mage::getModel("sales/quote")->load($quoteID);
			$quote->setIsActive(false)->save();
		}

		// CREATES INVOICE if payment instantcapture is ON
		if ((int)$payment->getConfigData('instantcapture') == 1 && (int)$payment->getConfigData('instantinvoice') == 1) {
			if ($order->canInvoice()) {
				$invoice = $order->prepareInvoice();
				$invoice->register();
				$invoice->setEmailSent(true);
				$invoice->getOrder()->setCustomerNoteNotify(true);
				$invoice->sendEmail(true, '');
				Mage::getModel('core/resource_transaction')->addObject($invoice)->addObject($invoice->getOrder())->save();

				$order->addStatusToHistory(Mage_Sales_Model_Order::STATE_COMPLETE);
				$order->save();
			}
		} else {
			if (((int)$payment->getConfigData('sendmailorderconfirmationbefore')) == 1) {
			  $this->sendEmail($order);
			}
		}

		$this->_redirect('checkout/onepage/success');
	}

	public function callbackAction() {
		Mage::log("Logging callback data", null, 'qp_callback.log');
		$requestBody = file_get_contents("php://input");
		$request = json_decode($requestBody);
		Mage::log($request, null, 'qp_callback.log');
		$session = $this->_getSession();
		$payment = Mage::getModel('quickpaypayment/payment');
		$key = $payment->getConfigData('privatekey');
		$checksum = hash_hmac("sha256", $requestBody, $key);

		if ($checksum == $_SERVER["HTTP_QUICKPAY_CHECKSUM_SHA256"]) {
			Mage::log('Checksum ok', null, 'qp_callback.log');
			$order = Mage::getModel('sales/order')->loadByIncrementId((int)$request->order_id);

			$operation = end($request->operations);

			// Save the order into the quickpaypayment_order_status table
			// IMPORTANT to update the status as 1 to ensure that the stock is handled correctly!
			if (($request->accepted && $operation->type == 'authorize' && $operation->qp_status_code == "20000") || ($operation->type == 'authorize' && $operation->qp_status_code == "20200" && $operation->pending == TRUE)) {
				if ($operation->pending == TRUE) {
					Mage::log('Transaction accepted but pending', null, 'qp_callback.log');
				} else {
					Mage::log('Transaction accepted', null, 'qp_callback.log');
				}
				if ((int)$payment->getConfigData('transactionfee') == 1) {
					$fee = $operation->amount - ($order->getGrandTotal() * 100.0);
					$fee = ((int)$fee / 100.0);
					Mage::log('Transaction fee added: ' . $fee, null, 'qp_callback.log');
					$fee_text = "";
					if ((int)$payment->getConfigData('specifytransactionfee') == 1) {
						$fee_text = " " . Mage::helper('quickpaypayment')->__("inkl. %s %s i transaktionsgebyr", $fee, $order->getData('order_currency_code'));
					}

					$order->setShippingDescription($order->getShippingDescription() . $fee_text);
					$order->setShippingAmount($order->getShippingAmount() + $fee);
					$order->setBaseShippingAmount($order->getShippingAmount());
					$order->setGrandTotal($order->getGrandTotal() + $fee);
					$order->setBaseGrandTotal($order->getGrandTotal());
					$order->save();
				}

				$metadata = $request->metadata;
				$fraudSuspected = $metadata->fraud_suspected;
				if ($fraudSuspected) {
					$fraudProbability = "high";
				} else {
					$fraudProbability = "clear";
				}

				$fraudRemarksArray = $metadata->fraud_remarks;
				$fraudRemarks = "";
				for ($i = 0; $i < count($fraudRemarksArray); $i++) {
					$fraudRemarks .= $fraudRemarksArray[$i] . "<br/>";
				}

				$resource = Mage::getSingleton('core/resource');
				$table = $resource->getTableName('quickpaypayment_order_status');

				$query = "UPDATE $table SET " . 'transaction = "' . ((isset($request->id)) ? $request->id : '') . '", ' . 'status = "' . ((isset($request->accepted)) ? $request->accepted : '') . '", ' . 'pbsstat = "' . ((isset($_POST['pbsstat'])) ? $_POST['pbsstat'] : '') . '", ' . 'qpstat = "' . ((isset($operation->qp_status_code)) ? $operation->qp_status_code : '') . '", ' . 'qpstatmsg = "' . ((isset($operation->qp_status_msg)) ? $operation->qp_status_msg : '') . '", ' . 'chstat = "' . ((isset($operation->aq_status_code)) ? $operation->aq_status_code : '') . '", ' . 'chstatmsg = "' . ((isset($operation->aq_status_msg)) ? $operation->aq_status_msg : '') . '", ' . 'merchantemail = "' . ((isset($_POST['merchantemail'])) ? $_POST['merchantemail'] : '') . '", ' . 'merchant = "' . ((isset($_POST['merchant'])) ? $_POST['merchant'] : '') . '", ' . 'amount = "' . ((isset($operation->amount)) ? $operation->amount : '') . '", ' . 'currency = "' . ((isset($request->currency)) ? $request->currency : '') . '", ' . 'time = "' . ((isset($request->created_at)) ? $request->created_at : '') . '", ' . 'md5check = "' . ((isset($_POST['md5check'])) ? $_POST['md5check'] : '') . '", ' . 'cardtype = "' . ((isset($request->metadata->brand)) ? $request->metadata->brand : '') . '", ' . 'cardnumber = "' . ((isset($_POST['cardnumber'])) ? $_POST['cardnumber'] : '') . '", ' . 'splitpayment = "' . ((isset($_POST['splitpayment'])) ? $_POST['splitpayment'] : '') . '", ' . 'fraudprobability = "' . ((isset($fraudProbability)) ? $fraudProbability : '') . '", ' . 'fraudremarks = "' . ((isset($fraudRemarks)) ? $fraudRemarks : '') . '", ' . 'fraudreport = "' . ((isset($_POST['fraudreport'])) ? $_POST['fraudreport'] : '') . '", ' . 'fee = "' . ((isset($_POST['fee'])) ? $_POST['fee'] : '') . '", ' . 'capturedAmount = "0", ' . 'refundedAmount = "0"  ' . 'where ordernum = "' . $request->order_id . '"';

				Mage::log($query, null, 'qp_callback.log');

				$write = $resource->getConnection('core_write');
				$write->query($query);

				if (((int)$payment->getConfigData('sendmailorderconfirmation')) == 1) {
					$order->sendNewOrderEmail();
				}

				$payment = Mage::getModel('quickpaypayment/payment');
				// TODO: Consider to set pending payments in another state, must be handled in the api functions
				if ($order->getStatus() != $payment->getConfigData('order_status_after_payment')) {
					$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, $payment->getConfigData('order_status_after_payment'));
					$order->save();
				}

				Mage::helper('quickpaypayment')->createTransaction($order, $request->id, Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH);

				// Remove items from stock as the payment now has been made
				if ((int)Mage::getStoreConfig('cataloginventory/item_options/manage_stock') == 1) {
					Mage::helper('quickpaypayment')->removeFromStock($order->getIncrementId());
				}
			} else {
				Mage::log('Transaction not ok', null, 'qp_callback.log');
				$msg = "Der er fejl ved et betalings forsoeg:<br/>";
				$msg .= "Info: <br/>";
				$msg .= "qpstat: " . ((isset($operation->qp_status_code)) ? $operation->qp_status_code : '') . "<br/>";
				$msg .= "qpmsg: " . ((isset($operation->qp_status_msg)) ? $operation->qp_status_msg : '') . "<br/>";
				$msg .= "chstat: " . ((isset($operation->aq_status_code)) ? $operation->aq_status_code : '') . "<br/>";
				$msg .= "chstatmsg: " . ((isset($operation->aq_status_msg)) ? $operation->aq_status_msg : '') . "<br/>";
				$msg .= "amount: " . ((isset($operation->amount)) ? $operation->amount : '') . "<br/>";
				$order->addStatusToHistory($order->getStatus(), $msg);
				$order->save();
			}
		} else {
			header("Error: MD5 check failed", true, 500);
			exit('md5 mismatch');
		}

		// Callback from Quickpay - just respond ok
		echo "OK";
		exit();
	}

	/**
	 * Send an email to the customer
	 */
	protected function sendEmail($order) {
		$storeId = $order->getStoreId();
		$email = $order->getData('customer_email');

		if (!empty($email)) {
			$mailer = Mage::getModel('core/email_template_mailer');
			if ($order->getData('customer_is_guest') == '0') {
				$templateId = Mage::getStoreConfig(Mage_Sales_Model_Order::XML_PATH_EMAIL_GUEST_TEMPLATE, $storeId);
				$customerName = $order->getBillingAddress()->getName();
			} else {
				$templateId = Mage::getStoreConfig(Mage_Sales_Model_Order::XML_PATH_EMAIL_TEMPLATE, $storeId);
				$customerName = $order->getCustomerName();
			}

			$paymentBlock = Mage::helper('payment')->getInfoBlock($order->getPayment())->setIsSecureMode(true);
			$paymentBlock->getMethod()->setStore($storeId);
			$paymentBlockHtml = $paymentBlock->toHtml();

			$emailInfo = Mage::getModel('core/email_info');

			$isSendOrderEmail = Mage::getStoreConfig('sales_email/order/enabled');
			if ($isSendOrderEmail == 1) {
				$emailInfo->addTo($email, $customerName);
			}

			// Send any bcc's
			if (Mage::getStoreConfig('sales_email/order/copy_method') == 'bcc') {
				$copy_emails = Mage::getStoreConfig('sales_email/order/copy_to');
				$copy_emails = explode(',', $copy_emails);
				if (is_array($copy_emails) && count($copy_emails) > 0) {
					foreach ($copy_emails as $copy_email) {
						$copy_email = trim(strip_tags($copy_email));
						$emailInfo->addBcc($copy_email);
					}
				}
			}

			$mailer->addEmailInfo($emailInfo);
			$sender = Mage::getStoreConfig(Mage_Sales_Model_Order_Invoice::XML_PATH_EMAIL_IDENTITY, $storeId);
			$mailer->setTemplateId($templateId);
			$mailer->setSender($sender);
			$mailer->setStoreId($storeId);
			$mailer->setTemplateParams(array('order' => $order, 'comment' => '', 'billing' => $order->getBillingAddress(), 'payment_html' => $paymentBlockHtml, ));
			$mailer->send();

			$order->setData('email_sent', 1)->save();
		}
	}

    /**
     * Retrieve checkout session
     *
     * @return Mage_Checkout_Model_Session
     */
	protected function _getSession()
	{
		return Mage::getSingleton('checkout/session');
	}

}