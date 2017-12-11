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

namespace Novalnet\Methods;

use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodService;
use Plenty\Plugin\Application;
use Novalnet\Helper\PaymentHelper;


/**
 * Class NovalnetPaymentMethod
 * @package Novalnet\Methods
 */
class NovalnetPaymentMethod extends PaymentMethodService
{
    /**
     * Check the configuration if the payment method is active
     * Return true if the payment method is active, else return false
     *
     * @param ConfigRepository $configRepository
     * @return bool
     */
    public function isActive( ConfigRepository $configRepository ):bool
    {
        /** @var bool $active */
        $active = true;
        $vendor = preg_replace('/\s+/', '', $configRepository->get('Novalnet.vendor_id'));
        $auth_code = preg_replace('/\s+/', '', $configRepository->get('Novalnet.auth_code'));
        $product = preg_replace('/\s+/', '', $configRepository->get('Novalnet.product_id'));
        $tariff = preg_replace('/\s+/', '', $configRepository->get('Novalnet.tariff'));
        $access_key = preg_replace('/\s+/', '', $configRepository->get('Novalnet.access_key'));
        $payment_active = $configRepository->get('Novalnet.payment_active');
        if(!$payment_active || !is_numeric($vendor) || is_null($auth_code) || !is_numeric($product) || !is_numeric($tariff) || is_null($access_key))
            $active = false;

        return $active;
    }

    /**
     * Get the name of the payment method. The name can be entered in the config.json.
     *
     * @param ConfigRepository $configRepository
     * @return string
     */
    public function getName( ConfigRepository $configRepository, PaymentHelper $paymentHelper ):string
    {
        $name = trim($configRepository->get('Novalnet.name'));        
        if(empty($name))
        {
            $name = $paymentHelper->getTranslatedText('novalnet_frontend_name');
        }        
        return $name;
    }

    /**
     * Get the path of the icon. The URL can be entered in the config.json.
     *
     * @param ConfigRepository $configRepository
     * @return string
     */
    public function getIcon():string
    {
        $app = pluginApp(Application::class);
                $icon = $app->getUrlPath('novalnet').'/images/icon.png';
                return $icon;
    }

    /**
     * Get the description of the payment method. The description can be entered in the config.json.
     *
     * @param ConfigRepository $configRepository
     * @param PaymentHelper $paymentHelper
     * @return string
     */
    public function getDescription( ConfigRepository $configRepository, PaymentHelper $paymentHelper):string
    {
        $description = trim($configRepository->get('Novalnet.description'));
        if(empty($description))
        {
            $description = $paymentHelper->getTranslatedText('payment_description');
        }
        return $description;
    }
}
