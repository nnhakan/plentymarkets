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

namespace Novalnet\Controllers;

use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;

use Novalnet\Helper\PaymentHelper;
use Plenty\Plugin\Templates\Twig;
use Novalnet\Services\CallbackService;
use Plenty\Plugin\Log\Loggable;
use Plenty\Plugin\Mail\Contracts\MailerContract;

/**
 * Class CallbackController
 * @package Novalnet\Controllers
 */
class CallbackController extends Controller
{
    use Loggable;
    /**
     * @var config
     */
    private $config;

    /**
     * @var PaymentHelper
     */
    private $paymentHelper;

    /**
     * @var twig
     */
    private $twig;

    /**
     * @var callback
     */
    private $callback;

    /**
     * @var aryCapture
     */
    private $aryCapture;

    /*
     * @var aryPayments
     * @Array Type of payment available - Level : 0
     */
    protected $aryPayments = ['CREDITCARD', 'INVOICE_START', 'DIRECT_DEBIT_SEPA', 'GUARANTEED_INVOICE', 'GUARANTEED_DIRECT_DEBIT_SEPA', 'PAYPAL', 'ONLINE_TRANSFER', 'IDEAL', 'GIROPAY', 'PRZELEWY24', 'EPS', 'CASHPAYMENT'];

    /**
     * @var aryChargebacks
     * @Array Type of Chargebacks available - Level : 1
     */
    protected $aryChargebacks = ['PRZELEWY24_REFUND', 'RETURN_DEBIT_SEPA', 'REVERSAL', 'CREDITCARD_BOOKBACK', 'CREDITCARD_CHARGEBACK', 'PAYPAL_BOOKBACK', 'REFUND_BY_BANK_TRANSFER_EU', 'CASHPAYMENT_REFUND'];

    /**
     * @var aryCollection
     * @Array Type of CreditEntry payment and Collections available - Level : 2
     */
    protected $aryCollection = ['INVOICE_CREDIT', 'CREDIT_ENTRY_CREDITCARD', 'CREDIT_ENTRY_SEPA', 'DEBT_COLLECTION_SEPA', 'DEBT_COLLECTION_CREDITCARD', 'CASHPAYMENT_CREDIT'];

    /**
     * @var arySubscription
     */
    protected $arySubscription = ['SUBSCRIPTION_STOP'];

    /**
     * @var aryPaymentGroups
     */
    protected $aryPaymentGroups = [
                'novalnet_cc' => [
                        'CREDITCARD',
                                'CREDITCARD_BOOKBACK',
                                'CREDITCARD_CHARGEBACK',
                        'CREDIT_ENTRY_CREDITCARD',
                                'DEBT_COLLECTION_CREDITCARD',
                                'SUBSCRIPTION_STOP',
                        ],
                'novalnet_sepa' => [
                        'DIRECT_DEBIT_SEPA',
                                'RETURN_DEBIT_SEPA',
                                'CREDIT_ENTRY_SEPA',
                                'DEBT_COLLECTION_SEPA',
                                'GUARANTEED_DIRECT_DEBIT_SEPA',
                                'REFUND_BY_BANK_TRANSFER_EU',
                                'SUBSCRIPTION_STOP',
                        ],
                'novalnet_ideal' => [
                        'IDEAL',
                        'REVERSAL',
                        'REFUND_BY_BANK_TRANSFER_EU'
                        ],
                'novalnet_instant' => [
                        'ONLINE_TRANSFER',
                                'REVERSAL',
                        'REFUND_BY_BANK_TRANSFER_EU'
                        ],
                'novalnet_paypal' => [
                        'PAYPAL',
                        'SUBSCRIPTION_STOP',
                        'PAYPAL_BOOKBACK',
                                'REFUND_BY_BANK_TRANSFER_EU'
                        ],
                'novalnet_prepayment' => [
                        'INVOICE_START',
                        'INVOICE_CREDIT',
                        'SUBSCRIPTION_STOP'
                        ],
                'novalnet_invoice' => [
                        'INVOICE_START',
                        'GUARANTEED_INVOICE',
                        'INVOICE_CREDIT',
                        'SUBSCRIPTION_STOP'
                        ],
                'novalnet_eps' => [
                        'EPS',
                        'REFUND_BY_BANK_TRANSFER_EU'
                        ],
                'novalnet_giropay' => [
                        'GIROPAY',
                        'REFUND_BY_BANK_TRANSFER_EU'
                        ],
                'novalnet_przelewy24' => [
                        'PRZELEWY24',
                        'PRZELEWY24_REFUND'
                        ],
                'novalnet_cashpayment' => [
                        'CASHPAYMENT',
                        'CASHPAYMENT_CREDIT',
                        'CASHPAYMENT_REFUND',
                        ],
                ];

