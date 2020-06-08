<?php
/**
 * IDPay payment plugin
 *
 * @developer JMDMahdi
 * @publisher IDPay
 * @package VirtueMart
 * @subpackage payment
 * @copyright (C) 2018 IDPay
 * @license http://www.gnu.org/licenses/gpl-2.0.html GPLv2 or later
 *
 * http://idpay.ir
 */

defined('_JEXEC') or die('Restricted access');


if (!class_exists('vmPSPlugin')) {
    require(JPATH_VM_PLUGINS . '/vmpsplugin.php');
}

class plgVmPaymentIdpay extends vmPSPlugin
{
    function __construct(& $subject, $config)
    {
        parent::__construct($subject, $config);
        $this->_loggable = TRUE;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';
        $varsToPush = array('api_key' => array('', 'varchar'), 'sandbox' => array(0, 'int'), 'success_massage' => array('', 'varchar'), 'failed_massage' => array('', 'varchar'));
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    public function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment Idpay Table');
    }

    function getTableSQLFields()
    {
        $SQLfields = array(
            'id' => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(1) UNSIGNED',
            'order_number' => 'char(64)',
            'order_pass' => 'varchar(50)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'crypt_virtuemart_pid' => 'varchar(255)',
            'salt' => 'varchar(255)',
            'payment_name' => 'varchar(5000)',
            'amount' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency' => 'char(3)',
            'email_currency' => 'char(3)',
            'mobile' => 'varchar(12)',
            'tracking_code' => 'varchar(50)'
        );
        return $SQLfields;
    }


