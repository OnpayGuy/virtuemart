<?php if (!defined('_VALID_MOS') && !defined('_JEXEC'))
    die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');


if (!class_exists('vmPSPlugin'))
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');

class plgVmPaymentOnpay extends vmPSPlugin {

    // instance of class
    public static $_this = false;
	var $_OnpayOrders = false;
	var $_OnpayOrdersPS = false;
	var $_OnpayMethods = false;
	var $_OnpaySaveLog = false;
	static $_df_pay_mode = "fix";
	static $_df_form_id = "7";
	static $_df_pay_url = "http://secure.onpay.ru/pay/";
	static $_df_logo_url = "http://onpay.ru/images/onpay_logo.gif";
	static $_df_log_path = "/logs/.log.onpay_sale";

    function __construct(& $subject=null, $config=null) {
		parent::__construct($subject, $config);

		$this->_psType = 'payment'; 
		$this->_configTable = '#__virtuemart_' . $this->_psType . 'methods';
		$this->_configTableFieldName = $this->_psType . '_params';
		$this->_configTableFileName = $this->_psType . 'methods'; 
		$this->_configTableClassName = 'Table' . ucfirst($this->_psType) . 'methods';	
	
	    $this->_loggable = true;
	    $this->tableFields = array_keys($this->getTableSQLFields());
	    $varsToPush = array('payment_logos' => array('', 'char'),
		'countries' => array(0, 'int'),
		'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
		'payment_currency' =>  array(0, 'int'),
		'min_amount' => array(0, 'int'),
		'max_amount' => array(0, 'int'),
		'cost_per_transaction' => array(0, 'int'),
		'cost_percent_total' => array(0, 'int'),
		'tax_id' => array(0, 'int'),
		'payment_info' => array('', 'string'),
		'ONPAY_LOGIN' => array('', 'string'),
		'ONPAY_ADD_PARAMS' => array('', 'string'),
		'ONPAY_CONVERT' => array(0, 'int'),
		'ONPAY_PRICE_FINAL' => array(0, 'int'),
		'ONPAY_FORMID' => array(7, 'int'),
		'ONPAY_LANG' => array('', 'string'),
		'ONPAY_CURRENCY_UAH' => array('WMU', 'string'),
		'ONPAY_CURRENCY_USD' => array('USD', 'string'),
		'ONPAY_CURRENCY_EUR' => array('EUR', 'string'),
		'ONPAY_CURRENCY_RUB' => array('RUR', 'string'),
		'ONPAY_CURRENCY_BYR' => array('WMB', 'string'),
		'status_pending' => array('', 'string'),
		'status_success' => array('', 'string'),
		'ONPAY_SECRET_KEY' => array('', 'string')
	    );

	    $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }
	
	static function toFloat($sum) {
		$sum2 = round(floatval($sum), 2);
		$sum = sprintf("%01.2f", $sum2);
	    if (substr($sum, -1) == "0") {
			$sum = sprintf("%01.1f", $sum2);
		}
	    return $sum;
	}
	
    /**
     * Create the table for this plugin if it does not yet exist.
     * @author Valérie Isaksen
     */
    protected function getVmPluginCreateTableSQL() {
		return $this->createTableSQL('Payment Standard Table');
    }
    /**
     * Fields to create the payment table
     * @return string SQL Fileds
     */
    function getTableSQLFields() {
		$SQLfields = array(
		    'id' => 'tinyint(1) unsigned NOT NULL AUTO_INCREMENT',
		    'virtuemart_order_id' => 'int(11) UNSIGNED DEFAULT NULL',
		    'order_number' => 'char(32) DEFAULT NULL',
		    'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED DEFAULT NULL',
		    'payment_name' => 'char(255) NOT NULL DEFAULT \'\' ',
		    'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
		    'payment_currency' => 'char(3) ',
		    'cost_per_transaction' => ' decimal(10,2) DEFAULT NULL ',
		    'cost_percent_total' => ' decimal(10,2) DEFAULT NULL ',
		    'tax_id' => 'smallint(11) DEFAULT NULL'
		);

		return $SQLfields;
    }
	
