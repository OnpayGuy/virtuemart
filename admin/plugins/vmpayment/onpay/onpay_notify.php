<?  error_reporting (0);
	
	$_SERVER['REQUEST_URI']='';
	$_SERVER['SCRIPT_NAME']='';
	$_SERVER['QUERY_STRING']='';

	define('_JEXEC', 1);
	define('DS', DIRECTORY_SEPARATOR);
	$option='com_virtuemart';
	$my_path = dirname(__FILE__);
	$my_path = explode(DS.'plugins',$my_path);	
	$my_path = $my_path[0];			
	if (file_exists($my_path . '/defines.php')) {
		include_once $my_path . '/defines.php';
	}
	if (!defined('_JDEFINES')) {
		define('JPATH_BASE', $my_path);
		require_once JPATH_BASE.'/includes/defines.php';
	}
	define('JPATH_COMPONENT',				JPATH_BASE . '/components/' . $option);
	define('JPATH_COMPONENT_SITE',			JPATH_SITE . '/components/' . $option);
	define('JPATH_COMPONENT_ADMINISTRATOR',	JPATH_ADMINISTRATOR . '/components/' . $option);	
	require_once JPATH_BASE.'/includes/framework.php';
	$app = JFactory::getApplication('site');
	$app->initialise();
	if (!class_exists( 'VmConfig' )) require(JPATH_ADMINISTRATOR . DS . 'components' . DS . 'com_virtuemart'.DS.'helpers'.DS.'config.php');
	VmConfig::loadConfig();
	if (!class_exists('VirtueMartModelOrders'))
		require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );			
	if (!class_exists('plgVmPaymentOnpay'))
		require(dirname(__FILE__). DS . 'onpay.php');
	
	global $vmPSOnpay;
	$dispatcher = JDispatcher::getInstance();
	$vmPSOnpay = new plgVmPaymentOnpay($dispatcher, Array('type'=>'vmpayment', 'name'=>'onpay'));

// функция обновления статуса операции на оплаченную
function data_set_operation_processed($order_id) {
	global $vmPSOnpay;
	$ret = false;
	$modelOrder = new VirtueMartModelOrders();
	$paymentmethod_id = $vmPSOnpay->getOrderParamValue($order_id, 'virtuemart_paymentmethod_id');
	$payment_status_success = $vmPSOnpay->getOnpayPaymentParamValue($paymentmethod_id, 'status_success');
	if(!empty($payment_status_success)) {
		$orderUpd = array(
			'order_status' => $payment_status_success,
			'customer_notified' => 0,
			'virtuemart_order_id' => $order_id,
			'comments' => 'Onpay ID: '.$order_id
			);
		$ret = $modelOrder->updateStatusForOneOrder($order_id, $orderUpd, true);
	}
	return $ret;
}

//функция выдает ответ для сервиса onpay в формате XML на чек запрос 
function answercheck($code, $pay_for, $order_amount, $order_currency, $text) {
	global $vmPSOnpay;
	$key = $vmPSOnpay->getOnpayPaymentSecretKeyByOrder($pay_for);
	$md5 = strtoupper(md5("check;{$pay_for};{$order_amount};{$order_currency};{$code};{$key}")); 
	return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<result>\n<code>{$code}</code>\n<pay_for>{$pay_for}</pay_for>\n<comment>{$text}</comment>\n<md5>{$md5}</md5>\n</result>";
}

//функция выдает ответ для сервиса onpay в формате XML на pay запрос 
function answerpay($code, $pay_for, $order_amount, $order_currency, $text, $onpay_id) { 
	global $vmPSOnpay;
	$key = $vmPSOnpay->getOnpayPaymentSecretKeyByOrder($pay_for);
	$md5 = strtoupper(md5("pay;{$pay_for};{$onpay_id};{$pay_for};{$order_amount};{$order_currency};{$code};{$key}")); 
	return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<result>\n<code>{$code}</code>\n<comment>{$text}</comment>\n<onpay_id>{$onpay_id}</onpay_id>\n<pay_for>{$pay_for}</pay_for>\n<order_id>{$pay_for}</order_id>\n<md5>{$md5}</md5>\n</result>"; 
}

