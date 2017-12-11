<?php
/**
 * This module is used for real time processing of
 * Novalnet payment module of customers.
 * Released under the GNU General Public License.
 * This free contribution made by request.
 * If you have found this script useful a small
 * recommendation as well as a comment on merchant form
 * would be greatly appreciated.
 *
 * @author       Novalnet
 * @copyright(C) Novalnet. All rights reserved. <https://www.novalnet.de/>
 */

namespace Novalnet\Helper;

use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Modules\Payment\Models\Payment;
use Plenty\Modules\Payment\Models\PaymentProperty;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Payment\Contracts\PaymentOrderRelationRepositoryContract;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Plugin\Log\Loggable;
use Plenty\Plugin\Translation\Translator;

use \Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Comment\Contracts\CommentRepositoryContract;

/**
 * Class PaymentHelper
 *
 * @package Novalnet\Helper
 */
class PaymentHelper
{
    use Loggable;
    /**
     *
     * @var PaymentMethodRepositoryContract
     */
    private $paymentMethodRepository;

    /**
     *
     * @var PaymentRepositoryContract
     */
    private $paymentRepository;

    /**
     *
     * @var OrderRepositoryContract
     */
    private $orderRepository;

    /**
     *
     * @var PaymentOrderRelationRepositoryContract
     */
    private $paymentOrderRelationRepository;

     /**
     *
     * @var orderComment
     */
    private $orderComment;

    /**
     *
     * @var FrontendSessionStorageFactoryContract
     */
    private $session;


    /**
     * Constructor.
     *
     * @param PaymentMethodRepositoryContract $paymentMethodRepository
     * @param PaymentRepositoryContract $paymentRepository
     * @param OrderRepositoryContract $orderRepository
     * @param PaymentOrderRelationRepositoryContract $paymentOrderRelationRepository
     * @param CommentRepositoryContract $orderComment
     * @param FrontendSessionStorageFactoryContract $session
     */
    public function __construct(PaymentMethodRepositoryContract $paymentMethodRepository, PaymentRepositoryContract $paymentRepository, OrderRepositoryContract $orderRepository, PaymentOrderRelationRepositoryContract $paymentOrderRelationRepository, CommentRepositoryContract $orderComment, FrontendSessionStorageFactoryContract $session)
    {
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->paymentRepository = $paymentRepository;
        $this->orderRepository = $orderRepository;
        $this->paymentOrderRelationRepository = $paymentOrderRelationRepository;
        $this->orderComment = $orderComment;
        $this->session = $session;
    }

    /**
     * Create the ID of the payment method if it doesn't exist yet
     */
    public function createMopIfNotExists()
    {
        // Check whether the ID of the Pay upon pickup payment method has been created
        if($this->getPaymentMethod() == 'no_paymentmethod_found')
        {
            $paymentMethodData = array( 'pluginKey' => 'plenty_novalnet',
                                        'paymentKey' => 'NOVALNET',
                                        'name' => 'Novalnet');
            $this->paymentMethodRepository->createPaymentMethod($paymentMethodData);
        }
    }

    /**
     * Load the ID of the payment method
     * Return the ID for the payment method
     *
     * @return string|int
     */
    public function getPaymentMethod()
    {
        $paymentMethods = $this->paymentMethodRepository->allForPlugin('plenty_novalnet');

        if( !is_null($paymentMethods) )
        {
            foreach($paymentMethods as $paymentMethod)
            {
                if($paymentMethod->paymentKey == 'NOVALNET')
                {
                    return $paymentMethod->id;
                }
            }
        }
        return 'no_paymentmethod_found';
    }


