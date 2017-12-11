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

namespace Novalnet\Services;

use Plenty\Modules\Basket\Models\Basket;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Item\Item\Contracts\ItemRepositoryContract;
use Plenty\Modules\Basket\Models\BasketItem;
use Plenty\Modules\Account\Address\Models\Address;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use Plenty\Modules\Helper\Services\WebstoreHelper;
use Novalnet\Helper\PaymentHelper;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Payment\Method\Models\PaymentMethod;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Frontend\Services\AccountService;


/**
 * Class PaymentService
 *
 * @package Novalnet\Services
 */
class PaymentService
{

    use Loggable;

    /**
     *
     * @var ConfigRepository
     */
    private $config;

    /**
     *
     * @var ItemRepositoryContract
     */
    private $itemRepository;

    /**
     *
     * @var FrontendSessionStorageFactoryContract
     */
    private $session;

    /**
     *
     * @var AddressRepositoryContract
     */
    private $addressRepository;

    /**
     *
     * @var CountryRepositoryContract
     */
    private $countryRepository;

    /**
     *
     * @var WebstoreHelper
     */
    private $webstoreHelper;

    /**
     *
     * @var PaymentHelper
     */
    private $paymentHelper;

    /**
     *
     * @var OrderRepositoryContract
     */
    private $orderRepository;

    /**
     * Constructor.
     *
     * @param ConfigRepository $config
     * @param ItemRepositoryContract $itemRepository
     * @param FrontendSessionStorageFactoryContract $session
     * @param AddressRepositoryContract $addressRepository
     * @param CountryRepositoryContract $countryRepository
     * @param WebstoreHelper $webstoreHelper
     * @param PaymentHelper $paymentHelper
     * @param OrderRepositoryContract $orderRepository
     */
    public function __construct(ConfigRepository $config, ItemRepositoryContract $itemRepository, FrontendSessionStorageFactoryContract $session, AddressRepositoryContract $addressRepository, CountryRepositoryContract $countryRepository, WebstoreHelper $webstoreHelper, PaymentHelper $paymentHelper, OrderRepositoryContract $orderRepository)
    {
        $this->config = $config;
        $this->itemRepository = $itemRepository;
        $this->session = $session;
        $this->addressRepository = $addressRepository;
        $this->countryRepository = $countryRepository;
        $this->webstoreHelper = $webstoreHelper;
        $this->paymentHelper = $paymentHelper;
        $this->orderRepository = $orderRepository;
    }


    /**
     * Creates the payment in plentymarkets.
     *
     * @param array $data
     *
     * @return array
     */
    public function executePayment($data,$request)
    {
        try {
            if((in_array($data['payment_id'],array('34','78')) && in_array($data['tid_status'],array('86','90','85')))) {
                $data['order_status'] = $this->config->get('Novalnet.paypal_payment_pending_status');
                if($data['payment_id'] == '78') {
                    $data['order_status'] = $this->config->get('Novalnet.przelewy_payment_pending_status');
                }
                $data['paid_amount'] = 0;
            } elseif($data['payment_id'] == '41') {
                $data['order_status'] = $this->config->get('Novalnet.invoice_callback_order_status');
            } elseif($data['payment_id'] == '27') {
                $data['order_status'] = $this->config->get('Novalnet.order_completion_status');
                $data['paid_amount'] = 0;
            } else {
                $data['order_status'] = $this->config->get('Novalnet.order_completion_status');
                $data['paid_amount'] = $data['amount'];
            }

            $payment_config = [];
            $payment_config['test_mode'] =  $data['test_mode'];
            if(in_array($data['payment_id'], array(27, 41))) {
            if(!empty($data['product']) && is_numeric($data['product'])){
                $payment_config['product'] = $data['product'];
            }else{
                $access_key = preg_replace('/\s+/', '', $this->config->get('Novalnet.access_key'));
                $payment_config['product'] = $this->paymentHelper->decodeData($request['product'], $request['uniqid'], $access_key);
            }
        }
            if(in_array($data['payment_id'], array('27','41')) && $data['invoice_type'] == 'PREPAYMENT') {
                $payment_config['reference'] = $this->config->get('Novalnet.prepayment_payment_reference');
            } elseif(in_array($data['payment_id'], array('27','41'))){
                $payment_config['reference'] = $this->config->get('Novalnet.invoice_payment_reference');
            }
            $comments = $this->paymentHelper->getTransactionComments($data, $payment_config);
            $payment = $this->paymentHelper->createPlentyPayment($data);
            $this->paymentHelper->assignPlentyPaymentToPlentyOrder($payment, (int)$data['order_no']);
            $this->paymentHelper->updateOrderStatus((int)$data['order_no'],$data['order_status']);
            $this->paymentHelper->createOrderComments((int)$data['order_no'], $comments);
            return [
                'type' => 'success',
                'value' => $this->paymentHelper->getNovalnetStatusText($data)
            ];
        } catch (\Exception $e) {
                $this->getLogger(__METHOD__)->error('ExecutePayment failed.', $e);
                return [
                        'type' => 'error',
                        'value' => $e->getMessage()
                    ];
        }
    }