    /**
     * @var aryCaptureparams
     * @Array Callback Capture parameters
     */
    protected $aryCaptureparams = [];

    /**
     * @var paramsRequired
     */
    protected $paramsRequired = [];

    /**
     * @var ipAllowed
     * @IP-ADDRESS Novalnet IP, is a fixed value, DO NOT CHANGE!!!!!
     */
    protected $ipAllowed = ['195.143.189.210', '195.143.189.214'];

    /**
     * @var processTestMode
     */
    protected $processTestMode;

    /**
     * @var sendTestMail
     */
    protected $sendTestMail;

    /**
     * @var to
     */
    protected $to;

    /**
     * @var bcc
     */
    protected $bcc;


    /**
     * PaymentController constructor.
     *
     * @param Request $request
     * @param ConfigRepository $config
     * @param PaymentHelper $paymentHelper
     * @param Twig $twig
     * @param CallbackService $callbackDb
     */
    public function __construct(  Request $request,
                                  ConfigRepository $config,
                                  PaymentHelper $paymentHelper,
                                  Twig $twig,
                                  CallbackService $callbackDb
                                )
    {
        $this->config           = $config;
        $this->paymentHelper    = $paymentHelper;
        $this->twig             = $twig;
        $this->callback         = $callbackDb;
        $this->aryCapture       = $request->all();
        $this->processTestMode  = $this->config->get('Novalnet.callback_test_mode');
        $this->sendTestMail     = $this->config->get('Novalnet.enable_email');
        $this->to = $this->config->get('Novalnet.email_to');
        $this->bcc = $this->config->get('Novalnet.email_bcc');
        $this->paramsRequired = array('vendor_id', 'tid', 'payment_type', 'status', 'tid_status');
        if(isset($this->aryCapture['subs_billing']) && $this->aryCapture['subs_billing'] == 1){
            array_push($this->paramsRequired, 'signup_tid');
        } elseif (isset($this->aryCapture['payment_type'])
                && in_array($this->aryCapture['payment_type'], array_merge($this->aryChargebacks, $this->aryCollection))) {
            array_push($this->paramsRequired, 'tid_payment');
        }
    }

