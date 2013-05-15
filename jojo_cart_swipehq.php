<?php
/**
 *                    Jojo CMS
 *                ================
 *
 * Copyright 2008 Harvey Kane <code@ragepank.com>
 * Copyright 2008 Michael Holt <code@gardyneholt.co.nz>
 *
 * See the enclosed file license.txt for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @author  Harvey Kane <code@ragepank.com>
 * @license http://www.fsf.org/copyleft/lgpl.html GNU Lesser General Public License
 * @link    http://www.jojocms.org JojoCMS
 */


class jojo_plugin_jojo_cart_swipehq extends JOJO_Plugin
{
    /* checks if a currency is supported  */
    function isValidCurrency($currency)
    {
        $currencies_str = 'NZD';
        $currencies = explode(',', $currencies_str);
        /* remove whitespace */
        foreach ($currencies as &$c) {
            $c = trim($c);
            if (strtoupper($currency) == strtoupper($c)) return true;
        }
        return false;
    }
    
    function getPaymentOptions()
    {
        /* ensure the order currency is the same as DPS currency */
        $currency = call_user_func(array(Jojo_Cart_Class, 'getCartCurrency'));
        if (!self::isValidCurrency($currency)) return array();

        global $smarty;
        $options = array();

        /* get available card types */
        $cardtypes = explode(',', 'visa,mastercard');
        $cardimages = array();

        /* uppercase first letter of each card type */
        foreach ($cardtypes as $k => $v) {
            $cardtypes[$k] = trim(ucwords($v));
            if ($cardtypes[$k] == 'Visa') {
                $cardimages[$k] = '<img class="creditcard-icon" src="images/creditcardvisa.gif" alt="Visa" />';
            } elseif ($cardtypes[$k] == 'Mastercard') {
                $cardimages[$k] = '<img class="creditcard-icon" src="images/creditcardmastercard.gif" alt="Mastercard" />';
            } elseif ($cardtypes[$k] == 'Amex') {
                $cardimages[$k] = '<img class="creditcard-icon" src="images/creditcardamex.gif" alt="American Express" />';
            }
        }
        $smarty->assign('cardtypes', $cardtypes);
        $options[] = array('id' => 'swipehq', 'label' => 'Pay now by Credit card via secure payment provider SwipeHQ'.implode(', ', $cardimages), 'html' => $smarty->fetch('jojo_cart_swipehq_checkout.tpl'));
        return $options;
    }

    /*
    * Determines whether this payment plugin is active for the current payment.
    */
    function isActive()
    {
        /* they submitted the form from the checkout page */
        if (Jojo::getFormData('handler', false) == 'swipehq') return true;
        if (isset($_POST['td_user_data'])) return true;
        if (!isset($_GET['result'])) return false;

        /* Ensure the transaction has not already been processed - DPS may ping the script more than once */
        $token    = Jojo::getFormData('token', false);
        if ($token && isset($_GET['result'])) {
            $data = Jojo::selectQuery("SELECT * FROM {cart} WHERE token=? AND status='complete'", $token);
            if (count($data)) {
                /* redirect to thank you page if the transaction has been processed already */
                global $page;
                $languageurlprefix = $page->page['pageid'] ? Jojo::getPageUrlPrefix($page->page['pageid']) : $_SESSION['languageurlprefix'];
                Jojo::redirect(_SECUREURL.'/' .$languageurlprefix. 'cart/complete/'.$token.'/', 302);
            }
        }
        return true;

    }