	function __SaveLog($data) {
		if($this->_OnpaySaveLog && $data) {
			$log_name = $_SERVER['DOCUMENT_ROOT'].self::$_df_log_path;
			if(!file_exists($log_name)) {
				mkdir($log_name);
				chmod($log_name, 0755);
			}
			$log_name .= "/".date('d').".php";
			$td = mktime(0, 0, 0, intval(date("m")), intval(date("d")), intval(date("Y")));
			$log_open = (!file_exists($log_name) || file_exists($log_name) && filemtime($log_name) < $td) ? "w" : "a+";
			if($fh = fopen($log_name, $log_open)) {
				if($log_open == "w") fwrite($fh, "#\n#<?php die('Forbidden.'); ?>\n#\n");
				fwrite($fh, "#".date("d.m.Y H:i:s")." ip:{$_SERVER['REMOTE_ADDR']}\n");
				if(is_array($data)) {
					$key = $data['key'] && in_array($data['type'], array('check', 'pay')) ? $data['key'] : false ;
					$str = serialize($data);
					if($key) {
						$str = str_replace($key, "#KEY#", $str);
					}
				} elseif(is_string($data)) {
					$str = $data;
				} else {
					$str = serialize($data);
				}
				fwrite($fh, $str."\n");
				fclose($fh);
				chmod($log_name, 0755);
			}
		}
	}
	
	function __getVmPluginMethod($method_id) {
		if (!($method = $this->getVmPluginMethod($method_id))) 
		return null; 
		else return $method;
    }
	
	function getOnpayPaymentSecretKeyByOrder($order_id) {
		$order_id = intval($order_id);
		$ret = false;
		if($order_id > 0) {
			$paymentmethod_id = $this->getOrderParamValue($order_id, 'virtuemart_paymentmethod_id');
			$ret = $this->getOnpayPaymentParamValue($paymentmethod_id, 'ONPAY_SECRET_KEY');
		}
		return $ret;
    }
	
	function getOnpayPaymentParamValue($method_id, $param_name=false, $ret = false) {
		if($method_id > 0 && $param_name && !isset($this->_OnpayMethods[$method_id])) {
			$this->_OnpayMethods[$method_id] = $this->getVmPluginMethod($method_id);
		}
		if($method_id > 0 && $param_name && isset($this->_OnpayMethods[$method_id])) {
			$val = $this->_OnpayMethods[$method_id]->{$param_name};
			$ret = empty($val) && $ret !== false ? $ret : $val;
		}
		return $ret;
    }
	
	function getOrderPSParamValue($order_id, $param_name=false) {
		$ret = false;
		if($order_id > 0 && $param_name && !isset($this->_OnpayOrdersPS[$order_id])) {
			$db = JFactory::getDBO();
			$q = 'SELECT * FROM `'.$this->_tablename.'` WHERE `virtuemart_order_id` = '.$order_id;
			$db->setQuery($q);
			$this->_OnpayOrdersPS[$order_id] = $db->loadObject();
		}
		if($order_id > 0 && $param_name && isset($this->_OnpayOrdersPS[$order_id])) {
			$ret = $this->_OnpayOrdersPS[$order_id]->{$param_name};
		}
		return $ret;
    }
	
	function getOrderParamValue($order_id, $param_name=false) {
		$ret = false;
		if($order_id > 0 && $param_name && !isset($this->_OnpayOrders[$order_id])) {
		    if (!class_exists('VirtueMartModelOrders'))
			    require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
			$order = new VirtueMartModelOrders();
			if ($orderitems = $order->getOrder($order_id)) {
				$this->_OnpayOrders[$order_id] = $orderitems['details']['BT'];
			}
		}
		if($order_id > 0 && $param_name && isset($this->_OnpayOrders[$order_id])) {
			$ret = $this->_OnpayOrders[$order_id]->{$param_name};
		}
		return $ret;
    }
	