    /**
     * Execute callback process for PAYPAL, PRZELEWY24, INVOICE_CREDIT, CASHPAYMENT_CREDIT, CREDITCARD_BOOKBACK, PAYPAL_BOOKBACK, REFUND_BY_BANK_TRANSFER_EU, PRZELEWY24_REFUND and CASHPAYMENT_REFUND
     */
    public function processCallback()
    {
        if($ipCheck = $this->validateIpAddress())
           return $ipCheck;
        $this->aryCaptureparams = $this->validateCaptureParams($this->aryCapture);
        if(is_string($this->aryCaptureparams))
           return $this->renderTemplate($this->aryCaptureparams);
        if(!isset($this->aryCaptureparams['vendor_activation'] ) || 1 != $this->aryCaptureparams['vendor_activation'] ) {
        $nntransHistory = $this->getOrderDetails();
        if(is_string($nntransHistory))
                return $this->renderTemplate($nntransHistory);
        if($this->getPaymentTypeLevel() == 2 && $this->aryCaptureparams['tid_status'] == '100') {
            //Credit entry of INVOICE or PREPAYMENT
            if(in_array($this->aryCaptureparams['payment_type'], array('INVOICE_CREDIT', 'CASHPAYMENT_CREDIT')) && $this->aryCaptureparams['tid_status'] == 100) {
                if($this->aryCaptureparams['subs_billing'] != 1) {
                    if ($nntransHistory->order_paid_amount < $nntransHistory->order_total_amount) {
                        $paymentData = [];
                        $callback_greater_amount = '';
                        $callback_comments = '</br>';
                        $callback_comments .= sprintf('Novalnet Callback Script executed successfully for the TID: %s with amount %s %s on %s. Please refer PAID transaction in our Novalnet Merchant Administration with the TID: %s', $this->aryCaptureparams['shop_tid'], ($this->aryCaptureparams['amount']/100), $this->aryCaptureparams['currency'], date('Y-m-d H:i:s'), $this->aryCaptureparams['tid'] ).'</br>';
                        if($nntransHistory->order_total_amount <= ($nntransHistory->order_paid_amount + $this->aryCaptureparams['amount'])) {
                            if($nntransHistory->paymentName == 'novalnet_invoice') {
                                $order_status = (float) $this->config->get('Novalnet.invoice_callback_order_status');
                            } elseif($nntransHistory->paymentName == 'novalnet_prepayment') {
                                $order_status = (float) $this->config->get('Novalnet.prepayment_callback_order_status');
                            } else {
                                $order_status = (float) $this->config->get('Novalnet.cashpayment_callback_order_status');
                            }
                            $this->paymentHelper->updateOrderStatus($nntransHistory->orderNo,$order_status);
                        }
                        $paymentData['currency'] = (string) $this->aryCaptureparams['currency'];
                        $paymentData['paid_amount'] = (float) ($this->aryCaptureparams['amount']/100);
                        $paymentData['tid'] = $this->aryCaptureparams['tid'];

                        $callback_table_param['callback_amount'] = $this->aryCaptureparams['amount'];
                        $callback_table_param['amount'] = $nntransHistory->order_total_amount;
                        $callback_table_param['tid'] = $this->aryCaptureparams['shop_tid'];
                        $callback_table_param['ref_tid'] = $this->aryCaptureparams['tid'];
                        $callback_table_param['payment_name'] = $nntransHistory->paymentName;
                        $callback_table_param['order_no'] = $nntransHistory->orderNo;
                        $this->callback->saveCallback($callback_table_param);
                        $payment = $this->paymentHelper->createPlentyPayment($paymentData);
                        $this->paymentHelper->assignPlentyPaymentToPlentyOrder($payment, $nntransHistory->orderNo);
                        $this->paymentHelper->createOrderComments($nntransHistory->orderNo, $callback_comments);
                        if($this->sendTestMail && $this->to)
                        {
                            $cc = [];
                            $this->sendMail($callback_comments, $this->to, 'Novalnet Callback Script Access Report', $cc, $this->bcc);
                        }

                        return $this->renderTemplate($callback_comments);
                 }else{
                      return $this->renderTemplate('Novalnet callback received. Callback Script executed already. Refer Order :'.$nntransHistory->orderNo);
                 }
            }
         } else {
            $error = 'Novalnet Callbackscript received. Payment type ( '.$this->aryCaptureparams['payment_type'].' ) is not applicable for this process!';
                    return $this->renderTemplate($error);
         }
        } else if($this->getPaymentTypeLevel() == 1 && $this->aryCaptureparams['tid_status'] == 100) {
            $callback_comments = '</br>';
            $callback_comments .= (in_array( $this->aryCaptureparams['payment_type'], array( 'CREDITCARD_BOOKBACK', 'PAYPAL_BOOKBACK', 'REFUND_BY_BANK_TRANSFER_EU', 'PRZELEWY24_REFUND', 'CASHPAYMENT_REFUND' ))) ? sprintf(' Novalnet callback received. Refund/Bookback executed successfully for the TID: %s amount: %s %s on %s. The subsequent TID: %s.', $nntransHistory->tid, sprintf( '%0.2f',( $this->aryCaptureparams['amount']/100) ) , $this->aryCaptureparams['currency'], date('Y-m-d H:i:s'), $this->aryCaptureparams['tid'] ) . '</br>' : sprintf( ' Novalnet callback received. Chargeback executed successfully for the TID: %s amount: %s %s on %s. The subsequent TID: %s.', $nntransHistory->tid, sprintf( '%0.2f',( $this->aryCaptureparams['amount']/100) ), $this->aryCaptureparams['currency'], date('Y-m-d H:i:s'), $this->aryCaptureparams['tid'] ) . '</br>';

            $callback_table_param['callback_amount'] = $this->aryCaptureparams['amount'];
            $callback_table_param['amount'] = $nntransHistory->order_total_amount;
            $callback_table_param['tid'] = $this->aryCaptureparams['shop_tid'];
            $callback_table_param['ref_tid'] = $this->aryCaptureparams['tid'];
            $callback_table_param['payment_name'] = $nntransHistory->paymentName;
            $callback_table_param['order_no'] = $nntransHistory->orderNo;
            $this->callback->saveCallback($callback_table_param);

            $paymentData['currency'] = (string) $this->aryCaptureparams['currency'];
            $paymentData['paid_amount'] = (float) ($this->aryCaptureparams['amount']/100);
            $paymentData['tid'] = $this->aryCaptureparams['tid'];
            $paymentData['type'] = 'debit';

            $payment = $this->paymentHelper->createPlentyPayment($paymentData);
            $this->paymentHelper->assignPlentyPaymentToPlentyOrder($payment, (int)$nntransHistory->orderNo);
            $this->paymentHelper->createOrderComments($nntransHistory->orderNo, $callback_comments);
            if($this->sendTestMail && $this->to)
            {
                $cc = [];
                $this->sendMail($callback_comments, $this->to, 'Novalnet Callback Script Access Report', $cc, $this->bcc);
            }
            return $this->renderTemplate( $callback_comments );

         } elseif($this->getPaymentTypeLevel() == 0 ){

            if($this->aryCaptureparams['subs_billing'] == 1) {
            }
            else if(in_array($this->aryCaptureparams['payment_type'],array('PAYPAL','PRZELEWY24')) && $this->aryCaptureparams['status'] == '100' && $this->aryCaptureparams['tid_status'] == '100') {
               if ($nntransHistory->order_paid_amount < $nntransHistory->order_total_amount) {
                    $callback_comments = '</br>';
                    $callback_comments .= sprintf('Novalnet Callback Script executed successfully for the TID: %s with amount %s %s on %s. Please refer PAID transaction in our Novalnet Merchant Administration with the TID: %s', $this->aryCaptureparams['shop_tid'], ($this->aryCaptureparams['amount']/100), $this->aryCaptureparams['currency'], date('Y-m-d H:i:s'), $this->aryCaptureparams['tid'] ).'</br>';



                    $callback_table_param['callback_amount'] = $nntransHistory->order_total_amount;
                    $callback_table_param['amount'] = $nntransHistory->order_total_amount;
                    $callback_table_param['tid'] = $this->aryCaptureparams['shop_tid'];
                    $callback_table_param['ref_tid'] = $this->aryCaptureparams['tid_payment'];
                    $callback_table_param['payment_name'] = $nntransHistory->paymentName;
                    $callback_table_param['order_no'] = $nntransHistory->orderNo;
                    $this->callback->saveCallback($callback_table_param);

                    $paymentData['currency'] = (string) $this->aryCaptureparams['currency'];
                    $paymentData['paid_amount'] = (float) ($this->aryCaptureparams['amount']/100);
                    $paymentData['tid'] = $this->aryCaptureparams['tid'];
                    $order_status = (float) $this->config->get('Novalnet.order_completion_status');

                    $payment = $this->paymentHelper->createPlentyPayment($paymentData);
                    $this->paymentHelper->assignPlentyPaymentToPlentyOrder($payment, (int)$nntransHistory->orderNo);
                    $this->paymentHelper->updateOrderStatus($nntransHistory->orderNo, $order_status);
                    $this->paymentHelper->createOrderComments($nntransHistory->orderNo, $callback_comments);

                    if($this->sendTestMail && $this->to)
                    {
                        $cc = [];
                        $this->sendMail($callback_comments, $this->to, 'Novalnet Callback Script Access Report', $cc, $this->bcc);
                    }

                    return $this->renderTemplate($callback_comments);
                 } else {
                    return $this->renderTemplate('Novalnet Callbackscript received. Order already Paid');
                }
            } elseif( 'PRZELEWY24' == $this->aryCaptureparams['payment_type'] && ( !in_array($this->aryCaptureparams['tid_status'], array('100','86')) || '100' != $this->aryCaptureparams['status'] ) ) {
                // Przelewy24 cancel.
                                        $callback_comments = '</br>' . sprintf('The transaction has been canceled due to: %s',$this->paymentHelper->getNovalnetStatusText($this->aryCaptureparams) ) . '</br>';
                    $order_status = (float) $this->config->get('Novalnet.order_cancel_status');
                    $this->paymentHelper->updateOrderStatus($nntransHistory->orderNo,$order_status);
                    $this->paymentHelper->createOrderComments($nntransHistory->orderNo, $callback_comments);

                if($this->sendTestMail && $this->to)
                {
                    $cc = [];
                    $this->sendMail($callback_comments, $this->to, 'Novalnet Callback Script Access Report', $cc, $this->bcc);
                }
                return $this->renderTemplate($callback_comments);
            }
             else {
                $error = 'Novalnet Callbackscript received. Payment type ( '.$this->aryCaptureparams['payment_type'].' ) is not applicable for this process!';
                return $this->renderTemplate($error);
            }
         } else {
             return $this->renderTemplate('Novalnet callback received. TID Status ('.$this->aryCaptureparams['tid_status'].') is not valid: Only 100 is allowed');
         }
    }
        return $this->renderTemplate('Novalnet callback received. Callback Script executed already.');
    }


