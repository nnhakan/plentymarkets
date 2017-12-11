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
use Plenty\Plugin\Http\Response;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Novalnet\Helper\PaymentHelper;
use Plenty\Plugin\Log\Loggable;

/**
 * Class PaymentController
 * @package Novalnet\Controllers
 */
class PaymentController extends Controller
{
    use Loggable;
    /**
     * @var Request
     */
    private $request;

    /**
     * @var Response
     */
    private $response;

    /**
     * @var ConfigRepository
     */
    private $config;

    /**
     * @var PaymentHelper
     */
    private $paymentHelper;

    /**
     * @var SessionStorageService
     */
    private $sessionStorage;


    /**
     * PaymentController constructor.
     *
     * @param Request $request
     * @param Response $response
     * @param ConfigRepository $config
     * @param PaymentHelper $paymentHelper
     * @param SessionStorageService $sessionStorage
     */
    public function __construct(  Request $request,
                                  Response $response,
                                  ConfigRepository $config,
                                  PaymentHelper $paymentHelper,
                                  FrontendSessionStorageFactoryContract $sessionStorage
                                )
    {
        $this->request          = $request;
        $this->response         = $response;
        $this->config           = $config;
        $this->paymentHelper    = $paymentHelper;
        $this->sessionStorage   = $sessionStorage;
    }

    /**
     * Novalnet redirects to this page if the payment could not be executed or other problems occurred
     */
    public function checkoutCancel()
    {
        $this->sessionStorage->getPlugin()->setValue('nn_response_data', null);
        $nn_response = $this->request->all();
        $this->sessionStorage->getPlugin()->setValue('nn_response_data', $nn_response);
        $message_type = "notifications";
        $notifications = json_decode($this->sessionStorage->getPlugin()->getValue($message_type));
        $message = $this->paymentHelper->getNovalnetStatusText($nn_response);
        array_push($notifications, array(
            'message' => $message,
            'type' => "error",
            'code' => ''
        ));
        $value = json_encode($notifications);
        $this->sessionStorage->getPlugin()->setValue($message_type, $value);
        // Redirects to the cancellation page.
        return $this->response->redirectTo('checkout');
    }

    /**
     * Novalnet redirects to this page if the payment was executed successfully
     */
    public function checkoutSuccess()
    {
        $nn_response = $this->request->all();
        if(isset($nn_response['tid']) && in_array($nn_response['payment_id'],array('6','27','37','33','34','40','41','49','50','59','69','78')) && (($nn_response['status'] == '100') || ($nn_response['payment_id'] == '34' && $nn_response['status'] == '90')))
        {
            if(in_array($nn_response['payment_id'],array('33','34','49','50','69','78')) || ($nn_response['payment_id'] == '6' && !empty($nn_response['cc_3d'])))
            {
                $access_key = preg_replace('/\s+/', '', $this->config->get('Novalnet.access_key'));
                $nn_response['amount'] = $this->paymentHelper->decodeData($nn_response['amount'], $nn_response['uniqid'], $access_key);
                $nn_response['test_mode'] = $this->paymentHelper->decodeData($nn_response['test_mode'], $nn_response['uniqid'], $access_key);
                $nn_response['product'] = $this->paymentHelper->decodeData($nn_response['product'], $nn_response['uniqid'], $access_key);
                $nn_response['amount'] = $nn_response['amount']/100;
            }
            $this->sessionStorage->getPlugin()->setValue('nn_response_data', $nn_response);
            $message_type = "notifications";
            $notifications = json_decode($this->sessionStorage->getPlugin()->getValue($message_type));
            $message = $this->paymentHelper->getNovalnetStatusText($nn_response);
            array_push($notifications, array(
                'message' => $message,
                'type' => "success",
                'code' => ''
            ));
            $value = json_encode($notifications);
            $this->sessionStorage->getPlugin()->setValue($message_type, $value);
            // Redirect to the success page.
            return $this->response->redirectTo('place-order');
        } else {
            // Redirects to the cancellation page.
            return $this->checkoutCancel();
        }
    }
}
