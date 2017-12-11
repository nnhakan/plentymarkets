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

namespace Novalnet\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Modules\Payment\Events\Checkout\ExecutePayment;
use Plenty\Modules\Payment\Events\Checkout\GetPaymentMethodContent;
use Plenty\Modules\Basket\Events\Basket\AfterBasketCreate;
use Plenty\Modules\Basket\Events\Basket\AfterBasketChanged;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodContainer;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Plugin\Log\Loggable;
use Novalnet\Helper\PaymentHelper;
use Novalnet\Methods\NovalnetPaymentMethod;
use Novalnet\Services\PaymentService;
use Novalnet\Services\CallbackService;

/**
 * Class NovalnetServiceProvider
 *
 * @package Novalnet\Providers
 */
class NovalnetServiceProvider extends ServiceProvider
{
    use Loggable;

    /**
     * Register the route service provider
     */
    public function register()
    {
        $this->getApplication()->register(NovalnetRouteServiceProvider::class);
    }

    /**
     * Boot additional services for the payment method
     *
     * @param paymentHelper $paymentHelper
     * @param PaymentMethodContainer $payContainer
     * @param Dispatcher $eventDispatcher
     * @param PaymentService $paymentService
     * @param BasketRepositoryContract $basketRepository
     * @param PaymentMethodRepositoryContract $paymentMethodService
     * @param FrontendSessionStorageFactoryContract $sessionStorage
     * @param CallbackService $callback
     */
    public function boot(Dispatcher $eventDispatcher, PaymentHelper $paymentHelper, PaymentService $paymentService, BasketRepositoryContract $basketRepository, PaymentMethodContainer $payContainer, PaymentMethodRepositoryContract $paymentMethodService, FrontendSessionStorageFactoryContract $sessionStorage, CallbackService $callback)
    {
        // Create the ID of the payment method if it doesn't exist yet
        $paymentHelper->createMopIfNotExists();

        // Register the Novalnet payment method in the payment method container
        $payContainer->register('plenty_novalnet::NOVALNET', NovalnetPaymentMethod::class,
                              [ AfterBasketChanged::class, AfterBasketItemAdd::class, AfterBasketCreate::class ]
        );

        // Listen for the event that gets the payment method content
        $eventDispatcher->listen(GetPaymentMethodContent::class,
                function(GetPaymentMethodContent $event) use( $paymentHelper, $paymentService, $basketRepository, $paymentMethodService, $sessionStorage)
                {
                    if($event->getMop() == $paymentHelper->getPaymentMethod())
                    {
                        $contentType = 'errorCode';
                        $requestData = $paymentService->getNovalnetReqParam($basketRepository->load());
                        $content = $requestData['status'];
                        if($requestData['status'] == 'success')
                        {
                            $sessionStorage->getPlugin()->setValue('nnRequest', $requestData['data']);
                            $content = '<form name="novalnet_redirect_form" method="post" action="https://paygate.novalnet.de/paygate.jsp">';
                            foreach($requestData['data'] as $key => $value)
                            {
                                $content .= '<input name="'.$key.'" type="hidden" value="'.$value.'">';
                            }
                            $redirect_text = $paymentHelper->getTranslatedText('novalnet_redirect_text');
                            $content .= '<div>' . $redirect_text . '</div>';
                            $content .= '<button type="submit" class="btn btn-default" id="novalnet_form_btn">'. $paymentHelper->getTranslatedText('submit_button_text').'</button></form><script type="text/javascript">$("#novalnet_form_btn").click();</script>';
                            $contentType = 'htmlContent';
                        }
                        $event->setValue($content);
                        $event->setType($contentType);
                    }
                });

        // Listen for the event that executes the payment
        $eventDispatcher->listen(ExecutePayment::class, function (ExecutePayment $event) use ($paymentHelper, $paymentService, $sessionStorage, $callback) {
            if($event->getMop() == $paymentHelper->getPaymentMethod()) {
                $nn_response = $sessionStorage->getPlugin()->getValue('nn_response_data');
                $sessionStorage->getPlugin()->setValue('nn_response_data',null);
                if(isset($nn_response['tid']) && in_array($nn_response['payment_id'],array(6,37,27,33,34,40,41,49,50,59,69,78)) && (($nn_response['status'] == '100') || ($nn_response['payment_id'] == '34' && $nn_response['status'] == '90')))
                {
                    $reqData = $sessionStorage->getPlugin()->getValue('nnRequest');
                    $sessionStorage->getPlugin()->setValue('nnRequest',null);
                    $nn_response['order_no'] = $event->getOrderId();
                    $paymentService->sendPostbackCall($nn_response, $reqData);
                    $nn_response['amount'] = (float) $nn_response['amount'];

                    $result = $paymentService->executePayment($nn_response, $reqData);

                    $data['callback_amount'] = $nn_response['amount']*100;
                    $data['amount'] = $nn_response['amount']*100;
                    $data['tid'] = $nn_response['tid'];
                    $data['ref_tid'] = $nn_response['tid'];
                    $data['payment_name'] = $paymentHelper->getPaymentNameByResponse($nn_response,true);
                    $data['order_no'] = $nn_response['order_no'];
                        if($nn_response['payment_id'] == '27' || $nn_response['payment_id'] == '59' || ($nn_response['payment_id'] == '34' && in_array($nn_response['tid_status'], array('90','85'))) || ($nn_response['payment_id'] == '78' && $nn_response['tid_status'] == '86'))
                        $data['callback_amount'] = 0;

                    $callback->saveCallback($data);
                } else {
                    $result['type'] = 'error';
                    $result['value'] = $paymentHelper->getTranslatedText('payment_not_success');
                }
                $event->setType($result['type']);
                $event->setValue($result['value']);
            }
        });
   }
}