     /**
     *
     * Validate ip address
     *
     * @return bool|string
     */
    public function validateIpAddress()
    {
        $client_ip = $_SERVER['REMOTE_ADDR'];
        $data = false;
        if(!in_array($client_ip, $this->ipAllowed) && !$this->config->get('Novalnet.callback_test_mode')) {
            $data = $this->renderTemplate("Novalnet callback received. Unauthorised access from the IP ".$client_ip);
        }
        return $data;
    }

     /**
     *
     * Validate request param
     *
     * @param array $data
     * @return array|string
     */
    public function validateCaptureParams($data)
    {
        if(!isset($data['vendor_activation'])) {
            foreach ($this->paramsRequired as $v) {
            if (empty($data[$v])) {
                return 'Required param ( ' . $v . '  ) missing!';
            }
            if (in_array($v, array('tid', 'tid_payment', 'signup_tid')) && !preg_match('/^\d{17}$/', $data[$v])) {
                return 'Novalnet callback received. Invalid TID ['. $data[$v] . '] for Order.';
            }
        }

        if (!in_array(($data['payment_type']), array_merge($this->aryPayments, $this->aryChargebacks, $this->aryCollection,$this->arySubscription))) {
            return 'Novalnet callback received. Payment type ( '.$data['payment_type'].' ) is mismatched!';
        }
        if (isset($data['status']) && $data['status'] !=100 && ($data['payment_type'] != 'PRZELEWY'))  {
            return 'Novalnet callback received. Status ('.$data['status'].') is not valid: Only 100 is allowed';
        }
        if(!empty($data['signup_tid'])) { // Subscription
            $data['shop_tid'] = $data['signup_tid'];
        }
        else if(in_array($data['payment_type'], array_merge($this->aryChargebacks, $this->aryCollection))) {
            $data['shop_tid'] = $data['tid_payment'];
        }
        else if(!empty($data['tid'])) {
            $data['shop_tid'] = $data['tid'];
        }
           }
        return $data;

    }