    function process()
    {
        global $page;
        $languageurlprefix = $page->page['pageid'] ? Jojo::getPageUrlPrefix($page->page['pageid']) : $_SESSION['languageurlprefix'];
        
        $cart     = call_user_func(array(Jojo_Cart_Class, 'getCart'));
        $testmode = call_user_func(array(Jojo_Cart_Class, 'isTestMode'));
        $token    = Jojo::getFormData('token', false);

        $errors  = array();

        /* Get visitor details for emailing etc */
        if (!empty($cart->fields['billing_email'])) {
            $email = $cart->fields['billing_email'];
        } elseif (!empty($cart->fields['shipping_email'])) {
            $email = $cart->fields['shipping_email'];
        } else {
            $email = Jojo::either(_CONTACTADDRESS,_FROMADDRESS,_WEBMASTERADDRESS);
        }

        /* ensure the order currency is the same as SwipeHQ currency */
        $currency = call_user_func(array(Jojo_Cart_Class, 'getCartCurrency'));
        if (!self::isValidCurrency($currency)) {
            return array(
                        'success' => false,
                        'receipt' => '',
                        'errors'  => array('This plugin is only currently able to process transactions in ' . 'NZD' . '.')
                        );
        }

        /* error checking */

        /* set authentication constants, used in the script */

            define('SWIPEHQ_ID', Jojo::getOption('swipehq_merchantid', false));
            define('SWIPEHQ_KEY', Jojo::getOption('swipehq_key', false));

        /* Ensure the transaction has not already been processed - DPS may ping the script more than once */
        $data = Jojo::selectQuery("SELECT * FROM {cart} WHERE token=? AND status='complete'", $token);
        if (count($data)) {
            /* redirect to thank you page if the transaction has been processed already */
            Jojo::redirect(Jojo::either(_SECUREURL, _SITEURL) . '/' .$languageurlprefix. 'cart/complete/'.$token.'/', 302);
        }

        /* check for $result data appended to querystring */
        $result = Jojo::getFormData('result', false); //'result' is the encrypted response from DPS

        if (isset($_POST['status'])) {
        	$Success = (boolean)($_POST['status']=='accepted');
        	
        	/* Check the post is for real */
			$url = "https://api.swipehq.com/verifyTransaction.php";

			$body = "api_key=" . Jojo::getOption('swipehq_key', false);
			$body .= "&merchant_id=" . Jojo::getOption('swipehq_merchantid', false);
			$body .= "&transaction_id=" . htmlentities($_POST['transaction_id']);
			$verify = self::post_to_url($url, $body);
			$verify = json_decode($transactionid);

			if ($verify->response_code != 200 || $verify->data->transaction_approved != 'yes') {
				$Success = false;
			}

            /* build receipt */
            $receipt = array('Transaction Amount' => htmlentities($_POST['amount']),
                             'Transaction ID'          => htmlentities($_POST['transaction_id']),
                             'Card Name'          => htmlentities($_POST['name_on_card']),
                             'Email Address'  => ((isset($_POST['customer_email'])) ? htmlentities($_POST['customer_email']) : ''),
                             'Response'           => htmlentities($_POST['status'])
                             );

            $message = ($Success) ? "Thank you for your payment via $CardName Credit Card.": '';

            return array(
                        'success' => $Success,
                        'receipt' => $receipt,
                        'errors'  => $errors,
                        'message' => $message
                        );

        } else {
            /* Prepare the request, send request to SwipeHQ, then redirect user to URL provided */
        
			$url = "https://api.swipehq.com/createTransactionIdentifier.php";

			$body = "api_key=" . Jojo::getOption('swipehq_key', false);
			$body .= "&merchant_id=" . Jojo::getOption('swipehq_merchantid', false);
			$body .= "&td_item=RSCart";
			$body .= "&td_default_quantity=1";
			$body .= "&td_amount=" . number_format($cart->order['amount'], 2, '.', '');
			$body .= "&td_user_data=" . $cart->token;

			$transactionid = self::post_to_url($url, $body);
			$transactionid = json_decode($transactionid);

			if ($transactionid->response_code !=200 || $transactionid->message !='OK') {
			   return array(
							'success' => false,
							'receipt' => '',
							'errors'  => array('There was a problem accessing SwipeHQ.')
							);
			}
			$identifier = $transactionid->data->identifier;
			$paymenturl = "https://payment.swipehq.com/?identifier_id="  . $identifier;
            Jojo::redirect($paymenturl, 302);
         }
    }

	public static function getToken(){
		if (isset($_POST) && $_POST['td_user_data']) {
		 	return $_POST['td_user_data'];
		}
		
		return false;
	}

	private static function post_to_url($url, $body){
		$ch = curl_init ($url);
		curl_setopt ($ch, CURLOPT_POST, 1);
		curl_setopt ($ch, CURLOPT_POSTFIELDS, $body);
		curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,0);
		curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,0);
		$html = curl_exec ($ch);
		curl_close ($ch);
		return $html;
	}
}