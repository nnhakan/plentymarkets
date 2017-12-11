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

use Plenty\Plugin\Templates\Twig;

use Novalnet\Helper\PaymentHelper;
use Novalnet\Services\PaymentService;
use Plenty\Modules\Payment\Models\Payment;
use Plenty\Modules\Payment\Models\PaymentProperty;
use Plenty\Modules\Comment\Contracts\CommentRepositoryContract;
use \Plenty\Modules\Authorization\Services\AuthHelper;


/**
 * Class NovalnetOrderConfirmationDataProvider
 *
 * @package Novalnet\Providers
 */
class NovalnetOrderConfirmationDataProvider
{
    /**
     * Render the order comments
     *
     * @param Twig $twig
     * @return string
     */
    public function call(Twig $twig, $arg)
    {
        $paymentHelper = pluginApp(PaymentHelper::class);
        $paymentMethodId = $paymentHelper->getPaymentMethod();
        $order = $arg[0];
        if(isset($order->order))
            $order = $order->order;
        foreach($order->properties as $property){
            if($property->typeId == '3' && $property->value == $paymentMethodId) {
                $orderId = (int) $order->id;

                $authHelper = pluginApp(AuthHelper::class);
                $comments = $authHelper->processUnguarded(
                        function () use ($orderId) {
                    $commentsObj = pluginApp(CommentRepositoryContract::class);
                    $commentsObj->setFilters(["referenceType" => "order", "referenceValue" => $orderId]);
                    return $commentsObj->listComments();
                    }
                );

                $comment = '';
                foreach($comments as $data)
                    {
                    $comment .= (string)$data->text;
                    $comment .= '</br>';
                }

                return $twig->render('Novalnet::NovalnetOrderHistory', ["comments" => html_entity_decode($comment)]);
            }
        }
    }
}