    /**
     * create the Plenty payment
     * Return the Plenty payment object
     *
     * @param array $data
     * @return object
     */
    public function createPlentyPayment($data)
    {
        /** @var Payment $payment */
        $payment = pluginApp(\Plenty\Modules\Payment\Models\Payment::class);

        $payment->mopId = (int) $this->getPaymentMethod();
        $payment->transactionType = Payment::TRANSACTION_TYPE_BOOKED_POSTING;
        $payment->status = Payment::STATUS_CAPTURED;
        $payment->currency = $data['currency'];
        $payment->amount = $data['paid_amount'];
        $transactionId = $data['tid'];
        if(!empty($data['type']))
        {
            $payment->type = $data['type'];
            $payment->status = Payment::STATUS_REFUNDED;
        }

        $paymentProperty = [];
        $paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_BOOKING_TEXT, $transactionId);
        $paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_TRANSACTION_ID, $transactionId);
        $paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_ORIGIN, Payment::ORIGIN_PLUGIN);
        $payment->properties = $paymentProperty;

        $payment = $this->paymentRepository->createPayment($payment);
        return $payment;
    }


    /**
     * Get the payment property
     *
     * @param mixed $typeId
     * @param mixed $value
     * @return object
     */
    private function getPaymentProperty($typeId, $value)
    {
        /** @var PaymentProperty $paymentProperty */
        $paymentProperty = pluginApp(\Plenty\Modules\Payment\Models\PaymentProperty::class);

        $paymentProperty->typeId = $typeId;
        $paymentProperty->value = (string) $value;

        return $paymentProperty;
    }

    /**
     * Assign the payment to an order in plentymarkets.
     *
     * @param Payment $payment
     * @param int $orderId
     */
    public function assignPlentyPaymentToPlentyOrder(Payment $payment, int $orderId)
    {
        try {
        /** @var \Plenty\Modules\Authorization\Services\AuthHelper $authHelper */
        $authHelper = pluginApp(AuthHelper::class);
        $authHelper->processUnguarded(
                function () use ($payment, $orderId) {
                //unguarded
                $order = $this->orderRepository->findOrderById($orderId);
                if (! is_null($order) && $order instanceof Order) {
                    $this->paymentOrderRelationRepository->createOrderRelation($payment, $order);
                }
            }
        );
        } catch (\Exception $e) {
                $this->getLogger(__METHOD__)->error('Novalnet::assignPlentyPaymentToPlentyOrder', $e);
        }
    }


    /**
     * Update order status by order id
     *
     * @param int $orderId
     * @param float $statusId
     */
    public function updateOrderStatus($orderId,$statusId)
    {
        try {
            /** @var \Plenty\Modules\Authorization\Services\AuthHelper $authHelper */
            $authHelper = pluginApp(AuthHelper::class);
            $authHelper->processUnguarded(
                    function () use ($orderId, $statusId) {
                    //unguarded
                    $order = $this->orderRepository->findOrderById($orderId);
                    if (!is_null($order) && $order instanceof Order) {
                        $status['statusId'] = (float) $statusId;
                        $this->orderRepository->updateOrder($status, $orderId);
                    }
                }
            );
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Novalnet::updateOrderStatus', $e);
        }
    }


    /**
     * Save order comment by order id
     *
     * @param int $orderId
     * @param string $text
     */
    public function createOrderComments($orderId, $text)
    {
        try{
            $authHelper = pluginApp(AuthHelper::class);
            $authHelper->processUnguarded(
                    function () use ($orderId, $text) {
                    $comment['referenceType'] = 'order';
                    $comment['referenceValue'] = $orderId;
                    $comment['text'] = $text;
                    $comment['isVisibleForContact'] = true;
                    $this->orderComment->createComment($comment);
                }
            );
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Novalnet::createOrderComments', $e);
        }
    }



    /**
    /**
    /**
    /**
     * Return payments details by order id
     * @param int $orderId
     * @return bool|object
     */
    public function getPaymentsByOrderId($orderId)
    {
        try {
            $payments = $this->paymentRepository->getPaymentsByOrderId($orderId);
            return $payments;
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Novalnet::getPaymentsByOrderId', $e);
            return false;
        }
    }

    /**
     * Get the payment property value by payment property type
     * @param Payment $payment
     * @param int $propertyType
     * @return mixed
     */
    public function getPaymentPropertyValue($payment, $propertyType)
    {
        $properties = $payment->properties;

        if(($properties->count() > 0) || (is_array($properties ) && count($properties ) > 0))
        {
            /** @var PaymentProperty $property */
            foreach($properties as $property)
            {
                if($property instanceof PaymentProperty)
                {
                    if($property->typeId == $propertyType)
                    {
                        return $property->value;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get the Novalnet status message.
     *
     * @param array $response
     * @return string
     */
    public function getNovalnetStatusText($response)
    {
        $message = ((!empty($response['status_message']))
                ? $response['status_message']
                : ((!empty($response['status_desc']))
                    ? $response['status_desc']
                    : ((!empty($response['status_text'])
                        ? $response['status_text']
                        : $this->getTranslatedText('payment_not_success')))));
        return $message;
   }

   /**
    * Execute curl process
    *
    * @param array $data
    * @param string $url
    * @return array
    */
   public function executeCurl($data, $url)
   {
        $curl = curl_init();
        // Set cURL options
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        return ['response'=>$response,'error'=>$err];
   }

   /**
    * Get payment Name/Code by server response
    *
    * @param array $response
    * @param bool $code
    * @return string
    */
   public function getPaymentNameByResponse($response,$code = false)
   {
       $payment_name = '';
       $key = $response['payment_id'];
       if(!$code) {
           if(in_array($key,array('27','41')))
           {
            $payment_name = $this->getTranslatedText('invoice_name');
            if('PREPAYMENT' == $response['invoice_type'])
                $payment_name = $this->getTranslatedText('prepayment_name');
           } else {
           $payment_name_array = array(
                '6'     => $this->getTranslatedText('cc_name'),
                '37'    => $this->getTranslatedText('sepa_name'),
                '33'    => $this->getTranslatedText('sofort_name'),
                '34'    => $this->getTranslatedText('paypal_name'),
                '49'    => $this->getTranslatedText('ideal_name'),
                '50'    => $this->getTranslatedText('eps_name'),
                '59'    => $this->getTranslatedText('cashpayment_name'),
                '69'    => $this->getTranslatedText('giropay_name'),
                '78'    => $this->getTranslatedText('przelewy_name'),
                '40'    => $this->getTranslatedText('sepa_name'),
            );
           $payment_name = $payment_name_array[$key];
           }
        } else {

            if(in_array($key,array('27','41')))
            {
                $payment_name = 'novalnet_invoice';
                if('PREPAYMENT' == $response['invoice_type'])
                    $payment_name = 'novalnet_prepayment';
            } else {
               $payment_name_array = array(
                    '6'     => 'novalnet_cc',
                    '37'    => 'novalnet_sepa',
                    '33'    => 'novalnet_instant',
                    '34'    => 'novalnet_paypal',
                    '49'    => 'novalnet_ideal',
                    '50'    => 'novalnet_eps',
                    '59'    => 'novalnet_cashpayment',
                    '69'    => 'novalnet_giropay',
                    '78'    => 'novalnet_przelewy24',
                    '40'    => 'novalnet_sepa',
                );
               $payment_name = $payment_name_array[$key];
            }
       }
       return $payment_name;
   }

   /**
    * Build Transaction comment
    *
    * @param array $data
    * @param array $config
    */
   public function getTransactionComments($data, $config)
   {
       $comments = '</br>';
       $comments .= $this->getPaymentNameByResponse($data);
       $comments .= '</br>' . $this->getTranslatedText('nn_tid') . $data['tid'];
       if(!empty($data['test_mode']) || !empty($config['test_mode']))
           $comments .= '</br>' . $this->getTranslatedText('test_order');
       if(in_array($data['payment_id'],array('40','41')))
           $comments .= '</br>' . $this->getTranslatedText('guarantee_text');
       if(in_array($data['payment_id'],array('27','41'))) {
           $comments .= '</br>' . $this->getAdditionalBankDetails($data, $config);
           } else if($data['payment_id'] == '59') {
                   $comments .= '</br>' . $this->getCashPaymentComments($data);
           }

       return $comments;
   }

   /**
    * Build Bank details comment
    *
    * @param array $data
    * @return string
    */
   public function getAdditionalBankDetails($data, $config)
   {
       $comments = '';
       $comments .= $this->getTranslatedText('transfer_amount_text');
       if(!empty($data['due_date']))
       {
        $due_date =  (string)$data['due_date'];
        $comments.= '</br>' . $this->getTranslatedText('due_date') . date('Y/m/d', (int)strtotime($due_date));
       }
       $acc_holder = 'Novalnet AG';
       if(!empty($data['invoice_account_holder'])) {
            $acc_holder = $data['invoice_account_holder'];
       }
       $comments .= '</br>' . $this->getTranslatedText('account_holder_novalnet') . $acc_holder;
       $comments .= '</br>' . $this->getTranslatedText('iban') . $data['invoice_iban'];
       $comments .= '</br>' . $this->getTranslatedText('bic') . $data['invoice_bic'];
       $comments .= '</br>' . $this->getTranslatedText('bank') . $this->checkUtfcharacter($data['invoice_bankname']) . ' ' . $this->checkUtfcharacter($data['invoice_bankplace']);
       $comments .= '</br>' . $this->getTranslatedText('amount') . $data['amount'] . ' ' . $data['currency'];


       if(isset($config['reference'])) {
               $invoice_comments = '';
               $references[1] = (int) preg_match("/ref/", $config['reference']);
               $references[2] = (int) preg_match("/tid/", $config['reference']);
               $references[3] = (int) preg_match("/order_no/", $config['reference']);
               $i = 1;
               $count_reference   = $references[1] + $references[1] + $references[3];
               $invoice_comments .= '</br></br>'.(($count_reference > 1) ? $this->getTranslatedText('any_one_reference_text') : $this->getTranslatedText('single_ref_text'));
               foreach ($references as $key => $value) {
                   if ($references[$key] == 1) {
                       $invoice_comments .= '</br>'.(($count_reference == 1) ? $this->getTranslatedText('single_ref') : sprintf($this->getTranslatedText('multi_ref'), $i++));
                       $invoice_comments .= ($key == 1) ? ('BNR-'.$config['product'].'-'.$data['order_no']) : ($key == 2 ? 'TID '.$data['tid'] : $this->getTranslatedText('order_no').$data['order_no']);
                   }
               }
               $comments .= $invoice_comments;
       }
       $comments .= '</br>';

       return $comments;
   }

   /**
     * Build Bank details comment
     *
     * @param array $data
     * @return string
     */
   public function getCashPaymentComments($data)
   {
       $comments = $this->getTranslatedText('cashpayment_expire_date') . $data['cashpayment_due_date'] . '</br>';
       $comments .= '</br><b>' . $this->getTranslatedText('cashpayment_near_you') . '</b></br></br>';

        $strNos = 0;
        foreach($data as $key => $val) {
            if(strpos($key, 'nearest_store_title') !== false){
                $strnos++;
            }
        }
        for($i = 1; $i <= $strnos; $i++){
            $countryName = !empty($data['nearest_store_country_' . $i])
                ? $data['nearest_store_country_' . $i] : '';

            $comments .= $data['nearest_store_title_' . $i] . '</br>';
            $comments .= $countryName . '</br>';
            $comments .= $this->checkUtfcharacter($data['nearest_store_street_' . $i]) . '</br>';
            $comments .= $data['nearest_store_city_' . $i] . '</br>';
            $comments .= $data['nearest_store_zipcode_' . $i] . '</br></br>';
        }
        return $comments;
   }

    /**
     * Generate 16 digit unique number
     *
     */
    public function getUniqueId()
    {
        return rand(1000000000000000,9999999999999999);
    }

    /**
     * Encode data
     *
     * @param mixed $data
     * @param mixed $uniqid
     * @param string $access_key
     * @return string
     */
    public function encodeData($data,$uniqid,$access_key)
    {
        ### Encryption process
        $encrypted_data = htmlentities(base64_encode(openssl_encrypt($data, "aes-256-cbc", $access_key, 1, $uniqid)));
        ### Response
        return $encrypted_data;
    }

    /**
     * Decode data
     *
     * @param mixed $data
     * @param mixed $uniqid
     * @param string $access_key
     * @return string
     */
    public function decodeData($data, $uniqid, $access_key)
    {
        $decrypted_data = openssl_decrypt(base64_decode($data), "aes-256-cbc", $access_key, 1, $uniqid);
        return $decrypted_data;
    }

    /**
     * Generate Unique Hash
     *
     * @param array $data
     * @param string $access_key
     * @return string
     */
    public function generateHash($data, $access_key)
    {
        if (!function_exists('hash')) {
            return 'Error: Function n/a';
        }
        $strRevKey = $this->reverseString($access_key);
        return hash('sha256', ($data['auth_code'].$data['product'].$data['tariff'].$data['amount'].$data['test_mode'].$data['uniqid'].$strRevKey));
    }

    /**
     * Reverse the given string
     *
     * @param mixed $str
     * @return string
     */
    public function reverseString($str)
    {
        $string = '';
        //find string length
        $len = strlen($str);
        //loop through it and print it reverse
        for($i=$len-1;$i>=0;$i--)
        {
            $string .= $str[$i];
        }
        return $string;
    }

    /**
     * Get the language from session
     *
     * @return string
     */
    public function getLang()
    {
        $lang = $this->session->getLocaleSettings()->language;
        $langVal = 'en';
        if($lang == 'de')
        {
            $langVal = 'de';
        }
        return $langVal;
    }

   /**
    * Get the Translated text
    *
    * @return array
    */
    public function getTranslatedText($key)
    {
        $text = '';
        if(!empty($key)){
            $translator = pluginApp(Translator::class);
            $text = $translator->trans("Novalnet::PaymentMethod.".$key);
        }
        return $text;
    }
    
    /**
    * Check given string is UTF-8
    *
    * @param string $str
    * @return string
    */
    public function checkUtfcharacter($str)
    {
        $decoded = utf8_decode($str);
        if(mb_detect_encoding($decoded , 'UTF-8', true) === false){
            return $str;
        } else {
            return $decoded;
        }   
    }    

}