if (isset ( $_REQUEST ['type'] )) {
	$vmPSOnpay->__SaveLog($_REQUEST);
	
	$rezult = ''; 
	$error = ''; 
	//проверяем чек запрос 
	if ($_REQUEST['type'] == 'check') { 
	    $order_amount 	= $_REQUEST['order_amount']; 
	    $order_currency = $_REQUEST['order_currency']; 
	    $pay_for 		= $_REQUEST['pay_for']; 
	    $md5 			= $_REQUEST['md5']; 
	    $sum 			= floatval( $order_amount );
	    
		if ($vmPSOnpay->CheckOrderPayAllow($pay_for, false, $sum)) {
			$rezult = answercheck(0, $pay_for, $order_amount, $order_currency, 'OK'); 
		} else {
			$rezult = answercheck(2, $pay_for, $order_amount, $order_currency, 'Error order_id:' . $pay_for . ' in order_id!=order_id, order_sum>sum or order_status!=P' );
		}
	}

	//проверяем запрос на пополнение 
	if ($_REQUEST['type'] == 'pay') {
	    $onpay_id 				= $_REQUEST['onpay_id']; 
	    $pay_for 				= $_REQUEST['pay_for']; 
	    $order_amount 			= $_REQUEST['order_amount']; 
	    $order_currency			= $_REQUEST['order_currency']; 
	    $balance_amount 		= $_REQUEST['balance_amount']; 
	    $balance_currency 		= $_REQUEST['balance_currency']; 
	    $exchange_rate 			= $_REQUEST['exchange_rate']; 
	    $paymentDateTime 		= $_REQUEST['paymentDateTime']; 
	    $md5 					= $_REQUEST['md5']; 
	    //производим проверки входных данных 
	    if (empty($onpay_id)) {$error .="Не указан id<br>";} 
	    else {if (!is_numeric(intval($onpay_id))) {$error .="Параметр не является числом<br>";}} 
	    if (empty($order_amount)) {$error .="Не указана сумма<br>";} 
	    else {if (!is_numeric($order_amount)) {$error .="Параметр не является числом<br>";}} 
	    if (empty($balance_amount)) {$error .="Не указана сумма<br>";} 
	    else {if (!is_numeric(intval($balance_amount))) {$error .="Параметр не является числом<br>";}} 
	    if (empty($balance_currency)) {$error .="Не указана валюта<br>";} 
	    else {if (strlen($balance_currency)>4) {$error .="Параметр слишком длинный<br>";}} 
	    if (empty($order_currency)) {$error .="Не указана валюта<br>";} 
	    else {if (strlen($order_currency)>4) {$error .="Параметр слишком длинный<br>";}} 
	    if (empty($exchange_rate)) {$error .="Не указана сумма<br>";} 
	    else {if (!is_numeric($exchange_rate)) {$error .="Параметр не является числом<br>";}} 
	
	    //если нет ошибок 
			if (!$error) { 
				if (is_numeric($pay_for)) {
					//Если pay_for - число 
					$sum = floatval($order_amount); 
					if ($vmPSOnpay->CheckOrderPayAllow($pay_for, false, $sum)) { 
						//создаем строку хэша с присланных данных 
						$mdkey = $vmPSOnpay->getOnpayPaymentSecretKeyByOrder($pay_for);
						$md5fb = strtoupper(md5($_REQUEST['type'].";".$pay_for.";".$onpay_id.";".$order_amount.";".$order_currency.";".$mdkey)); 
						//сверяем строчки хеша (присланную и созданную нами) 
						if ($md5fb != $md5) {
							$rezult = answerpay(8, $pay_for, $order_amount, $order_currency, 'Md5 signature is wrong. Expected '.$md5fb, $onpay_id);
						} else { 
							$rezult_operation = data_set_operation_processed($pay_for);
							var_dump($rezult_operation);
							//если оба запроса прошли успешно выдаем ответ об удаче, если нет, то о том что операция не произошла 
							if ($rezult_operation) {
								$rezult = answerpay(0, $pay_for, $order_amount, $order_currency, 'OK', $onpay_id);
							} else {
								$rezult = answerpay(9, $pay_for, $order_amount, $order_currency, 'Error in mechant database queries: operation tables error', $onpay_id);
							} 
						}
					} else {
						$rezult = answerpay(10, $pay_for, $order_amount, $order_currency, 'Cannot find any pay rows acording to this parameters: wrong payment', $onpay_id);
					} 
				} else {
					//Если pay_for - не правильный формат 
					$rezult = answerpay(11, $pay_for, $order_amount, $order_currency, 'Error in parameters data', $onpay_id); 
				} 
			} else {
				//Если есть ошибки 
				$rezult = answerpay(12, $pay_for, $order_amount, $order_currency, 'Error in parameters data: '.$error, $onpay_id); 
			} 
	}
	echo $rezult;
	$vmPSOnpay->__SaveLog($rezult."\n");
}
?>