    /**
     * Send postback call to server for update order number
     *
     * @param array $data
     */
    public function sendPostbackCall($response, $request)
    {
        $url = 'https://payport.novalnet.de/paygate.jsp';
        $postData = [
            'vendor'         => $request['vendor'],
            'product'        => $request['product'],
            'tariff'         => $request['tariff'],
            'auth_code'      => $request['auth_code'],
            'key'            => $response['payment_id'],
            'status'         => 100,
            'tid'            => $response['tid'],
            'order_no'       => $response['order_no'],
            'remote_ip'      => (filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '127.0.0.1' : $_SERVER['REMOTE_ADDR']),
            'implementation' => 'ENC',
            'uniqid' => $request['uniqid']
        ];

        if(in_array($response['payment_id'], array(27, 41))) {
            if(!empty($response['product']) && is_numeric($response['product'])){
                $product = $response['product'];
            }else{
                $access_key = preg_replace('/\s+/', '', $this->config->get('Novalnet.access_key'));
                $product = $this->paymentHelper->decodeData($request['product'], $request['uniqid'], $access_key);
            }
            $postData['invoice_ref'] = 'BNR-' . $product . '-' . $response['order_no'];
        }
        $response = $this->paymentHelper->executeCurl($postData, $url);
    }


    /**
     *
     * Get the billing address object from Basket object
     *
     * @param object $basket
     * @return object
     */
    private function getBillingAddress(Basket $basket): Address
    {
        $addressId = $basket->customerInvoiceAddressId;
        return $this->addressRepository->findAddressById($addressId);
    }


    /**
     *
     * Get the billing address
     *
     * @param object $address
     * @return array
     */
    private function getAddress(Address $address): array
    {
        $data = [
            'city' => $address->town,
            'country' => $this->countryRepository->findIsoCode($address->countryId, 'iso_code_2'),
            'country_code' => $this->countryRepository->findIsoCode($address->countryId, 'iso_code_2'),
            'email' => $address->email,
            'first_name' => $address->firstName,
            'last_name' => $address->lastName,
            'zip' => $address->postalCode,
            'street' => $address->street,
            'search_in_street' => '1'
        ];
        if(!empty($address->houseNumber))
            $data['house_no'] = $address->houseNumber;
        if(!empty($address->companyName))
            $data['company'] = $address->companyName;
        if(!empty($address->phone))
            $data['mobile'] = $address->phone;

        return $data;

    }


    /**
     *
     * Get success page url
     *
     * @return string
     */
    private function getSuccessUrl(): string
    {
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/novalnet/checkoutSuccess';
    }

    /**
     *
     * Get failure page url
     *
     * @return string
     */
    private function getFailedUrl(): string
    {
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/novalnet/checkoutCancel';
    }

    /**
     * Build novalnet server request parameters
     *
     * @param Basket $basket
     * @param PaymentMethod $paymentMethod
     * @return array
     */
    public function getNovalnetReqParam(Basket $basket)
    {
        $param = [];
        $this->getVendorParam($param);
        $access_key = preg_replace('/\s+/', '', $this->config->get('Novalnet.access_key'));
        if(!is_numeric($param['vendor']) || !is_numeric($param['product']) || !is_numeric($param['tariff']) || empty($param['auth_code']) || empty($access_key))
            return array('status' => $this->paymentHelper->getTranslatedText('basic_param_validation'), 'data' => '');

        $this->getPaymentParam($param);
        $this->getCustomerParam($basket, $param);
        $this->getOrderParam($basket, $param);
        $this->getSystemParam($param);
        $this->getRedirectParam($param);
        $this->getAdditionalParam($param);
        $this->processEncode($param, $access_key);

        // Hide the Shop details, Customer details, Tariff details, etc. In MOTO form
        $param['address_form']  = '0';
        $param['shide']         = '1';
        $param['lhide']         = '1';
        $param['thide']         = '1';
        $param['hfooter']       = '0';

        return array('status' => 'success','data'=>$param);
    }


    /**
     * Get merchant related parameters
     *
     *
     * @param array $param
     */
    public function getVendorParam(&$param)
    {
        $param['vendor'] = preg_replace('/\s+/', '', $this->config->get('Novalnet.vendor_id'));
        $param['auth_code'] = preg_replace('/\s+/', '', $this->config->get('Novalnet.auth_code'));
        $param['product'] = preg_replace('/\s+/', '', $this->config->get('Novalnet.product_id'));
        $param['tariff'] = preg_replace('/\s+/', '', $this->config->get('Novalnet.tariff'));
    }