	function CheckOrderPayAllow($order_id, $paymentmethod_id=false, $sum=false) {
		$order_id = intval($order_id);
		$_sum = floatval($sum);
		$ret = ($order_id > 0);
		$order_status = $payment_status_pending = false;
		if($ret) {
			$order_status = $this->getOrderParamValue($order_id, 'order_status');
		}
		$ret = ($ret && !empty($order_status));
		if($ret && empty($paymentmethod_id)) {
			$paymentmethod_id = $this->getOrderParamValue($order_id, 'virtuemart_paymentmethod_id');
		}
		$ret = ($ret && !empty($paymentmethod_id));
		if($ret) {
			$payment_status_pending = $this->getOnpayPaymentParamValue($paymentmethod_id, 'status_pending');
		}
		$ret = ($ret && !empty($payment_status_pending) && ($order_status == $payment_status_pending));
		if($sum !== false) {
			$this->__SaveLog(array('order_status'=>$order_status, 'payment_status_pending'=>$payment_status_pending, 'order'=>$this->_OnpayOrders[$order_id]));
		}
		if($ret && $sum !== false) {
			$ret = false;
			if($_sum > 0) {
				$db = JFactory::getDBO();
				$q = 'SELECT * FROM `'.$this->_tablename.'` WHERE `virtuemart_order_id` = '.$order_id;
				$db->setQuery($q);
				if($paymentTable = $db->loadObject()) {
					$payment_sum = floatval($paymentTable->payment_order_total);
					$ret = ($_sum >= $payment_sum);
				}
				$this->__SaveLog($paymentTable);
			}
		}
		return $ret;
    }
	
	private function getOnpayPaymentForm($order_id, $paymentmethod_id) {
		$ret = "";
		if($this->CheckOrderPayAllow($order_id, $paymentmethod_id)) {
			$arPay = array(
				'pay_mode' => self::$_df_pay_mode,
				'price' => self::toFloat($this->getOrderPSParamValue($order_id, 'payment_order_total')),
				'ticker' => $this->getOrderPSParamValue($order_id, 'payment_currency'),
				'pay_for' => $order_id,
				'convert' => $this->getOnpayPaymentParamValue($paymentmethod_id, 'ONPAY_CONVERT') == '1' ? 'yes' : 'no',
				'key' => $this->getOnpayPaymentParamValue($paymentmethod_id, 'ONPAY_SECRET_KEY'),
				);
			$arPay['md5string'] = implode(';', $arPay);
			$arPay['md5'] = strtoupper(md5($arPay['md5string']));
			
			$login = $this->getOnpayPaymentParamValue($paymentmethod_id, 'ONPAY_LOGIN');
			$form_id = intval($this->getOnpayPaymentParamValue($paymentmethod_id, 'ONPAY_FORMID', self::$_df_form_id));
			$user_email = $this->getOrderParamValue($order_id, 'email');
			$path = "http://{$_SERVER['HTTP_HOST']}/index.php?option=com_virtuemart&view=orders&layout=details&order_number=".$this->getOrderParamValue($order_id, 'order_number');
			
			$url = self::$_df_pay_url."{$login}?f={$form_id}&pay_mode={$arPay['pay_mode']}&pay_for={$arPay['pay_for']}&price={$arPay['price']}&ticker={$arPay['ticker']}&convert={$arPay['convert']}&md5={$arPay['md5']}&user_email=".urlencode($user_email)."&url_success=".urlencode($path);
			if($this->getOnpayPaymentParamValue($paymentmethod_id, 'ONPAY_PRICE_FINAL') == "1") {
				$url .= "&price_final=true";
			}
			if($lang = $this->getOnpayPaymentParamValue($paymentmethod_id, 'ONPAY_LANG')) {
				$url .= "&ln={$lang}";
			}
			if($ext_params = $this->getOnpayPaymentParamValue($paymentmethod_id, 'ONPAY_ADD_PARAMS')) {
				$url .= "&{$ext_params}";
			}
	
			$ret = "<form action=\"{$url}\" method=\"post\" target=\"_blank\">
<table><tr><td><img src=\"".self::$_df_logo_url."\" style=\"float:left;margin-right:10px;\" /><input type=\"submit\" name=\"submit\"  value=\"".Jtext::_("VMPAYMENT_ONPAY_PAY")."\" /><br style=\"clear:left;\" /></td></tr></table>
</form>";
		}
		return $ret;
    }
	