    function plgVmConfirmedOrder($cart, $order)
    {


        if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
            return null;
        }

        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return NULL;
        }

        $session = JFactory::getSession();
        $salt = JUserHelper::genRandomPassword(32);
        $crypt_virtuemartPID = JUserHelper::getCryptedPassword($order['details']['BT']->virtuemart_order_id, $salt);
        if ($session->isActive('idpay')) {
            $session->clear('idpay');
        }
        $session->set('idpay', $crypt_virtuemartPID);

        $payment_currency = $this->getPaymentCurrency($method, $order['details']['BT']->payment_currency_id);
        $totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total, $payment_currency);
        $email_currency = $this->getEmailCurrency($method);
        $dbValues['payment_name'] = $this->renderPluginName($method) . '<br />';
        $dbValues['order_number'] = $order['details']['BT']->order_number;
        $dbValues['order_pass'] = $order['details']['BT']->order_pass;
        $dbValues['virtuemart_paymentmethod_id'] = $order['details']['BT']->virtuemart_paymentmethod_id;
        $dbValues['crypt_virtuemart_pid'] = $crypt_virtuemartPID;
        $dbValues['salt'] = $salt;
        $dbValues['payment_currency'] = $order['details']['BT']->order_currency;
        $dbValues['email_currency'] = $email_currency;
        $dbValues['amount'] = $totalInPaymentCurrency['value'];
        $dbValues['mobile'] = $order['details']['BT']->phone_2;
        $this->storePSPluginInternalData($dbValues);
        $app = JFactory::getApplication();


        $api_key = $method->api_key;
        $sandbox = $method->sandbox == 0 ? 'false' : 'true';

        $amount = $totalInPaymentCurrency['value'];
        $desc = 'خرید محصول از فروشگاه   ' . $cart->vendor->vendor_store_name;
        $callback = JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&gw=IDPay';

        if (empty($amount)) {
            $msg = 'واحد پول انتخاب شده پشتیبانی نمی شود.';
            $link = JRoute::_(JUri::root() . 'index.php/component/virtuemart/cart', false);
            $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
        }

        // Customer information
        $name = $order['details']['BT']->first_name . ' ' . $order['details']['BT']->last_name;
        $phone = $order['details']['BT']->phone_2;
        $mail = $order['details']['BT']->email;


        $data = array(
            'order_id' => $order['details']['BT']->order_number,
            'amount' => $amount,
            'name' => $name,
            'phone' => $phone,
            'mail' => $mail,
            'desc' => $desc,
            'callback' => $callback,
        );

        $ch = curl_init('https://api.idpay.ir/v1.1/payment');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-API-KEY:' . $api_key,
            'X-SANDBOX:' . $sandbox,
        ));

        $result = curl_exec($ch);
        $result = json_decode($result);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);


        if ($http_status != 201 || empty($result) || empty($result->id) || empty($result->link)) {
            $msg= 'خطا هنگام ایجاد تراکنش. وضعیت خطا:'.$http_status ."<br>".'کد خطا: '.$result->error_code.' پیغام خطا '. $result->error_message;
            $this->updateStatus('P', 0, $msg, $order['details']['BT']->virtuemart_order_id);
            $this->updateOrderInfo($order['details']['BT']->virtuemart_order_id, $msg);
            $link = JRoute::_(JUri::root() . 'index.php/component/virtuemart/cart', false);
            $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
        }

        Header('Location: ' . $result->link);
    }

    public function plgVmOnPaymentResponseReceived(&$html)
    {


        if (!class_exists('VirtueMartModelOrders')) {
            require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
        }


        $app = JFactory::getApplication();
        $jinput = $app->input;
        $gateway = $jinput->get->get('gw', '', 'STRING');
        $msgNumber = $jinput->post->get('status', '', 'INTEGER');


        if ($gateway == 'IDPay') {

            $session = JFactory::getSession();
            if ($session->isActive('idpay') && $session->get('idpay') != null) {
                $cryptID = $session->get('idpay');
            } else {
                $msg = 'سفارش پیدا نشد';
                $link = JRoute::_(JUri::root() . 'index.php/component/virtuemart/cart', false);
                $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
            }
            $orderInfo = $this->getOrderInfo($cryptID);
            if ($orderInfo != null) {
                if (!($currentMethod = $this->getVmPluginMethod($orderInfo->virtuemart_paymentmethod_id))) {
                    return NULL;
                }
            } else {
                return NULL;
            }


            $salt = $orderInfo->salt;
            $id = $orderInfo->virtuemart_order_id;
            $uId = $cryptID . ':' . $salt;

            $order_id = $orderInfo->order_number;
            $payment_id = $orderInfo->virtuemart_paymentmethod_id;
            $pass_id = $orderInfo->order_pass;
            $price = round($orderInfo->amount, 5);
            $method = $this->getVmPluginMethod($payment_id);


            if (JUserHelper::verifyPassword($id, $uId)) {
                $pid = $jinput->post->get('id', '', 'STRING');
                $porder_id = $jinput->post->get('order_id', '', 'STRING');
                $pstatus = $jinput->post->get('status', 0, 'INT');


                if (!empty($pid) && !empty($porder_id) && $porder_id == $order_id) {


                    if ($pstatus == 10) {


                        $api_key = $method->api_key;
                        $sandbox = $method->sandbox == 0 ? 'false' : 'true';

                        $data = array(
                            'id' => $pid,
                            'order_id' => $order_id,
                        );


                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, 'https://api.idpay.ir/v1.1/payment/verify');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                            'Content-Type: application/json',
                            'X-API-KEY:' . $api_key,
                            'X-SANDBOX:' . $sandbox,
                        ));


                        $result = curl_exec($ch);
                        $result = json_decode($result);
                        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);


                        if ($http_status != 200) {
                            $msg = sprintf('خطا هنگام بررسی وضعیت تراکنش. وضعیت خطا: %s - کد خطا: %s - پیام خطا: %s', $http_status, $result->error_code, $result->error_message);
                            $link = JRoute::_(JUri::root() . 'index.php/component/virtuemart/cart', FALSE);
                            $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
                        }


                        $verify_status = empty($result->status) ? NULL : $result->status;
                        $verify_amount = empty($result->amount) ? NULL : $result->amount;
                        $verify_order_id = empty($result->order_id) ? NULL : $result->order_id;
                        $verify_track_id = empty($result->track_id) ? NULL : $result->track_id;
                        $hashed_card_no = empty($result->payment->hashed_card_no) ? NULL : $result->payment->hashed_card_no;
                        $card_no = empty($result->payment->hashed_card_no) ? NULL : $result->payment->hashed_card_no;


                        if (empty($verify_status) || empty($verify_track_id) || empty($verify_amount) || $verify_amount != $price || $verify_status < 100) {
                            $msg = $this->idpay_get_failed_message($method, $verify_track_id, $order_id);
                            $link = JRoute::_(JUri::root() . 'index.php/component/virtuemart/cart', FALSE);
                            $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
                        } else {

                            if ($verify_order_id !== $order_id) {
                                $this->updateStatus('P', 0, $this->otherStatusMessages(), $id);
                                $this->updateOrderInfo($id, $this->otherStatusMessages());
                                $msg = $this->idpay_get_failed_message($method, $verify_track_id, $order_id, 0);
                                $link = JRoute::_(JUri::root() . 'index.php/component/virtuemart/cart', FALSE);
                                $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
                            }


                            $msg = $this->idpay_get_success_message($method, $verify_track_id, $order_id, $msgNumber);

                            $html = $this->renderByLayout('idpay', array(
                                'order_number' => $order_id,
                                'order_pass' => $pass_id,
                                'status' => $msg
                            ));

                            $msgForSaveDataTDataBase = $this->otherStatusMessages($verify_status) . "کد پیگیری :  $verify_track_id " . "شماره کارت :  $card_no " . "شماره کارت رمزنگاری شده : $hashed_card_no ";
                            var_dump($msgForSaveDataTDataBase);
                            $this->updateStatus('C', 1, $msgForSaveDataTDataBase, $id);
                            $this->updateOrderInfo($id, sprintf('وضعیت پرداخت تراکنش: %s', $verify_status));
                            vRequest::setVar('html', $html);
                            $cart = VirtueMartCart::getCart();
                            $cart->emptyCart();
                            $session->clear('idpay');

                        }
                    } else {
                        //save pay faild pay message
                        $this->updateStatus('P', 0, $this->otherStatusMessages($msgNumber), $id);
                        $this->updateOrderInfo($id, $this->otherStatusMessages($msgNumber));
                        $msg = $this->idpay_get_failed_message($method, 'فاقد کد تراکنش', $order_id, $msgNumber);
                        $link = JRoute::_(JUri::root() . 'index.php/component/virtuemart/cart', FALSE);
                        $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
                    }
                } else {
                    $msg = 'کاربر از انجام تراکنش منصرف شده است';
                    $this->updateStatus('X', 0, $msg, $id);
                    $link = JRoute::_(JUri::root() . 'index.php/component/virtuemart/cart', false);
                    $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
                }
            } else {
                $msg = 'سفارش پیدا نشد';
                $link = JRoute::_(JUri::root() . 'index.php/component/virtuemart/cart', false);
                $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
            }
        } else {
            return NULL;
        }
    }


    protected function getOrderInfo($id)
    {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select('*')
            ->from($db->qn('#__virtuemart_payment_plg_idpay'));
        $query->where($db->qn('crypt_virtuemart_pid') . ' = ' . $db->q($id));
        $db->setQuery((string)$query);
        $result = $db->loadObject();
        return $result;
    }

    protected function updateOrderInfo($id, $trackingCode)
    {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $fields = array($db->qn('tracking_code') . ' = ' . $db->q($trackingCode));
        $conditions = array($db->qn('virtuemart_order_id') . ' = ' . $db->q($id));
        $query->update($db->qn('#__virtuemart_payment_plg_idpay'));
        $query->set($fields);
        $query->where($conditions);
        $db->setQuery($query);
        $db->execute();
    }


    protected function checkConditions($cart, $method, $cart_prices)
    {
        $address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

        if ($this->_toConvert) {
            $this->convertToVendorCurrency($method);
        }

        $countries = array();
        if (!empty($method->countries)) {
            if (!is_array($method->countries)) {
                $countries[0] = $method->countries;
            } else {
                $countries = $method->countries;
            }
        }

        if (!is_array($address)) {
            $address = array();
            $address['virtuemart_country_id'] = 0;
        }

        if (!isset($address['virtuemart_country_id'])) {
            $address['virtuemart_country_id'] = 0;
        }
        if (count($countries) == 0 || in_array($address['virtuemart_country_id'], $countries)) {
            return TRUE;
        }

        return FALSE;
    }

    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        if ($this->getPluginMethods($cart->vendorId) === 0) {
            if (empty($this->_name)) {
                $app = JFactory::getApplication();
                $app->enqueueMessage(vmText::_('COM_VIRTUEMART_CART_NO_' . strtoupper($this->_psType)));
                return false;
            } else {
                return false;
            }
        }
        $method_name = $this->_psType . '_name';

        $htmla = array();
        foreach ($this->methods as $this->_currentMethod) {
            if ($this->checkConditions($cart, $this->_currentMethod, $cart->cartPrices)) {

                $html = '';
                $cartPrices = $cart->cartPrices;
                if (isset($this->_currentMethod->cost_method)) {
                    $cost_method = $this->_currentMethod->cost_method;
                } else {
                    $cost_method = true;
                }
                $methodSalesPrice = $this->setCartPrices($cart, $cartPrices, $this->_currentMethod, $cost_method);

                $this->_currentMethod->payment_currency = $this->getPaymentCurrency($this->_currentMethod);
                $this->_currentMethod->$method_name = $this->renderPluginName($this->_currentMethod);
                $html .= $this->getPluginHtml($this->_currentMethod, $selected, $methodSalesPrice);
                $htmla[] = $html;
            }
        }
        $htmlIn[] = $htmla;
        return true;

    }


    function idpay_get_success_message($method, $track_id, $order_id)
    {
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], $method->success_massage);
    }

    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
    {
        if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
            return null;
        }

        return $this->OnSelectCheck($cart);
    }

    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter)
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    public function plgVmOnCheckoutCheckDataPayment(VirtueMartCart $cart)
    {
        if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
            return NULL;
        }
        return true;
    }

    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }


    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    static function getPaymentCurrency(&$method, $selectedUserCurrency = false)
    {
        if (empty($method->payment_currency)) {
            $vendor_model = VmModel::getModel('vendor');
            $vendor = $vendor_model->getVendor($method->virtuemart_vendor_id);
            $method->payment_currency = $vendor->vendor_currency;
            return $method->payment_currency;
        } else {

            $vendor_model = VmModel::getModel('vendor');
            $vendor_currencies = $vendor_model->getVendorAndAcceptedCurrencies($method->virtuemart_vendor_id);

            if (!$selectedUserCurrency) {
                if ($method->payment_currency == -1) {
                    $mainframe = JFactory::getApplication();
                    $selectedUserCurrency = $mainframe->getUserStateFromRequest("virtuemart_currency_id", 'virtuemart_currency_id', vRequest::getInt('virtuemart_currency_id', $vendor_currencies['vendor_currency']));
                } else {
                    $selectedUserCurrency = $method->payment_currency;
                }
            }

            $vendor_currencies['all_currencies'] = explode(',', $vendor_currencies['all_currencies']);
            if (in_array($selectedUserCurrency, $vendor_currencies['all_currencies'])) {
                $method->payment_currency = $selectedUserCurrency;
            } else {
                $method->payment_currency = $vendor_currencies['vendor_currency'];
            }

            return $method->payment_currency;
        }

    }

    protected function updateStatus($status, $notified, $comments = '', $id)
    {
        $modelOrder = VmModel::getModel('orders');
        $order['order_status'] = $status;
        $order['customer_notified'] = $notified;
        $order['comments'] = $comments;
        $modelOrder->updateStatusForOneOrder($id, $order, TRUE);
    }


    public function idpay_get_failed_message($method, $track_id, $order_id, $msgNumber = null)
    {
        $msg = $this->otherStatusMessages($msgNumber);
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], $method->failed_massage) . "<br>" . "$msg";

    }

    /**
     * @param $msgNumber
     * @get status from $_POST['status]
     * @return string
     */
    public function otherStatusMessages($msgNumber = null)
    {

        switch ($msgNumber) {
            case "1":
                $msg = "پرداخت انجام نشده است";
                break;
            case "2":
                $msg = "پرداخت ناموفق بوده است";
                break;
            case "3":
                $msg = "خطا رخ داده است";
                break;
            case "3":
                $msg = "بلوکه شده";
                break;
            case "5":
                $msg = "برگشت به پرداخت کننده";
                break;
            case "6":
                $msg = "برگشت خورده سیستمی";
                break;
            case "7":
                $msg = "انصراف از پرداخت";
                break;
            case "8":
                $msg = "به درگاه پرداخت منتقل شد";
                break;
            case "10":
                $msg = "در انتظار تایید پرداخت";
                break;
            case "100":
                $msg = "پرداخت تایید شده است";
                break;
            case "101":
                $msg = "پرداخت قبلا تایید شده است";
                break;
            case "200":
                $msg = "به دریافت کننده واریز شد";
                break;
            case "0":
                $msg = "سواستفاده از تراکنش قبلی";
                break;
            case null:
                $msg = "خطا دور از انتظار";
                $msgNumber = '1000';
                break;
        }

        return $msg . ' -وضعیت: ' . "$msgNumber";

    }


}