    /**
     * Get payment related parameters
     *
     * @param array $param
     */
    public function getPaymentParam(&$param)
    {
        $testmode = $this->config->get('Novalnet.test_mode');
        $param['test_mode'] = ($testmode == "1") ? $testmode : "0";
        $param['sepa_due_date'] = $this->getSepaDueDate();
        $invoice_due_date = trim($this->config->get('Novalnet.invoice_due_date'));
        $cashpayment_due_date = trim($this->config->get('Novalnet.cashpayment_due_date'));
        if(is_numeric($invoice_due_date))
            $param['invoice_due_date'] = date( 'Y-m-d', strtotime( date( 'y-m-d' ) . '+ ' . $invoice_due_date . ' days' ) );
        if(is_numeric($cashpayment_due_date))
            $param['cashpayment_due_date'] = date( 'Y-m-d', strtotime( date( 'y-m-d' ) . '+ ' . $cashpayment_due_date . ' days' ) );
    }

    /**
     * Get customer related parameters
     *
     * @param object $basket
     * @param array $param
     */
    public function getCustomerParam($basket, &$param)
    {
        $address = $this->getAddress($this->getBillingAddress($basket));
        $account = pluginApp(AccountService::class);
        $customerId = $account->getAccountContactId();
        $address['customer_no'] = ($customerId) ? $customerId : 'guest';
        $param = array_merge($param, $address);
    }

    /**
     * Get system related parameters
     *
     * @param array $param
     */
    public function getSystemParam(&$param)
    {
        $param['remote_ip'] = (filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '127.0.0.1' : $_SERVER['REMOTE_ADDR']);
        $param['system_ip'] = (filter_var($_SERVER['SERVER_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '127.0.0.1' : $_SERVER['SERVER_ADDR']);
        $param['system_url'] = $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl;
        $param['system_name'] = 'PlentyMarket';
        $param['system_version'] = '7.0.0-NN(1.0.0)';
        $param['notify_url'] = $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/novalnet/callback';
    }

    /**
     * Get order related parameters
     *
     * @param object $basket
     * @param array $param
     */
    public function getOrderParam($basket, &$param)
    {
        $param['lang'] = strtoupper($this->session->getLocaleSettings()->language);
        $param['amount'] = (sprintf('%0.2f', $basket->basketAmount) * 100);
        $param['currency'] = $basket->currency;
    }


    /**
     * Get redirection related parameters
     *
     * @param array $param
     */
    public function getRedirectParam(&$param)
    {
        $param['return_url'] = $this->getSuccessUrl();
        $param['return_method'] = 'POST';
        $param['error_return_url'] = $this->getFailedUrl();
        $param['error_return_method'] = 'POST';
        $param['implementation'] = 'ENC';
        $param['uniqid'] = $this->paymentHelper->getUniqueId();
    }


    /**
     * Get additional parameters
     *
     * @param array $param
     */
    public function getAdditionalParam(&$param)
    {
        if($this->config->get('Novalnet.cc_3d') == '1')
            $param['cc_3d'] = '1';

        if(is_numeric($this->config->get('Novalnet.on_hold')) && $this->config->get('Novalnet.on_hold') <= $param['amount'])
            $param['on_hold'] = '1';

        if(is_numeric($this->config->get('Novalnet.referrer_id')))
            $param['referrer_id'] = $this->config->get('Novalnet.referrer_id');

        if(!empty($this->config->get('Novalnet.reference1')))
        {
            $param['input1'] = 'reference1';
            $param['inputval1'] = strip_tags($this->config->get('Novalnet.reference1'));
        }
        if(!empty($this->config->get('Novalnet.reference2')))
        {
            $param['input2'] = 'reference2';
            $param['inputval2'] = strip_tags($this->config->get('Novalnet.reference2'));
        }
    }


    /**
     * Get merchant configuration parameters
     *
     * @param string $key
     * @return mixed
     */
    public function getNovalnetConfig($key)
    {
        return preg_replace('/\s+/', '', $this->config->get('Novalnet.'.$key));
    }


    /**
     * Encode the server request parameters
     *
     * @param array $param
     * @param string $access_key
     */
    public function processEncode(&$param, $access_key)
    {
        foreach (array('auth_code', 'product', 'tariff', 'amount', 'test_mode') as $key) {
            // Encoding process
                $param[$key] = $this->paymentHelper->encodeData($param[$key], $param['uniqid'], $access_key);
         }
         // Generate hash value
         $param['hash'] = $this->paymentHelper->generateHash($param, $access_key);
    }


    /**
     * Calculate SEPA due date
     *
     * @return string
     */
    public function getSepaDueDate()
    {
        $days = $this->config->get('Novalnet.sepa_due_date');
        $due = preg_match('/^[0-9]/', $days) ? $days : '7';
        if( $due < 7 ) $due = 7;
        $day_timestamp = strtotime("+ " . $due . " day");
        $due_date = date( "Y-m-d", $day_timestamp );
        return $due_date;

    }
}