    function plgVmConfirmedOrder($cart, $order) {
		$virtuemart_paymentmethod_id = $order['details']['BT']->virtuemart_paymentmethod_id;
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
		    return null;
		}
		if (!$this->selectedThisElement($method->payment_element)) {
		    return false;
		}

		if (!class_exists('VirtueMartModelOrders'))
		    require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		$this->getPaymentCurrency($method);
		$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
		$db = &JFactory::getDBO();
		$db->setQuery($q);
		$currency_code_3 = $db->loadResult();
		$convert_currency_name = "ONPAY_CURRENCY_".$currency_code_3;
		$onpay_currency = $method->{$convert_currency_name};
		$paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
		$totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total, false), 2);

		if(empty($onpay_currency)) {
			JRequest::setVar('html', str_replace("#CURRENCY#", $currency_code_3, Jtext::_('VMPAYMENT_ONPAY_PAY')));
			return false;
		}
		$dbValues['payment_name'] = $this->renderPluginName($method);
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['virtuemart_paymentmethod_id'] = $virtuemart_paymentmethod_id;
		$dbValues['cost_per_transaction'] = $method->cost_per_transaction;
		$dbValues['cost_percent_total'] = $method->cost_percent_total;
		$dbValues['payment_currency'] = $onpay_currency ;
		$dbValues['payment_order_total'] = $totalInPaymentCurrency;
		$dbValues['tax_id'] = $method->tax_id;
		$this->storePSPluginInternalData($dbValues);
		
		$html = '<table>' . "\n";
		$html .= $this->getHtmlRow('ONPAY_PAYMENT_INFO', $dbValues['payment_name']);
		if (!class_exists('VirtueMartModelCurrency'))
		    require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
		$currency = CurrencyDisplay::getInstance('', $order['details']['BT']->virtuemart_vendor_id);
		$html .= $this->getHtmlRow('ONPAY_ORDER_NUMBER', $order['details']['BT']->order_number);
		$html .= $this->getHtmlRow('ONPAY_AMOUNT', $currency->priceDisplay($order['details']['BT']->order_total));
		$html .= '</table>' . "\n";
		$id_for_onpay = VirtueMartModelOrders::getOrderIdByOrderNumber($order['details']['BT']->order_number); 
		
		$html .= $this->getOnpayPaymentForm($id_for_onpay, $virtuemart_paymentmethod_id);
		
		$cart->emptyCart();
		JRequest::setVar('html', $html);
		return true;
    }

    /**
     * Display stored payment data for an order
     *
     */
    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id) {
		if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
		    return null; // Another method was selected, do nothing
		}

		$db = JFactory::getDBO();
		$q = 'SELECT * FROM `' . $this->_tablename . '` '
			. 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
		$db->setQuery($q);
		if (!($paymentTable = $db->loadObject())) {
		    vmWarn(500, $q . " " . $db->getErrorMsg());
		    return '';
		}
		$this->getPaymentCurrency($paymentTable);

		$html = '<table class="adminlist">' . "\n";
		$html .=$this->getHtmlHeaderBE();
		$html .= $this->getHtmlRowBE('ONPAY_PAYMENT_INFO', $paymentTable->payment_name);
		$html .= $this->getHtmlRowBE('ONPAY_AMOUNT', $paymentTable->payment_order_total.' '.$paymentTable->payment_currency);
		$html .= '</table>' . "\n";
		return $html;
    }
	
	function getCosts(VirtueMartCart $cart, $method, $cart_prices) {
		if (preg_match('/%$/', $method->cost_percent_total)) {
		    $cost_percent_total = substr($method->cost_percent_total, 0, -1);
		} else {
		    $cost_percent_total = $method->cost_percent_total;
		}
		return ($method->cost_per_transaction + ($cart_prices['salesPrice'] * $cost_percent_total * 0.01));
    }

    /**
     * Check if the payment conditions are fulfilled for this payment method
     * @author: Valerie Isaksen
     *
     * @param $cart_prices: cart prices
     * @param $payment
     * @return true: if the conditions are fulfilled, false otherwise
     *
     */
    protected function checkConditions($cart, $method, $cart_prices) {

		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

		$amount = $cart_prices['salesPrice'];
		$amount_cond = ($amount >= $method->min_amount AND $amount <= $method->max_amount
			OR
			($method->min_amount <= $amount AND ($method->max_amount == 0) ));
		if (!$amount_cond) {
		    return false;
		}
		$countries = array();
		if (!empty($method->countries)) {
		    if (!is_array($method->countries)) {
			$countries[0] = $method->countries;
		    } else {
			$countries = $method->countries;
		    }
		}

		// probably did not gave his BT:ST address
		if (!is_array($address)) {
		    $address = array();
		    $address['virtuemart_country_id'] = 0;
		}

		if (!isset($address['virtuemart_country_id']))
		    $address['virtuemart_country_id'] = 0;
		if (count($countries) == 0 || in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) {
		    return true;
		}

		return false;
    }

    /*
     * We must reimplement this triggers for joomla 1.7
     */

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     * @author Valérie Isaksen
     *
     */
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {
		return $this->onStoreInstallPluginTable($jplugin_id);
    }

    /**
     * This event is fired after the payment method has been selected. It can be used to store
     * additional payment info in the cart.
     *
     * @author Max Milbers
     * @author Valérie isaksen
     *
     * @param VirtueMartCart $cart: the actual cart
     * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
     *
     */
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart) {
		return $this->OnSelectCheck($cart);
    }

    /**
     * plgVmDisplayListFEPayment
     * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
     *
     * @param object $cart Cart object
     * @param integer $selected ID of the method selected
     * @return boolean True on succes, false on failures, null when this plugin was not selected.
     * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
     *
     * @author Valerie Isaksen
     * @author Max Milbers
     */
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {
		return $this->displayListFE($cart, $selected, $htmlIn);
    }

    /*
     * plgVmonSelectedCalculatePricePayment
     * Calculate the price (value, tax_id) of the selected method
     * It is called by the calculator
     * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
     * @author Valerie Isaksen
     * @cart: VirtueMartCart the current cart
     * @cart_prices: array the new cart prices
     * @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
     *
     *
     */

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) {

		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
		    return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
		    return false;
		}
		 $this->getPaymentCurrency($method);

		$paymentCurrencyId = $method->payment_currency;
    }

    /**
     * plgVmOnCheckAutomaticSelectedPayment
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     * @author Valerie Isaksen
     * @param VirtueMartCart cart: the cart object
     * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
     *
     */
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array()) {
		return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param integer $order_id The order ID
     * @return mixed Null for methods that aren't active, text (HTML) otherwise
     * @author Max Milbers
     * @author Valerie Isaksen
     */
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
		$payment_name .= $this->getOnpayPaymentForm($virtuemart_order_id, $virtuemart_paymentmethod_id);
    }

    function plgVmonShowOrderPrintPayment($order_number, $method_id) {
		return $this->onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPayment($name, $id, &$data) {
		return $this->declarePluginParams('payment', $name, $id, $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
		return $this->setOnTablePluginParams($name, $id, $table);
    }
}