     /**
     *
     * Get novalnet order by tid
     *
     * @return object|string
     */
    public function getOrderDetails()
    {
        $tid = $this->aryCaptureparams['shop_tid'];
        $order = $this->callback->getCallback('tid', $tid);
        if(!empty($order)) {
            $order_no = ($order[0]->orderNo);
            $order[0]->tid = $tid;

        $order[0]->order_total_amount = $order[0]->amount;
        //Collect paid amount information from the novalnet_callback_history
        $order[0]->order_paid_amount = 0;
        $payment_type_level = $this->getPaymentTypeLevel();

          if (in_array($payment_type_level,array(0,2))) {
              $orderAmountTotal = $this->callback->getCallback('orderNo', $order_no);
              if(!empty($orderAmountTotal)){
                  $amt = 0;
                  foreach($orderAmountTotal as $data){
                      $amt += $data->callbackAmount;
                  }
                 $order[0]->order_paid_amount = $amt;
              }
          }

          if (!isset($order[0]->paymentName) || !in_array($this->aryCaptureparams['payment_type'], $this->aryPaymentGroups[$order[0]->paymentName])) {
              return 'Novalnet callback received. Payment Type [' . $this->aryCaptureparams['payment_type'] . '] is not valid.';
          }
          if (!empty($this->aryCaptureparams['order_no']) && $this->aryCaptureparams['order_no'] != $order_no) {
              return 'Novalnet callback received. Order Number is not valid.';
          }
        } else {
            return 'Transaction mapping failed';
        }
        return $order[0];
    }


     /**
     *
     * Get callback execute payment level
     *
     * @return int
     */
    public function getPaymentTypeLevel() {
      if(in_array($this->aryCaptureparams['payment_type'], $this->aryPayments)) {
        return 0;
      }
      else if(in_array($this->aryCaptureparams['payment_type'], $this->aryChargebacks)) {
        return 1;
      }
      else if(in_array($this->aryCaptureparams['payment_type'], $this->aryCollection)) {
        return 2;
      }
   }


     /**
     *
     * Send callback mail
     *
     * @param $mailContent
     * @param $to
     * @param $subject
     * @param $cc
     * @param $bcc
     * @return bool
     */
    public function sendMail($mailContent, $to, $subject, $cc, $bcc)
    {
        try{
            if(!empty($bcc)){
                $bcc_mail = explode(",",$bcc);
            } else {
                $bcc_mail = [];
            }
            $mailer = pluginApp(MailerContract::class);
            $mailer->sendHtml($mailContent, $to, $subject, $cc, $bcc_mail);
            return true;
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Novalnet::CallbackMailNotSend', $e);
            return false;
        }
    }


     /**
     *
     * Render twig template for callback message
     *
     * @param $templateData
     * @return string
     */
    public function renderTemplate($templateData)
    {
        return $this->twig->render('Novalnet::callback.callback', ['comments' => $templateData]);
    }
}
